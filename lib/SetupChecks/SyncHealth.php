<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\SetupChecks;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Says under Administration → Overview and on the settings page whether the mirror works, from
 * the health that StatusService keeps: an error when the last run failed, a warning when it
 * completed with errors or when no run succeeded for longer than StatusService::staleAfter(), and
 * a note with the way to the settings while the app is installed but not set up.
 */
final class SyncHealth implements ISetupCheck {
	/** The most characters of the error that the result names. */
	private const ERROR_LIMIT = 200;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private StatusService $status,
		private ConfigService $configService,
		private IL10N $l10n,
		private IDateTimeFormatter $dateTimeFormatter,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Paperless Sync');
	}

	public function run(): SetupResult {
		$config = $this->configService->get();
		$health = $this->status->health($config);
		$failures = $this->status->failures();
		$error = mb_substr($failures['error'], 0, self::ERROR_LIMIT);
		$guide = AppConstants::guide($this->l10n->getLanguageCode(), 'being-notified');
		$lastSuccess = $this->status->get()['lastSuccess'];

		return match ($health) {
			'unconfigured' => SetupResult::info(
				$this->l10n->t('Paperless Sync is installed but not set up yet. Its settings lead through the first steps.'),
				$this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => AppConstants::APP_ID]),
			),
			'off' => SetupResult::success($this->l10n->t('Scheduled runs of Paperless Sync are off.')),
			'waiting' => SetupResult::info($this->l10n->t('Paperless Sync has not completed a run yet; the next background job starts one.')),
			'failed' => SetupResult::error(
				$this->l10n->n('The last run of Paperless Sync failed: %s', 'The last %n runs of Paperless Sync failed, the last one with: %s', $failures['runs'], [$error]),
				$guide,
			),
			'errors' => SetupResult::warning(
				$this->l10n->n('The last run of Paperless Sync completed with errors: %s', 'The last %n runs of Paperless Sync completed with errors, the last one with: %s', $failures['runs'], [$error]),
				$guide,
			),
			'stale' => SetupResult::warning(
				$this->l10n->t('The last successful run of Paperless Sync was %s. Check that the background jobs of Nextcloud run.', [$this->dateTimeFormatter->formatTimeSpan($lastSuccess)]),
				$guide,
			),
			'ok' => SetupResult::success($this->l10n->t('The last run of Paperless Sync succeeded %s.', [$this->dateTimeFormatter->formatTimeSpan($lastSuccess)])),
		};
	}
}
