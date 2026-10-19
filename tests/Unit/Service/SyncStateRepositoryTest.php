<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\SyncStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\DB\Exception;
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
	private const SENDS = 'paperless_sync_send';

	/** @var array<string, list<array<string, string|null>>> the rows of each table, as the database driver returns them */
	private array $tables = [self::EXPORTS => [], self::IMPORTS => [], self::SENDS => []];
	/** @var list<string> the kind of every statement that changed a table, in order */
	private array $statements = [];
	/** The queries that read a table. */
	private int $selects = 0;
	/** @var array<string, string|null>|null a row that another request inserts right before the next insert, which then violates the unique index */
	private ?array $racingRow = null;
	/** The reason of the database error that the racing insert causes. */
	private int $racingReason = Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
	private TestClock $clock;
	private SyncStateRepository $repository;

	protected function setUp(): void {
		$connection = $this->createStub(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$this->clock = new TestClock();
		$this->repository = new SyncStateRepository($connection, $this->clock);
	}

	public function testNewExportStartsWithDefaults(): void {
		$this->repository->saveExport('paperless', 123, ['path' => 'Archiv/a.pdf', 'fingerprint' => 'abc', 'owner_uid' => 'intruder', 'unknown' => 'x']);

		self::assertSame(['insert'], $this->statements, 'A new export is one insert, and no update follows it.');
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
		self::assertSame(TestClock::START, $export['last_seen']);
		self::assertSame(TestClock::START, $export['created_at']);
		self::assertSame(TestClock::START, $export['updated_at']);
		self::assertArrayNotHasKey('unknown', $export);
	}

	public function testSavingAnExistingExportChangesOnlyTheGivenColumns(): void {
		$this->repository->saveExport('paperless', 123, ['path' => 'Archiv/a.pdf', 'fingerprint' => 'abc']);

		$this->repository->saveExport('paperless', 123, ['state' => 'trash', 'trash_date' => 1788000000, 'document_id' => 999]);

		self::assertSame(['insert', 'update'], $this->statements);
		$export = $this->repository->findExport('paperless', 123);
		self::assertNotNull($export);
		self::assertSame('trash', $export['state']);
		self::assertSame(1788000000, $export['trash_date']);
		self::assertSame('Archiv/a.pdf', $export['path']);
		self::assertSame('abc', $export['fingerprint']);
		self::assertCount(1, $this->tables[self::EXPORTS]);
	}

	/** A caller that knows whether the export exists saves the query for it. */
	public function testAnExportThatIsKnownToExistOrNotIsSavedWithoutAQuery(): void {
		$this->repository->saveExport('paperless', 123, ['path' => 'Archiv/a.pdf'], false);
		$this->clock->advance(60);
		$this->repository->saveExport('paperless', 123, ['remote_checksum' => 'abc', 'remote_revision' => 'archive:2026-08-26T11:00:00+02:00'], true);

		self::assertSame(['insert', 'update'], $this->statements);
		self::assertSame(0, $this->selects);
		$export = $this->repository->findExport('paperless', 123);
		self::assertNotNull($export);
		self::assertSame(['Archiv/a.pdf', 'abc', 'archive:2026-08-26T11:00:00+02:00', TestClock::START, TestClock::START + 60], [$export['path'], $export['remote_checksum'], $export['remote_revision'], $export['created_at'], $export['updated_at']]);
	}

	public function testANewExportKnowsNoChecksumOfPaperless(): void {
		$this->repository->saveExport('paperless', 123, ['path' => 'Archiv/a.pdf']);

		$export = $this->repository->findExport('paperless', 123);
		self::assertNotNull($export);
		self::assertSame([null, null], [$export['remote_checksum'], $export['remote_revision']]);
		self::assertSame(2, $this->selects, 'Without a hint, the save asks whether the export exists.');
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

	public function testSavingAnExportOfOneOwnerLeavesTheSameDocumentOfAnotherAlone(): void {
		$this->repository->saveExport('paperless', 123, ['path' => 'a.pdf', 'state' => 'active']);
		$this->repository->saveExport('someone-else', 123, ['path' => 'b.pdf', 'state' => 'active']);
		$this->clock->advance(60);

		$this->repository->saveExport('paperless', 123, ['path' => 'c.pdf', 'state' => 'trash']);

		$other = $this->repository->findExport('someone-else', 123);
		self::assertNotNull($other);
		self::assertSame(['b.pdf', 'active', TestClock::START], [$other['path'], $other['state'], $other['updated_at']]);
		$own = $this->repository->findExport('paperless', 123);
		self::assertNotNull($own);
		self::assertSame(['c.pdf', 'trash', TestClock::START + 60], [$own['path'], $own['state'], $own['updated_at']]);
		self::assertSame(TestClock::START, $own['created_at'], 'An update keeps the time the export was created.');
	}

	public function testNewImportIsPendingAndFoundByItsPath(): void {
		$this->repository->saveImport('paperless', 'Eingang/police.pdf', ['etag' => 'e1', 'task_id' => 'task-1', 'path' => 'Eingang/other.pdf']);

		self::assertSame(['insert'], $this->statements, 'A new import is one insert, and no update follows it.');
		$import = $this->repository->findImport('paperless', 'Eingang/police.pdf');
		self::assertNotNull($import);
		self::assertSame('Eingang/police.pdf', $import['path']);
		self::assertSame(hash('sha256', 'Eingang/police.pdf'), $import['path_hash']);
		self::assertSame('pending', $import['status']);
		self::assertSame('task-1', $import['task_id']);
		self::assertSame('e1', $import['etag']);
		self::assertSame(TestClock::START, $import['submitted_at']);
		self::assertSame(TestClock::START, $import['created_at']);
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

		self::assertSame(['insert', 'update'], $this->statements);
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

	public function testSavingAnImportOfOneOwnerLeavesTheSameFileOfAnotherAlone(): void {
		$this->repository->saveImport('paperless', 'Eingang/a.pdf', ['task_id' => 'task-1']);
		$this->repository->saveImport('someone-else', 'Eingang/a.pdf', ['task_id' => 'task-2']);

		$this->repository->saveImport('paperless', 'Eingang/a.pdf', ['status' => 'success']);

		self::assertSame(['pending'], array_column($this->repository->allImports('someone-else'), 'status'));
		self::assertSame(['success'], array_column($this->repository->allImports('paperless'), 'status'));
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

	/** A file sent from Files waits for its upload, and is there once for each user. */
	public function testANewSendIsQueuedAndFoundByItsUserAndFile(): void {
		$this->repository->saveSend('alice', 42, ['path' => 'Scans/receipt.pdf', 'folder' => '2026', 'not_a_column' => 'x']);

		self::assertSame([
			'id' => 1,
			'user_id' => 'alice',
			'file_id' => 42,
			'path' => 'Scans/receipt.pdf',
			'folder' => '2026',
			'tags' => null,
			'rule_id' => 0,
			'task_id' => '',
			'status' => 'queued',
			'submitted_at' => 0,
			'attempts' => 0,
			'retry_at' => 0,
			'last_error' => null,
			'created_at' => TestClock::START,
			'updated_at' => TestClock::START,
		], $this->repository->findSend('alice', 42));
		self::assertNull($this->repository->findSend('bob', 42));
		self::assertNull($this->repository->findSend('alice', 43));
	}

	/** A rule of Flow names itself and its tags, which a run reads as the list of the setting. */
	public function testASendOfARuleKeepsItsTagsAndTheIdOfTheRule(): void {
		$this->repository->saveSend('alice', 42, ['path' => 'Scans/receipt.pdf', 'tags' => 'Scans,Family', 'rule_id' => 7]);

		self::assertSame(['tags' => 'Scans,Family', 'rule_id' => 7], array_intersect_key($this->repository->findSend('alice', 42) ?? [], ['tags' => 0, 'rule_id' => 0]));
	}

	public function testSavingAnExistingSendChangesOnlyTheGivenColumns(): void {
		$this->repository->saveSend('alice', 42, ['path' => 'Scans/receipt.pdf']);
		$this->clock->advance(60);

		$this->repository->saveSend('alice', 42, ['status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START + 60]);

		self::assertSame(['insert', 'update'], $this->statements);
		self::assertSame(
			['path' => 'Scans/receipt.pdf', 'task_id' => 'task-1', 'status' => 'pending', 'submitted_at' => TestClock::START + 60, 'created_at' => TestClock::START, 'updated_at' => TestClock::START + 60],
			array_intersect_key($this->repository->findSend('alice', 42) ?? [], array_flip(['path', 'task_id', 'status', 'submitted_at', 'created_at', 'updated_at'])),
		);
	}

	/** Two clicks at the same time both find no row; the second insert then updates the row of the first. */
	public function testASendThatAnotherRequestInsertedMeanwhileIsUpdated(): void {
		$this->racingRow = ['user_id' => 'alice', 'file_id' => '42', 'path' => 'Scans/old.pdf', 'folder' => '', 'task_id' => '', 'status' => 'queued', 'submitted_at' => '0', 'attempts' => '0', 'retry_at' => '0', 'last_error' => null, 'created_at' => (string)TestClock::START, 'updated_at' => (string)TestClock::START];

		$this->repository->saveSend('alice', 42, ['path' => 'Scans/receipt.pdf', 'status' => 'queued']);

		self::assertSame(['insert', 'update'], $this->statements);
		self::assertCount(1, $this->repository->allSends());
		self::assertSame('Scans/receipt.pdf', $this->repository->findSend('alice', 42)['path'] ?? null);
	}

	/** Any other error of the database stays an error. */
	public function testAnotherErrorOfTheInsertOfASendIsThrown(): void {
		$this->racingRow = ['user_id' => 'bob', 'file_id' => '7'];
		$this->racingReason = Exception::REASON_LOCK_WAIT_TIMEOUT;

		$this->expectException(Exception::class);
		$this->repository->saveSend('alice', 42, ['path' => 'Scans/receipt.pdf']);
	}

	/** The job takes every sent file, the oldest first, and an ended import leaves the others alone. */
	public function testEverySendIsListedInTheOrderOfItsClickAndDeletedAlone(): void {
		$this->repository->saveSend('alice', 42, ['path' => 'a.pdf']);
		$this->repository->saveSend('bob', 42, ['path' => 'b.pdf']);
		$this->repository->saveSend('alice', 7, ['path' => 'c.pdf']);

		self::assertSame(['a.pdf', 'b.pdf', 'c.pdf'], array_column($this->repository->allSends(), 'path'));

		$this->repository->deleteSend('alice', 42);

		self::assertSame(['b.pdf', 'c.pdf'], array_column($this->repository->allSends(), 'path'));
	}

	private function queryBuilder(): IQueryBuilder {
		$query = ['type' => '', 'table' => '', 'values' => [], 'where' => []];
		$parameters = [];
		$builder = $this->createStub(IQueryBuilder::class);
		$expression = $this->createStub(IExpressionBuilder::class);
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
		$builder->method('orderBy')->willReturnCallback(function (string $column) use (&$query, $builder): IQueryBuilder {
			// The rows of the database in memory are in the order of their IDs already.
			self::assertSame('id', $column);
			$query['order'] = $column;

			return $builder;
		});
		foreach (['where', 'andWhere'] as $method) {
			$builder->method($method)->willReturnCallback(function (string $predicate) use (&$query, $builder): IQueryBuilder {
				$query['where'][] = $predicate;

				return $builder;
			});
		}
		$builder->method('executeQuery')->willReturnCallback(function () use (&$query, &$parameters): IResult {
			self::assertSame('select', $query['type']);
			++$this->selects;

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
		$this->statements[] = $query['type'];
		self::assertTrue($query['type'] === 'insert' || $query['where'] !== [], 'Every change of the repository is limited by conditions.');
		$table = &$this->tables[$query['table']];
		$values = array_map(fn (string $parameter): ?string => $this->stored($parameters[$parameter]), $query['values']);
		if ($query['type'] === 'insert') {
			self::assertSame([], $query['where']);
			if ($this->racingRow !== null) {
				$table[] = ['id' => (string)(count($table) + 1)] + $this->racingRow;
				$this->racingRow = null;
				$reason = $this->racingReason;
				throw new class($reason) extends Exception {
					public function __construct(
						private int $reason,
					) {
						parent::__construct('Duplicate entry');
					}

					public function getReason(): ?int {
						return $this->reason;
					}
				};
			}
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
