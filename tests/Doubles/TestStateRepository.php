<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;

/**
 * The synchronization state of exports and imports in memory.
 */
final class TestStateRepository implements SyncStateRepositoryInterface {
	/** @var array<int, array<string, int|string|null>> */
	public array $exports = [];
	/** @var array<string, array<string, int|string|null>> */
	public array $imports = [];

	public function findExport(string $ownerUid, int $documentId): ?array {
		return $this->exports[$documentId] ?? null;
	}

	public function allExports(string $ownerUid): array {
		return array_values($this->exports);
	}

	public function saveExport(string $ownerUid, int $documentId, array $values): void {
		$this->exports[$documentId] = array_merge($this->exports[$documentId] ?? ['document_id' => $documentId], $values);
	}

	public function deleteExport(string $ownerUid, int $documentId): void {
		unset($this->exports[$documentId]);
	}

	public function findImport(string $ownerUid, string $path): ?array {
		return $this->imports[$path] ?? null;
	}

	public function allImports(string $ownerUid): array {
		return array_values($this->imports);
	}

	public function saveImport(string $ownerUid, string $path, array $values): void {
		$this->imports[$path] = array_merge($this->imports[$path] ?? ['path' => $path], $values);
	}

	public function deleteImport(string $ownerUid, string $path): void {
		unset($this->imports[$path]);
	}
}
