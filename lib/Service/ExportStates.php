<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

/**
 * The export states of the target user during one run. The run reads them from the database
 * once, at its start, instead of once for every document, and the states here follow every
 * change that the run saves. A save writes only the values that differ from the state, and
 * nothing when none does, so a run over an archive without changes writes nothing.
 */
final class ExportStates {
	/** @var array<int, array<string, int|string|null>> the states by document ID */
	private array $entries = [];

	public function __construct(
		private SyncStateRepositoryInterface $repository,
		private string $ownerUid,
	) {
		foreach ($repository->allExports($ownerUid) as $entry) {
			$this->entries[(int)($entry['document_id'] ?? 0)] = $entry;
		}
	}

	/** @return array<string, int|string|null>|null */
	public function find(int $documentId): ?array {
		return $this->entries[$documentId] ?? null;
	}

	/** @return array<int, array<string, int|string|null>> the states by document ID */
	public function all(): array {
		return $this->entries;
	}

	/** @param array<string, int|string|null> $values */
	public function save(int $documentId, array $values): void {
		$entry = $this->entries[$documentId] ?? null;
		if ($entry !== null) {
			$values = array_filter(
				$values,
				static fn (int|string|null $value, string $column): bool => !array_key_exists($column, $entry) || $entry[$column] !== $value,
				ARRAY_FILTER_USE_BOTH,
			);
			if ($values === []) {
				return;
			}
		}
		$this->repository->saveExport($this->ownerUid, $documentId, $values, $entry !== null);
		$this->entries[$documentId] = array_merge($entry ?? ['document_id' => $documentId] + SyncStateRepositoryInterface::EXPORT_DEFAULTS, $values);
	}

	public function delete(int $documentId): void {
		$this->repository->deleteExport($this->ownerUid, $documentId);
		unset($this->entries[$documentId]);
	}
}
