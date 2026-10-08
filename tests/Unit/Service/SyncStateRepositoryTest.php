<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\SyncStateRepository;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The repository against a database in memory that understands the queries it builds:
 * select, insert, update and delete with equality conditions.
 */
final class SyncStateRepositoryTest extends TestCase {
	private const EXPORTS = 'paperless_sync_export';
	private const IMPORTS = 'paperless_sync_import';

	/** @var array<string, list<array<string, string|null>>> the rows of each table, as the database driver returns them */
	private array $tables = [self::EXPORTS => [], self::IMPORTS => []];
	private SyncStateRepository $repository;

	protected function setUp(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$this->repository = new SyncStateRepository($connection);
	}

	public function testNewExportStartsWithDefaults(): void {
		$before = time();

		$this->repository->saveExport('paperless', 123, ['path' => 'Archiv/a.pdf', 'fingerprint' => 'abc', 'owner_uid' => 'intruder', 'unknown' => 'x']);

		$export = $this->repository->findExport('paperless', 123);
		self::assertNotNull($export);
		self::assertSame('paperless', $export['owner_uid']);
		self::assertSame(123, $export['document_id']);
		self::assertSame('Archiv/a.pdf', $export['path']);
		self::assertSame('abc', $export['fingerprint']);
		self::assertSame('', $export['source_revision']);
		self::assertSame('active', $export['state']);
		self::assertSame(0, $export['missing_runs']);
		self::assertNull($export['trash_date']);
		self::assertNull($export['last_error']);
		self::assertGreaterThanOrEqual($before, $export['created_at']);
		self::assertSame($export['created_at'], $export['updated_at']);
		self::assertArrayNotHasKey('unknown', $export);
	}

	public function testSavingAnExistingExportChangesOnlyTheGivenColumns(): void {
		$this->repository->saveExport('paperless', 123, ['path' => 'Archiv/a.pdf', 'fingerprint' => 'abc']);

		$this->repository->saveExport('paperless', 123, ['state' => 'trash', 'trash_date' => 1788000000, 'document_id' => 999]);

		$export = $this->repository->findExport('paperless', 123);
		self::assertNotNull($export);
		self::assertSame('trash', $export['state']);
		self::assertSame(1788000000, $export['trash_date']);
		self::assertSame('Archiv/a.pdf', $export['path']);
		self::assertSame('abc', $export['fingerprint']);
		self::assertCount(1, $this->tables[self::EXPORTS]);
	}

	public function testExportsBelongToTheirOwner(): void {
		$this->repository->saveExport('paperless', 1, ['path' => 'a.pdf']);
		$this->repository->saveExport('paperless', 2, ['path' => 'b.pdf']);
		$this->repository->saveExport('someone-else', 1, ['path' => 'c.pdf']);

		self::assertSame(['a.pdf', 'b.pdf'], array_column($this->repository->allExports('paperless'), 'path'));
		self::assertSame([], $this->repository->allExports('nobody'));
		self::assertNull($this->repository->findExport('paperless', 3));

		$this->repository->deleteExport('paperless', 1);

		self::assertSame(['b.pdf'], array_column($this->repository->allExports('paperless'), 'path'));
		self::assertSame(['c.pdf'], array_column($this->repository->allExports('someone-else'), 'path'));
	}

	public function testNewImportIsPendingAndFoundByItsPath(): void {
		$this->repository->saveImport('paperless', 'Eingang/police.pdf', ['etag' => 'e1', 'task_id' => 'task-1', 'path' => 'Eingang/other.pdf']);

		$import = $this->repository->findImport('paperless', 'Eingang/police.pdf');
		self::assertNotNull($import);
		self::assertSame('Eingang/police.pdf', $import['path']);
		self::assertSame(hash('sha256', 'Eingang/police.pdf'), $import['path_hash']);
		self::assertSame('pending', $import['status']);
		self::assertSame('task-1', $import['task_id']);
		self::assertSame('e1', $import['etag']);
		self::assertIsInt($import['submitted_at']);
		self::assertSame(0, $import['attempts']);
		self::assertSame(0, $import['retry_at']);
		self::assertNull($import['last_error']);
		self::assertNull($this->repository->findImport('paperless', 'Eingang/other.pdf'));
	}

