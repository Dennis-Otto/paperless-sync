<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * A document that Paperless found by the checksums of a file, and which of them found it:
 * Paperless-ngx keeps the SHA-256 of an original since 3.0 and only its MD5 before, so on 2.x
 * the MD5 alone finds a document.
 */
final class DocumentMatch {
	/**
	 * @param int $id the ID of the document
	 * @param bool $bySha256 whether the SHA-256 of the file found it, and not the MD5 alone
	 */
	public function __construct(
		public readonly int $id,
		public readonly bool $bySha256,
	) {
	}
}
