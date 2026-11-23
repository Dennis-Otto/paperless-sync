<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000200Date20261124000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class Version000200Date20261124000000Test extends TestCase {
	/** @var array<string, object> the tables that the migration created, by name */
	private array $created = [];

	/**
	 * Nextcloud runs a migration once, so an installed database keeps the columns of this one, and
	 * a change here would reach new installations only. Every option of every column is pinned.
	 */
	public function testCreatesTheTableOfTheRecordsOfTheUploads(): void {
		$schema = $this->schema(false);

		self::assertSame($schema, $this->migrate($schema));

		self::assertSame(['paperless_sync_upload'], array_keys($this->created));
		$records = $this->created['paperless_sync_upload'];
		self::assertSame([
			'id' => ['bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]],
			'user_id' => ['string', ['notnull' => true, 'length' => 64]],
			'source' => ['string', ['notnull' => true, 'length' => 16, 'default' => 'inbox']],
			'path' => ['string', ['notnull' => true, 'length' => 1024, 'default' => '']],
			'sha256' => ['string', ['notnull' => true, 'length' => 64]],
			'md5' => ['string', ['notnull' => true, 'length' => 32]],
			'known_id' => ['bigint', ['notnull' => true, 'default' => 0]],
			'task_id' => ['string', ['notnull' => true, 'length' => 255, 'default' => '']],
			'last_attempt_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'created_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'updated_at' => ['bigint', ['notnull' => true, 'default' => 0]],
		], $records->columns);
		self::assertSame(['id'], $records->primaryKey);
		self::assertSame(['psync_upload_user_sha' => ['user_id', 'sha256']], $records->uniqueIndexes);
	}

	public function testAnExistingTableIsKept(): void {
		self::assertNull($this->migrate($this->schema(true)), 'Nothing changes, so the schema stays as it is.');
		self::assertSame([], $this->created);
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

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version000200Date20261124000000())->changeSchema($this->createStub(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	private function schema(bool $exists): ISchemaWrapper {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => $exists && $name === 'paperless_sync_upload');
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
