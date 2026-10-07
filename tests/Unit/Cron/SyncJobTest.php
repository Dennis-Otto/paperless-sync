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
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\AppFramework\Utility\ITimeFactory;
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

	private int $now;
	private ConfigService $configService;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SyncJob $job;

	protected function setUp(): void {
		$this->now = time();
		$this->token = 'secret-token';
		$this->configService = $this->configService();
		$this->configService->save(['enabled' => true, 'sync_interval_minutes' => 15] + self::validSettings());
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$status = new StatusService($this->appConfig());
		$sync = new SyncService(
			$this->configService,
			new TestPaperlessClient(),
			new TestStorage(),
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->job = new SyncJob($time, $this->configService, $status, $sync, $this->logger);
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
		$this->settings['status_last_completed'] = $this->now - 60;
		$this->settings['status_last_started'] = $this->now - 14 * 60;

		$this->start();
		self::assertArrayNotHasKey('status_last_state', $this->settings, 'A run one minute ago is too recent.');

		$this->now += 14 * 60;
		$this->start();
		self::assertSame('completed', $this->settings['status_last_state'], 'Fifteen minutes after the last run, the next one is due.');
	}

	public function testFailedRunIsLogged(): void {
		$locked = new LockedException('paperless_sync::synchronization');
		$this->locking->method('acquireLock')->willThrowException($locked);
		$this->logger->expects(self::once())->method('error')->with('Scheduled Paperless synchronization failed', ['exception' => $locked]);

		$this->start();
	}

	private function start(): void {
		$this->job->start($this->createMock(IJobList::class));
	}
}
