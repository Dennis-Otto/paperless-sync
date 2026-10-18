<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IDBConnection;

final class SyncStateRepository implements SyncStateRepositoryInterface {
	private const EXPORT_TABLE = 'paperless_sync_export';
	private const IMPORT_TABLE = 'paperless_sync_import';
	private const SEND_TABLE = 'paperless_sync_send';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IDBConnection $connection,
		private ITimeFactory $timeFactory,
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

	public function saveExport(string $ownerUid, int $documentId, array $values, ?bool $exists = null): void {
		$exists ??= $this->findExport($ownerUid, $documentId) !== null;
		$now = $this->timeFactory->getTime();
		$values = array_intersect_key($values, array_flip([
			'path', 'fingerprint', 'source_revision', 'state', 'missing_runs', 'last_seen', 'trash_date', 'last_error',
			'remote_checksum', 'remote_revision',
		]));
		$values['updated_at'] = $now;

		if (!$exists) {
			$values = array_merge(
				['owner_uid' => $ownerUid, 'document_id' => $documentId, 'last_seen' => $now, 'created_at' => $now],
				self::EXPORT_DEFAULTS,
				$values,
			);
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
		$now = $this->timeFactory->getTime();
		$values = array_intersect_key($values, array_flip([
			'etag', 'task_id', 'status', 'submitted_at', 'attempts', 'retry_at', 'last_error',
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
				'attempts' => 0,
				'retry_at' => 0,
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

	/** @psalm-suppress MixedReturnTypeCoercion */
	public function allSends(): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::SEND_TABLE)
			->orderBy('id');
		$result = $query->executeQuery();
		$rows = [];
		while (($row = $result->fetchAssociative()) !== false) {
			$rows[] = $this->normalizeRow($row);
		}
		$result->closeCursor();

		return $rows;
	}

	/** @psalm-suppress MixedReturnTypeCoercion */
	public function findSend(string $userId, int $fileId): ?array {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::SEND_TABLE)
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('file_id', $query->createNamedParameter($fileId)));
		$result = $query->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		return is_array($row) ? $this->normalizeRow($row) : null;
	}

	public function saveSend(string $userId, int $fileId, array $values): void {
		$existing = $this->findSend($userId, $fileId);
		$now = $this->timeFactory->getTime();
		$values = array_intersect_key($values, array_flip([
			'path', 'folder', 'tags', 'rule_id', 'task_id', 'status', 'submitted_at', 'attempts', 'retry_at', 'last_error',
		]));
		$values['updated_at'] = $now;
		if ($existing === null) {
			$row = array_merge([
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
				'created_at' => $now,
			], $values);
			try {
				$this->insert(self::SEND_TABLE, $row);
				return;
			} catch (Exception $exception) {
				// Another request, such as a second click at the same time, inserted the row since
				// it was looked up: it is there now, and takes the values.
				if ($exception->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $exception;
				}
			}
		}

		$this->update(self::SEND_TABLE, $values, ['user_id' => $userId, 'file_id' => $fileId]);
	}

	public function deleteSend(string $userId, int $fileId): void {
		$this->delete(self::SEND_TABLE, ['user_id' => $userId, 'file_id' => $fileId]);
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
		$integerColumns = ['id', 'document_id', 'file_id', 'missing_runs', 'last_seen', 'trash_date', 'submitted_at', 'attempts', 'retry_at', 'rule_id', 'created_at', 'updated_at'];
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
