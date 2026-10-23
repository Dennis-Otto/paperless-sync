<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\TranslatableError;
use OCP\Files\NotEnoughSpaceException;

/**
 * Nextcloud has too little free space for a file that the app writes, as the app checks it
 * itself: a NotEnoughSpaceException of Nextcloud, which stops the writes of the run, whose
 * message the status keeps with its text and parameters, for the settings page to translate.
 */
final class FullStorageException extends NotEnoughSpaceException implements TranslatableError {
	private RunError $error;

	/**
	 * @param string $text the text in English, with %s or %1$s for each parameter
	 * @param list<string> $parameters the values of its placeholders
	 */
	public function __construct(string $text, array $parameters = []) {
		$this->error = new RunError($text, $parameters);
		parent::__construct($this->error->message());
	}

	public function runError(): RunError {
		return $this->error;
	}
}
