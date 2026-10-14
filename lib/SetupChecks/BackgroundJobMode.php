<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\SetupChecks;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Warns, under Administration → Overview and on the settings page, when scheduled runs are on but
 * Nextcloud runs its background jobs with AJAX or Webcron: these start a job only while someone
 * uses Nextcloud, so the mirror falls behind, and a long run may stop halfway.
 */
final class BackgroundJobMode implements ISetupCheck {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $appConfig,
		private ConfigService $configService,
		private IL10N $l10n,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Paperless Sync background job');
	}

	public function run(): SetupResult {
		if (!$this->configService->get()->enabled) {
			return SetupResult::success($this->l10n->t('Scheduled runs of Paperless Sync are off.'));
		}
		// Nextcloud keeps the mode as the background jobs settings and occ background:cron write it.
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		if ($mode === 'cron') {
			return SetupResult::success($this->l10n->t('Nextcloud runs its background jobs with cron, as the scheduled runs of Paperless Sync need.'));
		}

		return SetupResult::warning(
			$this->l10n->t('Nextcloud runs its background jobs with %s, which starts them only while someone uses Nextcloud, so the scheduled runs of Paperless Sync fall behind or stop halfway. Set the background jobs to cron in the basic settings and set up the cron job of the system.', [$mode === 'webcron' ? 'Webcron' : 'AJAX']),
			AppConstants::GUIDE_URL . '#being-notified',
		);
	}
}
