<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000170Date20261112000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class Version000170Date20261112000000Test extends TestCase {
	public function testAddsTheFileIdToTheImports(): void {
		$imports = $this->table([]);

		$schema = $this->schema($imports);
		self::assertSame($schema, $this->migrate($schema));

		self::assertSame(['file_id' => ['bigint', ['notnull' => true, 'default' => 0]]], $imports->added, 'A state of an earlier version has no file ID, 0.');
	}

	public function testAnExistingColumnIsKept(): void {
		$imports = $this->table(['file_id']);

		self::assertNull($this->migrate($this->schema($imports)), 'Nothing changes, so the schema stays as it is.');
		self::assertSame([], $imports->added);
	}

	public function testNamesFitTheLimitsOfEveryDatabaseOfNextcloud(): void {
		$imports = $this->table([]);

		$this->migrate($this->schema($imports));

		foreach (array_keys($imports->added) as $column) {
			self::assertLessThanOrEqual(30, strlen($column), $column);
		}
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version000170Date20261112000000())->changeSchema($this->createStub(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	private function schema(object $imports): ISchemaWrapper {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('getTable')->willReturnCallback(static function (string $name) use ($imports): object {
			self::assertSame('paperless_sync_import', $name);

			return $imports;
		});

		return $schema;
	}

	/**
	 * The table of imports, with the columns it has already.
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
