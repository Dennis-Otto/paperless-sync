<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFile;

/**
 * The newest version of each document, whose file Paperless serves. Since Paperless-ngx 3.0, a
 * document keeps versions of its file: a new file added to it, such as a born-digital PDF for a
 * scan, or, since 3.1, another document merged into it. The fields of the document still describe
 * its first file, its original name, its type and whether it has an archived version, while its
 * download, its metadata and its checksums are those of the newest version. The copy holds the
 * newest version, so its extension and the choice between the archived version and the original
 * follow that version, and a version without an archived version falls back to its original.
 *
 * A document lists its versions, the newest first. One whose newest version is a file of its own
 * costs one request for its metadata in a run, also when several archives take it, and that answer
 * names its checksums as well. Paperless-ngx 2.x lists no versions, and its documents cost nothing.
 */
final class FileVersions {
	/** @var array<string, DocumentFile> the files of the newest versions that the run read, by "<document>:<version>" */
	private array $files = [];

	public function __construct(
		private PaperlessClientInterface $paperless,
	) {
	}

	/**
	 * The ID of the newest version of a document, when that is a file of its own and not the first
	 * file of the document; null without versions, as on Paperless-ngx 2.x.
	 *
	 * @param array<string, mixed> $document
	 */
	public static function newest(array $document): ?int {
		/** @psalm-suppress MixedAssignment */
		$versions = $document['versions'] ?? null;
		/** @psalm-suppress MixedAssignment */
		$newest = is_array($versions) && array_is_list($versions) && $versions !== [] ? $versions[0] : null;
		if (!is_array($newest) || ($newest['is_root'] ?? null) !== false) {
			return null;
		}
		/** @psalm-suppress MixedAssignment */
		$id = $newest['id'] ?? null;

		return is_int($id) && $id > 0 ? $id : null;
	}

	/**
	 * The document with the file of its newest version: its original name, its archived version,
	 * if it has one, and its type. A document whose newest file is its first stays as it is.
	 *
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	public function document(int $documentId, array $document): array {
		$file = $this->file($documentId, $document);
		if ($file === null) {
			return $document;
		}
		$document['original_file_name'] = $file->originalFileName;
		$document['archived_file_name'] = $file->archiveFileName;
		$document['mime_type'] = $file->mimeType;

		return $document;
	}

	/**
	 * The checksum that Paperless reports for the original or the archived version of a document,
	 * from the metadata that the run read for its newest version, or else from Paperless.
	 *
	 * @param array<string, mixed> $document
	 */
	public function checksum(int $documentId, array $document, bool $original): string {
		return $this->file($documentId, $document)?->checksum($original) ?? $this->paperless->documentChecksum($documentId, $original);
	}

	/** @param array<string, mixed> $document */
	private function file(int $documentId, array $document): ?DocumentFile {
		$version = self::newest($document);
		if ($version === null) {
			return null;
		}

		return $this->files[$documentId . ':' . $version] ??= $this->paperless->documentFile($documentId);
	}
}
