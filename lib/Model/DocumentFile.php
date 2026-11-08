<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * The file that Paperless serves for a document, as its metadata names it. Since Paperless-ngx 3.0,
 * a document keeps versions of its file, and the metadata, the download and the checksums are those
 * of the newest version, while the fields of the document describe its first file.
 */
final class DocumentFile {
	/**
	 * @param string|null $originalFileName the name of the original file, as it reached Paperless
	 * @param string|null $archiveFileName the name of the archived version, a PDF, or null without one
	 * @param string|null $mimeType the type of the original file
	 * @param string|null $originalChecksum the checksum of the original, MD5 up to Paperless-ngx 2.x, SHA-256 since 3.0
	 * @param string|null $archiveChecksum the checksum of the archived version, or null without one
	 */
	public function __construct(
		public readonly ?string $originalFileName,
		public readonly ?string $archiveFileName,
		public readonly ?string $mimeType,
		public readonly ?string $originalChecksum,
		public readonly ?string $archiveChecksum,
	) {
	}

	/** The checksum of the original or of the archived version, or null when the metadata named none. */
	public function checksum(bool $original): ?string {
		return $original ? $this->originalChecksum : $this->archiveChecksum;
	}
}
