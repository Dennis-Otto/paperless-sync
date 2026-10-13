<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000100Date20260826000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class Version000100Date20260826000000Test extends TestCase {
	/** @var array<string, object> the tables the migration created, by name */
	private array $created = [];

	public function testCreatesTheTablesOfExportsAndImports(): void {
		$schema = $this->schema([]);

		$result = $this->migrate($schema);

		self::assertSame($schema, $result);
		self::assertSame(['paperless_sync_export', 'paperless_sync_import'], array_keys($this->created));

		$exports = $this->created['paperless_sync_export'];
		self::assertSame(
			['id', 'owner_uid', 'document_id', 'path', 'fingerprint', 'source_revision', 'state', 'missing_runs', 'last_seen', 'trash_date', 'last_error', 'created_at', 'updated_at'],
			array_keys($exports->columns),
		);
		self::assertSame(['id'], $exports->primaryKey);
		self::assertSame(['psync_export_owner_doc' => ['owner_uid', 'document_id']], $exports->uniqueIndexes);
		self::assertSame(['bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]], $exports->columns['id']);
		self::assertFalse($exports->columns['trash_date'][1]['notnull'], 'Documents outside the trash have no trash date.');

		$imports = $this->created['paperless_sync_import'];
		self::assertSame(
			['id', 'owner_uid', 'path_hash', 'path', 'etag', 'task_id', 'status', 'submitted_at', 'last_error', 'created_at', 'updated_at'],
			array_keys($imports->columns),
		);
		self::assertSame(['id'], $imports->primaryKey);
		self::assertSame(['psync_import_owner_path' => ['owner_uid', 'path_hash']], $imports->uniqueIndexes);
		self::assertSame(64, $imports->columns['path_hash'][1]['length'], 'A SHA-256 hash has 64 hexadecimal digits.');
	}

	public function testNamesFitTheLimitsOfEveryDatabaseOfNextcloud(): void {
		$this->migrate($this->schema([]));

		foreach ($this->created as $name => $table) {
			self::assertLessThanOrEqual(30, strlen('oc_' . $name), $name);
			foreach ([...array_keys($table->columns), ...array_keys($table->uniqueIndexes)] as $identifier) {
				self::assertLessThanOrEqual(30, strlen($identifier), $identifier);
			}
		}
	}

	public function testExistingTablesAreKept(): void {
		$schema = $this->schema(['paperless_sync_export', 'paperless_sync_import']);
		$schema->expects(self::never())->method('createTable');

		self::assertSame($schema, $this->migrate($schema));
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version000100Date20260826000000())->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	/**
	 * @param list<string> $existing
	 * @return ISchemaWrapper&MockObject
	 */
	private function schema(array $existing): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => in_array($name, $existing, true));
		$schema->method('createTable')->willReturnCallback(function (string $name): object {
			$this->created[$name] = new class() {
				/** @var array<string, array{string, array<string, mixed>}> */
				public array $columns = [];
				/** @var list<string> */
				public array $primaryKey = [];
				/** @var array<string, list<string>> */
				public array $uniqueIndexes = [];

				/** @param array<string, mixed> $options */
				public function addColumn(string $name, string $type, array $options = []): void {
					$this->columns[$name] = [$type, $options];
				}

				/** @param list<string> $columns */
				public function setPrimaryKey(array $columns): void {
					$this->primaryKey = $columns;
				}

				/** @param list<string> $columns */
				public function addUniqueIndex(array $columns, string $name): void {
					$this->uniqueIndexes[$name] = $columns;
				}
			};

			return $this->created[$name];
		});

		return $schema;
	}
}
