<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000190Date20261123000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Version000190Date20261123000000Test extends TestCase {
	private const COLUMN = ['first_attempt_at' => ['bigint', ['notnull' => true, 'default' => 0]]];

	/** The imports of the inbox and the sent files keep the time of their first upload; a state of an earlier version has none, 0. */
	public function testAddsTheTimeOfTheFirstAttemptToTheImportsAndTheSends(): void {
		$tables = ['paperless_sync_import' => $this->table([]), 'paperless_sync_send' => $this->table([])];

		$schema = $this->schema($tables);
		self::assertSame($schema, $this->migrate($schema));

		self::assertSame([self::COLUMN, self::COLUMN], [$tables['paperless_sync_import']->added, $tables['paperless_sync_send']->added]);
	}

	/**
	 * A column that a table has already stays, and the other table still gets it.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function tablesWithTheColumn(): array {
		return [
			'the imports' => ['paperless_sync_import', 'paperless_sync_send'],
			'the sends' => ['paperless_sync_send', 'paperless_sync_import'],
		];
	}

	#[DataProvider('tablesWithTheColumn')]
	public function testATableThatHasTheColumnKeepsIt(string $having, string $lacking): void {
		$tables = [$having => $this->table(['first_attempt_at']), $lacking => $this->table([])];

		$schema = $this->schema($tables);
		self::assertSame($schema, $this->migrate($schema));

		self::assertSame([[], self::COLUMN], [$tables[$having]->added, $tables[$lacking]->added]);
	}

	public function testExistingColumnsAreKept(): void {
		$tables = ['paperless_sync_import' => $this->table(['first_attempt_at']), 'paperless_sync_send' => $this->table(['first_attempt_at'])];

		self::assertNull($this->migrate($this->schema($tables)), 'Nothing changes, so the schema stays as it is.');
		self::assertSame([[], []], [$tables['paperless_sync_import']->added, $tables['paperless_sync_send']->added]);
	}

	public function testNamesFitTheLimitsOfEveryDatabaseOfNextcloud(): void {
		$tables = ['paperless_sync_import' => $this->table([]), 'paperless_sync_send' => $this->table([])];

		$this->migrate($this->schema($tables));

		foreach ($tables as $table) {
			foreach (array_keys($table->added) as $column) {
				self::assertLessThanOrEqual(30, strlen($column), $column);
			}
		}
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version000190Date20261123000000())->changeSchema($this->createStub(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	/** @param array<string, object> $tables */
	private function schema(array $tables): ISchemaWrapper {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('getTable')->willReturnCallback(static function (string $name) use ($tables): object {
			self::assertArrayHasKey($name, $tables);

			return $tables[$name];
		});

		return $schema;
	}

	/**
	 * A table, with the columns it has already.
	 *
	 * @param list<string> $existing
	 */
	private function table(array $existing): object {
		return new class($existing) {
			/** @var array<string, array{string, array<string, mixed>}> */
			public array $added = [];

			/** @param list<string> $existing */
			public function __construct(
				private array $existing,
			) {
			}

			public function hasColumn(string $name): bool {
				return in_array($name, $this->existing, true) || isset($this->added[$name]);
			}

			/** @param array<string, mixed> $options */
			public function addColumn(string $name, string $type, array $options = []): void {
				$this->added[$name] = [$type, $options];
			}
		};
	}
}
