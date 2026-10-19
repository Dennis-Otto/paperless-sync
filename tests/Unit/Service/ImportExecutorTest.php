<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Model\Plan\ForgetImport;
use OCA\PaperlessSync\Model\Plan\ImportAction;
use OCA\PaperlessSync\Model\Plan\ImportCheckFailed;
use OCA\PaperlessSync\Model\Plan\ImportContext;
use OCA\PaperlessSync\Model\Plan\ImportFailed;
use OCA\PaperlessSync\Model\Plan\ImportSucceeded;
use OCA\PaperlessSync\Model\Plan\RetryUnfinished;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\Upload;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ImportExecutor;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PaperlessUploadException;
use OCA\PaperlessSync\Service\SyncBatch;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The executor of the inbox is the one place where a dry-run and a run of the inbox differ: a run
 * uploads, moves and deletes the files and saves the states of their uploads, and a dry-run
 * reports the same, in the same batch, and changes nothing.
 */
#[AllowMockObjectsWithoutExpectations]
final class ImportExecutorTest extends TestCase {
	private const FILE = TestConfig::INBOX . '/Steuern/a.pdf';
	private const ERROR = TestConfig::ERRORS . '/Steuern/a.pdf';
	private const CONTENT = '%PDF-a';
	/** The writes of the storage, which a dry-run never calls. */
	private const WRITES = ['writeStream', 'writeText', 'move', 'delete', 'pruneEmptyParents'];

