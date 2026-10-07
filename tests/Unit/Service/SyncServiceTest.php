<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SyncServiceTest extends TestCase {
	use InMemoryConfiguration;

	private const ARCHIVE = 'Dokumente/Paperless/Archiv';
	private const EXPORTED_PATH = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';

	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SyncService $service;

	protected function setUp(): void {
		$this->token = 'test-token';
		$this->paperless = new TestPaperlessClient();
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$this->configService = $this->configService($paths);
		$this->configService->save(self::validSettings());
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SyncService(
			$this->configService,
			$this->paperless,
			$this->storage,
			$this->state,
			$paths,
			new StatusService($this->appConfig()),
			$this->locking,
			$this->logger,
		);
	}

	public function testExportsMovesTrashesRestoresAndPermanentlyDeletes(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$first = $this->service->run();
		$initialPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(1, $first->exported);
		self::assertSame('%PDF-content-v1', $this->storage->files[$initialPath]);
		self::assertSame(1, $this->paperless->downloads);

		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$moved = $this->service->run();
		$renamedPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(1, $moved->moved);
		self::assertArrayNotHasKey($initialPath, $this->storage->files);
		self::assertArrayHasKey($renamedPath, $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'Metadata-only changes must not download the PDF again.');
		self::assertSame(1, $this->paperless->checksumRequests);

		$trashedDocument = $this->paperless->documents[0];
		$trashedDocument['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument];
		$trashed = $this->service->run();
		self::assertSame(1, $trashed->movedToTrash);
		$trashPath = 'Dokumente/Paperless/Archiv/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertArrayHasKey($trashPath, $this->storage->files);

		unset($trashedDocument['deleted_at']);
		$this->paperless->documents = [$trashedDocument];
		$this->paperless->trash = [];
		$restored = $this->service->run();
		self::assertSame(1, $restored->moved);
		self::assertArrayHasKey($renamedPath, $this->storage->files);

		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->service->run();
		$this->configService->save(['permanent_delete' => true, 'missing_grace_runs' => 1]);
		$this->paperless->trash = [];
		$deleted = $this->service->run();
		self::assertSame(1, $deleted->permanentlyDeleted);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testRefusesToRunBeforeTheAppIsConfigured(): void {
		$this->configService->reset();
		$this->locking->expects(self::once())->method('acquireLock');
		$this->locking->expects(self::once())->method('releaseLock');
		$this->logger->expects(self::once())->method('error')->with('Paperless synchronization failed');

		try {
			$this->service->run();
			self::fail('An unconfigured app must not synchronize.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless Sync is not completely configured.', $exception->getMessage());
		}

		self::assertSame('failed', $this->settings['status_last_state']);
		self::assertSame('Paperless Sync is not completely configured.', $this->settings['status_last_error']);
	}

	public function testCompletedRunIsLoggedAndRecordedInTheStatus(): void {
		$this->exportDocument();
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization completed', self::callback(
			static fn (array $summary): bool => $summary['unchanged'] === 1 && !array_key_exists('actions', $summary),
		));

		$report = $this->service->run();

		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertSame($report->completedAt, $this->settings['status_last_completed']);
	}

	public function testUnchangedDocumentIsNeitherDownloadedNorRewritten(): void {
		$this->exportDocument();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $report->exported);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(0, $this->paperless->checksumRequests, 'An unchanged revision needs no checksum.');
	}

	public function testNewRevisionWithTheSameContentOnlyRecordsTheRevision(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->checksumRequests);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	public function testOriginalIsExportedWhenPaperlessHasNoArchiveVersion(): void {
		$document = $this->document();
		$document['archived_file_name'] = null;
		$this->exportDocument($document);

		self::assertSame('original:2026-08-26T10:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	public function testChangedContentIsDownloadedAgainAndTheOldCopyRemoved(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		self::assertSame(1, $report->exported);
		self::assertSame(0, $report->moved);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0]);
		self::assertSame(1, $report->foldersPruned);
		self::assertSame(hash('sha256', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	public function testBatchSizeLimitsTheChangesOfOneRun(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(), $this->document(124)];
		$this->paperless->contents = [123 => '%PDF-123', 124 => '%PDF-124'];

		$first = $this->service->run();
		self::assertSame(1, $first->exported);
		self::assertSame(1, $first->skipped);
		self::assertArrayNotHasKey(124, $this->state->exports);

		$second = $this->service->run();
		self::assertSame(1, $second->unchanged);
		self::assertSame(1, $second->exported);
		self::assertArrayHasKey(124, $this->state->exports);
	}

	public function testDocumentWithoutAValidIdFailsTheRun(): void {
		$this->paperless->documents = [['id' => 'abc', 'title' => 'Broken']];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Paperless returned a document without a valid ID.');
		$this->service->run();
	}

	public function testFailedDownloadIsReportedAndRetriedInTheNextRun(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');

		$failed = $this->exportDocument();

		self::assertSame(1, $failed->errors);
		self::assertSame(['ERROR: P123: Paperless is unavailable'], $failed->actions);
		self::assertSame('Paperless is unavailable', $this->state->exports[123]['last_error']);
		self::assertSame([], $this->storage->files);
		self::assertSame('completed-with-errors', $this->settings['status_last_state']);

		$this->paperless->downloadException = null;
		$retried = $this->service->run();
		self::assertSame(1, $retried->exported);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testErrorWithoutMessageNamesTheExceptionClass(): void {
		$this->paperless->downloadException = new LogicException();

		$report = $this->exportDocument();

		self::assertSame(['ERROR: P123: LogicException'], $report->actions);
	}

	public function testDryRunReportsAnUnusableDocumentWithoutRecordingIt(): void {
		$document = $this->document();
		$document['id'] = 12.5;

		$this->paperless->documents = [$document];
		$report = $this->service->run(true);

		self::assertSame(['ERROR: P12: Paperless returned a document without a numeric ID.'], $report->actions);
		self::assertSame([], $this->state->exports);
	}

	public function testExistingFileIsKeptWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->storage->files[self::EXPORTED_PATH] = 'a file of the user';

		$report = $this->exportDocument();

		self::assertSame(1, $report->errors);
		self::assertSame('a file of the user', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('conflict', $this->state->exports[123]['last_error']);
	}

	public function testImportsInboxAndDeletesSourceAfterSuccessfulTask(): void {
		$path = 'Dokumente/Paperless/Eingang/Versicherung/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';

		$submitted = $this->service->run();
		self::assertSame(1, $submitted->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox'], $this->paperless->uploads);
		self::assertArrayHasKey($path, $this->storage->files);

		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$completed = $this->service->run();
		self::assertSame(1, $completed->importsSucceeded);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	public function testFailedImportMovesFileAndWritesDiagnostic(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];

		$report = $this->service->run();
		$errorPath = 'Dokumente/Paperless/Fehler/broken.pdf';
		self::assertSame(1, $report->importsFailed);
		self::assertSame('broken', $this->storage->files[$errorPath]);
		self::assertStringContainsString('Unsupported mime type', $this->storage->files[$errorPath . '.error.txt']);
	}

	public function testFailedTaskIsNotSubmittedAgainWhenErrorHandlingThrows(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];
		$this->storage->writeTextException = new RuntimeException('Synthetic diagnostic write failure');

		$report = $this->service->run();

		self::assertSame(0, $report->importsSubmitted);
		self::assertSame(1, $report->errors);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testUnreadableTaskStatusIsRetriedInTheNextRun(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->taskException = new RuntimeException('Paperless timed out');

		$report = $this->service->run();

		self::assertSame(['ERROR: Import task ' . $path . ': Paperless timed out'], $report->actions);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testImportStateOfAVanishedFileIsForgotten(): void {
		$this->state->imports['Dokumente/Paperless/Eingang/gone.pdf'] = ['path' => 'Dokumente/Paperless/Eingang/gone.pdf', 'status' => 'pending', 'task_id' => 'task-9'];

		$report = $this->service->run();

		self::assertSame([], $this->state->imports);
		self::assertSame(0, $report->importsSubmitted);
	}

	public function testDryRunKeepsTheImportStateOfAVanishedFile(): void {
		$this->state->imports['Dokumente/Paperless/Eingang/gone.pdf'] = ['path' => 'Dokumente/Paperless/Eingang/gone.pdf', 'status' => 'pending', 'task_id' => 'task-9'];

		$this->service->run(true);

		self::assertArrayHasKey('Dokumente/Paperless/Eingang/gone.pdf', $this->state->imports);
	}

	public function testKeptInboxFileIsImportedOnceUntilItChanges(): void {
		$this->configService->save(['delete_inbox_after_success' => false]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCEEDED', 'message' => ''];

		$succeeded = $this->service->run();
		self::assertSame(1, $succeeded->importsSucceeded);
		self::assertSame('success', $this->state->imports[$path]['status']);
		self::assertArrayHasKey($path, $this->storage->files);

		$unchanged = $this->service->run();
		self::assertSame(1, $unchanged->unchanged);
		self::assertSame(0, $unchanged->importsSubmitted);

		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$changed = $this->service->run();
		self::assertSame(1, $changed->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox-corrected'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testFileChangedDuringItsImportIsSubmittedAgain(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];

		$succeeded = $this->service->run();
		self::assertSame(1, $succeeded->importsSucceeded);
		self::assertSame(0, $succeeded->importsSubmitted);
		self::assertArrayHasKey($path, $this->storage->files, 'A file that changed after its upload must not be deleted.');
		self::assertSame([], $this->state->imports);

		$resubmitted = $this->service->run();
		self::assertSame(1, $resubmitted->importsSubmitted);
		self::assertSame('%PDF-inbox-corrected', $this->paperless->uploads['police.pdf']);
	}

	public function testDryRunReportsImportsWithoutTouchingTheInbox(): void {
		$succeeded = 'Dokumente/Paperless/Eingang/succeeded.pdf';
		$failed = 'Dokumente/Paperless/Eingang/Ordner/failed.pdf';
		$this->storage->files[$succeeded] = '%PDF-1';
		$this->storage->files[$failed] = '%PDF-2';
		$this->service->run();
		$this->paperless->tasks = [
			'task-1' => ['status' => 'SUCCESS', 'message' => ''],
			'task-2' => ['status' => 'FAILED', 'message' => 'Broken'],
		];
		$new = 'Dokumente/Paperless/Eingang/new.pdf';
		$this->storage->files[$new] = '%PDF-3';
		$files = $this->storage->files;
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame([
			'IMPORT SUCCESS: ' . $succeeded,
			'IMPORT ERROR: ' . $failed . ' -> Dokumente/Paperless/Fehler/Ordner/failed.pdf',
			'IMPORT: ' . $new,
		], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame($files, $this->storage->files);
		self::assertSame($imports, $this->state->imports);
	}

	public function testDryRunKeepsTheStateOfAFileChangedDuringItsImport(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];

		$this->service->run(true);

		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testImportsBeyondTheBatchSizeWaitForTheNextRun(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(1, $report->skipped);
		self::assertSame(['a.pdf' => '%PDF-a'], $this->paperless->uploads);
	}

	public function testFailedUploadIsReportedAndRetriedInTheNextRun(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = new RuntimeException('Paperless rejected the upload');

		$failed = $this->service->run();
		self::assertSame(['ERROR: Import ' . $path . ': Paperless rejected the upload'], $failed->actions);
		self::assertSame([], $this->state->imports);

		$this->paperless->uploadException = null;
		$retried = $this->service->run();
		self::assertSame(1, $retried->importsSubmitted);
	}

	public function testExcludedDocumentRemovesMirroredCopyAndCanBeExportedAgain(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$this->service->run();
		$this->paperless->documents[0]['tags'] = [9];
		$excluded = $this->service->run();

		self::assertSame(1, $excluded->removedExcluded);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);

		$this->paperless->documents[0]['tags'] = [];
		$exportedAgain = $this->service->run();
		self::assertSame(1, $exportedAgain->exported);
		self::assertSame(2, $this->paperless->downloads);
	}

	public function testExcludedDocumentThatWasNeverExportedIsOnlySkipped(): void {
		$document = $this->document();
		$document['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->exportDocument($document);

		self::assertSame(1, $report->skipped);
		self::assertSame(0, $report->removedExcluded);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testExcludedTagNamesMatchWithoutRegardToCase(): void {
		$this->configService->save(['excluded_tags' => 'Privat, Steuer']);
		$this->paperless->tagInfo = ['names' => ['5' => 'privat', '6' => 'Haushalt'], 'inbox' => []];
		$private = $this->document();
		$private['tags'] = [['id' => 6], ['id' => 5]];
		$household = $this->document(124);
		$household['tags'] = [['id' => 6], ['name' => 'without an id']];

		$report = $this->exportDocuments([$private, $household]);

		self::assertSame(1, $report->skipped);
		self::assertSame(1, $report->exported);
		self::assertArrayNotHasKey(123, $this->state->exports);
		self::assertArrayHasKey(124, $this->state->exports);
	}

	public function testInboxTagDoesNotExcludeWhenTheInboxIsSynchronizedToo(): void {
		$this->configService->save(['skip_inbox' => false]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$document = $this->document();
		$document['tags'] = [9];

		$report = $this->exportDocument($document);

		self::assertSame(1, $report->exported);
	}

	public function testDryRunReportsTheRemovalOfAnExcludedCopy(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->service->run(true);

		self::assertSame(['REMOVE EXCLUDED P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->removedExcluded);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testExcludedCopyWaitsWhenTheBatchIsFull(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$excluded = $this->paperless->documents[0];
		$excluded['tags'] = [9];
		$this->paperless->documents = [$this->document(124), $excluded];
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();
		self::assertSame(1, $first->exported);
		self::assertSame(0, $first->removedExcluded);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);

		$second = $this->service->run();
		self::assertSame(1, $second->removedExcluded);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testFailedRemovalOfAnExcludedCopyIsReported(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$this->storage->deleteException = new RuntimeException('File is locked');

		$report = $this->service->run();

		self::assertSame(['ERROR: Excluded P123: File is locked'], $report->actions);
		self::assertSame('File is locked', $this->state->exports[123]['last_error']);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testTrashedDocumentThatWasNeverExportedIsIgnored(): void {
		$this->paperless->trash = [$this->document() + ['deleted_at' => '2026-08-27T08:00:00+02:00']];

		$report = $this->service->run();

		self::assertSame(1, $report->trashedDocuments);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame([], $this->state->exports);
	}

	public function testDocumentAlreadyInTheTrashFolderStaysThere(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->service->run();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('trash', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsTheMoveToTheTrashFolder(): void {
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run(true);

		$trashPath = self::ARCHIVE . '/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(['TRASH P123: ' . $trashPath], $report->actions);
		self::assertSame(1, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testMoveToTheTrashFolderWaitsWhenTheBatchIsFull(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->trashDocument();
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(1, $report->skipped);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testFailedMoveToTheTrashFolderIsReported(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->moveException = new RuntimeException('Storage is full');

		$report = $this->service->run();

		self::assertSame(['ERROR: Trash P123: Storage is full'], $report->actions);
		self::assertSame('Storage is full', $this->state->exports[123]['last_error']);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testKeepPolicyLeavesTheCopyOfATrashedDocumentInPlace(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame(strtotime('2026-08-27T08:00:00+02:00'), $this->state->exports[123]['trash_date']);
	}

	public function testDryRunReportsNothingWhenTheKeepPolicyLeavesTheCopyInPlace(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testTrashedDocumentWithoutAFileIsOnlyMarked(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->files = [];

		$first = $this->service->run();

		self::assertSame(0, $first->movedToTrash);
		self::assertSame([], $this->storage->prunes);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'Nothing was moved, so the state keeps the path.');

		$second = $this->service->run();

		self::assertSame(0, $second->movedToTrash);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'The path must not grow by a deleted folder in every run.');
	}

	public function testTrashedDocumentWithoutAFileTakesNoPlaceInTheBatch(): void {
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->paperless->trash = array_map(
			static fn (array $document): array => $document + ['deleted_at' => '2026-08-27T08:00:00+02:00'],
			$this->paperless->documents,
		);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->skipped);
		self::assertSame([$this->trashFolderPath(124) => '%PDF-content'], $this->storage->files);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testDryRunReportsNoMoveToTheTrashFolderWithoutAFile(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->files = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
	}

	public function testTrashedDocumentThatWasNeverExportedIsOnlyMarked(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run();

		self::assertSame(0, $report->errors);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['last_error']);
		self::assertArrayNotHasKey('path', $this->state->exports[123], 'A folder of the deleted folder must not become the path of the document.');
	}

	/** @return array<string, array{mixed}> */
	public static function unreadableDeletionDates(): array {
		return [
			'missing' => [null],
			'empty' => [''],
			'unparsable' => ['not a date'],
		];
	}

	#[DataProvider('unreadableDeletionDates')]
	public function testTrashFolderUsesTheCurrentDateWithoutAReadableDeletionDate(mixed $deletedAt): void {
		$this->exportDocument();
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = $deletedAt;
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];

		$this->service->run();

		$today = gmdate('Y-m-d');
		self::assertArrayHasKey(self::ARCHIVE . "/_Gelöscht/{$today}/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf", $this->storage->files);
	}

	public function testMissingDocumentWaitsForTheConfiguredRuns(): void {
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['WAIT P123: missing run 1/3'], $report->actions);
		self::assertSame(1, $this->state->exports[123]['missing_runs']);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testDryRunDoesNotCountMissingRuns(): void {
		$this->exportDocument();
		$this->paperless->documents = [];

		$this->service->run(true);

		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	public function testMissingDocumentMovesToTheDeletedFolderAfterTheGraceRuns(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		$deletedPath = self::ARCHIVE . '/_Gelöscht/' . gmdate('Y-m-d') . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(1, $report->movedToTrash);
		self::assertSame([$deletedPath => '%PDF-content'], $this->storage->files);
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame($deletedPath, $this->state->exports[123]['path']);

		$again = $this->service->run();
		self::assertSame(0, $again->movedToTrash);
		self::assertSame([$deletedPath => '%PDF-content'], $this->storage->files);
		self::assertSame(2, $this->state->exports[123]['missing_runs']);
	}

	public function testMissingDocumentWithoutAFileIsOnlyMarked(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->files = [];

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertSame([], $this->storage->prunes);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'Nothing was moved, so the state keeps the path.');
	}

	public function testMissingDocumentWithoutAFileTakesNoPlaceInTheBatch(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->skipped);
		self::assertSame([$this->deletedToday(124) => '%PDF-content'], $this->storage->files);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testMissingDocumentThatWasNeverExportedIsOnlyMarked(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertSame(0, $report->errors);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertArrayNotHasKey('path', $this->state->exports[123], 'A folder of the deleted folder must not become the path of the document.');
	}

	public function testDryRunReportsNoMoveOfAMissingDocumentWithoutAFile(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->files = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
	}

	public function testMoveOfAMissingDocumentWaitsWhenTheBatchIsFull(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();

		self::assertSame(1, $first->exported);
		self::assertSame(1, $first->skipped);
		self::assertSame(0, $first->movedToTrash);
		self::assertSame('%PDF-content', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);

		$second = $this->service->run();

		self::assertSame(1, $second->movedToTrash);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday()]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame($this->deletedToday(), $this->state->exports[123]['path']);
	}

	public function testFailedMoveOfAMissingDocumentIsReportedAndRetried(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'conflict_mode' => 'skip']);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->storage->files[$this->deletedToday()] = 'a file of the user';
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['ERROR: Missing P123: conflict'], $report->actions);
		self::assertSame(1, $report->movedToTrash, 'The other missing document still moves.');
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday(124)]);
		self::assertSame('%PDF-content', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('a file of the user', $this->storage->files[$this->deletedToday()]);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame('conflict', $this->state->exports[123]['last_error']);
		self::assertSame('completed-with-errors', $this->settings['status_last_state']);

		unset($this->storage->files[$this->deletedToday()]);
		$retried = $this->service->run();

		self::assertSame(1, $retried->movedToTrash);
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday()]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testDryRunReportsTheMoveOfAMissingDocument(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		$deletedPath = self::ARCHIVE . '/_Gelöscht/' . gmdate('Y-m-d') . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(['MISSING P123: ' . $deletedPath], $report->actions);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testMissingDocumentIsDeletedDirectlyWhenAllowed(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->permanentlyDeleted);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame([[self::EXPORTED_PATH, self::ARCHIVE]], $this->storage->prunes);
	}

	public function testDryRunReportsThePermanentDeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		self::assertSame(['DELETE P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->permanentlyDeleted);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testFailedPermanentDeletionIsReported(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->deleteException = new RuntimeException('File is locked');

		$report = $this->service->run();

		self::assertSame(['ERROR: Missing P123: File is locked'], $report->actions);
		self::assertSame(0, $report->permanentlyDeleted);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('File is locked', $this->state->exports[123]['last_error']);
	}

	public function testMissingDocumentWithoutAFileIsForgottenWithoutADeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame(0, $report->errors);
		self::assertSame([], $this->state->exports);
	}

	public function testPermanentDeletionWaitsWhenTheBatchIsFull(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$report = $this->service->run();

		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame(1, $report->skipped);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testFoldersAreKeptWhenPruningIsOff(): void {
		$this->configService->save(['prune_empty_folders' => false, 'missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$excluded = $this->paperless->documents[0];
		$excluded['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$this->paperless->documents = [$excluded];

		$report = $this->service->run();

		self::assertSame(1, $report->removedExcluded);
		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->foldersPruned);
		self::assertSame([], $this->storage->prunes);
	}

	public function testDryRunDoesNotMutateFilesOrState(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$report = $this->service->run(true);
		self::assertSame(1, $report->exported);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame(0, $this->paperless->downloads);
	}

	public function testDryRunReportsAMoveAsMove(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$report = $this->service->run(true);

		self::assertSame(['MOVE P123: ' . self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf'], $report->actions);
		self::assertSame(1, $report->moved);
		self::assertSame('dry-run-completed', $this->settings['status_last_state']);
	}

	public function testDisabledDirectionsAreNotSynchronized(): void {
		$this->configService->save(['inbox_enabled' => false, 'export_enabled' => false]);
		$this->paperless->documents = [$this->document()];
		$this->storage->files['Dokumente/Paperless/Eingang/police.pdf'] = '%PDF-inbox';

		$report = $this->service->run();

		self::assertSame(0, $report->activeDocuments);
		self::assertSame(0, $report->importsSubmitted);
		self::assertSame([], $this->state->exports);
	}

	public function testScheduleFlagAvoidsNextcloudReservedEnabledKey(): void {
		$this->configService->save(['enabled' => true]);

		self::assertTrue($this->settings['sync_enabled']);
		self::assertArrayNotHasKey('enabled', $this->settings);
	}

	/** @param array<string, mixed>|null $document */
	private function exportDocument(?array $document = null): SyncReport {
		return $this->exportDocuments([$document ?? $this->document()]);
	}

	/** @param list<array<string, mixed>> $documents */
	private function exportDocuments(array $documents): SyncReport {
		$this->paperless->documents = $documents;
		foreach ($documents as $document) {
			$this->paperless->contents[(int)$document['id']] = '%PDF-content';
		}
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		return $this->service->run();
	}

	/** The path of the copy of a document that a run of today moves to the deleted folder. */
	private function deletedToday(int $id = 123): string {
		return self::ARCHIVE . '/_Gelöscht/' . gmdate('Y-m-d') . "/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/** The path of the copy of a document that Paperless moved to its trash on 2026-08-27. */
	private function trashFolderPath(int $id = 123): string {
		return self::ARCHIVE . "/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	private function trashDocument(): void {
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];
	}

	/** @return array<string, mixed> */
	private function document(int $id = 123): array {
		return [
			'id' => $id,
			'title' => 'Strom August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'tags' => [],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];
	}
}
