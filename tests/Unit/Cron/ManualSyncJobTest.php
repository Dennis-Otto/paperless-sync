<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Cron;

use OC;
use OCA\PaperlessSync\Cron\ManualSyncJob;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The background job of "Synchronize now", started the way Nextcloud's cron starts it.
 */
final class ManualSyncJobTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private TestPaperlessClient $paperless;
	/** @var IJobList&MockObject the job list that the job queues itself on again */
	private IJobList $jobList;
	private ManualSyncJob $job;

	protected function setUp(): void {
		// Scheduled runs are off: a run by hand runs all the same.
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->paperless = new TestPaperlessClient();
		$clock = new TestClock();
		$status = $this->statusService($clock, $this->locking);
		$sync = new SyncService(
			$this->configService(),
			$this->paperless,
			new TestStorage(),
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
			$clock,
			$this->activityPublisher(new TestStorage(), $this->createMock(LoggerInterface::class)),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($status, new TestStorage(), $clock, $this->createMock(LoggerInterface::class)),
		);
		$this->jobList = $this->createMock(IJobList::class);
		$this->job = new ManualSyncJob($this->createMock(ITimeFactory::class), $sync, $this->jobList, $this->logger);
		$this->job->setId('42');
		OC::$server = new TestServer([LoggerInterface::class => new NullLogger()]);
	}

	protected function tearDown(): void {
		OC::$server = null;
	}

	public function testQueuedRunSynchronizesOnceAndLeavesTheQueue(): void {
		$this->settings['status_last_state'] = 'queued';
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('removeById')->with('42');

		$this->job->start($jobList);

		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertFalse($this->job->getAllowParallelRuns());
		self::assertSame(1, count($this->paperless->connections), 'A run, not a dry-run, that tests its connection first.');
	}

	/** The job has no session of the administrator who queued it, so the Activity stream names them from its argument. */
	public function testRunNamesTheAdministratorWhoQueuedIt(): void {
		$this->paperless->documents = [['id' => 5, 'title' => 'Strom', 'created' => '2026-08-26', 'original_file_name' => 'scan.pdf']];
		$this->paperless->contents = [5 => '%PDF-5'];
		$this->job->setArgument(['author' => 'admin']);

		$this->job->start($this->createMock(IJobList::class));

		self::assertCount(1, $this->activities);
		self::assertSame('admin', $this->activities[0]->getAuthor());
	}

	public function testRunWithoutAnAuthorNamesNobody(): void {
		$this->paperless->documents = [['id' => 5, 'title' => 'Strom', 'created' => '2026-08-26', 'original_file_name' => 'scan.pdf']];
		$this->paperless->contents = [5 => '%PDF-5'];
		$this->job->setArgument(['author' => 42]);

		$this->job->start($this->createMock(IJobList::class));

		self::assertCount(1, $this->activities);
		self::assertSame('', $this->activities[0]->getAuthor());
	}

	/**
	 * Another run holds the lock, which may be a dry-run or forgetting copies that don't make the
	 * changes of this run: the run waits for the next background jobs, with the same administrator.
	 */
	public function testRunWhileAnotherIsActiveWaitsForTheNextBackgroundJobs(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));
		$this->job->setArgument(['author' => 'admin']);
		$this->jobList->expects(self::once())->method('add')->with(ManualSyncJob::class, ['author' => 'admin']);
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization by hand postponed: another run is active.');

		$this->job->start($this->createMock(IJobList::class));

		self::assertSame([], $this->paperless->connections);
	}

	public function testCompletedRunIsNotQueuedAgain(): void {
		$this->jobList->expects(self::never())->method('add');

		$this->job->start($this->createMock(IJobList::class));
	}

	/** Only the message: the synchronization logs a failed run with its trace already. */
	public function testFailedRunIsLogged(): void {
		$this->settings['paperless_url'] = '';
		$this->logger->expects(self::once())->method('error')->with(
			'Paperless synchronization by hand failed: {error}',
			['error' => 'Paperless Sync is not completely configured.'],
		);

		$this->job->start($this->createMock(IJobList::class));

		self::assertSame('failed', $this->settings['status_last_state']);
	}
}