	private TestStorage $storage;
	private TestStateRepository $state;
	private TestPaperlessClient $paperless;
	private TestClock $clock;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SyncReport $report;
	private SyncBatch $batch;
	private ImportExecutor $executor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->arrange(false);
	}

	/**
	 * Each action of the plan of the inbox, which a dry-run reports as the run that follows it does,
	 * with the settings that it needs.
	 *
	 * @return array<string, array{ImportAction, array<string, bool|int|string>}>
	 */
	public static function actions(): array {
		$context = new ImportContext(self::FILE, ['path' => self::FILE, 'status' => 'pending', 'last_error' => null]);

		return [
			'an upload' => [self::upload(), []],
			'an upload with the tags of its folder, which the run creates' => [self::upload(), ['inboxFolderTags' => true, 'inboxCreateTags' => true]],
			'an unchanged file' => [new Unchanged(), []],
			'a skipped file' => [new Skipped(), []],
			'the state of a file that is gone' => [new ForgetImport(self::FILE), []],
			'an import that succeeded, whose file is deleted' => [new ImportSucceeded($context, 'etag', true, 7), []],
			'an import that succeeded, whose file stays' => [new ImportSucceeded($context, 'etag', true, 7), ['deleteInboxAfterSuccess' => false]],
			'an import of a file that changed since its upload' => [new ImportSucceeded($context, 'etag', false, 7), []],
			'an import that failed' => [new ImportFailed($context, self::ERROR, 'Broken PDF', "Paperless import failed\n"), []],
			'an import that Paperless has not finished a day after the upload' => [new ImportFailed($context, self::ERROR, 'Not finished', "Paperless import not finished\n", 'task-1', 'Paperless has not finished it'), []],
			'a check that failed' => [new ImportCheckFailed($context, new RuntimeException('Paperless is down')), []],
		];
	}

	/** @param array<string, bool|int|string> $settings */
	#[DataProvider('actions')]
	public function testADryRunReportsTheChangeOfTheRunAndMakesNone(ImportAction $action, array $settings): void {
		$this->arrange(true, $settings);
		$this->executor->apply($action);
		$dryRun = $this->report;
		$writes = array_values(array_intersect($this->storage->methods(), self::WRITES));
		$imports = $this->state->imports;
		$tags = $this->paperless->tagCreations;

		$this->arrange(false, $settings);
		$this->executor->apply($action);

		self::assertSame($this->report->actions, $dryRun->actions);
		self::assertSame(self::counts($this->report), self::counts($dryRun));
		self::assertSame([], $writes, 'A dry-run writes no file.');
		self::assertSame([self::FILE => ['path' => self::FILE, 'status' => 'pending']], $imports, 'A dry-run writes no state.');
		self::assertSame(0, $tags, 'A dry-run creates no tag.');
		self::assertSame([], $dryRun->importResults(), 'The activity of a dry-run names no import.');
	}

	public function testAnUploadSavesItsTaskForTheNextRun(): void {
		$this->executor->apply(self::upload());

		self::assertSame(['IMPORT: ' . self::FILE], $this->report->actions);
		self::assertSame(['a.pdf' => self::CONTENT], $this->paperless->uploads);
		self::assertSame(['status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => $this->clock->time], array_intersect_key($this->state->imports[self::FILE], array_flip(['task_id', 'status', 'submitted_at'])));
		self::assertSame([], array_filter($this->storage->openedStreams, 'is_resource'), 'The upload closes the file.');
	}

	/** Paperless would refuse the same file again, so it waits until it changes, and the other uploads go on. */
	public function testARefusedUploadWaitsUntilTheFileChanges(): void {
		$this->paperless->uploadFailures['a.pdf'] = new PaperlessUploadException('Unsupported file type', true, 400);
		$this->logger->expects(self::once())->method('warning')->with('Paperless refused the inbox file {path}; it stays in the inbox until it changes: {reason}', [
			'path' => self::FILE,
			'reason' => 'Unsupported file type',
			'status' => 400,
		]);

		$this->executor->apply(self::upload());

		self::assertSame(['IMPORT REJECTED: ' . self::FILE . ': Unsupported file type'], $this->report->actions);
		self::assertSame('rejected', $this->state->imports[self::FILE]['status']);
		self::assertSame([['outcome' => SyncReport::IMPORT_REJECTED, 'path' => self::FILE, 'document' => null, 'reason' => 'Unsupported file type']], $this->report->importResults());
		self::assertFalse($this->batch->stopped());
		self::assertSame([], array_filter($this->storage->openedStreams, 'is_resource'), 'A failed upload closes the file as well.');
	}

	/** A Paperless that is down or overloaded would fail every other upload as well, so they wait for the next run. */
	public function testAnUploadThatMayPassStopsTheOtherUploads(): void {
		$this->paperless->uploadFailures['a.pdf'] = new PaperlessUploadException('Paperless is busy', false, 503);
		$this->logger->expects(self::once())->method('log')->with(LogLevel::WARNING, self::anything(), self::callback(static fn (array $context): bool => $context['attempts'] === 1 && $context['minutes'] === 15));

		$this->executor->apply(self::upload());

		self::assertSame(['ERROR: Import ' . self::FILE . ': Paperless is busy (attempt 1, the next in 15 minutes)'], $this->report->actions);
		self::assertSame(['status' => 'retry', 'attempts' => 1, 'retry_at' => $this->clock->time + 900], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'attempts', 'retry_at'])));
		self::assertTrue($this->batch->stopped());
	}

	/** A file that keeps failing waits twice as long after each failure, and only its first failure is a warning. */
	public function testAnUploadThatFailsAgainWaitsLonger(): void {
		$this->storage->readException = new RuntimeException('The file is locked');
		$this->logger->expects(self::once())->method('log')->with(LogLevel::INFO, self::anything(), self::callback(static fn (array $context): bool => $context['attempts'] === 2 && $context['minutes'] === 30));

		$this->executor->apply(self::upload(attempts: 1));

		self::assertSame($this->clock->time + 1800, $this->state->imports[self::FILE]['retry_at']);
		self::assertFalse($this->batch->stopped(), 'A file that can\'t be read concerns only itself.');
	}

	/** Without the list of the tags, every other file would fail the same way. */
	public function testTagsThatCannotBeReadStopTheUploads(): void {
		$this->arrange(true, ['inboxFolderTags' => true]);
		$this->paperless->tagsException = new RuntimeException('Paperless is down');

		$this->executor->apply(self::upload());

		self::assertSame(['ERROR: Import ' . self::FILE . ': Paperless is down'], $this->report->actions, 'A dry-run schedules no next attempt.');
		self::assertTrue($this->batch->stopped());

		$this->arrange(false, ['inboxFolderTags' => true]);
		$this->paperless->tagsException = new RuntimeException('Paperless is down');

		$this->executor->apply(self::upload());

		self::assertSame(['ERROR: Import ' . self::FILE . ': Paperless is down (attempt 1, the next in 15 minutes)'], $this->report->actions);
		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertTrue($this->batch->stopped());
	}

	/** A tag that Paperless doesn't create fails the file, and the others go on, unless Paperless could not be reached. */
	public function testATagThatCannotBeCreatedFailsOnlyItsFile(): void {
		$this->arrange(false, ['inboxFolderTags' => true, 'inboxCreateTags' => true]);
		$this->paperless->createTagException = new RuntimeException('Permission denied');

		$this->executor->apply(self::upload());

		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertFalse($this->batch->stopped());

		$this->arrange(false, ['inboxFolderTags' => true, 'inboxCreateTags' => true]);
		$this->paperless->createTagException = new PaperlessRequestException('Paperless could not be reached');

		$this->executor->apply(self::upload());

		self::assertTrue($this->batch->stopped());
	}

	public function testTheFileOfASucceededImportIsDeletedWithTheFoldersThatItLeavesEmpty(): void {
		$this->executor->apply(new ImportSucceeded(new ImportContext(self::FILE, []), 'etag', true, 7));

		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P7'], $this->report->actions);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);
		self::assertSame([], $this->state->imports);
		self::assertSame([[self::FILE, TestConfig::INBOX]], $this->storage->prunes);
	}

	public function testTheFileOfAFailedImportMovesToTheErrorFolderWithItsReason(): void {
		$this->logger->expects(self::never())->method('warning');

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), self::ERROR, 'Broken PDF', "Paperless import failed\n"));

		self::assertSame(['IMPORT ERROR: ' . self::FILE . ' -> ' . self::ERROR], $this->report->actions);
		self::assertSame([self::ERROR => self::CONTENT, self::ERROR . '.error.txt' => "Paperless import failed\n"], $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	/** The log says why a file moved to the error folder whose task Paperless still lists. */
	public function testAnUnfinishedImportInTheErrorFolderIsLogged(): void {
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('it moves to the error folder, as Paperless may still import it'), [
			'path' => self::FILE,
			'error' => 'Paperless has not finished it',
			'task' => 'task-1',
		]);

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), self::ERROR, 'Not finished', "Paperless import not finished\n", 'task-1', 'Paperless has not finished it'));
	}

	/** A dry-run says that the run uploads a lost file again, and the run schedules its upload. */
	public function testAFileWhoseTaskPaperlessLostGoesUpAgain(): void {
		$retry = new RetryUnfinished(new ImportContext(self::FILE, []), 'etag', 1, 'task-1', 'Paperless lost the task');
		$this->arrange(true);

		$this->executor->apply($retry);

		self::assertSame(['ERROR: Import ' . self::FILE . ': Paperless lost the task; a run uploads it again'], $this->report->actions);
		self::assertSame('pending', $this->state->imports[self::FILE]['status']);

		$this->arrange(false);
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('it is uploaded again in {minutes} minutes'), self::callback(static fn (array $context): bool => $context['minutes'] === 15));

		$this->executor->apply($retry);

		self::assertSame(['ERROR: Import ' . self::FILE . ': Paperless lost the task (attempt 1, the next in 15 minutes)'], $this->report->actions);
		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
	}

	/** An end of an upload that fails, such as the move to the error folder, keeps it pending, and a lasting failure is logged once. */
	public function testAFailedEndOfAnUploadKeepsItPending(): void {
		$this->storage->moveException = new RuntimeException('The error folder is read-only');
		$this->logger->expects(self::once())->method('warning')->with('Paperless Sync could not check the import of the inbox file {path}: {error}', [
			'path' => self::FILE,
			'error' => 'The error folder is read-only',
		]);

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, ['last_error' => null]), self::ERROR, 'Broken PDF', ''));
		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, ['last_error' => 'The error folder is read-only']), self::ERROR, 'Broken PDF', ''));

		self::assertSame(array_fill(0, 2, 'ERROR: Import task ' . self::FILE . ': The error folder is read-only'), $this->report->actions);
		self::assertSame(['path' => self::FILE, 'status' => 'pending', 'last_error' => 'The error folder is read-only'], $this->state->imports[self::FILE]);
	}

	/** The tags that Paperless lacks are named once for the run; only a run, which sent the files without them, logs it. */
	public function testTheTagsThatPaperlessLacksAreNamedOnce(): void {
		$this->arrange(true, ['inboxTags' => 'Steuer, Bank']);
		$this->executor->apply(self::upload());
		$this->executor->finish();

		self::assertSame(['IMPORT: ' . self::FILE, 'TAGS UNKNOWN: Paperless shows the account no tags named Steuer, Bank, so the files of the inbox go to Paperless without them. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".'], $this->report->actions);

		$this->arrange(false, ['inboxTags' => 'Steuer']);
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('no tags named {names}'), ['names' => 'Steuer']);
		$this->executor->apply(self::upload());
		$this->executor->finish();

		self::assertSame('TAGS UNKNOWN: Paperless shows the account no tag named Steuer, so the files of the inbox go to Paperless without it. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".', $this->report->actions[1]);
	}

	public function testARunWithoutUnknownTagsNamesNone(): void {
		$this->executor->finish();

		self::assertSame([], $this->report->actions);
	}

	public function testAnUploadBeyondTheBatchIsRefused(): void {
		$this->executor->apply(self::upload());

		$this->expectException(LogicException::class);

		$this->executor->apply(self::upload());
	}

	public function testAnUnknownActionIsRefused(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessageMatches('/^The inbox has no action .*ImportAction@anonymous.*\.$/s');

		$this->executor->apply(new class implements ImportAction {
		});
	}

	/** The upload of the file of the tests, in the folder Steuern. */
	private static function upload(int $attempts = 0): Upload {
		return new Upload(self::FILE, 'a.pdf', hash('sha256', self::CONTENT), $attempts, 'Steuern');
	}

	/**
	 * Fresh doubles with the file of the tests and its pending upload, and the executor of a run or
	 * a dry-run with the settings and a batch of one.
	 *
	 * @param array<string, bool|int|string> $settings
	 */
	private function arrange(bool $dryRun, array $settings = []): void {
		$this->storage = new TestStorage();
		$this->storage->files[self::FILE] = self::CONTENT;
		$this->state = new TestStateRepository();
		$this->state->imports[self::FILE] = ['path' => self::FILE, 'status' => 'pending'];
		$this->paperless = new TestPaperlessClient();
		$this->clock = new TestClock();
		$this->report = new SyncReport($dryRun);
		$this->batch = new SyncBatch(1);
		$this->executor = new ImportExecutor(TestConfig::of($settings + ['batchSize' => 1]), $this->report, $this->state, $this->batch, $this->storage, $this->paperless, $this->logger, $this->clock, TestConfig::INBOX);
	}

	/** @return array<string, bool|int> the counts of the report, without those of the kind of run and the pruned folders, which a dry-run doesn't prune */
	private static function counts(SyncReport $report): array {
		return array_diff_key($report->summary(), array_flip(['dryRun', 'startedAt', 'completedAt', 'unsavedSettings', 'foldersPruned']));
	}
}
