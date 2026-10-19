<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Activity;

use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\TestActivityEvent;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class ActivityPublisherTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;

	private const ARCHIVE = 'Dokumente/Paperless/Archiv';
	private const INBOX = 'Dokumente/Paperless/Eingang';

	private TestStorage $storage;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private ActivityPublisher $publisher;
	private SyncConfig $config;

	protected function setUp(): void {
		// A German instance, as the documents of the tests: the folders have their German names.
		$this->defaultLanguage = 'de';
		$this->storage = new TestStorage();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->publisher = $this->activityPublisher($this->storage, $this->logger);
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'secret-token');
		$this->config = $configService->get();
	}

	public function testRunTellsTheTargetUserWhatChangedWithItsFirstFiles(): void {
		$report = new SyncReport(false);
		$report->exported = 6;
		$report->moved = 1;
		$report->movedToTrash = 1;
		foreach (range(1, 7) as $id) {
			$this->storage->files[self::ARCHIVE . "/{$id}.pdf"] = '%PDF';
			$report->changedFile(self::ARCHIVE . "/{$id}.pdf");
		}
		$trashed = self::ARCHIVE . '/_Gelöscht/2026-10-13/8.pdf';
		$this->storage->files[$trashed] = '%PDF';
		$report->changedFile($trashed);

		$this->publisher->publish($this->config, $report);

		self::assertCount(1, $this->activities);
		$event = $this->activities[0];
		self::assertSame(AppConstants::APP_ID, $event->getApp());
		self::assertSame(ActivityPublisher::TYPE, $event->getType());
		self::assertSame('paperless', $event->getAffectedUser());
		self::assertSame('', $event->getAuthor(), 'Without an author, Nextcloud names the user of the session, and nobody in a scheduled run.');
		self::assertSame(ActivityPublisher::SUBJECT_RUN, $event->getSubject());
		$archive = $this->storage->findFileId('paperless', self::ARCHIVE);
		self::assertSame([
			'exported' => 6,
			'moved' => 1,
			'trashed' => 1,
			'deleted' => 0,
			'removed' => 0,
			'submitted' => 0,
			'files' => array_map(fn (int $id): array => ['id' => $this->storage->fileId(self::ARCHIVE . "/{$id}.pdf"), 'path' => self::ARCHIVE . "/{$id}.pdf"], range(1, 5)),
			'more' => 3,
			'trash' => ['id' => $this->storage->findFileId('paperless', self::ARCHIVE . '/_Gelöscht'), 'path' => self::ARCHIVE . '/_Gelöscht'],
			'folder' => ['id' => $archive, 'path' => self::ARCHIVE],
		], $event->getSubjectParameters());
		self::assertSame(['files', $archive, self::ARCHIVE], [$event->getObjectType(), $event->getObjectId(), $event->getObjectName()], 'The sidebar of the archive folder shows the entry.');
	}

	public function testFileThatIsGoneAgainHasNoLinkAndCountsAmongTheOthers(): void {
		$report = new SyncReport(false);
		$report->exported = 2;
		$this->storage->files[self::ARCHIVE . '/a.pdf'] = '%PDF';
		$report->changedFile(self::ARCHIVE . '/a.pdf');
		$report->changedFile(self::ARCHIVE . '/gone.pdf');

		$this->publisher->publish($this->config, $report);

		$parameters = $this->activities[0]->getSubjectParameters();
		self::assertSame([['id' => $this->storage->fileId(self::ARCHIVE . '/a.pdf'), 'path' => self::ARCHIVE . '/a.pdf']], $parameters['files']);
		self::assertSame(1, $parameters['more']);
		self::assertArrayNotHasKey('trash', $parameters, 'Only a run that moved documents to the deleted folder names it.');
	}

	public function testRunThatOnlySentFilesBelongsToTheInbox(): void {
		$report = new SyncReport(false);
		$report->importsSubmitted = 2;
		$this->storage->files[self::INBOX . '/scan.pdf'] = '%PDF';

		$this->publisher->publish($this->config, $report);

		$event = $this->activities[0];
		self::assertSame(['id' => $this->storage->findFileId('paperless', self::INBOX), 'path' => self::INBOX], $event->getSubjectParameters()['folder']);
		self::assertSame(self::INBOX, $event->getObjectName());
	}

	public function testFolderThatIsGoneHasNoObject(): void {
		$report = new SyncReport(false);
		$report->permanentlyDeleted = 1;

		$this->publisher->publish($this->config, $report);

		$event = $this->activities[0];
		self::assertSame(['id' => 0, 'path' => self::ARCHIVE], $event->getSubjectParameters()['folder']);
		self::assertSame('', $event->getObjectType());
	}

	public function testEveryImportResultHasAnEntryOfItsOwn(): void {
		$report = new SyncReport(false);
		$kept = self::INBOX . '/kept.pdf';
		$failed = 'Dokumente/Paperless/Fehler/broken.pdf';
		$refused = self::INBOX . '/notes.bin';
		foreach ([$kept, $failed, $refused] as $path) {
			$this->storage->files[$path] = 'content';
		}
		$report->importResult(SyncReport::IMPORTED, self::INBOX . '/scan.pdf', 123);
		$report->importResult(SyncReport::IMPORTED, $kept);
		$report->importResult(SyncReport::IMPORT_FAILED, $failed, null, str_repeat('x', ActivityPublisher::REASON_LIMIT + 10));
		$report->importResult(SyncReport::IMPORT_REJECTED, $refused, null, 'File type not supported');

		$this->publisher->publish($this->config, $report);

		self::assertSame(
			[ActivityPublisher::SUBJECT_IMPORTED, ActivityPublisher::SUBJECT_IMPORTED, ActivityPublisher::SUBJECT_IMPORT_FAILED, ActivityPublisher::SUBJECT_IMPORT_REJECTED],
			array_map(static fn ($event): string => $event->getSubject(), $this->activities),
			'A run without changes of its own has no entry for the run.',
		);
		self::assertSame(['file' => ['id' => 0, 'path' => self::INBOX . '/scan.pdf'], 'document' => 123], $this->activities[0]->getSubjectParameters());
		self::assertSame('', $this->activities[0]->getObjectType(), 'The imported file is gone from the inbox.');
		self::assertSame(['file' => ['id' => $this->storage->fileId($kept), 'path' => $kept], 'document' => null], $this->activities[1]->getSubjectParameters());
		self::assertSame(['files', $this->storage->fileId($kept), $kept], [$this->activities[1]->getObjectType(), $this->activities[1]->getObjectId(), $this->activities[1]->getObjectName()]);
		self::assertSame(['file' => ['id' => $this->storage->fileId($failed), 'path' => $failed], 'reason' => str_repeat('x', ActivityPublisher::REASON_LIMIT)], $this->activities[2]->getSubjectParameters());
		self::assertSame(['file' => ['id' => $this->storage->fileId($refused), 'path' => $refused], 'reason' => 'File type not supported'], $this->activities[3]->getSubjectParameters());
		foreach ($this->activities as $event) {
			self::assertSame('paperless', $event->getAffectedUser());
		}
	}

	/**
	 * A run by hand runs in a background job, without the session of the administrator who started
	 * it, so its entries name the administrator themselves.
	 */
	public function testEntriesOfARunByHandNameWhoStartedIt(): void {
		$report = new SyncReport(false);
		$report->exported = 1;
		$report->importsSubmitted = 1;
		$report->importResult(SyncReport::IMPORTED, self::INBOX . '/scan.pdf', 123);

		$this->publisher->publish($this->config, $report, 'admin');

		self::assertCount(2, $this->activities);
		self::assertSame(['admin', 'admin'], array_map(static fn (TestActivityEvent $event): string => $event->getAuthor(), $this->activities));
	}

	public function testDryRunAndRunWithoutChangesAddNothing(): void {
		$dryRun = new SyncReport(true);
		$dryRun->exported = 3;
		$quiet = new SyncReport(false);
		$quiet->unchanged = 40;
		$quiet->skipped = 2;
		$quiet->error('P1: Paperless is unavailable');

		$this->publisher->publish($this->config, $dryRun);
		$this->publisher->publish($this->config, $quiet);

		self::assertSame([], $this->activities);
	}

	public function testEntryThatNextcloudRefusesIsLoggedWithoutFailingTheRun(): void {
		$report = new SyncReport(false);
		$report->exported = 1;
		$failure = new RuntimeException('The database is full.');
		$this->activityFailure = $failure;
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not add the changes of its run to the activity stream: {error}',
			['error' => 'The database is full.', 'exception' => $failure],
		);

		$this->publisher->publish($this->config, $report);

		self::assertSame([], $this->activities);
	}
}
