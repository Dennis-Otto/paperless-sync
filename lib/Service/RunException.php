<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\TranslatableError;
use RuntimeException;

/**
 * A failure of a run that the app explains, such as a list of Paperless that the token may not
 * read: its message is the English text, for the log, and the status keeps the text with its
 * parameters, which the settings page translates. The text is a literal at the place that throws
 * it, where scripts/check-l10n.mjs finds it.
 */
final class RunException extends RuntimeException implements TranslatableError {
	private RunError $error;

	/**
	 * @param string $text the text in English, with %s or %1$s for each parameter
	 * @param list<string|RunError> $parameters the values of its placeholders
	 */
	public function __construct(string $text, array $parameters = [], ?\Throwable $previous = null) {
		$this->error = new RunError($text, $parameters);
		parent::__construct($this->error->message(), 0, $previous);
	}

	public function runError(): RunError {
		return $this->error;
	}
}
