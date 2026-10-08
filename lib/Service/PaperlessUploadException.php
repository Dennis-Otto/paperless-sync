<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use RuntimeException;

/**
 * Paperless did not take a file of the inbox. A permanent refusal, such as an unsupported
 * file type, repeats as long as the file stays the same; any other failure may pass.
 */
final class PaperlessUploadException extends RuntimeException {
	public function __construct(
		string $message,
		public readonly bool $permanent,
		public readonly int $statusCode = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
