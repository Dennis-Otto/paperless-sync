<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
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
	 * Saving runs the connection checklist, checks(), when the schedule will run with the
	 * settings, or when a token was entered. A new URL needs the token again
	 * (ConfigService::resolveToken()), so it is tested as well. Otherwise nothing reaches Paperless
	 * until an administrator starts a run, which finds a problem on its own, so the settings are
	 * stored without the test: an administrator can turn off a schedule that fails while Paperless
	 * is down, its token revoked or the target user disabled.
	 *
	 * A blocking failure of the checklist refuses the settings and says what to do. A failure that
	 * concerns one module, such as a missing permission for the tags, is saved with the settings
	 * and shown, so that the permissions can be fixed in Paperless after the save.
	 *
	 * The answer is the configuration as it was stored, normalized, for the page to show,
	 * connectionTested, whether the connection was tested, and checks, the lines of the checklist.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function save(array $settings = [], #[\SensitiveParameter] string $token = ''): JSONResponse {
		$tokenEntered = trim($token) !== '';
		// Whether only the schedule asked for the test, so that turning it off saves without one.
		$onlyForTheSchedule = false;
		$checks = [];
		try {
			$config = $this->configService->validate($settings, $token);
			$tested = $config->enabled || $tokenEntered;
			if ($tested) {
				$onlyForTheSchedule = !$tokenEntered;
				$checks = $this->checks($config, $this->configService->resolveToken($token, $config->paperlessUrl));
				foreach ($checks as $check) {
					if ($check->blocks()) {
						return $this->refusal($check, $checks, $onlyForTheSchedule);
					}
				}
				// The checks passed: when storing the settings fails, turning off the schedule doesn't help.
				$onlyForTheSchedule = false;
			}

			return new JSONResponse([
				...$this->configService->save($settings, $token)->jsonSerialize(),
				'connectionTested' => $tested,
				'checks' => $this->translated($checks),
			]);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $exception) {
			$message = 'Could not validate the Paperless and Nextcloud configuration: ' . $exception->getMessage();
			if (!$onlyForTheSchedule) {
				return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
			}

			// The way to pause a synchronization that fails, such as while Paperless is down, and the
			// setting that does it, which the page moves the focus to.
			return new JSONResponse(
				['message' => $message . ' ' . $this->l10n->t('To save the settings without the test, turn off scheduled synchronization.'), 'setting' => 'enabled'],
				Http::STATUS_BAD_REQUEST,
			);
		}
	}

	/**
	 * The connection checklist of the settings in the form, without saving them: the answer lists
	 * every check with what it found and what to do. It sends the token as a save does, a stored
	 * one only to the URL that it was stored with, and reaches an address of the local network
	 * only when the form allows it, so Nextcloud asks for the password of the administrator here
	 * as well.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/test')]
	#[PasswordConfirmationRequired]
	public function test(array $settings = [], #[\SensitiveParameter] string $token = ''): JSONResponse {
		try {
			$config = $this->configService->validate($settings, $token);
			$checks = $this->checks($config, $this->configService->resolveToken($token, $config->paperlessUrl));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['checks' => $this->translated($checks)]);
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

	/**
	 * The connection checklist: the checks of Paperless, then whether the target user may create
	 * files in the base folder, which every run needs, whatever Paperless answered.
	 *
	 * @return list<ConnectionCheck>
	 */
	private function checks(SyncConfig $config, #[\SensitiveParameter] string $token): array {
		$checks = $this->paperless->preflight($config, $token);
		try {
			$this->storage->test($config->targetUser, $config->basePath);
			$checks[] = ConnectionCheck::passed(ConnectionCheck::FOLDER, '%s may create files in %s.', [$config->targetUser, $config->basePath]);
		} catch (\Throwable $exception) {
			$checks[] = ConnectionCheck::failed(
				ConnectionCheck::FOLDER,
				'Nextcloud cannot store the files there: %s',
				[$exception->getMessage()],
				'Check the target user ID, and that the base folder is a folder in which that user may create files.',
				true,
			);
		}

		return $checks;
	}

	/**
	 * Settings that a check refuses: what it found and what to do, the setting that the page moves
	 * the focus to, and every line of the checklist. When only the schedule asked for the test,
	 * turning the schedule off saves the settings, unless the check names a way of its own.
	 *
	 * @param list<ConnectionCheck> $checks
	 */
	private function refusal(ConnectionCheck $blocking, array $checks, bool $onlyForTheSchedule): JSONResponse {
		$message = $blocking->translatedText($this->l10n);
		$setting = $blocking->setting;
		if ($setting === '' && $onlyForTheSchedule) {
			$message .= ' ' . $this->l10n->t('To save the settings without the test, turn off scheduled synchronization.');
			$setting = 'enabled';
		}
		$answer = ['message' => $message, 'checks' => $this->translated($checks)];
		if ($setting !== '') {
			$answer['setting'] = $setting;
		}

		return new JSONResponse($answer, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * The lines of the checklist in the language of the administrator, as the page shows them.
	 *
	 * @param list<ConnectionCheck> $checks
	 * @return list<array{id: string, label: string, status: string, message: string, hint: string, setting: string}>
	 */
	private function translated(array $checks): array {
		return array_map(fn (ConnectionCheck $check): array => $check->translated($this->l10n), $checks);
	}
}
