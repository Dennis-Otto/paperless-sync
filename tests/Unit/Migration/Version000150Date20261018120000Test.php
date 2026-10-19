<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000150Date20261018120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class Version000150Date20261018120000Test extends TestCase {
	/** Every option of every column is pinned, as an installed database keeps them. */
	public function testAddsTheTagsAndTheRuleToTheSentFiles(): void {
		$sends = $this->table([]);

		$schema = $this->schema($sends);
		self::assertSame($schema, $this->migrate($schema));

		self::assertSame([
			'tags' => ['text', ['notnull' => false]],
			'rule_id' => ['bigint', ['notnull' => true, 'default' => 0, 'unsigned' => true]],
		], $sends->added);
		foreach (array_keys($sends->added) as $column) {
			self::assertLessThanOrEqual(30, strlen($column), 'Names fit the limits of every database of Nextcloud.');
		}
	}

	public function testExistingColumnsAreKept(): void {
		$sends = $this->table(['tags', 'rule_id']);

		$this->migrate($this->schema($sends));

		self::assertSame([], $sends->added);
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version000150Date20261018120000())->changeSchema($this->createStub(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	private function schema(object $sends): ISchemaWrapper {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('getTable')->willReturnCallback(static function (string $name) use ($sends): object {
			self::assertSame('paperless_sync_send', $name);

			return $sends;
		});

		return $schema;
	}

	/**
	 * The table of the sent files, with the columns it has already.
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
			public function addColumn(string $name, string $type, array $options): void {
				$this->added[$name] = [$type, $options];
			}
		};
	}
}
