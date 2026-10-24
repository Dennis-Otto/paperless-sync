<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Service\AccountPermissions;
use OCA\PaperlessSync\Service\AccountSetup;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\ConnectionChecklist;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessAccountExistsException;
use OCA\PaperlessSync\Service\PaperlessClientFactoryInterface;
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
		'path_template', 'prefer_archive', 'skip_inbox', 'excluded_tags', 'included_tags',
		'empty_correspondent', 'empty_document_type', 'empty_date', 'untitled',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessClientFactoryInterface $clients,
		private ConnectionChecklist $checklist,
		private IL10N $l10n,
		private SyncService $syncService,
		private PathTemplateService $pathTemplate,
		private AccountPermissions $permissions,
		private AccountSetup $accountSetup,
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
			return $this->invalid($exception);
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
				$checks = $this->checklist->checks($config, $this->configService->resolveToken($token, $config->paperlessUrl));
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
			return $this->invalid($exception);
		} catch (\Throwable $exception) {
			$message = $this->l10n->t('Could not validate the Paperless and Nextcloud configuration: %s', [$exception->getMessage()]);
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
			$checks = $this->checklist->checks($config, $this->configService->resolveToken($token, $config->paperlessUrl));
		} catch (InvalidArgumentException $exception) {
			return $this->invalid($exception);
		}

		return new JSONResponse(['checks' => $this->translated($checks)]);
	}

	/**
	 * The answer to settings that the app can't use: what to do, in the language of the
	 * administrator, and the setting that it names, which the page moves the focus to.
	 */
	private function invalid(InvalidArgumentException $exception): JSONResponse {
		$answer = ['message' => $this->message($exception)];
		if ($exception instanceof InvalidSettingException && $exception->setting !== '') {
			$answer['setting'] = $exception->setting;
		}

		return new JSONResponse($answer, Http::STATUS_BAD_REQUEST);
	}

	/** The message of an exception, in the language of the administrator when the app translates it. */
	private function message(\Throwable $exception): string {
		return $exception instanceof InvalidSettingException ? $exception->translated($this->l10n) : $exception->getMessage();
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
	 * The names of the tags of Paperless, which the page offers for the excluded tags, the tags to
	 * mirror and the tags for every import, read with the stored URL and token, as a run reads them. Without both, there is nothing to read: the page
	 * then takes names as they are typed. A name that the setting can't hold, with a comma or a line
	 * break, which separate its names, is left out, and so is an empty one or one that comes twice.
	 * The names are in the order of their letters, case aside. Only administrators may ask, as for
	 * every route of the controller.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/settings/paperless/tags')]
	public function tags(): JSONResponse {
		[$config, $connection] = $this->configService->snapshot();
		if ($config->paperlessUrl === '' || !$config->tokenConfigured) {
			return new JSONResponse(['message' => $this->l10n->t('Save the connection to Paperless first; then its tags appear here.')], Http::STATUS_CONFLICT);
		}
		try {
			$names = $this->clients->connect($connection)->tags()['names'];
		} catch (PaperlessLocalNetworkException $exception) {
			return new JSONResponse(
				['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING],
				Http::STATUS_BAD_GATEWAY,
			);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $this->l10n->t('Paperless did not list its tags: %s', [$exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
		}
		// By the name in small letters, as a run compares them: Draft and draft are one tag to it.
		$tags = [];
		foreach ($names as $name) {
			$name = trim($name);
			$key = mb_strtolower($name);
			if ($name !== '' && preg_match('/[,\r\n]/', $name) !== 1 && !isset($tags[$key])) {
				$tags[$key] = $name;
			}
		}
		$tags = array_values($tags);
		usort($tags, strnatcasecmp(...));

		return new JSONResponse(['tags' => $tags]);
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
	 * Creates the account of the app in Paperless with the token of a superuser of Paperless,
	 * $superuserToken, or, with $update, updates the user $username that exists already
	 * (decision 0027). The account gets the permissions that the modules of the settings in the form
	 * need, as AccountPermissions lists them; then the checklist runs with its token, and the
	 * settings are saved with that token, as a save with a typed token saves them.
	 *
	 * The token of the superuser goes only to the Paperless URL of the form, for the requests of the
	 * setup: it is never stored, logged or answered. So Nextcloud asks for the password of the
	 * administrator first, as for a save.
	 *
	 * The settings are checked first, and the folder of the target user, so that Paperless gets no
	 * account for settings that can't be saved. A user that exists already is changed only with
	 * $update: the answer is 409 then, with what an update changes, for the page to ask. A blocking
	 * failure of the checklist refuses the settings, as on save; the account is ready then, and the
	 * setup can update it once the failure is fixed.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/account')]
	#[PasswordConfirmationRequired]
	public function account(array $settings = [], #[\SensitiveParameter] string $superuserToken = '', string $username = '', bool $update = false): JSONResponse {
		$superuserToken = trim($superuserToken);
		try {
			if ($superuserToken === '') {
				throw new InvalidSettingException('Enter the API token of a superuser of Paperless.', [], 'account_token');
			}
			$config = $this->configService->validate($settings, $superuserToken);
		} catch (InvalidArgumentException $exception) {
			return $this->invalid($exception);
		}
		$folder = $this->checklist->folder($config);
		if ($folder->blocks()) {
			return $this->refusal($folder, [$folder], false);
		}
		$permissions = $this->permissions->of($config);
		$labels = AccountPermissions::labels($permissions, $this->l10n);
		try {
			$account = $this->accountSetup->setUp(PaperlessConnection::of($config, $superuserToken), $username, $permissions, $update);
		} catch (PaperlessAccountExistsException $exception) {
			return new JSONResponse([
				'message' => $this->l10n->t('The user %1$s exists in Paperless already. Updating it gives it exactly these permissions: %2$s. It also gets a new random password, which nobody needs, because the app signs in with its token. It is no Admin any more, and the permissions of its groups stay.', [$exception->username, implode(', ', $labels)]),
				'exists' => true,
			], Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $exception) {
			return $this->invalid($exception);
		} catch (PaperlessLocalNetworkException $exception) {
			return new JSONResponse(['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING], Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $this->l10n->t('Paperless did not set up the account: %s', [$exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
		}
		try {
			$checks = $this->checklist->checks($config, $account['token']);
			foreach ($checks as $check) {
				if ($check->blocks()) {
					$refusal = $this->refusal($check, $checks, false);
					/** @var array{message: string} $answer */
					$answer = $refusal->getData();
					$answer['message'] .= ' ' . $this->l10n->t('The account %s is ready in Paperless: once this is fixed, set it up again, which updates it.', [$account['username']]);

					return new JSONResponse($answer, Http::STATUS_BAD_REQUEST);
				}
			}

			return new JSONResponse([
				...$this->configService->save($settings, $account['token'])->jsonSerialize(),
				'connectionTested' => true,
				'checks' => $this->translated($checks),
				'account' => ['username' => $account['username'], 'created' => $account['created'], 'permissions' => $labels],
			]);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $this->l10n->t('Could not validate the Paperless and Nextcloud configuration: %s', [$this->message($exception)])], Http::STATUS_BAD_REQUEST);
		}
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
