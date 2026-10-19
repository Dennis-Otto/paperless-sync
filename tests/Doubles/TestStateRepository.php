<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use LogicException;
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
	/** @var list<string> the method of every call of the exports, in its order, for the count of reads and writes */
	public array $exportCalls = [];
	/**
	 * The files sent from Files, by "user/file ID", in the order of their first save, as the
	 * database keeps them, with the defaults of a new one as SyncStateRepository writes them.
	 *
	 * @var array<string, array<string, int|string|null>>
	 */
	public array $sends = [];

	public function findExport(string $ownerUid, int $documentId): ?array {
		$this->exportCalls[] = 'findExport';

		return $this->exportsOf($ownerUid)[$documentId] ?? null;
	}

	public function allExports(string $ownerUid): array {
		$this->exportCalls[] = 'allExports';

		return array_values($this->exportsOf($ownerUid));
	}

	public function saveExport(string $ownerUid, int $documentId, array $values, ?bool $exists = null): void {
		$this->exportCalls[] = 'saveExport';
		$exports = &$this->exportsOf($ownerUid);
		// The database would insert a second row, or update none, after a wrong hint.
		if ($exists !== null && $exists !== isset($exports[$documentId])) {
			throw new LogicException("The export of document {$documentId} " . ($exists ? 'does not exist.' : 'exists already.'));
		}
		$exports[$documentId] = array_merge($exports[$documentId] ?? ['document_id' => $documentId], $values);
	}

	public function deleteExport(string $ownerUid, int $documentId): void {
		$this->exportCalls[] = 'deleteExport';
		$exports = &$this->exportsOf($ownerUid);
		unset($exports[$documentId]);
	}

	public function exportOwners(): array {
		$owners = array_map('strval', array_keys(array_filter($this->exportsOfOthers)));
		if ($this->exports !== []) {
			$owners[] = self::OWNER;
		}
		sort($owners, SORT_STRING);

		return $owners;
	}

	/** The writes of the exports since the calls were last cleared. */
	public function exportWrites(): int {
		return count(array_filter($this->exportCalls, static fn (string $call): bool => in_array($call, ['saveExport', 'deleteExport'], true)));
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

	public function allSends(): array {
		return array_values($this->sends);
	}

	public function findSend(string $userId, int $fileId): ?array {
		return $this->sends[$userId . '/' . $fileId] ?? null;
	}

	public function saveSend(string $userId, int $fileId, array $values): void {
		$this->sends[$userId . '/' . $fileId] = array_merge($this->sends[$userId . '/' . $fileId] ?? [
			'user_id' => $userId,
			'file_id' => $fileId,
			'path' => '',
			'folder' => '',
			'tags' => null,
			'rule_id' => 0,
			'task_id' => '',
			'status' => 'queued',
			'submitted_at' => 0,
			'attempts' => 0,
			'retry_at' => 0,
			'last_error' => null,
		], $values);
	}

	public function deleteSend(string $userId, int $fileId): void {
		unset($this->sends[$userId . '/' . $fileId]);
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
