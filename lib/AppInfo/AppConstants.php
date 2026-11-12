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
	/**
	 * The German guide, which has an anchor of each part that the app links to under the same name
	 * as the English one, such as #being-notified.
	 */
	public const GERMAN_GUIDE_URL = 'https://dennis-otto.github.io/paperless-sync/de/guide.html';
	/** The troubleshooting page on the website, whose table of the messages the box of errors links to. */
	public const TROUBLESHOOTING_URL = 'https://dennis-otto.github.io/paperless-sync/troubleshooting.html';
	/** The German troubleshooting page, with the anchors of the English one, such as #messages-and-their-fixes. */
	public const GERMAN_TROUBLESHOOTING_URL = 'https://dennis-otto.github.io/paperless-sync/de/troubleshooting.html';
	/** The lock that a run holds, so that only one runs at a time, and that tells whether one is active. */
	public const LOCK_PATH = self::APP_ID . '::synchronization';

	/**
	 * The address of a part of the guide in the language of the reader, as Nextcloud names it, such
	 * as de, de_DE for the formal German or en: the German guide for German, the English one for
	 * every other language, which the website has no translation of.
	 */
	public static function guide(string $language, string $anchor): string {
		$guide = self::german($language) ? self::GERMAN_GUIDE_URL : self::GUIDE_URL;

		return "{$guide}#{$anchor}";
	}

	/** The address of a part of the troubleshooting page in the language of the reader, as guide() has it. */
	public static function troubleshooting(string $language, string $anchor): string {
		$page = self::german($language) ? self::GERMAN_TROUBLESHOOTING_URL : self::TROUBLESHOOTING_URL;

		return "{$page}#{$anchor}";
	}

	/** Whether the website has the page in the language of the reader: German, also the formal de_DE. */
	private static function german(string $language): bool {
		return preg_match('/^de(?:_|$)/', $language) === 1;
	}
}
