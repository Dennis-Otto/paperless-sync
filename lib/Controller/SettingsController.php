<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use InvalidArgumentException;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

final class SettingsController extends Controller {
	/** The newest documents of Paperless whose paths the preview of the path template shows. */
	public const PREVIEW_DOCUMENTS = 5;

	/** The settings of the page that decide the path of a document or whether a run skips it; the preview takes the others as saved. */
	private const PREVIEW_SETTINGS = [
		'path_template', 'prefer_archive', 'skip_inbox', 'excluded_tags',
		'empty_correspondent', 'empty_document_type', 'empty_date', 'untitled',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessClientInterface $paperless,
		private NextcloudStorageInterface $storage,
		private IL10N $l10n,
		private SyncService $syncService,
		private PathTemplateService $pathTemplate,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The paths that the newest documents of Paperless would have with the path template of the
	 * page and its fallback names, before they are saved, for the preview under the template. It
	 * reads Paperless with the saved connection and changes nothing, so it needs no password.
	 *
	 * The answer is the template as a save would store it, whether Paperless is connected, and the
	 * paths, relative to the archive folder; an invalid template is a message, as on save.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/preview')]
	public function preview(array $settings = []): JSONResponse {
		$saved = $this->configService->get();
		/** @psalm-suppress MixedAssignment */
		$template = $settings['path_template'] ?? $saved->pathTemplate;
		try {
			$normalized = $this->pathTemplate->validateTemplate(is_string($template) ? $template : '');
			// Without a saved connection, the template is checked, and there is nothing to show.
			if (!$saved->tokenConfigured) {
				return new JSONResponse(['template' => $normalized, 'connected' => false, 'paths' => []]);
			}
			$config = $this->configService->validate(array_intersect_key($settings, array_flip(self::PREVIEW_SETTINGS)));

			return new JSONResponse([
				'template' => $config->pathTemplate,
				'connected' => true,
				'paths' => $this->syncService->previewPaths($config, self::PREVIEW_DOCUMENTS),
			]);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (PaperlessLocalNetworkException $exception) {
			return new JSONResponse(['message' => $exception->translated($this->l10n)], Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $this->l10n->t('Paperless could not be read for the preview: %s', [$exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Saving decides where the token goes, so Nextcloud asks for the password of the administrator
	 * first, unless it was confirmed in the last 30 minutes; a session that someone else took over
	 * is not enough. The stored token is tested only with the URL that it was stored with, and an
	 * address of the local network only when the settings allow it.
	 *
	 * Saving tests the connection to Paperless and the folder in Nextcloud when the schedule will
	 * run with the settings, or when a token was entered. A new URL needs the token again
	 * (ConfigService::resolveToken()), so it is tested as well. Otherwise nothing reaches Paperless
	 * until an administrator starts a run, which finds a problem on its own, so the settings are
	 * stored without the test: an administrator can turn off a schedule that fails while Paperless
	 * is down, its token revoked or the target user disabled.
	 *
	 * The answer is the configuration as it was stored, normalized, for the page to show, and
	 * connectionTested, whether the connection was tested.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function save(array $settings = [], #[\SensitiveParameter] string $token = ''): JSONResponse {
		$tokenEntered = trim($token) !== '';
		// Whether only the schedule asked for the test, so that turning it off saves without one.
		$onlyForTheSchedule = false;
		try {
			$config = $this->configService->validate($settings, $token);
			$tested = $config->enabled || $tokenEntered;
			if ($tested) {
				$onlyForTheSchedule = !$tokenEntered;
				$effectiveToken = $this->configService->resolveToken($token, $config->paperlessUrl);
				$this->paperless->testConnection($config->paperlessUrl, $effectiveToken, $config->paperlessLocalNetwork);
				$this->storage->test($config->targetUser, $config->basePath);
				// Both tests passed: when storing the settings fails, turning off the schedule doesn't help.
				$onlyForTheSchedule = false;
			}

			return new JSONResponse([...$this->configService->save($settings, $token)->jsonSerialize(), 'connectionTested' => $tested]);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (PaperlessLocalNetworkException $exception) {
			// What to do, and the setting that does it, which the page moves the focus to.
			return new JSONResponse(
				['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $exception) {
			$message = 'Could not validate the Paperless and Nextcloud configuration: ' . $exception->getMessage();
			if (!$onlyForTheSchedule) {
				return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
			}

			// The way to pause a synchronization that fails, such as while Paperless is down, and the
			// setting that does it, which the page moves the focus to.
			$hint = $this->l10n->t('To save the settings without the test, turn off scheduled synchronization.');

			return new JSONResponse(['message' => self::sentence($message) . ' ' . $hint, 'setting' => 'enabled'], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * The text with a full stop at its end, unless it ends a sentence already, so that a hint
	 * after it starts a sentence of its own: the message of a failed request of Guzzle ends with
	 * the URL, for example.
	 */
	private static function sentence(string $text): string {
		$text = rtrim($text);

		return preg_match('/[.!?]$/', $text) === 1 ? $text : $text . '.';
	}

	/**
	 * Disconnecting deletes the token, also only with the password confirmed. The answer is the
	 * configuration that stays, for the page to show.
	 */
	#[FrontpageRoute(verb: 'DELETE', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function reset(): JSONResponse {
		return new JSONResponse($this->configService->reset());
	}
}
