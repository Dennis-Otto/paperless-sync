<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\AppInfo;

final class AppConstants {
	public const APP_ID = 'paperless_sync';
	public const APP_NAME = 'Paperless Sync';
	public const USER_AGENT = 'Nextcloud-Paperless-Sync/0.1';
	/** The guide on the website, whose parts the sections of the settings link to. */
	public const GUIDE_URL = 'https://dennis-otto.github.io/paperless-sync/guide.html';
	/** The lock that a run holds, so that only one runs at a time, and that tells whether one is active. */
	public const LOCK_PATH = self::APP_ID . '::synchronization';
}
