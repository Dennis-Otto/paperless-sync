<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\Plan\Copy;
use OCA\PaperlessSync\Model\Plan\DocumentContext;
use OCA\PaperlessSync\Model\Plan\ExportAction;
use OCA\PaperlessSync\Model\Plan\ExportCopy;
use OCA\PaperlessSync\Model\Plan\Failed;
use OCA\PaperlessSync\Model\Plan\ForgetState;
use OCA\PaperlessSync\Model\Plan\MoveToDeleted;
use OCA\PaperlessSync\Model\Plan\RemoveCopy;
use OCA\PaperlessSync\Model\Plan\SaveState;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\WaitForMissing;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ExportExecutor;
use OCA\PaperlessSync\Service\ExportStates;
use OCA\PaperlessSync\Service\MetadataSidecars;
use OCA\PaperlessSync\Service\SyncBatch;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\Files\NotEnoughSpaceException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The executor of the export is the one place where a dry-run and a run differ: a run makes the
 * changes of the plan, and a dry-run reports the same changes, in the same batch, and makes none.
 */
#[AllowMockObjectsWithoutExpectations]
final class ExportExecutorTest extends TestCase {
	private const ARCHIVE = TestConfig::ARCHIVE;
	private const OLD = self::ARCHIVE . '/Alt/A [P1].pdf';
	private const NEW = self::ARCHIVE . '/Neu/A [P1].pdf';
	private const DELETED = self::ARCHIVE . '/_Gelöscht/2026-08-27/Alt/A [P1].pdf';
	private const CONTENT = '%PDF-1';
	/** The writes of the storage, which a dry-run never calls. */
	private const WRITES = ['writeStream', 'writeText', 'move', 'delete', 'pruneEmptyParents', 'prepare'];

