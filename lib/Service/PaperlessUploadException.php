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
 * Paperless did not take a file of the inbox. A permanent refusal, such as an unsupported
 * file type, repeats as long as the file stays the same; any other failure may pass. A failure
 * that the app explains in its own words, such as a host name that Paperless refuses, keeps them,
 * so that the report shows them in the language of the reader.
 */
final class PaperlessUploadException extends RuntimeException implements TranslatableError {
	/** @param ?RunError $error what happened and what to do, in the words of the app, or null for the message as it came */
	public function __construct(
		string $message,
		public readonly bool $permanent,
		public readonly int $statusCode = 0,
		private readonly ?RunError $error = null,
	) {
		parent::__construct($message);
	}

	public function runError(): RunError {
		return $this->error ?? RunError::plain($this->getMessage());
	}
}
