<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\PerUserScope;
use OCA\PaperlessSync\Model\Plan\ForgetImport;
use OCA\PaperlessSync\Model\Plan\ImportAction;
use OCA\PaperlessSync\Model\Plan\ImportCheckFailed;
use OCA\PaperlessSync\Model\Plan\ImportContext;
use OCA\PaperlessSync\Model\Plan\ImportFailed;
use OCA\PaperlessSync\Model\Plan\ImportSucceeded;
use OCA\PaperlessSync\Model\Plan\MoveImport;
use OCA\PaperlessSync\Model\Plan\RetryUnfinished;
use OCA\PaperlessSync\Model\Plan\SetAside;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\Upload;
use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ImportExecutor;
use OCA\PaperlessSync\Service\PaperlessHostException;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PaperlessNotFoundException;
use OCA\PaperlessSync\Service\PaperlessOwnerException;
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
	private const VIDEO = TestConfig::INBOX . '/Aus Videos/film.mp4';
	/** The ID of the file of the tests in Nextcloud. */
	private const FILE_ID = 42;
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
			'a file set aside' => [new SetAside(self::VIDEO, 'film.mp4', 'video'), []],
			'the state of a file that is gone' => [new ForgetImport(self::FILE), []],
			'the state of a file that was renamed' => [new MoveImport(self::FILE, TestConfig::INBOX . '/Steuern/b.pdf'), []],
			'an import that succeeded, whose file is deleted' => [new ImportSucceeded($context, 'etag', true, 7, true), []],
			'an import that succeeded, whose file stays' => [new ImportSucceeded($context, 'etag', true, 7, true), ['deleteInboxAfterSuccess' => false]],
			'an import of a file that changed since its upload' => [new ImportSucceeded($context, 'etag', false, 7, true), []],
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
		self::assertSame(['status' => 'pending', 'file_id' => self::FILE_ID, 'task_id' => 'task-1', 'submitted_at' => $this->clock->time], array_intersect_key($this->state->imports[self::FILE], array_flip(['file_id', 'task_id', 'status', 'submitted_at'])));
		self::assertSame([hash('sha256', self::CONTENT)], $this->paperless->searches, 'The upload asks Paperless for the content first.');
		self::assertSame([], array_filter($this->storage->openedStreams, 'is_resource'), 'The upload closes the file.');
	}

	/** The state of a file that was renamed or moved inside the inbox follows it to its new path. */
	public function testTheStateOfARenamedFileFollowsIt(): void {
		$to = TestConfig::INBOX . '/Steuern/b.pdf';

		$this->executor->apply(new MoveImport(self::FILE, $to));

		self::assertSame([$to => ['path' => $to, 'status' => 'pending']], $this->state->imports);
		self::assertSame([], $this->report->actions);
	}

	/**
	 * A file whose content Paperless has already, such as one whose upload Paperless received
	 * although its answer never came, ends as imported without a second upload, which would make
	 * a duplicate, and leaves the inbox as an imported file does.
	 */
	public function testAFileThatPaperlessHasAlreadyIsNotUploadedAgain(): void {
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->logger->expects(self::once())->method('info')->with('Paperless already has the content of the inbox file {path} as document {document}, so it was not uploaded again', ['path' => self::FILE, 'document' => 9]);

		$this->executor->apply(self::upload(attempts: 1));

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame([], $this->paperless->downloadRequests, 'A document that the SHA-256 found has the content of the file.');
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
		self::assertSame([['outcome' => SyncReport::IMPORTED, 'path' => self::FILE, 'document' => 9, 'reason' => '']], $this->report->importResults());
		self::assertSame([0, 1], [$this->report->importsSubmitted, $this->report->importsSucceeded]);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);
		self::assertSame([], $this->state->imports);
		self::assertSame([[self::FILE, TestConfig::INBOX]], $this->storage->prunes);
		self::assertSame([], array_filter($this->storage->openedStreams, 'is_resource'), 'The look at the content closes the file.');
	}

	/** A kept file whose content Paperless has stays in the inbox as imported, and its document keeps its owner. */
	public function testAKeptFileThatPaperlessHasAlreadyStaysAsImported(): void {
		$this->arrange(false, ['deleteInboxAfterSuccess' => false], 3);
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = ['id' => 9, 'owner' => 3];

		$this->executor->apply(self::upload());

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE]);
		self::assertSame(['status' => 'success', 'etag' => hash('sha256', self::CONTENT), 'file_id' => self::FILE_ID, 'last_error' => null], array_intersect_key($this->state->imports[self::FILE], array_flip(['etag', 'file_id', 'status', 'last_error'])));
		self::assertSame([], $this->paperless->ownerChanges, 'The document is the inbox\'s already, so its owner stays.');
	}

	/**
	 * In per-user ownership, the account of the app sees the documents of every user, so a document
	 * with the content of the file that isn't the inbox's gives the file no reason to leave the
	 * inbox: it goes up as the document of the inbox's user.
	 *
	 * @return array<string, array{array<string, mixed>|null}>
	 */
	public static function documentsThatAreNotTheInboxs(): array {
		return [
			'a document of another user' => [['id' => 9, 'owner' => 4]],
			'a document without an owner' => [['id' => 9, 'owner' => null]],
			'a document that Paperless does not show' => [null],
		];
	}

	/** @param array<string, mixed>|null $document */
	#[DataProvider('documentsThatAreNotTheInboxs')]
	public function testAFileWhoseContentIsAnotherUsersDocumentIsUploaded(?array $document): void {
		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		if ($document !== null) {
			$this->paperless->documents[] = $document;
		}

		$this->executor->apply(self::upload());

		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE], 'The file stays until the upload is imported.');
		self::assertSame(['IMPORT: ' . self::FILE], $this->report->actions);
		self::assertSame('pending', $this->state->imports[self::FILE]['status']);
		self::assertSame([], $this->paperless->ownerChanges, 'The other user keeps the document.');
	}

	/**
	 * In per-user ownership, a document of the account of the app that Paperless added after the
	 * first upload of the file is that upload, whose answer never came: the file ends as imported,
	 * and the document gets its owner; a dry-run gives none.
	 */
	public function testAFileWhoseUploadTheAccountOwnsEndsAsImportedAndItsDocumentGetsItsOwner(): void {
		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = self::accountDocument();

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame([[9, 3]], $this->paperless->ownerChanges);
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);

		$this->arrange(true, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = self::accountDocument();

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
	}

	/**
	 * A document of the account of the app is the file's own upload only when Paperless added it at
	 * or after the first upload of the file: otherwise it is the target user's, such as an upload
	 * of the target user or a document that the account owns, and the file goes up as a document of
	 * its own, without a download of the original, and the document keeps its owner.
	 *
	 * @return array<string, array{Upload, array<string, mixed>}>
	 */
	public static function documentsOfTheAccountWithoutProof(): array {
		return [
			'a file that never went up' => [self::upload(), self::accountDocument()],
			'a file whose upload failed before it reached Paperless' => [self::upload(attempts: 1), self::accountDocument()],
			'a document that Paperless added before the first upload' => [self::upload(attempts: 1, firstAttempt: TestClock::START + 301), self::accountDocument()],
			'a document without the time it was added' => [self::upload(attempts: 1, firstAttempt: TestClock::START), ['id' => 9, 'owner' => 1]],
		];
	}

	/** @param array<string, mixed> $document */
	#[DataProvider('documentsOfTheAccountWithoutProof')]
	public function testAFileWithTheContentOfADocumentOfTheAccountGoesUpWithoutProofOfItsUpload(Upload $upload, array $document): void {
		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;
		$this->paperless->documents[] = $document;

		$this->executor->apply($upload);

		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame([], $this->paperless->downloadRequests, 'A document that does not count costs no download.');
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE]);
	}

	/** A document that Paperless added in the same second as the first upload may be that upload. */
	public function testADocumentOfTheAccountAddedAtTheFirstUploadIsTheFilesOwn(): void {
		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = self::accountDocument();

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START + 300));

		self::assertSame([[9, 3]], $this->paperless->ownerChanges);
		self::assertSame([], $this->paperless->uploadAttempts);
	}

	/**
	 * A document of the user's own user of Paperless counts, with its owner as a number or as a
	 * string of digits, and so does one of a user of Paperless whose documents go to the inbox's
	 * user; it keeps its owner.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function ownersOfTheInboxsDocuments(): array {
		return [
			'the user of Paperless of the inbox' => [3],
			'the same as digits' => ['3'],
		];
	}

	#[DataProvider('ownersOfTheInboxsDocuments')]
	public function testADocumentOfTheInboxsUserCountsAndKeepsItsOwner(mixed $owner): void {
		$this->arrange(false, [], 3);
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = ['id' => 9, 'owner' => $owner];

		$this->executor->apply(self::upload());

		self::assertSame([[], []], [$this->paperless->uploadAttempts, $this->paperless->ownerChanges]);
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
		self::assertSame(0, $this->paperless->accountReads, 'A document of the inbox\'s user needs no account.');
	}

	/**
	 * While an upload of another user waits for its owner, a document of the account may be that
	 * upload's, so it counts for nobody: the file waits, neither uploaded nor deleted, also as its
	 * own lost upload; without the ID of the account, every document of another owner does.
	 *
	 * @return array<string, array{int|false, array<string, mixed>}>
	 */
	public static function heldDocuments(): array {
		return [
			'a document of the account' => [1, self::accountDocument()],
			'any other owner while Paperless does not name its account' => [false, ['id' => 9, 'owner' => 4]],
		];
	}

	/** @param array<string, mixed> $document */
	#[DataProvider('heldDocuments')]
	public function testAFileWithTheContentOfAHeldDocumentWaits(int|false $held, array $document): void {
		$this->arrange(false, [], 3, $held);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;
		$this->paperless->documents[] = $document;

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([[], [], []], [$this->paperless->uploadAttempts, $this->paperless->ownerChanges, $this->paperless->downloadRequests]);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE]);
		self::assertSame(['status' => 'retry', 'attempts' => 2], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'attempts'])));
		self::assertSame(TestClock::START, $this->state->imports[self::FILE]['first_attempt_at'], 'The wait keeps the time of the upload before it.');
		self::assertSame(['Import ' . self::FILE . ': Paperless has the content of the file as the document 9, which may be that of an upload that waits for its owner, so the file waits until that upload ends (attempt 2, the next in 30 minutes)'], $this->report->errorMessages);
		self::assertFalse($this->batch->stopped(), 'The other files go on.');
	}

	/** A document of the user's own user of Paperless is never held, also while Paperless does not name its account. */
	public function testADocumentOfTheInboxsUserIsNeverHeld(): void {
		$this->arrange(false, [], 3, false);
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = ['id' => 9, 'owner' => 3];

		$this->executor->apply(self::upload());

		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
	}

	/**
	 * In single ownership, every document is the account's, so a document with the content of the
	 * file counts without a look at its owner.
	 */
	public function testSingleOwnershipReadsNoOwner(): void {
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = ['id' => 9, 'owner' => 4];

		$this->executor->apply(self::upload());

		self::assertSame([[], 0], [$this->paperless->documentReads, $this->paperless->accountReads]);
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
	}

	/**
	 * An upload that may have reached Paperless keeps the time of the first one in the state of the
	 * file, across its attempts, so that a document of the account that Paperless added since then
	 * can be shown to be the file's; a failure before the upload and a refusal of Paperless don't.
	 */
	public function testTheStateKeepsTheTimeOfTheFirstUploadThatMayHaveReachedPaperless(): void {
		$this->paperless->uploadException = new RuntimeException('Paperless did not answer in time');

		$this->executor->apply(self::upload());

		self::assertSame(['status' => 'retry', 'first_attempt_at' => TestClock::START], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'first_attempt_at'])));

		$this->arrange(false);
		$this->clock->advance(900);

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame(['status' => 'pending', 'first_attempt_at' => TestClock::START], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'first_attempt_at'])), 'A later upload keeps the time of the first.');

		$this->arrange(false);
		$this->paperless->uploadException = new PaperlessUploadException('Paperless is overloaded: HTTP 503', false, 503);

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START - 60));

		self::assertSame(TestClock::START - 60, $this->state->imports[self::FILE]['first_attempt_at']);

		$this->arrange(false);
		$this->storage->readException = new RuntimeException('The file is locked');

		$this->executor->apply(self::upload());

		self::assertSame(0, $this->state->imports[self::FILE]['first_attempt_at'], 'A file that could not be read never reached Paperless.');

		$this->arrange(false);
		$this->paperless->uploadException = new PaperlessUploadException('Paperless refused the file: HTTP 400', true, 400);

		$this->executor->apply(self::upload());

		self::assertSame('rejected', $this->state->imports[self::FILE]['status']);
		self::assertArrayNotHasKey('first_attempt_at', $this->state->imports[self::FILE], 'A refusal created no document.');
	}

	/** Without the owner of the document, the run can't tell whose it is, so the file waits. */
	public function testAFileWaitsWhenPaperlessDoesNotNameTheOwnerOfItsDocument(): void {
		foreach ([['id' => 9], ['id' => 9, 'owner' => 'alice']] as $document) {
			$this->arrange(false, [], 3);
			$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
			$this->paperless->documents[] = $document;

			$this->executor->apply(self::upload());

			self::assertSame([], $this->paperless->uploadAttempts);
			self::assertSame('retry', $this->state->imports[self::FILE]['status']);
			self::assertSame(['Import ' . self::FILE . ': Paperless did not name the owner of the document 9. (attempt 1, the next in 15 minutes)'], $this->report->errorMessages);
		}
	}

	/** Without the owner of the document that Paperless has, or without the new owner, the file waits, as for a failed upload. */
	public function testAFileWaitsWhenPaperlessCannotSayWhoseDocumentItHas(): void {
		$this->arrange(false, [], 3);
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documentException = new RuntimeException('Paperless answered with HTTP 500');

		$this->executor->apply(self::upload());

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertArrayHasKey(self::FILE, $this->storage->files);

		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->paperless->documents[] = self::accountDocument();
		$this->paperless->ownerException = new RuntimeException('Paperless did not make the user 3 the owner of the document 9: HTTP 403.');

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertArrayHasKey(self::FILE, $this->storage->files);
	}

	/** A dry-run reports a file that Paperless has as the run does, and changes nothing. */
	public function testADryRunReportsAFileThatPaperlessHasAlready(): void {
		$this->arrange(true);
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;

		$this->executor->apply(self::upload());

		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE]);
		self::assertSame([self::FILE => ['path' => self::FILE, 'status' => 'pending']], $this->state->imports);
		self::assertSame([], $this->report->importResults());
	}

	/**
	 * Paperless-ngx 2.x knows a document only by the MD5 of its original, so a document that the
	 * MD5 alone found has the content of the file only when its original has the SHA-256 of the
	 * file, which one download of the original tells, in a dry-run too.
	 */
	public function testADocumentFoundByTheMd5AloneCountsOnlyWithTheSameSha256(): void {
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;

		$this->executor->apply(self::upload());

		self::assertSame([[9, true]], $this->paperless->downloadRequests);
		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);

		$this->arrange(true);
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;

		$this->executor->apply(self::upload());

		self::assertSame([[9, true]], $this->paperless->downloadRequests, 'The download only reads, so a dry-run makes it too.');
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P9'], $this->report->actions);
	}

	/**
	 * A document that only shares the MD5 of the file is another document: the file goes up as its
	 * own, stays in the inbox until Paperless has answered, and isn't reported as imported.
	 */
	public function testAFileWhoseMd5AloneADocumentWithOtherContentHasIsUploaded(): void {
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = '%PDF-other';

		$this->executor->apply(self::upload());

		self::assertSame([[9, true]], $this->paperless->downloadRequests);
		self::assertSame(['a.pdf' => self::CONTENT], $this->paperless->uploads);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE], 'The file stays until Paperless has imported it.');
		self::assertSame(['IMPORT: ' . self::FILE], $this->report->actions);
		self::assertSame([], $this->report->importResults());
		self::assertSame([1, 0], [$this->report->importsSubmitted, $this->report->importsSucceeded]);
		self::assertSame('pending', $this->state->imports[self::FILE]['status']);

		$this->arrange(true);
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = '%PDF-other';

		$this->executor->apply(self::upload());

		self::assertSame(['IMPORT: ' . self::FILE], $this->report->actions, 'A dry-run reports the upload of the run.');
	}

	/**
	 * The original decides, not the archived version that Paperless made of it: the original has
	 * the bytes of the file that went up.
	 *
	 * @return array<string, array{string, string, list<string>}>
	 */
	public static function originalsAndArchivedVersions(): array {
		return [
			'the original has the content of the file' => [self::CONTENT, '%PDF-archive', []],
			'only the archived version has it' => ['%PDF-other', self::CONTENT, ['a.pdf']],
		];
	}

	/** @param list<string> $uploads */
	#[DataProvider('originalsAndArchivedVersions')]
	public function testTheOriginalNotTheArchivedVersionConfirmsAMatchByTheMd5(string $original, string $archive, array $uploads): void {
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->originalContents[9] = $original;
		$this->paperless->contents[9] = $archive;

		$this->executor->apply(self::upload());

		self::assertSame([[9, true]], $this->paperless->downloadRequests);
		self::assertSame($uploads, $this->paperless->uploadAttempts);
	}

	/**
	 * In per-user ownership, the owner of a document that the MD5 alone found is read first: a
	 * document of another user costs no download, and a document of the account of the app gets
	 * the inbox's user only once its original has the content of the file.
	 */
	public function testADocumentOfTheAccountFoundByTheMd5AloneKeepsItsOwnerUntilConfirmed(): void {
		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = '%PDF-other';
		$this->paperless->documents[] = self::accountDocument();

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([], $this->paperless->ownerChanges, 'Another content keeps the account as the owner.');
		self::assertSame([[9, true]], $this->paperless->downloadRequests);
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE]);

		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;
		$this->paperless->documents[] = self::accountDocument();
		$this->paperless->downloadException = new RuntimeException('Paperless answered with HTTP 500');

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([], $this->paperless->ownerChanges, 'Without the original, the owner stays.');
		self::assertSame('retry', $this->state->imports[self::FILE]['status']);

		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;
		$this->paperless->documents[] = self::accountDocument();

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([[9, 3]], $this->paperless->ownerChanges);
		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);

		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->contents[9] = self::CONTENT;
		$this->paperless->documents[] = ['id' => 9, 'owner' => 4];

		$this->executor->apply(self::upload());

		self::assertSame([], $this->paperless->downloadRequests, 'A document of another user is no reason for a download.');
		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
	}

	/**
	 * Without the original, the app can't tell whether Paperless has the content of the file, so
	 * the file waits, as when Paperless can't answer the search: nothing goes up and nothing leaves
	 * the inbox, and without Paperless the other files wait as well.
	 */
	public function testAFileWaitsWhenTheOriginalCannotBeDownloadedToConfirmIt(): void {
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->downloadException = new RuntimeException('Paperless answered with HTTP 500');

		$this->executor->apply(self::upload());

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame(self::CONTENT, $this->storage->files[self::FILE]);
		self::assertSame(['status' => 'retry', 'attempts' => 1], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'attempts'])));
		self::assertSame(0, $this->report->importsSucceeded);
		self::assertFalse($this->batch->stopped());

		$this->arrange(false);
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->downloadException = new PaperlessRequestException('Paperless could not be reached');

		$this->executor->apply(self::upload());

		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertTrue($this->batch->stopped());
	}

	/**
	 * An original that Paperless doesn't have (HTTP 404), such as one that was missing at the
	 * migration of its checksums, confirms nothing: the file goes up and Paperless decides, rather
	 * than waiting in every run. In per-user ownership, the document of the account keeps its owner.
	 */
	public function testAFileGoesUpWhenPaperlessHasNoOriginalToConfirmIt(): void {
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->downloadException = new PaperlessNotFoundException('Client error: 404 Not Found');

		$this->executor->apply(self::upload());

		self::assertSame([[9, true]], $this->paperless->downloadRequests);
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
		self::assertFalse($this->batch->stopped());

		$this->arrange(false, [], 3);
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', self::CONTENT)] = 9;
		$this->paperless->documents[] = self::accountDocument();
		$this->paperless->downloadException = new PaperlessNotFoundException('Client error: 404 Not Found');

		$this->executor->apply(self::upload(attempts: 1, firstAttempt: TestClock::START));

		self::assertSame([[9, true]], $this->paperless->downloadRequests);
		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
	}

	/**
	 * A file that changed carries no time of a first upload, so an attempt that fails before its
	 * upload clears the time of the old content: otherwise the next run would take a document of
	 * the account added after the old upload for the new content's own.
	 */
	public function testAFailureBeforeTheUploadOfAChangedFileClearsTheTimeOfTheOldContent(): void {
		$this->state->imports[self::FILE] = ['path' => self::FILE, 'etag' => 'old', 'status' => 'retry', 'attempts' => 1, 'first_attempt_at' => TestClock::START - 3600];
		$this->paperless->findException = new RuntimeException('Paperless answered with HTTP 500');

		$this->executor->apply(self::upload(attempts: 1));

		self::assertSame(['etag' => self::upload()->etag, 'status' => 'retry', 'first_attempt_at' => 0], array_intersect_key($this->state->imports[self::FILE], array_flip(['etag', 'status', 'first_attempt_at'])));
	}

	/** Without the answer whether Paperless has the content, the file waits, and without Paperless, so do the others. */
	public function testAFileWaitsWhenPaperlessCannotSayWhetherItHasItsContent(): void {
		$this->paperless->findException = new RuntimeException('Paperless answered with HTTP 500');

		$this->executor->apply(self::upload());

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame(['status' => 'retry', 'attempts' => 1, 'file_id' => self::FILE_ID], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'file_id', 'attempts'])));
		self::assertFalse($this->batch->stopped());

		$this->arrange(false);
		$this->paperless->findException = new PaperlessRequestException('Paperless could not be reached');

		$this->executor->apply(self::upload());

		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertTrue($this->batch->stopped());

		$this->arrange(true);
		$this->paperless->findException = new PaperlessRequestException('Paperless could not be reached');

		$this->executor->apply(self::upload());

		self::assertSame(['ERROR: Import ' . self::FILE . ': Paperless could not be reached'], $this->report->actions, 'A dry-run schedules no next attempt.');
		self::assertSame('pending', $this->state->imports[self::FILE]['status']);
	}

	/** A file that Paperless has, but that can't leave the inbox, tries again later. */
	public function testAFileThatPaperlessHasAndThatCannotBeDeletedWaits(): void {
		$this->paperless->originals[hash('sha256', self::CONTENT)] = 9;
		$this->storage->deleteException = new RuntimeException('The file is locked');

		$this->executor->apply(self::upload());

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertArrayHasKey(self::FILE, $this->storage->files);
		self::assertSame('retry', $this->state->imports[self::FILE]['status']);
		self::assertSame(['ERROR: Import ' . self::FILE . ': The file is locked (attempt 1, the next in 15 minutes)'], $this->report->actions);
		self::assertSame(0, $this->report->importsSucceeded);
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
		self::assertSame(['status' => 'retry', 'attempts' => 1, 'retry_at' => $this->clock->time + 900, 'file_id' => self::FILE_ID], array_intersect_key($this->state->imports[self::FILE], array_flip(['status', 'attempts', 'retry_at', 'file_id'])));
		self::assertTrue($this->batch->stopped());
	}

	/**
	 * A Paperless that refuses the host name of its URL refuses every upload: the file waits instead
	 * of being refused for good, and the report keeps the words of the app with their setting, so
	 * that the reader gets them in their language.
	 */
	public function testAnUploadThatPaperlessRefusesTheHostNameWaitsWithTheWordsOfTheApp(): void {
		$refused = new PaperlessHostException();
		$this->paperless->uploadFailures['a.pdf'] = new PaperlessUploadException($refused->getMessage(), false, 400, $refused->runError());

		$this->executor->apply(self::upload());

		self::assertSame('retry', $this->state->imports[self::FILE]['status'], 'The file waits, although 400 is a permanent status of an upload.');
		self::assertSame($refused->getMessage(), $this->state->imports[self::FILE]['last_error']);
		self::assertEquals([new RunError('Import %1$s: %2$s (attempt %3$s, the next in %4$s minutes)', [self::FILE, $refused->runError(), '1', '15'])], $this->report->errorRecords);
		$record = $this->report->errorRecords[0]->parameters[1];
		self::assertTrue($record instanceof RunError && $record->translatable && $record->setting === PaperlessHostException::SETTING);
		self::assertTrue($this->batch->stopped(), 'Every other upload would get the same answer.');
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

	/**
	 * A file that waits for its next upload keeps an error that the app wrote as its record, which
	 * the settings page translates as in a dry-run; the state keeps it in English.
	 */
	public function testAFailedUploadKeepsTheRecordOfTheErrorOfTheApp(): void {
		$this->arrange(false, ['inboxFolderTags' => true]);
		$exception = new PaperlessLocalNetworkException('paperless.local');
		$this->paperless->tagsException = $exception;

		$this->executor->apply(self::upload());

		self::assertEquals([new RunError('Import %1$s: %2$s (attempt %3$s, the next in %4$s minutes)', [self::FILE, RunError::of($exception), '1', '15'])], $this->report->errorRecords);
		self::assertTrue($this->report->errorRecords[0]->parameters[1] instanceof RunError && $this->report->errorRecords[0]->parameters[1]->translatable);
		self::assertSame($exception->getMessage(), $this->state->imports[self::FILE]['last_error']);
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
		$this->executor->apply(new ImportSucceeded(new ImportContext(self::FILE, []), 'etag', true, 7, true));

		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P7'], $this->report->actions);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);
		self::assertSame([], $this->state->imports);
		self::assertSame([[self::FILE, TestConfig::INBOX]], $this->storage->prunes);
	}

	/** In per-user ownership, the document of an upload becomes that of the user of Paperless whose inbox held the file. */
	public function testTheDocumentOfAnUploadGetsTheOwnerOfTheInbox(): void {
		$this->arrange(false, [], 5);

		$this->executor->apply(new ImportSucceeded(new ImportContext(self::FILE, []), 'etag', true, 7, true));

		self::assertSame([[7, 5]], $this->paperless->ownerChanges);
		self::assertSame(['IMPORT SUCCESS: ' . self::FILE . ' -> P7'], $this->report->actions);
	}

	/**
	 * A document that the task of the upload doesn't name, or that a search found by the content of
	 * the file and that may be someone else's, keeps its owner, and the report says so.
	 *
	 * @return array<string, array{ImportSucceeded}>
	 */
	public static function documentsOfOthers(): array {
		$context = new ImportContext(self::FILE, []);

		return [
			'a document that Paperless did not name' => [new ImportSucceeded($context, 'etag', true, null, true)],
			'a document that a search found' => [new ImportSucceeded($context, 'etag', true, 7, false)],
		];
	}

	/** The user's own upload whose answer never came, which the end of an unfinished upload found by its content, gets its owner. */
	public function testTheLostUploadOfAnUnfinishedImportGetsTheOwnerOfTheInbox(): void {
		$this->arrange(false, [], 5);

		$this->executor->apply(new ImportSucceeded(new ImportContext(self::FILE, []), 'etag', true, 7, false, true));

		self::assertSame([[7, 5]], $this->paperless->ownerChanges);
		self::assertSame([], $this->report->errorMessages);
	}

	#[DataProvider('documentsOfOthers')]
	public function testOnlyTheDocumentOfTheUploadGetsAnOwner(ImportSucceeded $action): void {
		$this->arrange(false, [], 5);

		$this->executor->apply($action);

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame(['Import ' . self::FILE . ': Paperless did not name the document that the upload created, so its owner stays as Paperless has it'], $this->report->errorMessages);
		self::assertSame(1, $this->report->importsSucceeded, 'The import succeeded all the same.');
	}

	/**
	 * While Paperless refuses the owner for a reason that may pass, the upload stays pending, so that
	 * the next run asks again; a dry-run asks nothing.
	 */
	public function testAnOwnerThatPaperlessRefusesKeepsTheUploadPending(): void {
		$this->arrange(false, [], 5);
		$this->paperless->ownerException = new PaperlessOwnerException('Paperless did not make the user 5 the owner of the document 7: HTTP 503.', 503);

		$this->executor->apply($this->uploaded(86399));

		self::assertArrayHasKey(self::FILE, $this->storage->files);
		self::assertSame('Paperless did not make the user 5 the owner of the document 7: HTTP 503.', $this->state->imports[self::FILE]['last_error']);
		self::assertSame(0, $this->report->importsSucceeded);

		$this->arrange(true, [], 5);
		$this->executor->apply($this->uploaded(86399));
		self::assertSame([], $this->paperless->ownerChanges);
	}

	/** @return array<string, array{\Throwable, int}> a refusal of the owner, and how long ago its file went up */
	public static function lastingRefusals(): array {
		return [
			'a refusal that repeats for good' => [new PaperlessOwnerException('Paperless did not make the user 5 the owner of the document 7: HTTP 403: Insufficient permissions.', 403), 0],
			'a refusal that may pass, a day after the upload' => [new PaperlessOwnerException('Paperless did not make the user 5 the owner of the document 7: HTTP 503.', 503), 86400],
			'a failure without an answer, a day after the upload' => [new RuntimeException('Paperless did not make the user 5 the owner of the document 7: HTTP 503.'), 86400],
		];
	}

	/**
	 * A refusal of the owner that repeats for good, or that lasts a day, ends the import as one that
	 * succeeded, as the document is in Paperless: the file leaves the inbox, and the report names the
	 * owner that the document keeps as an error of this run alone (#439).
	 */
	#[DataProvider('lastingRefusals')]
	public function testAnOwnerThatPaperlessKeepsRefusingEndsTheImport(\Throwable $refusal, int $age): void {
		$this->arrange(false, [], 5);
		$this->paperless->ownerException = $refusal;
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('keeps the owner that Paperless gave it'), self::identicalTo(['path' => self::FILE, 'document' => 7, 'error' => $refusal->getMessage()]));

		$this->executor->apply($this->uploaded($age));

		$error = 'Import ' . self::FILE . ': Paperless refused to change the owner of the document 7, which keeps the owner that Paperless gave it: ' . $refusal->getMessage();
		self::assertSame([$error], $this->report->errorMessages);
		self::assertSame(['ERROR: ' . $error, 'IMPORT SUCCESS: ' . self::FILE . ' -> P7'], $this->report->actions);
		self::assertSame(1, $this->report->importsSucceeded);
		self::assertArrayNotHasKey(self::FILE, $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	/** The import of the document 7 of the file, whose upload was $ago seconds ago, as the plan of a run finds it. */
	private function uploaded(int $ago): ImportSucceeded {
		return new ImportSucceeded(new ImportContext(self::FILE, ['status' => 'pending', 'submitted_at' => $this->clock->time - $ago]), 'etag', true, 7, true);
	}

	public function testTheFileOfAFailedImportMovesToTheErrorFolderWithItsReason(): void {
		$this->logger->expects(self::never())->method('warning');

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), self::ERROR, 'Broken PDF', "Paperless import failed\n"));

		self::assertSame(['IMPORT ERROR: ' . self::FILE . ' -> ' . self::ERROR], $this->report->actions);
		self::assertSame([self::ERROR => self::CONTENT, self::ERROR . '.error.txt' => "Paperless import failed\n"], $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	/**
	 * A file of the error folder is the only original of a document that Paperless never created, so a
	 * failed file never replaces one, even with the conflict policy replace: a file that took its free
	 * name since the plan keeps it, the failed file stays in the inbox, and the next run plans another
	 * name, where both stay.
	 */
	public function testAFailedImportNeverReplacesAFileOfTheErrorFolder(): void {
		$second = TestConfig::ERRORS . '/Steuern/a (2).pdf';
		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), self::ERROR, 'Broken PDF', "first\n"));
		$this->storage->files[self::FILE] = 'second scan';
		$this->state->imports[self::FILE] = ['path' => self::FILE, 'status' => 'pending'];
		$this->logger->expects(self::once())->method('warning')->with('Paperless Sync could not check the import of the inbox file {path}: {error}', self::anything());

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), self::ERROR, 'Broken PDF', "second\n"));

		self::assertSame([self::ERROR => self::CONTENT, self::ERROR . '.error.txt' => "first\n", self::FILE => 'second scan'], $this->storage->files);
		self::assertSame('pending', $this->state->imports[self::FILE]['status']);
		self::assertSame('ERROR: Import task ' . self::FILE . ': conflict', $this->report->actions[1]);

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), $second, 'Broken PDF', "second\n"));

		self::assertSame([self::ERROR => self::CONTENT, self::ERROR . '.error.txt' => "first\n", $second => 'second scan', $second . '.error.txt' => "second\n"], $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	/** The text next to a failed file never replaces an .error.txt that appeared at its name since the plan. */
	public function testTheReasonOfAFailedImportNeverReplacesAFileOfTheErrorFolder(): void {
		$this->storage->files[self::ERROR . '.error.txt'] = "earlier\n";
		$this->logger->expects(self::once())->method('warning')->with('Paperless Sync could not check the import of the inbox file {path}: {error}', self::anything());

		$this->executor->apply(new ImportFailed(new ImportContext(self::FILE, []), self::ERROR, 'Broken PDF', "second\n"));

		self::assertSame("earlier\n", $this->storage->files[self::ERROR . '.error.txt']);
		self::assertSame('ERROR: Import task ' . self::FILE . ': conflict', $this->report->actions[0]);
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

	/** The upload of the task that Paperless lost reached Paperless, so the state keeps the time of the first upload for the next attempt. */
	public function testAFileWhoseTaskPaperlessLostKeepsTheTimeOfItsFirstUpload(): void {
		$this->executor->apply(new RetryUnfinished(new ImportContext(self::FILE, []), 'etag', 1, 'task-1', 'Paperless lost the task', TestClock::START - 86400));

		self::assertSame(TestClock::START - 86400, $this->state->imports[self::FILE]['first_attempt_at']);
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

	/** A file set aside is named and stays where it is, without an upload, a state, a batch, an error or a warning in the log. */
	public function testAFileSetAsideStaysAndIsNamed(): void {
		$this->storage->files[self::VIDEO] = 'video';
		$imports = $this->state->imports;
		$this->logger->expects(self::never())->method('warning');

		$this->executor->apply(new SetAside(self::VIDEO, 'film.mp4', 'video'));

		self::assertSame(['IMPORT SET ASIDE: ' . self::VIDEO . ': video'], $this->report->actions);
		self::assertSame([['type' => SyncReport::TYPE_SET_ASIDE, 'path' => self::VIDEO, 'values' => ['kind' => 'video']]], $this->report->entries);
		self::assertSame([1, 0, 0, 0], [$this->report->setAside, $this->report->importsSubmitted, $this->report->importsFailed, $this->report->errors]);
		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame($imports, $this->state->imports);
		self::assertSame('video', $this->storage->files[self::VIDEO]);
		self::assertSame([], array_values(array_intersect($this->storage->methods(), self::WRITES)));
		self::assertSame([], $this->report->importResults(), 'A file set aside is no import, so it has no entry in the activity stream and no notification.');
		self::assertTrue($this->batch->fits(SyncBatch::WRITE));
	}

	/** The notice names one file with the words for one, and stays out of a run that set none aside. */
	public function testTheNoticeOfOneFileSetAside(): void {
		$this->executor->apply(new SetAside(self::VIDEO, 'film.mp4', 'video'));
		$this->executor->finish();

		self::assertSame("SET ASIDE: 1 file of the inbox is a video, a program or an archive, which Paperless can't use as a document, so it stays in the inbox and doesn't go to Paperless: film.mp4. Move it out of the inbox or delete it.", $this->report->actions[1]);
		self::assertSame(['type' => SyncReport::TYPE_INBOX_SET_ASIDE, 'values' => ['names' => 'film.mp4', 'count' => 1]], $this->report->entries[1]);

		$this->arrange(false);
		$this->executor->finish();

		self::assertSame([], $this->report->actions);
	}

	/** The notice names all of the files that fit it, without a sign of more. */
	public function testTheNoticeNamesAsManyFilesAsFit(): void {
		foreach (range(1, ImportExecutor::SET_ASIDE_NAMES) as $file) {
			$this->executor->apply(new SetAside(TestConfig::INBOX . "/{$file}.zip", "{$file}.zip", 'archive'));
		}
		$this->executor->finish();

		self::assertSame(['names' => '1.zip, 2.zip, 3.zip, 4.zip, 5.zip', 'count' => 5], $this->report->entries[5]['values'] ?? null);
	}

	/** The notice stays short with hundreds of files: it counts them all and names the first, in a run and in a dry-run. */
	#[DataProvider('kindsOfRun')]
	public function testTheNoticeCountsAllFilesAndNamesTheFirst(bool $dryRun): void {
		$this->arrange($dryRun);
		foreach (range(1, 300) as $file) {
			$this->executor->apply(new SetAside(TestConfig::INBOX . "/{$file}.zip", "{$file}.zip", 'archive'));
		}
		$this->executor->finish();

		$notice = (string)end($this->report->actions);
		self::assertSame("SET ASIDE: 300 files of the inbox are videos, programs or archives, which Paperless can't use as documents, so they stay in the inbox and don't go to Paperless: 1.zip, 2.zip, 3.zip, 4.zip, 5.zip, …. Move them out of the inbox or delete them.", $notice);
		self::assertSame(300, $this->report->setAside);
		self::assertSame(200, $this->report->omittedActions);
	}

	/** @return array<string, array{bool}> */
	public static function kindsOfRun(): array {
		return ['a run' => [false], 'a dry-run' => [true]];
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
	private static function upload(int $attempts = 0, int $firstAttempt = 0): Upload {
		return new Upload(self::FILE, 'a.pdf', hash('sha256', self::CONTENT), $attempts, 'Steuern', self::FILE_ID, $firstAttempt);
	}

	/**
	 * A document of the account of the app, the user 1, that Paperless added five minutes after the
	 * start of the clock.
	 *
	 * @return array<string, mixed>
	 */
	private static function accountDocument(): array {
		return ['id' => 9, 'owner' => 1, 'added' => '2026-08-28T10:05:00+00:00'];
	}

	/**
	 * Fresh doubles with the file of the tests and its pending upload, and the executor of a run or
	 * a dry-run with the settings and a batch of one.
	 *
	 * @param array<string, bool|int|string> $settings
	 */
	private function arrange(bool $dryRun, array $settings = [], ?int $owner = null, int|false|null $held = null): void {
		$this->storage = new TestStorage();
		$this->storage->files[self::FILE] = self::CONTENT;
		$this->state = new TestStateRepository();
		$this->state->imports[self::FILE] = ['path' => self::FILE, 'status' => 'pending'];
		$this->paperless = new TestPaperlessClient();
		$this->clock = new TestClock();
		$this->report = new SyncReport($dryRun);
		$this->batch = new SyncBatch(1);
		$this->executor = new ImportExecutor(TestConfig::of($settings + ['batchSize' => 1]), $this->report, $this->state, $this->batch, $this->storage, $this->paperless, $this->logger, $this->clock, TestConfig::INBOX, $owner !== null ? self::scope($owner, $held) : null);
	}

	/**
	 * Per-user ownership, in which the inbox is that of a user other than the target user, whose
	 * user of Paperless is $owner; bob is the user 4 of Paperless, and the account of the app,
	 * which the tests make the user 1, has no user of Nextcloud, so its documents go to the target
	 * user, as do those without an owner.
	 */
	private static function scope(int $owner, int|false|null $held = null): PerUserScope {
		$route = static fn (int $id, string $name, ?string $user): array => ['id' => $id, 'name' => $name, 'user' => $user, 'reason' => $user !== null ? Owners::NAME : Owners::NONE, 'detail' => ''];

		return new PerUserScope(new Owners('target', [$owner => $route($owner, TestStorage::USER, TestStorage::USER), 4 => $route(4, 'bob', 'bob'), 1 => $route(1, 'paperless-sync', null)]), TestStorage::USER, $held);
	}

	/** @return array<string, bool|int> the counts of the report, without those of the kind of run and the pruned folders, which a dry-run doesn't prune */
	private static function counts(SyncReport $report): array {
		return array_diff_key($report->summary(), array_flip(['dryRun', 'startedAt', 'completedAt', 'unsavedSettings', 'foldersPruned']));
	}
}