	private TestStorage $storage;
	private TestStateRepository $state;
	private TestPaperlessClient $paperless;
	private TestClock $clock;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SyncReport $report;
	private SyncBatch $batch;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->arrange(false);
	}

	/**
	 * Each action of a plan, which a dry-run reports as the run that follows it does.
	 *
	 * @return array<string, array{\Closure(self): ExportAction}>
	 */
	public static function actions(): array {
		return [
			'the export of a new document' => [static fn (self $test): ExportAction => $test->export(oldPath: '', download: true)],
			'the export of a new content in place' => [static fn (self $test): ExportAction => $test->export(oldPath: self::NEW, download: true, ownTarget: true)],
			'the move of a copy' => [static fn (self $test): ExportAction => $test->export(oldPath: self::OLD, move: true)],
			'the move of a copy with a new content' => [static fn (self $test): ExportAction => $test->export(oldPath: self::OLD, move: true, download: true, ownTarget: true)],
			'the export beside a copy that another document shares' => [static fn (self $test): ExportAction => $test->export(oldPath: self::OLD, download: true)],
			'the move to the deleted folder of a trashed document' => [static fn (self $test): ExportAction => new MoveToDeleted(DocumentContext::trash(1, $test->exported()), self::OLD, self::DELETED, SyncReport::TYPE_TRASH, ['state' => 'trash'])],
			'the move to the deleted folder of a missing document' => [static fn (self $test): ExportAction => new MoveToDeleted(DocumentContext::missing(1, $test->exported()), self::OLD, self::DELETED, SyncReport::TYPE_MISSING, ['state' => 'missing'])],
			'the removal of an excluded copy' => [static fn (self $test): ExportAction => new RemoveCopy(DocumentContext::excluded(1, $test->exported()), self::OLD, SyncReport::TYPE_REMOVE_EXCLUDED)],
			'the deletion of a copy' => [static fn (self $test): ExportAction => new RemoveCopy(DocumentContext::missing(1, $test->exported()), self::OLD, SyncReport::TYPE_DELETE)],
			'an unchanged document' => [static fn (self $test): ExportAction => new Unchanged(1, ['missing_runs' => 0], DocumentContext::trash(1, $test->exported()))],
			'a skipped document' => [static fn (self $test): ExportAction => new Skipped(1, ['remote_checksum' => 'abc'], DocumentContext::export(1, $test->exported(), self::OLD))],
			'a new state' => [static fn (self $test): ExportAction => new SaveState(1, ['state' => 'missing'], DocumentContext::missing(1, $test->exported()))],
			'a forgotten state' => [static fn (self $test): ExportAction => new ForgetState(1, DocumentContext::excluded(1, $test->exported()))],
			'a document that waits' => [static fn (self $test): ExportAction => new WaitForMissing(1, self::OLD, 1, 3)],
			'a failed plan' => [static fn (self $test): ExportAction => new Failed(DocumentContext::export(1, $test->exported(), self::NEW), new RuntimeException('No path'), ['remote_checksum' => 'abc'], true)],
		];
	}

	/** @param \Closure(self): ExportAction $action */
	#[DataProvider('actions')]
	public function testADryRunReportsTheChangeOfTheRunAndMakesNone(\Closure $action): void {
		$this->arrange(true);
		$this->apply($action($this));
		$dryRun = $this->report;
		$writes = array_values(array_intersect($this->storage->methods(), self::WRITES));
		$stateWrites = $this->state->exportWrites();

		$this->arrange(false);
		$this->apply($action($this));

		self::assertSame($this->report->actions, $dryRun->actions);
		self::assertSame(self::counts($this->report), self::counts($dryRun));
		self::assertSame([], $writes, 'A dry-run writes no file.');
		self::assertSame(0, $stateWrites, 'A dry-run writes no state.');
		self::assertSame([], $dryRun->changedFiles(), 'The activity of a dry-run names no file.');
	}

	public function testAnExportWritesTheCopyAndItsState(): void {
		$this->apply($this->export(oldPath: '', download: true, checksum: ['remote_checksum' => 'abc']));

		self::assertSame(['EXPORT P1: ' . self::NEW], $this->report->actions);
		self::assertSame(self::CONTENT, $this->storage->files[self::NEW]);
		// The state keeps what the run learned before the copy changed, then the new copy; the
		// values of a new state that are its defaults are not written.
		self::assertSame([
			'document_id' => 1,
			'remote_checksum' => 'abc',
			'path' => self::NEW,
			'fingerprint' => hash('sha256', self::CONTENT) . ' ' . hash('md5', self::CONTENT),
			'source_revision' => 'archive:m1',
			'last_seen' => $this->clock->time,
		], $this->state->exports[1]);
		self::assertSame([self::NEW], $this->report->changedFiles());
		self::assertFalse($this->batch->fits(SyncBatch::DELETE), 'The export is the change of the batch of one.');
	}

	/** The copy keeps its file ID with its shares and versions: it moves, and its content stays. */
	public function testAMoveKeepsTheCopy(): void {
		$move = $this->export(oldPath: self::OLD, move: true);
		$fileId = $this->storage->fileId(self::OLD);

		$this->apply($move);

		self::assertSame(['MOVE P1: ' . self::NEW], $this->report->actions);
		self::assertSame($fileId, $this->storage->fileId(self::NEW));
		self::assertSame(0, $this->paperless->downloads);
		self::assertSame('old fingerprint', $this->state->exports[1]['fingerprint']);
		self::assertSame([[self::OLD, self::ARCHIVE]], $this->storage->prunes);
	}

	/** A write in place that fails halfway leaves the copy incomplete, and its state says so, so that the next run writes it again. */
	public function testAFailedWriteInPlaceLeavesTheCopyPending(): void {
		$this->storage->writeFailures[self::NEW] = new RuntimeException('The connection broke');

		$this->apply($this->export(oldPath: self::NEW, download: true, ownTarget: true));

		self::assertSame(['ERROR: P1: The connection broke'], $this->report->actions);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[1]['fingerprint']);
		self::assertSame('The connection broke', $this->state->exports[1]['last_error']);
		self::assertFalse($this->batch->fits(SyncBatch::EXPORT), 'A failed export takes its place in the batch.');
		self::assertTrue($this->batch->fits(SyncBatch::DELETE), 'A failed export is no change.');
	}

	/** A copy that another document shares stays, and is removed after the export, with the folders that it leaves empty. */
	public function testAnExportBesideASharedCopyRemovesIt(): void {
		$this->apply($this->export(oldPath: self::OLD, download: true));

		self::assertArrayNotHasKey(self::OLD, $this->storage->files);
		self::assertSame(self::CONTENT, $this->storage->files[self::NEW]);
		self::assertSame([[self::OLD, self::ARCHIVE]], $this->storage->prunes);
	}

	public function testAMoveToTheDeletedFolderSavesThePathOfTheCopy(): void {
		$this->apply(new MoveToDeleted(DocumentContext::trash(1, $this->exported()), self::OLD, self::DELETED, SyncReport::TYPE_TRASH, ['state' => 'trash']));

		self::assertSame(['TRASH P1: ' . self::DELETED], $this->report->actions);
		self::assertSame(['trash', self::DELETED], [$this->state->exports[1]['state'], $this->state->exports[1]['path']]);
		self::assertSame([self::DELETED], $this->report->changedFiles());
	}

	/** A copy that vanished before its move is no change: only the state of its document changes. */
	public function testAMoveToTheDeletedFolderOfAVanishedCopyOnlyChangesTheState(): void {
		$move = new MoveToDeleted(DocumentContext::missing(1, $this->exported()), self::OLD, self::DELETED, SyncReport::TYPE_MISSING, ['state' => 'missing']);
		unset($this->storage->files[self::OLD]);

		$this->apply($move);

		self::assertSame([], $this->report->actions);
		self::assertSame(0, $this->report->movedToTrash);
		self::assertSame(['missing', self::OLD], [$this->state->exports[1]['state'], $this->state->exports[1]['path']]);
		self::assertFalse($this->batch->fits(SyncBatch::WRITE), 'The move took its place before it was tried.');
	}

	public function testARemovalForgetsTheState(): void {
		$this->apply(new RemoveCopy(DocumentContext::missing(1, $this->exported()), self::OLD, SyncReport::TYPE_DELETE));

		self::assertSame(['DELETE P1: ' . self::OLD], $this->report->actions);
		self::assertSame(1, $this->report->permanentlyDeleted);
		self::assertArrayNotHasKey(self::OLD, $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	/** An error that concerns the whole archive stops the other writes, and the report warns of it once. */
	public function testAFullQuotaStopsTheWritesOnce(): void {
		$this->logger->expects(self::exactly(2))->method('warning')->willReturnCallback(static function (string $message, array $context): void {
			static $calls = 0;
			self::assertSame(++$calls === 1
				? 'Nextcloud cannot store files in the archive, so this run exports and moves no further documents: {error}'
				: 'Paperless Sync could not synchronize the document P{documentId}: {error}', $message);
		});
		$this->arrange(false, 10);
		$full = new NotEnoughSpaceException('Not enough free space');

		$this->apply(new Failed(DocumentContext::export(1, null, self::NEW), $full));
		$this->apply(new Failed(DocumentContext::export(2, null, self::OLD), $full));

		self::assertSame([
			'ERROR: P1: Not enough free space',
			'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.',
			'ERROR: P2: Not enough free space',
		], $this->report->actions);
		self::assertFalse($this->batch->fits(SyncBatch::WRITE));
		self::assertTrue($this->batch->fits(SyncBatch::DELETE), 'A full quota leaves the deletions.');
	}

	/** The removal of an excluded copy needs no space, so its failure stops no writes. */
	public function testAFailedRemovalOfAnExcludedCopyStopsNoWrites(): void {
		$this->arrange(false, 10);
		$this->storage->deleteException = new NotEnoughSpaceException('Not enough free space');
		$this->logger->expects(self::once())->method('warning')->with('Paperless Sync could not synchronize the document P{documentId}: {error}', [
			'documentId' => 1,
			'path' => self::OLD,
			'error' => 'Not enough free space',
		]);

		$this->apply(new RemoveCopy(DocumentContext::excluded(1, $this->exported()), self::OLD, SyncReport::TYPE_REMOVE_EXCLUDED));

		self::assertSame(['ERROR: Excluded P1: Not enough free space'], $this->report->actions);
		self::assertTrue($this->batch->fits(SyncBatch::WRITE));
		self::assertSame($this->clock->time, $this->state->exports[1]['last_seen']);
	}

	/** An error that the state keeps from an earlier run is not logged again. */
	public function testAnErrorThatRecursIsLoggedOnce(): void {
		$this->logger->expects(self::never())->method('warning');

		$this->apply(new Failed(DocumentContext::trash(1, $this->exported(['last_error' => 'locked'])), new RuntimeException('locked')));

		self::assertSame(['ERROR: Trash P1: locked'], $this->report->actions);
		self::assertArrayNotHasKey('last_seen', $this->state->exports[1]);
	}

	/** A failed plan of an export that tried it, such as at a conflict, takes its place in the batch; other failures don't. */
	public function testAFailedPlanTakesThePlaceOfTheExportThatItTried(): void {
		$this->apply(new Failed(DocumentContext::export(1, null, self::NEW), new RuntimeException('No path')));
		self::assertTrue($this->batch->fits(SyncBatch::EXPORT));

		$this->apply(new Failed(DocumentContext::export(1, null, self::NEW), new RuntimeException('Conflict'), ['remote_checksum' => 'abc'], true));

		self::assertFalse($this->batch->fits(SyncBatch::EXPORT));
		self::assertSame('abc', $this->state->exports[1]['remote_checksum'], 'The state keeps what the run learned with the error.');
	}

	public function testADocumentThatWaitsKeepsItsCount(): void {
		$this->exported();

		$this->apply(new WaitForMissing(1, self::OLD, 2, 3));

		self::assertSame(['WAIT P1: missing run 2/3'], $this->report->actions);
		self::assertSame(2, $this->state->exports[1]['missing_runs']);
	}

	/** A state that can't be saved fails its document, or the run when the action names no document to fail. */
	public function testAFailedSaveFailsItsDocument(): void {
		$repository = $this->createStub(SyncStateRepositoryInterface::class);
		$repository->method('allExports')->willReturn([['document_id' => 1, 'path' => self::OLD, 'last_error' => null]]);
		$repository->method('saveExport')->willReturnCallback(static function (string $owner, int $document, array $values): void {
			if (!array_key_exists('last_error', $values)) {
				throw new RuntimeException('The database is read-only');
			}
		});
		$repository->method('deleteExport')->willThrowException(new RuntimeException('The database is read-only'));
		$this->executor($repository)->apply(new SaveState(1, ['state' => 'missing'], DocumentContext::missing(1, ['path' => self::OLD])));
		$this->executor($repository)->apply(new ForgetState(1, DocumentContext::excluded(1, ['path' => self::OLD])));

		self::assertSame(['ERROR: Missing P1: The database is read-only', 'ERROR: Excluded P1: The database is read-only'], $this->report->actions);

		$this->expectException(RuntimeException::class);
		$this->executor($repository)->apply(new SaveState(1, ['missing_runs' => 0]));
	}

	/** With the metadata next to the archive, a run describes each copy that it exported, moved or kept at its path. */
	public function testARunDescribesItsCopiesNextToTheArchive(): void {
		$sidecar = self::ARCHIVE . '/' . MetadataSidecars::FOLDER . '/P1.json';
		$this->executor($this->state, $this->sidecars())->apply($this->export(oldPath: '', download: true));

		self::assertStringContainsString('"title": "A"', $this->storage->files[$sidecar]);

		$this->arrange(false);
		$unchanged = new Unchanged(1, ['missing_runs' => 1], DocumentContext::export(1, $this->exported(), self::OLD), new Copy(['id' => 1, 'title' => 'A'], self::OLD, 'old fingerprint', false));
		$this->executor($this->state, $this->sidecars())->apply($unchanged);

		self::assertArrayHasKey($sidecar, $this->storage->files);

		$this->arrange(true);
		$this->exported();
		$this->executor($this->state, $this->sidecars())->apply($unchanged);

		self::assertArrayNotHasKey($sidecar, $this->storage->files, 'A dry-run writes no metadata.');
	}

	public function testAnExportBeyondTheBatchIsRefused(): void {
		$this->apply($this->export(oldPath: '', download: true));

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('The batch of 1 has no place for another export.');

		$this->apply($this->export(oldPath: '', download: true));
	}

	public function testAnUnknownActionIsRefused(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessageMatches('/^An export has no action .*ExportAction@anonymous.*\.$/s');

		$this->apply(new class implements ExportAction {
		});
	}

	/**
	 * The export of the document P1 to NEW, with the copy that the state records at $oldPath.
	 *
	 * @param array<string, int|string|null> $checksum
	 */
	public function export(string $oldPath, bool $move = false, bool $download = false, bool $ownTarget = false, array $checksum = []): ExportCopy {
		$entry = $oldPath !== '' ? $this->exported(['path' => $oldPath]) : null;

		return new ExportCopy(DocumentContext::export(1, $entry, self::NEW), ['id' => 1, 'title' => 'A'], self::NEW, $oldPath, false, 'archive:m1', 'old fingerprint', $checksum, $move, $download, $ownTarget);
	}

	/**
	 * The document P1, exported to OLD, and its state.
	 *
	 * @param array<string, int|string|null> $values
	 * @return array<string, int|string|null>
	 */
	public function exported(array $values = []): array {
		$entry = array_merge(['document_id' => 1, 'path' => self::OLD, 'fingerprint' => 'old fingerprint', 'state' => 'active', 'missing_runs' => 0, 'last_error' => null], $values);
		$this->state->exports[1] = $entry;
		$this->storage->files[(string)$entry['path']] = self::CONTENT;

		return $entry;
	}

	/** Fresh doubles, a report of a run or a dry-run and a batch of $batchSize. */
	private function arrange(bool $dryRun, int $batchSize = 1): void {
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->paperless = new TestPaperlessClient();
		$this->paperless->contents[1] = self::CONTENT;
		$this->clock = new TestClock();
		$this->report = new SyncReport($dryRun);
		$this->batch = new SyncBatch($batchSize);
	}

	/** Applies the action with the states as they are now, as the run reads them at its start. */
	private function apply(ExportAction $action): void {
		$this->executor($this->state)->apply($action);
	}

	private function sidecars(): MetadataSidecars {
		return new MetadataSidecars($this->storage, TestConfig::of(), $this->report, self::ARCHIVE, [], [], [], [], [], static fn (\Throwable $exception): bool => false);
	}

	private function executor(SyncStateRepositoryInterface $repository, ?MetadataSidecars $sidecars = null): ExportExecutor {
		return new ExportExecutor(
			TestConfig::of(['batchSize' => $this->batch->size]),
			$this->report,
			new ExportStates($repository, TestStorage::USER),
			$this->batch,
			$this->storage,
			$this->paperless,
			$this->logger,
			$this->clock,
			self::ARCHIVE,
			$sidecars,
		);
	}

	/** @return array<string, bool|int> the counts of the report, without those of the kind of run and the pruned folders, which a dry-run doesn't prune */
	private static function counts(SyncReport $report): array {
		return array_diff_key($report->summary(), array_flip(['dryRun', 'startedAt', 'completedAt', 'unsavedSettings', 'foldersPruned']));
	}
}
