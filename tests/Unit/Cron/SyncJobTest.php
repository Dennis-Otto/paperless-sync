<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Cron;

use OC;
use OCA\PaperlessSync\Cron\SyncJob;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\BackgroundJob\IJobList;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The background job, started the way Nextcloud's cron starts it.
 */
final class SyncJobTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;

	private TestClock $clock;
	private ConfigService $configService;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SyncService $sync;
	private SyncJob $job;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->configService = $this->configService();
		$this->configService->save(['enabled' => true, 'sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$status = new StatusService($this->appConfig(), $this->clock);
		$this->sync = new SyncService(
			$this->configService,
			new TestPaperlessClient(),
			new TestStorage(),
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
			$this->clock,
			$this->activityPublisher(new TestStorage(), $this->createMock(LoggerInterface::class)),
			new TestTimeZones(),
		);
		$this->job = new SyncJob($this->clock, $this->configService, $status, $this->sync, $this->logger);
		OC::$server = new TestServer([LoggerInterface::class => new NullLogger()]);
	}

	protected function tearDown(): void {
		OC::$server = null;
	}

	public function testCronChecksEveryFiveMinutesAndNeverRunsTwice(): void {
		self::assertSame(300, $this->job->getInterval());
		self::assertFalse($this->job->getAllowParallelRuns());
	}

	public function testFirstScheduledRunSynchronizes(): void {
		$this->start();

		self::assertSame('completed', $this->settings['status_last_state']);
	}

	public function testDisabledScheduleDoesNotSynchronize(): void {
		$this->configService->save(['enabled' => false]);

		$this->start();

		self::assertArrayNotHasKey('status_last_state', $this->settings);
	}

	public function testRunWaitsForTheConfiguredInterval(): void {
		$this->settings['status_last_completed'] = $this->clock->time - 60;
		$this->settings['status_last_started'] = $this->clock->time - 14 * 60;

		$this->start();
		self::assertArrayNotHasKey('status_last_state', $this->settings, 'A run one minute ago is too recent.');

		$this->clock->advance(14 * 60);
		$this->start();
		self::assertSame('completed', $this->settings['status_last_state'], 'Fifteen minutes after the last run, the next one is due.');
	}

	public function testRunAfterAFailedRunWaitsForTheConfiguredInterval(): void {
		$this->settings['status_last_started'] = $this->clock->time - 15 * 60;
		$this->settings['status_last_ended'] = $this->clock->time - 60;
		$this->settings['status_last_state'] = 'failed';

		$this->start();
		self::assertSame('failed', $this->settings['status_last_state'], 'A run that failed one minute ago is too recent.');

		$this->clock->advance(14 * 60);
		$this->start();
		self::assertSame('completed', $this->settings['status_last_state']);
	}

	/**
	 * A dry-run changes nothing, so the schedule counts from the last real run: a dry-run that an
	 * administrator starts shortly before the next run is due doesn't push it back by an interval.
	 */
	public function testADryRunDoesNotPostponeTheScheduledRun(): void {
		$this->settings['status_last_sync_started'] = $this->clock->time - 16 * 60;
		$this->settings['status_last_sync_ended'] = $this->clock->time - 16 * 60 + 30;
		$this->sync->run(true);
		self::assertSame('dry-run-completed', $this->settings['status_last_state']);

		$this->start();
		self::assertSame('completed', $this->settings['status_last_state'], 'Fifteen minutes after the last real run, the next one is due, after a dry-run too.');
	}

	/**
	 * A new installation runs a dry-run first, as the settings page recommends, and turns scheduled
	 * runs on after that: the next background job starts the first run, however long the interval.
	 */
	public function testADryRunBeforeTheFirstRunDoesNotPostponeIt(): void {
		$this->configService->save(['enabled' => false]);
		$this->sync->run(true);
		self::assertSame('dry-run-completed', $this->settings['status_last_state']);

		$this->configService->save(['enabled' => true, 'sync_interval_minutes' => ConfigService::MAXIMUM_INTERVAL_MINUTES]);
		$this->start();
		self::assertSame('completed', $this->settings['status_last_state'], 'The first scheduled run does not wait for a dry-run.');
	}

	/** A real run, also one started with "Synchronize now", is the one that the schedule counts from. */
	public function testARealRunPostponesTheScheduledRun(): void {
		$this->settings['status_last_sync_started'] = $this->clock->time - 16 * 60;
		$this->settings['status_last_sync_ended'] = $this->clock->time - 16 * 60 + 30;
		$this->sync->run(false);
		$this->settings['status_last_state'] = 'completed by hand';

		$this->start();
		self::assertSame('completed by hand', $this->settings['status_last_state'], 'A run a moment ago is too recent.');
	}

	/**
	 * Only the message: the synchronization logs a failed run with its trace already, and a second
	 * copy of the trace in the log helps nobody.
	 */
	public function testFailedRunIsLogged(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));
		$this->logger->expects(self::once())->method('error')->with(
			'Scheduled Paperless synchronization failed: {error}',
			['error' => '"paperless_sync::synchronization" is locked'],
		);

		$this->start();
	}

	private function start(): void {
		$this->job->start($this->createMock(IJobList::class));
	}
}
