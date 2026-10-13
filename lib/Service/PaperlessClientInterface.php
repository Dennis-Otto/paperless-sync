<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\PaperlessScan;

interface PaperlessClientInterface {
	public function testConnection(string $url, string $token): void;

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

	/** @param resource $sink */
	public function downloadDocument(int $documentId, bool $original, $sink): void;

	public function documentChecksum(int $documentId, bool $original): string;

	/**
	 * @param resource $source
	 * @throws PaperlessUploadException when Paperless doesn't take the file, or can't be reached
	 */
	public function uploadDocument($source, string $filename): string;

	/** @return array{status: string, message: string} */
	public function taskStatus(string $taskId): array;
}
