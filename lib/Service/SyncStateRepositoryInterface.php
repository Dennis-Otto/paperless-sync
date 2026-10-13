<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

interface SyncStateRepositoryInterface {
	/** @return array<string, int|string|null>|null */
	public function findExport(string $ownerUid, int $documentId): ?array;

	/** @return list<array<string, int|string|null>> */
	public function allExports(string $ownerUid): array;

	/** @param array<string, int|string|null> $values */
	public function saveExport(string $ownerUid, int $documentId, array $values): void;

	public function deleteExport(string $ownerUid, int $documentId): void;

	/** @return array<string, int|string|null>|null */
	public function findImport(string $ownerUid, string $path): ?array;

	/** @return list<array<string, int|string|null>> */
	public function allImports(string $ownerUid): array;

	/** @param array<string, int|string|null> $values */
	public function saveImport(string $ownerUid, string $path, array $values): void;

	public function deleteImport(string $ownerUid, string $path): void;
}
