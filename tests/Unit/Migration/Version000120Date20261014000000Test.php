<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000120Date20261014000000;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class Version000120Date20261014000000Test extends TestCase {
	use InMemoryConfiguration;

	public function testAddsTheChecksumOfPaperlessAndItsRevisionToTheExports(): void {
		$exports = $this->table([]);

		$schema = $this->schema($exports);
		self::assertSame($schema, $this->migrate($schema));

		self::assertSame([
			'remote_checksum' => ['string', ['notnull' => false, 'length' => 128]],
			'remote_revision' => ['string', ['notnull' => false, 'length' => 255]],
		], $exports->added);
		foreach (array_keys($exports->added) as $column) {
			self::assertLessThanOrEqual(30, strlen($column), 'Names fit the limits of every database of Nextcloud.');
		}
	}

	public function testExistingColumnsAreKept(): void {
		$exports = $this->table(['remote_checksum', 'remote_revision']);

		$this->migrate($this->schema($exports));

		self::assertSame([], $exports->added);
	}

	public function testSettingsAndStatusOfAnEarlierVersionBecomeLazy(): void {
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'sync_enabled' => true, 'status_last_state' => 'completed', 'enabled' => 'yes'];
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Stored 3 settings of Paperless Sync as lazy, which Nextcloud loads only when the app asks for them.');

		$this->step()->postSchemaChange($output, fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);

		self::assertSame(['paperless_url', 'sync_enabled', 'status_last_state'], array_keys($this->lazyKeys), 'The key of Nextcloud that enables the app stays as it is.');
	}

	public function testLazySettingsNeedNoChange(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');

		$this->step()->postSchemaChange($output, fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);

		self::assertSame([], $this->lazyKeys);
	}

	private function step(): Version000120Date20261014000000 {
		return new Version000120Date20261014000000($this->configService(), new StatusService($this->appConfig(), new TestClock()));
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return $this->step()->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	private function schema(object $exports): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('getTable')->willReturnCallback(static function (string $name) use ($exports): object {
			self::assertSame('paperless_sync_export', $name);

			return $exports;
		});

		return $schema;
	}

	/**
	 * The table of exports, with the columns it has already.
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
