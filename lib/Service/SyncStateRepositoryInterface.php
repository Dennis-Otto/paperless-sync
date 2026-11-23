<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

interface SyncStateRepositoryInterface {
	/**
	 * The values of a new export that its first save leaves out. The checksum of the document that
	 * Paperless reported last and the revision that it belongs to stay empty until a run asks for
	 * them.
	 */
	public const EXPORT_DEFAULTS = [
		'path' => '',
		'fingerprint' => '',
		'source_revision' => '',
		'state' => 'active',
		'missing_runs' => 0,
		'trash_date' => null,
		'last_error' => null,
		'remote_checksum' => null,
		'remote_revision' => null,
	];

	/** @return array<string, int|string|null>|null */
	public function findExport(string $ownerUid, int $documentId): ?array;

	/** @return list<array<string, int|string|null>> */
	public function allExports(string $ownerUid): array;

	/**
	 * Saves the values of an export, a new one with the defaults for the others. A caller that knows
	 * whether the export exists says so in $exists, which saves the query for it.
	 *
	 * @param array<string, int|string|null> $values
	 */
	public function saveExport(string $ownerUid, int $documentId, array $values, ?bool $exists = null): void;

	public function deleteExport(string $ownerUid, int $documentId): void;

	/**
	 * The users of Nextcloud whose archives have copies of documents, or other states of exports,
	 * such as those of per-user ownership, in the order of their IDs.
	 *
	 * @return list<string>
	 */
	public function exportOwners(): array;

	/** @return array<string, int|string|null>|null */
	public function findImport(string $ownerUid, string $path): ?array;

	/** @return list<array<string, int|string|null>> */
	public function allImports(string $ownerUid): array;

	/** @param array<string, int|string|null> $values */
	public function saveImport(string $ownerUid, string $path, array $values): void;

	public function deleteImport(string $ownerUid, string $path): void;

	/** Moves the state of an upload to the new path of its file, at which no other state may be. */
	public function moveImport(string $ownerUid, string $from, string $to): void;

	/**
	 * Every file that someone sent to Paperless from Files, or a rule of Flow, and whose import has
	 * not ended yet, the oldest first: its user, the ID of the file, its path when it was sent, the
	 * folder below the sent folder that holds it, the names of the tags of the rule, separated by
	 * commas, and the ID of the rule, 0 for a file sent from Files, and the state of its upload as
	 * for a file of the inbox.
	 *
	 * @return list<array<string, int|string|null>>
	 */
	public function allSends(): array;

	/** @return array<string, int|string|null>|null */
	public function findSend(string $userId, int $fileId): ?array;

	/**
	 * Saves the values of a sent file, a new one waiting for its upload.
	 *
	 * @param array<string, int|string|null> $values
	 */
	public function saveSend(string $userId, int $fileId, array $values): void;

	public function deleteSend(string $userId, int $fileId): void;

	/**
	 * Every record of an upload of per-user ownership that has not ended, the oldest first: its
	 * user, its source, inbox or send, the path of its file, the SHA-256 and the MD5 of its content,
	 * the highest ID of a document with that content before its first upload, the ID of its task
	 * once Paperless names it, '' until then or after a lost answer, and the times of its last
	 * attempt and of its start, created_at, as UploadRecords explains.
	 *
	 * @return list<array<string, int|string|null>>
	 */
	public function allUploads(): array;

	/** @return array<string, int|string|null>|null the record of an upload of this content by this user */
	public function findUpload(string $userId, string $sha256): ?array;

	/**
	 * Saves the values of the record of an upload, a new one with the defaults for the others. The
	 * time of its start, created_at, is that of the first save: a later one keeps it.
	 *
	 * @param array<string, int|string|null> $values
	 */
	public function saveUpload(string $userId, string $sha256, array $values): void;

	public function deleteUpload(string $userId, string $sha256): void;

	/**
	 * Deletes every state of a user whom Nextcloud deleted: the exports of their archive, the
	 * imports of their inbox, the files that they sent from Files and the records of their
	 * uploads, so that a new account with the same ID starts without them. Returns how many it
	 * deleted.
	 */
	public function deleteOwner(string $userId): int;
}
