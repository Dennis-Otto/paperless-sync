<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use InvalidArgumentException;
use OCP\IL10N;

/**
 * A dry-run of unsaved settings that change the connection to Paperless or the target user,
 * which only a save may change, with the setting to save first.
 */
final class UnsavedSettingException extends InvalidArgumentException {
	/** The advice; translated() looks it up in the translations of the app. */
	private const ADVICE = 'A dry-run of unsaved settings uses the saved connection to Paperless and the saved target user. Save the settings to try another one.';

	/** @param string $setting the setting that differs from the saved one, which the settings page moves the focus to */
	public function __construct(
		public readonly string $setting,
	) {
		parent::__construct(self::ADVICE);
	}

	public function translated(IL10N $l10n): string {
		return $l10n->t(self::ADVICE);
	}
}
