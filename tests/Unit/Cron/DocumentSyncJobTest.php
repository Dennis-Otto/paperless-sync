<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Cron;

use OC;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Cron\DocumentSyncJob;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Service\WebhookService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\InMemoryWebhook;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The background job of a document that the webhook of Paperless named, started the way
 * Nextcloud's cron starts it.
 */
final class DocumentSyncJobTest extends TestCase {
	use InMemoryConfiguration;
	use InMemoryWebhook;
	use RecordedActivities;
	use RecordedNotifications;

	private const PATH = 'Documents/Paperless/Archive/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';

	private TestClock $clock;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	/** @var IJobList&MockObject the job list that the job queues itself on again */
	private IJobList $jobList;
	private WebhookService $webhook;
	private DocumentSyncJob $job;

	protected function setUp(): void {
		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
		$this->clock = new TestClock();
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->paperless = new TestPaperlessClient();
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->documents = [['id' => 123, 'title' => 'Strom August', 'correspondent' => 4, 'document_type' => 7, 'created' => '2026-08-26', 'modified' => '2026-08-26T10:00:00+02:00', 'original_file_name' => 'scan.pdf', 'archived_file_name' => 'archive.pdf']];
		$this->paperless->contents = [123 => '%PDF-123'];
		$this->storage = new TestStorage();
		$status = $this->statusService($this->clock, $this->locking);
		$sync = new SyncService(
			$this->configService(),
			$this->paperless,
			$this->storage,
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
			$this->clock,
			$this->activityPublisher($this->storage, $this->createMock(LoggerInterface::class)),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($status, $this->storage, $this->clock, $this->createMock(LoggerInterface::class)),
		);
		$this->jobList = $this->createMock(IJobList::class);
		$this->webhook = $this->webhookService($this->clock);
		$this->webhook->create();
		$this->job = new DocumentSyncJob($this->clock, $sync, $this->webhook, $this->jobList, $this->logger);
		$this->job->setId('42');
		$this->job->setArgument(['document' => 123]);
		OC::$server = new TestServer([LoggerInterface::class => new NullLogger()]);
	}

	protected function tearDown(): void {
		OC::$server = null;
	}

	public function testTheJobMirrorsItsDocumentOnceAndLeavesTheQueue(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('removeById')->with('42');
		$this->jobList->expects(self::never())->method('scheduleAfter');

		$this->job->start($jobList);

		self::assertSame('%PDF-123', $this->storage->files[self::PATH]);
		self::assertSame([123], $this->paperless->documentReads);
		self::assertFalse($this->job->getAllowParallelRuns());
		self::assertSame(['at' => TestClock::START, 'document' => 123, 'result' => 'exported', 'message' => ''], $this->webhook->state()['lastCall']);
	}

	/**
	 * Another run holds the lock, such as a scheduled one: the document waits a minute, not for the
	 * next job of the same cron run, which would start it again at once.
	 */
	public function testTheJobWaitsAMinuteWhileAnotherRunIsActive(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException(AppConstants::LOCK_PATH));
		$this->jobList->expects(self::once())->method('scheduleAfter')->with(DocumentSyncJob::class, TestClock::START + DocumentSyncJob::RETRY_DELAY, ['document' => 123]);
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization of document {document} postponed: another run is active.', ['document' => 123]);

		$this->job->start($this->createMock(IJobList::class));

		self::assertSame([], $this->paperless->documentReads);
	}

	/** An administrator turned synchronization off after Paperless called: the job changes nothing. */
	public function testTheJobChangesNothingOnceSynchronizationIsOff(): void {
		$this->configService()->save(['enabled' => false] + self::validSettings());
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization of document {document} skipped: {reason}', [
			'document' => 123,
			'reason' => 'Scheduled synchronization is off, so changes from Paperless wait until it is on again.',
		]);

		$this->job->start($this->createMock(IJobList::class));

		self::assertSame([], $this->storage->files);
		self::assertSame('off', $this->webhook->state()['lastCall']['result'] ?? null);
	}

	/** Only the message: the synchronization logs the failure with its trace already. */
	public function testAFailedRunIsLogged(): void {
		$this->paperless->documentException = new PaperlessRequestException('cURL error 7: Failed to connect');
		$this->jobList->expects(self::never())->method('scheduleAfter');
		$this->logger->expects(self::once())->method('error')->with('Paperless synchronization of document {document} failed: {error}', ['document' => 123, 'error' => 'cURL error 7: Failed to connect']);

		$this->job->start($this->createMock(IJobList::class));

		self::assertSame(['at' => TestClock::START, 'document' => 123, 'result' => 'failed', 'message' => 'cURL error 7: Failed to connect'], $this->webhook->state()['lastCall']);
	}

	public function testAJobWithoutADocumentChangesNothing(): void {
		foreach ([null, [], ['document' => '123'], ['document' => 0], 'P123'] as $argument) {
			$this->job->setArgument($argument);
			$this->job->start($this->createMock(IJobList::class));
		}

		self::assertSame([], $this->paperless->documentReads);
		self::assertSame([], $this->storage->files);
	}
}
