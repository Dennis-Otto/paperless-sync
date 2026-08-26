<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCP\IDBConnection;

final class SyncStateRepository implements SyncStateRepositoryInterface {
	private const EXPORT_TABLE = 'paperless_sync_export';
	private const IMPORT_TABLE = 'paperless_sync_import';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/** @psalm-suppress MixedReturnTypeCoercion */
	public function findExport(string $ownerUid, int $documentId): ?array {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::EXPORT_TABLE)
			->where($query->expr()->eq('owner_uid', $query->createNamedParameter($ownerUid)))
			->andWhere($query->expr()->eq('document_id', $query->createNamedParameter($documentId)));
		$result = $query->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		return is_array($row) ? $this->normalizeRow($row) : null;
	}

	public function allExports(string $ownerUid): array {
		return $this->allByOwner(self::EXPORT_TABLE, $ownerUid);
	}

	public function saveExport(string $ownerUid, int $documentId, array $values): void {
		$existing = $this->findExport($ownerUid, $documentId);
		$now = time();
		$values = array_intersect_key($values, array_flip([
			'path', 'fingerprint', 'source_revision', 'state', 'missing_runs', 'last_seen', 'trash_date', 'last_error',
		]));
		$values['updated_at'] = $now;

		if ($existing === null) {
			$values = array_merge([
				'owner_uid' => $ownerUid,
				'document_id' => $documentId,
				'path' => '',
				'fingerprint' => '',
				'source_revision' => '',
				'state' => 'active',
				'missing_runs' => 0,
				'last_seen' => $now,
				'trash_date' => null,
				'last_error' => null,
				'created_at' => $now,
			], $values);
			$this->insert(self::EXPORT_TABLE, $values);
			return;
		}

		$this->update(
			self::EXPORT_TABLE,
			$values,
			['owner_uid' => $ownerUid, 'document_id' => $documentId],
		);
	}

	public function deleteExport(string $ownerUid, int $documentId): void {
		$this->delete(self::EXPORT_TABLE, ['owner_uid' => $ownerUid, 'document_id' => $documentId]);
	}

	/** @psalm-suppress MixedReturnTypeCoercion */
	public function findImport(string $ownerUid, string $path): ?array {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::IMPORT_TABLE)
			->where($query->expr()->eq('owner_uid', $query->createNamedParameter($ownerUid)))
			->andWhere($query->expr()->eq('path_hash', $query->createNamedParameter(hash('sha256', $path))));
		$result = $query->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();
		if (!is_array($row) || ($row['path'] ?? null) !== $path) {
			return null;
		}

		return $this->normalizeRow($row);
	}

	public function allImports(string $ownerUid): array {
		return $this->allByOwner(self::IMPORT_TABLE, $ownerUid);
	}

	public function saveImport(string $ownerUid, string $path, array $values): void {
		$existing = $this->findImport($ownerUid, $path);
		$now = time();
		$values = array_intersect_key($values, array_flip([
			'etag', 'task_id', 'status', 'submitted_at', 'last_error',
		]));
		$values['updated_at'] = $now;
		if ($existing === null) {
			$values = array_merge([
				'owner_uid' => $ownerUid,
				'path_hash' => hash('sha256', $path),
				'path' => $path,
				'etag' => '',
				'task_id' => '',
				'status' => 'pending',
				'submitted_at' => $now,
				'last_error' => null,
				'created_at' => $now,
			], $values);
			$this->insert(self::IMPORT_TABLE, $values);
			return;
		}

		$this->update(
			self::IMPORT_TABLE,
			$values,
			['owner_uid' => $ownerUid, 'path_hash' => hash('sha256', $path)],
		);
	}

	public function deleteImport(string $ownerUid, string $path): void {
		$this->delete(self::IMPORT_TABLE, ['owner_uid' => $ownerUid, 'path_hash' => hash('sha256', $path)]);
	}

	/**
	 * @return list<array<string, int|string|null>>
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	private function allByOwner(string $table, string $ownerUid): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from($table)
			->where($query->expr()->eq('owner_uid', $query->createNamedParameter($ownerUid)));
		$result = $query->executeQuery();
		$rows = [];
		while (($row = $result->fetchAssociative()) !== false) {
			$rows[] = $this->normalizeRow($row);
		}
		$result->closeCursor();

		return $rows;
	}

	/** @param array<string, int|string|null> $values */
	private function insert(string $table, array $values): void {
		$query = $this->connection->getQueryBuilder();
		$query->insert($table);
		foreach ($values as $column => $value) {
			$query->setValue($column, $query->createNamedParameter($value));
		}
		$query->executeStatement();
	}

	/**
	 * @param array<string, int|string|null> $values
	 * @param array<string, int|string> $where
	 */
	private function update(string $table, array $values, array $where): void {
		$query = $this->connection->getQueryBuilder();
		$query->update($table);
		foreach ($values as $column => $value) {
			$query->set($column, $query->createNamedParameter($value));
		}
		foreach ($where as $column => $value) {
			$query->andWhere($query->expr()->eq($column, $query->createNamedParameter($value)));
		}
		$query->executeStatement();
	}

	/** @param array<string, int|string> $where */
	private function delete(string $table, array $where): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete($table);
		foreach ($where as $column => $value) {
			$query->andWhere($query->expr()->eq($column, $query->createNamedParameter($value)));
		}
		$query->executeStatement();
	}

	/** @param array<string, mixed> $row @return array<string, int|string|null> */
	private function normalizeRow(array $row): array {
		/** @var array<string, int|string|null> $result */
		$result = [];
		$integerColumns = ['id', 'document_id', 'missing_runs', 'last_seen', 'trash_date', 'submitted_at', 'created_at', 'updated_at'];
		/** @psalm-suppress MixedAssignment */
		foreach ($row as $key => $value) {
			if ($value === null) {
				$result[$key] = null;
			} elseif (in_array($key, $integerColumns, true)) {
				$result[$key] = (int)$value;
			} elseif (is_scalar($value)) {
				$result[$key] = (string)$value;
			}
		}

		return $result;
	}
}