	public function testFailedUploadRecordsItsAttemptsAndTheTimeOfTheNext(): void {
		$this->repository->saveImport('paperless', 'Eingang/police.pdf', ['etag' => 'e1', 'status' => 'retry', 'attempts' => 3, 'retry_at' => 1791460800, 'last_error' => 'HTTP 502']);

		$import = $this->repository->findImport('paperless', 'Eingang/police.pdf');
		self::assertNotNull($import);
		self::assertSame('retry', $import['status']);
		self::assertSame(3, $import['attempts']);
		self::assertSame(1791460800, $import['retry_at']);

		$this->repository->saveImport('paperless', 'Eingang/police.pdf', ['status' => 'pending', 'attempts' => 0, 'retry_at' => 0]);

		$import = $this->repository->findImport('paperless', 'Eingang/police.pdf');
		self::assertNotNull($import);
		self::assertSame(0, $import['attempts']);
		self::assertSame(0, $import['retry_at']);
	}

	public function testSavingAnExistingImportChangesOnlyTheGivenColumns(): void {
		$this->repository->saveImport('paperless', 'Eingang/police.pdf', ['etag' => 'e1', 'task_id' => 'task-1']);

		$this->repository->saveImport('paperless', 'Eingang/police.pdf', ['status' => 'success', 'last_error' => null]);

		$import = $this->repository->findImport('paperless', 'Eingang/police.pdf');
		self::assertNotNull($import);
		self::assertSame('success', $import['status']);
		self::assertSame('task-1', $import['task_id']);
		self::assertCount(1, $this->tables[self::IMPORTS]);
	}

	public function testImportsBelongToTheirOwner(): void {
		$this->repository->saveImport('paperless', 'Eingang/a.pdf', ['task_id' => 'task-1']);
		$this->repository->saveImport('paperless', 'Eingang/b.pdf', ['task_id' => 'task-2']);
		$this->repository->saveImport('someone-else', 'Eingang/a.pdf', ['task_id' => 'task-3']);

		$this->repository->deleteImport('paperless', 'Eingang/a.pdf');

		self::assertSame(['task-2'], array_column($this->repository->allImports('paperless'), 'task_id'));
		self::assertSame(['task-3'], array_column($this->repository->allImports('someone-else'), 'task_id'));
	}

	public function testImportWithTheSameHashButAnotherPathIsNotTheSame(): void {
		$this->tables[self::IMPORTS][] = [
			'id' => '1',
			'owner_uid' => 'paperless',
			'path_hash' => hash('sha256', 'Eingang/police.pdf'),
			'path' => 'Eingang/colliding.pdf',
			'status' => 'pending',
		];

		self::assertNull($this->repository->findImport('paperless', 'Eingang/police.pdf'));
	}

	public function testRowsAreNormalizedToTheirColumnTypes(): void {
		$this->tables[self::EXPORTS][] = [
			'id' => '7',
			'owner_uid' => 'paperless',
			'document_id' => '123',
			'path' => 'Archiv/a.pdf',
			'missing_runs' => '2',
			'trash_date' => null,
			'last_error' => 'Timeout',
		];
		$this->tables[self::EXPORTS][0]['fingerprint'] = 123;
		$this->tables[self::EXPORTS][0]['blob'] = [1];

		self::assertSame([
			'id' => 7,
			'owner_uid' => 'paperless',
			'document_id' => 123,
			'path' => 'Archiv/a.pdf',
			'missing_runs' => 2,
			'trash_date' => null,
			'last_error' => 'Timeout',
			'fingerprint' => '123',
		], $this->repository->findExport('paperless', 123));
	}

