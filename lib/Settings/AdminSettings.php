<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Settings;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\StatusTranslation;
use OCA\PaperlessSync\Service\WebhookService;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\SetupCheck\SetupResult;

final class AdminSettings implements ISettings {
	/** The users of the provisioning API of Nextcloud, at version 2 of its OCS API. */
	private const USERS_PATH = '/ocs/v2.php/cloud/users';
	/** The groups of the provisioning API, which the setting of the group that may send files picks from. */
	private const GROUPS_PATH = '/ocs/v2.php/cloud/groups';

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
	) {
	}

	public function getForm(): TemplateResponse {
		$config = $this->configService->get();
		// The page shows the status, its error and its report from the start, as after a refresh: in
		// the language of the administrator, as SyncController answers, and without the records.
		$status = StatusTranslation::status($this->statusService->overview($config), $this->l10n);
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
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.save'),
			'testUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.test'),
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
			// The users of Nextcloud, which the page offers for the target user, from the provisioning
			// API of Nextcloud, which is always on and answers administrators. A path from the root of
			// the server, as the page has it, also behind a proxy that changes the scheme or the host.
			'usersUrl' => $this->urlGenerator->getWebroot() . self::USERS_PATH,
			'groupsUrl' => $this->urlGenerator->getWebroot() . self::GROUPS_PATH,
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
}
