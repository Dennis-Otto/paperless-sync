<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessScan;

interface PaperlessClientInterface {
	/**
	 * A request with $token to $url; #[\SensitiveParameter] keeps the token out of the trace of its
	 * failure. $localNetwork allows an address of the local network for it.
	 *
	 * @throws PaperlessLocalNetworkException when Nextcloud refuses the address as a local one
	 */
	public function testConnection(string $url, #[\SensitiveParameter] string $token, bool $localNetwork): void;

	/** The active documents, and whether the run received all of them. */
	public function documents(): PaperlessScan;

	/** The documents in the trash of Paperless, and whether the run received all of them. */
	public function trash(): PaperlessScan;

	/** @return array<string, string> */
	public function correspondents(): array;

	/** @return array<string, string> */
	public function documentTypes(): array;

	/** @return array<string, string> */
	public function storagePaths(): array;

	/** @return array{names: array<string, string>, inbox: list<string>} */
	public function tags(): array;

	/**
	 * Downloads the original or the archived version of a document to a temporary file, computes its
	 * checksums in one pass and hands the file to $consume as a readable stream at its start, which
	 * $consume may close; the temporary file is gone afterwards, also when $consume fails.
	 *
	 * @param callable(resource): void $consume
	 */
	public function downloadDocument(int $documentId, bool $original, callable $consume): DocumentFingerprint;

	/** The checksum that Paperless reports for a version of a document: MD5 up to Paperless-ngx 2.x, SHA-256 since 3.0. */
	public function documentChecksum(int $documentId, bool $original): string;

	/**
	 * @param resource $source
	 * @throws PaperlessUploadException when Paperless doesn't take the file, or can't be reached
	 */
	public function uploadDocument($source, string $filename): string;

	/**
	 * The state of the task of an upload, its message, and the ID of the document that it created,
	 * when Paperless names it.
	 *
	 * @return array{status: string, message: string, document?: int}
	 */
	public function taskStatus(string $taskId): array;
}