	private function queryBuilder(): IQueryBuilder {
		$query = ['type' => '', 'table' => '', 'values' => [], 'where' => []];
		$parameters = [];
		$builder = $this->createMock(IQueryBuilder::class);
		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('eq')->willReturnCallback(static fn (string $column, string $parameter): string => $column . ' = ' . $parameter);
		$builder->method('expr')->willReturn($expression);
		$builder->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$parameters): string {
			$name = ':parameter' . count($parameters);
			$parameters[$name] = $value;

			return $name;
		});
		$builder->method('select')->willReturnCallback(function (string $columns) use (&$query, $builder): IQueryBuilder {
			self::assertSame('*', $columns);
			$query['type'] = 'select';

			return $builder;
		});
		foreach (['insert', 'update', 'delete', 'from'] as $method) {
			$builder->method($method)->willReturnCallback(function (string $table) use (&$query, $builder, $method): IQueryBuilder {
				if ($method !== 'from') {
					$query['type'] = $method;
				}
				$query['table'] = $table;

				return $builder;
			});
		}
		foreach (['set', 'setValue'] as $method) {
			$builder->method($method)->willReturnCallback(function (string $column, string $parameter) use (&$query, $builder): IQueryBuilder {
				$query['values'][$column] = $parameter;

				return $builder;
			});
		}
		foreach (['where', 'andWhere'] as $method) {
			$builder->method($method)->willReturnCallback(function (string $predicate) use (&$query, $builder): IQueryBuilder {
				$query['where'][] = $predicate;

				return $builder;
			});
		}
		$builder->method('executeQuery')->willReturnCallback(function () use (&$query, &$parameters): IResult {
			self::assertSame('select', $query['type']);

			return $this->queryResult(array_values(array_filter(
				$this->tables[$query['table']],
				fn (array $row): bool => $this->rowMatches($row, $query['where'], $parameters),
			)));
		});
		$builder->method('executeStatement')->willReturnCallback(function () use (&$query, &$parameters): int {
			return $this->execute($query, $parameters);
		});

		return $builder;
	}

	/**
	 * @param array{type: string, table: string, values: array<string, string>, where: list<string>} $query
	 * @param array<string, mixed> $parameters
	 */
	private function execute(array $query, array $parameters): int {
		$table = &$this->tables[$query['table']];
		$values = array_map(fn (string $parameter): ?string => $this->stored($parameters[$parameter]), $query['values']);
		if ($query['type'] === 'insert') {
			self::assertSame([], $query['where']);
			$table[] = ['id' => (string)(count($table) + 1)] + $values;

			return 1;
		}

		$affected = 0;
		foreach ($table as $index => $row) {
			if (!$this->rowMatches($row, $query['where'], $parameters)) {
				continue;
			}
			++$affected;
			if ($query['type'] === 'delete') {
				unset($table[$index]);
			} else {
				self::assertSame('update', $query['type']);
				$table[$index] = array_merge($row, $values);
			}
		}
		$table = array_values($table);

		return $affected;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $where
	 * @param array<string, mixed> $parameters
	 */
	private function rowMatches(array $row, array $where, array $parameters): bool {
		self::assertNotSame([], $where, 'Every query of the repository is limited by conditions.');
		foreach ($where as $predicate) {
			[$column, $parameter] = explode(' = ', $predicate);
			if (($row[$column] ?? null) !== $this->stored($parameters[$parameter])) {
				return false;
			}
		}

		return true;
	}

	/** Database drivers return every value as text. */
	private function stored(mixed $value): ?string {
		return $value === null ? null : (string)$value;
	}

	/** @param list<array<string, mixed>> $rows */
	private function queryResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAssociative')->willReturnCallback(function () use (&$rows): array|false {
			return array_shift($rows) ?? false;
		});
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		return $result;
	}
}
