<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\SyncConfig;

/**
 * A client of one Paperless-ngx, which PaperlessClientFactoryInterface::connect() makes for a
 * connection: every request goes to the URL of that connection, with its token, and to an
 * address of the local network only when the connection allows it.
 */
interface PaperlessClientInterface {
	/**
	 * The first request of a run, which tests the connection.
	 *
	 * The answer names the version of Paperless-ngx as Paperless names itself, '' when it doesn't,
	 * and the version of its API that the app uses.
	 *
	 * @return array{version: string, apiVersion: string}
	 * @throws PaperlessLocalNetworkException when Nextcloud refuses the address as a local one
	 * @throws PaperlessVersionException when Paperless doesn't serve the version of its API that the app uses
	 */
	public function testConnection(): array;

	/**
	 * The checks of Paperless for the connection checklist: that Paperless answers at the URL of
	 * the connection and takes its token, and that its account may read every list that the
	 * modules of $config need, owns the documents that it sees, so that the trash shows them, and
	 * may add documents and view their tasks for the inbox. A check that fails is a line of the
	 * list, not an exception; after a failure of the connection itself, the list ends there.
	 *
	 * @return list<ConnectionCheck>
	 */
	public function preflight(SyncConfig $config): array;

	/**
	 * The active documents, and whether the run received all of them; with $notes, the notes of
	 * each, which only the metadata next to the archive holds.
	 */
	public function documents(bool $notes = false): PaperlessScan;

	/** The documents in the trash of Paperless, and whether the run received all of them. */
	public function trash(): PaperlessScan;

	/**
	 * One active document, with the fields that documents() lists of each, for the run of one
	 * document that the webhook of a Paperless workflow asks for; null when Paperless doesn't show
	 * it to the account: in the trash, deleted, or of another owner who doesn't share it. With
	 * $notes, its notes as well, as documents() lists them.
	 *
	 * @return array<string, mixed>|null
	 */
	public function document(int $documentId, bool $notes = false): ?array;

	/** @return array<string, string> */
	public function correspondents(): array;

	/** @return array<string, string> */
	public function documentTypes(): array;

	/** @return array<string, string> */
	public function storagePaths(): array;

	/** @return array{names: array<string, string>, inbox: list<string>} */
	public function tags(): array;

	/**
	 * The names of the users of Paperless by their IDs, for {{ owner_username }}; Paperless lists
	 * them only for a token whose user may view users.
	 *
	 * @return array<string, string>
	 */
	public function users(): array;

	/**
	 * The number of documents that the account sees and that the user of Paperless with this ID
	 * owns, or that have no owner, for null: the preview of per-user ownership in the connection
	 * checklist.
	 *
	 * @throws PaperlessRequestException when Paperless can't be reached
	 * @throws \RuntimeException when Paperless doesn't list the documents
	 */
	public function ownedDocuments(?int $owner): int;

	/**
	 * The ID of the user of Paperless of the account of the token, which Paperless makes the owner
	 * of every document that the app uploads, or null when Paperless doesn't say, as for an
	 * account without the permission View for UI Settings.
	 */
	public function accountId(): ?int;

	/**
	 * Makes the user of Paperless with the ID $owner the owner of a document, as per-user ownership
	 * does with the document of a file that a user of Nextcloud sent: Paperless makes the account
	 * that uploads a file the owner of its document. Only the owner changes, not the permissions.
	 * A document that Paperless doesn't show the account after the change, such as one that it gave
	 * away without keeping the permission View, counts as changed.
	 *
	 * @throws PaperlessRequestException when Paperless can't be reached
	 * @throws \RuntimeException when Paperless doesn't change the owner, such as for an account that may not change the document
	 */
	public function setOwner(int $documentId, int $owner): void;

	/**
	 * The custom fields of Paperless by their IDs, for {{ custom_fields.<name> }}: the name, the
	 * data type, and the labels of the choices of a select field by their IDs.
	 *
	 * @return array<string, array{name: string, type: string, options: array<string, string>}>
	 */
	public function customFields(): array;

	/**
	 * The documents that were added to Paperless last, the newest first, for the preview of the
	 * path template.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function newestDocuments(int $count): array;

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
	 * The ID of a document whose original has the content of these checksums, or null when
	 * Paperless has none: Paperless-ngx keeps the SHA-256 of an original since 3.0 and its MD5
	 * before, so both are asked for.
	 */
	public function findDocument(DocumentFingerprint $content): ?int;

	/**
	 * Creates a tag in Paperless that it assigns to no document on its own, and returns its ID.
	 *
	 * @throws PaperlessRequestException when Paperless can't be reached
	 * @throws \RuntimeException when Paperless doesn't create the tag, such as for an account without the permission Add for Tags
	 */
	public function createTag(string $name): int;

	/**
	 * Uploads a file as a new document, with the tags of $tags besides the inbox tags of Paperless,
	 * and returns the ID of the task of Paperless that imports it.
	 *
	 * @param resource $source
	 * @param list<int> $tags the IDs of tags of Paperless
	 * @throws PaperlessUploadException when Paperless doesn't take the file, or can't be reached
	 */
	public function uploadDocument($source, string $filename, array $tags = []): string;

	/**
	 * The state of the task of an upload: its status in upper case, as Paperless names it, such as
	 * PENDING, STARTED, SUCCESS, FAILURE or REVOKED, PENDING also while Paperless doesn't list the
	 * task or has marked it as failed without writing the reason yet; what Paperless says about its
	 * result, such as the reason of a failure; the ID of the document that it created, if it names
	 * one; and whether Paperless lists the task at all.
	 *
	 * @return array{status: string, message: string, documentId: int|null, listed: bool}
	 */
	public function taskStatus(string $taskId): array;
}
