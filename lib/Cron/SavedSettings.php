<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Cron;

use OCP\Config\IUserConfig;
use OCP\IAppConfig;

/**
 * The settings as they are saved when a background job of the app starts.
 *
 * Nextcloud keeps the configuration of the apps, and that of the users, such as their time zones,
 * in the memory of the process that loaded it. A worker that keeps running, as
 * `occ background-job:worker` does, runs job after job in one process and doesn't clear it between
 * them, so each job would run with the settings of the worker's start: a token, a URL or a target
 * user saved since then reached no job until the worker restarted. A job of cron.php, or of the
 * worker with `--once`, starts in a process of its own, which loads the settings anew anyway.
 */
final class SavedSettings {
	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the class through its container. */
	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * Forgets the settings that this process loaded, so that the job reads them from the database.
	 * It reads nothing itself: the first value that is asked for after it loads them again.
	 */
	public function reload(): void {
		$this->appConfig->clearCache();
		$this->userConfig->clearCacheAll();
	}
}
