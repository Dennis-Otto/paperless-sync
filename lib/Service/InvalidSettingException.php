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
 * A setting that the app can't use, with what to do about it: in English as its message, for the
 * log, and in the language of the administrator with translated(), for the settings page. The
 * text is the key of its translation in l10n/, so it is a literal at the place that throws it,
 * where scripts/check-l10n.mjs finds it, never a variable.
 */
final class InvalidSettingException extends InvalidArgumentException {
	/**
	 * @param string $text the text in English, with %s or %1$s for each parameter
	 * @param list<string> $parameters the values of its placeholders, which no translation changes
	 * @param string $setting the setting that the settings page moves the focus to, or '' for none
	 */
	public function __construct(
		private string $text,
		private array $parameters = [],
		public readonly string $setting = '',
	) {
		parent::__construct(vsprintf($text, $parameters));
	}

	public function translated(IL10N $l10n): string {
		return $l10n->t($this->text, $this->parameters);
	}
}
