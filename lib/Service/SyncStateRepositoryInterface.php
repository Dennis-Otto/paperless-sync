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

	/** @return array<string, int|string|null>|null */
	public function findImport(string $ownerUid, string $path): ?array;

	/** @return list<array<string, int|string|null>> */
	public function allImports(string $ownerUid): array;

	/** @param array<string, int|string|null> $values */
	public function saveImport(string $ownerUid, string $path, array $values): void;

	public function deleteImport(string $ownerUid, string $path): void;
}
