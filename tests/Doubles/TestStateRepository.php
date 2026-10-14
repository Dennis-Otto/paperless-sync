<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;

/**
 * The synchronization state of exports and imports in memory, each owner's apart, as the tables
 * of the database keep it: a run that names another owner than the target user sees and changes
 * only that owner's state.
 */
final class TestStateRepository implements SyncStateRepositoryInterface {
	/** The target user of the tests, whose state $exports and $imports hold. */
	public const OWNER = TestStorage::USER;

	/** @var array<int, array<string, int|string|null>> the exports of the target user, by document ID */
	public array $exports = [];
	/** @var array<string, array<string, int|string|null>> the imports of the target user, by path */
	public array $imports = [];
	/** @var array<string, array<int, array<string, int|string|null>>> the exports of every other owner */
	public array $exportsOfOthers = [];
	/** @var array<string, array<string, array<string, int|string|null>>> the imports of every other owner */
	public array $importsOfOthers = [];
	/** @var list<string> the owner of every call, in its order */
	public array $owners = [];

	public function findExport(string $ownerUid, int $documentId): ?array {
		return $this->exportsOf($ownerUid)[$documentId] ?? null;
	}

	public function allExports(string $ownerUid): array {
		return array_values($this->exportsOf($ownerUid));
	}

	public function saveExport(string $ownerUid, int $documentId, array $values): void {
		$exports = &$this->exportsOf($ownerUid);
		$exports[$documentId] = array_merge($exports[$documentId] ?? ['document_id' => $documentId], $values);
	}

	public function deleteExport(string $ownerUid, int $documentId): void {
		$exports = &$this->exportsOf($ownerUid);
		unset($exports[$documentId]);
	}

	public function findImport(string $ownerUid, string $path): ?array {
		return $this->importsOf($ownerUid)[$path] ?? null;
	}

	public function allImports(string $ownerUid): array {
		return array_values($this->importsOf($ownerUid));
	}

	public function saveImport(string $ownerUid, string $path, array $values): void {
		$imports = &$this->importsOf($ownerUid);
		$imports[$path] = array_merge($imports[$path] ?? ['path' => $path], $values);
	}

	public function deleteImport(string $ownerUid, string $path): void {
		$imports = &$this->importsOf($ownerUid);
		unset($imports[$path]);
	}

	/** @return array<int, array<string, int|string|null>> */
	private function &exportsOf(string $ownerUid): array {
		$this->owners[] = $ownerUid;
		if ($ownerUid === self::OWNER) {
			return $this->exports;
		}
		$this->exportsOfOthers[$ownerUid] ??= [];

		return $this->exportsOfOthers[$ownerUid];
	}

	/** @return array<string, array<string, int|string|null>> */
	private function &importsOf(string $ownerUid): array {
		$this->owners[] = $ownerUid;
		if ($ownerUid === self::OWNER) {
			return $this->imports;
		}
		$this->importsOfOthers[$ownerUid] ??= [];

		return $this->importsOfOthers[$ownerUid];
	}
}
