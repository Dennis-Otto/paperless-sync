<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Settings;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\GettingStarted;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\StatusTranslation;
use OCA\PaperlessSync\Service\WebhookService;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;
use OCP\SetupCheck\SetupResult;

/**
 * The settings page, which an administrator can delegate to a group under Administration privileges
 * of Nextcloud: the routes of the page name this class in #[AuthorizedAdminSetting]
 * (decision 0035).
 */
final class AdminSettings implements IDelegatedSettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private StatusService $statusService,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialState,
		private SyncHealth $syncHealth,
		private BackgroundJobMode $backgroundJobMode,
		private WebhookService $webhookService,
		private IL10N $l10n,
		private GettingStarted $gettingStarted,
	) {
	}

	public function getForm(): TemplateResponse {
		$config = $this->configService->get();
		// The page shows the status, its error and its report from the start, as after a refresh: in
		// the language of the administrator, as SyncController answers, and without the records.
		$overview = $this->statusService->overview($config);
		$status = StatusTranslation::status($overview, $this->l10n);
		// The getting-started checklist, which the page shows until the first successful run and
		// updates with every status that it reads (SyncController::status()).
		$setup = $this->gettingStarted->progress($config, $overview);
		$status['setup'] = $setup;
		$this->initialState->provideInitialState('status', $status);
		// The stored configuration, without the token, which the form compares with what it shows
		// to tell unsaved changes, as after a save or a disconnect.
		$this->initialState->provideInitialState('config', $config);
		// The webhook of the workflows of Paperless, without its secret.
		$webhook = $this->webhookService->state();
		$this->initialState->provideInitialState('webhook', $webhook);

		return new TemplateResponse(AppConstants::APP_ID, 'settings', [
			'config' => $config,
			'status' => $status,
			'setup' => $setup,
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.save'),
			'testUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.test'),
			// The setup of the account of the app in Paperless, with the token of a superuser.
			'accountUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.account'),
			'resetUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.reset'),
			'runUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.sync.run'),
			'statusUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.sync.status'),
			'previewUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.preview'),
			// The Files app, where the report opens the folder of a file for the target user.
			'filesUrl' => $this->urlGenerator->linkToRoute('files.view.index'),
			'webhookStateUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.webhook.state'),
			'webhook' => $webhook,
			'health' => $this->health(),
			'tagsUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.tags'),
			// The users and groups of Nextcloud, which the page offers for the target user and its
			// groups, from routes of the app: those of Nextcloud answer only its administrators.
			'usersUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.directory.users'),
			'groupsUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.directory.groups'),
		]);
	}

	/**
	 * What the setup checks of the app say under Administration → Overview, also on top of this
	 * page: their warnings and errors, that of the runs before that of the background jobs.
	 *
	 * @return list<array{severity: string, description: string}>
	 */
	private function health(): array {
		$results = [];
		foreach ([$this->syncHealth, $this->backgroundJobMode] as $check) {
			$result = $check->run();
			if (in_array($result->getSeverity(), [SetupResult::WARNING, SetupResult::ERROR], true)) {
				$results[] = ['severity' => $result->getSeverity(), 'description' => (string)$result->getDescription()];
			}
		}

		return $results;
	}

	public function getSection(): string {
		return AppConstants::APP_ID;
	}

	public function getPriority(): int {
		return 55;
	}

	/**
	 * None: this form is the only one of the section, so Administration privileges lists it under
	 * the name of the section alone.
	 */
	public function getName(): ?string {
		return null;
	}

	/**
	 * No key of the app configuration. What this names, a delegated group may write with the
	 * provisioning API of Nextcloud, past every check of ConfigService, such as a base folder with
	 * "..". The page saves through SettingsController, which checks every value, and needs none.
	 *
	 * @return array<string, list<string>>
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
