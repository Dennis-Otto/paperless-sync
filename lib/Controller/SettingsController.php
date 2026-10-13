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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

final class SettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessClientInterface $paperless,
		private NextcloudStorageInterface $storage,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
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
			return new JSONResponse(
				['message' => $message . ' To save the settings without the test, turn off scheduled synchronization.', 'setting' => 'enabled'],
				Http::STATUS_BAD_REQUEST,
			);
		}
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
