<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000140Date20261018000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class Version000140Date20261018000000Test extends TestCase {
	/** @var array<string, object> the tables the migration created, by name */
	private array $created = [];

	/**
	 * Nextcloud runs a migration once, so an installed database keeps the columns of this one, and
	 * a change here would reach new installations only. Every option of every column is pinned.
	 */
	public function testCreatesTheTableOfTheSentFiles(): void {
		$schema = $this->schema(false);

		self::assertSame($schema, $this->migrate($schema));

		self::assertSame(['paperless_sync_send'], array_keys($this->created));
		$sends = $this->created['paperless_sync_send'];
		self::assertSame([
			'id' => ['bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]],
			'user_id' => ['string', ['notnull' => true, 'length' => 64]],
			'file_id' => ['bigint', ['notnull' => true, 'unsigned' => true]],
			'path' => ['string', ['notnull' => true, 'length' => 1024, 'default' => '']],
			'folder' => ['string', ['notnull' => true, 'length' => 1024, 'default' => '']],
			'task_id' => ['string', ['notnull' => true, 'length' => 255, 'default' => '']],
			'status' => ['string', ['notnull' => true, 'length' => 16, 'default' => 'queued']],
			'submitted_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'attempts' => ['integer', ['notnull' => true, 'default' => 0]],
			'retry_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'last_error' => ['text', ['notnull' => false]],
			'created_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'updated_at' => ['bigint', ['notnull' => true, 'default' => 0]],
		], $sends->columns);
		self::assertSame(['id'], $sends->primaryKey);
		self::assertSame(['psync_send_user_file' => ['user_id', 'file_id']], $sends->uniqueIndexes);
	}

	public function testNamesFitTheLimitsOfEveryDatabaseOfNextcloud(): void {
		$this->migrate($this->schema(false));

		foreach ($this->created as $name => $table) {
			self::assertLessThanOrEqual(30, strlen('oc_' . $name), $name);
			foreach ([...array_keys($table->columns), ...array_keys($table->uniqueIndexes)] as $identifier) {
				self::assertLessThanOrEqual(30, strlen($identifier), $identifier);
			}
		}
	}

	public function testAnExistingTableIsKept(): void {
		$schema = $this->schema(true);
		$schema->expects(self::never())->method('createTable');

		self::assertSame($schema, $this->migrate($schema));
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version000140Date20261018000000())->changeSchema($this->createStub(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	/** @return ISchemaWrapper&MockObject */
	private function schema(bool $exists): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => $exists && $name === 'paperless_sync_send');
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
