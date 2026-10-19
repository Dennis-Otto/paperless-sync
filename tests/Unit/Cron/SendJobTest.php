<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Cron;

use OC;
use OCA\PaperlessSync\Cron\SendJob;
use OCA\PaperlessSync\Service\SendService;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class SendJobTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedNotifications;

	private TestClock $clock;
	private TestStorage $storage;
	private TestStateRepository $state;
	private TestPaperlessClient $paperless;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->paperless = new TestPaperlessClient();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->configService()->save(self::validSettings(), 'secret-token');
		OC::$server = new TestServer([LoggerInterface::class => new NullLogger()]);
	}

	protected function tearDown(): void {
		OC::$server = null;
	}

	/** A file goes with the next run of cron, and never twice at the same time. */
	public function testTheJobRunsWithEveryRunOfCronAndNeverTwice(): void {
		$job = $this->job();

		self::assertSame(60, $job->getInterval());
		self::assertTrue($job->isTimeSensitive());
		self::assertFalse($job->getAllowParallelRuns());
	}

	public function testTheJobSendsTheQueuedFiles(): void {
		$this->storage->filesOfOthers['alice']['Scans/receipt.pdf'] = 'Synthetic receipt';
		$id = $this->storage->fileId('Scans/receipt.pdf', 'alice');
		$this->state->saveSend('alice', $id, ['path' => 'Scans/receipt.pdf', 'status' => SendService::QUEUED]);

		$this->job()->start($this->createStub(IJobList::class));

		self::assertSame(['receipt.pdf'], array_keys($this->paperless->uploads));
	}

	/** A failure of the whole round is logged, and the files wait for the next one. */
	public function testAFailedRoundIsLogged(): void {
		$failure = new RuntimeException('The database is gone.');
		$state = $this->createStub(SyncStateRepositoryInterface::class);
		$state->method('allSends')->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('could not send the files queued in Files'), self::callback(
			static fn (array $context): bool => $context['error'] === 'The database is gone.' && $context['exception'] === $failure,
		));

		$this->job($state)->start($this->createStub(IJobList::class));
	}

	private function job(?SyncStateRepositoryInterface $state = null): SendJob {
		$publisher = $this->notificationPublisher($this->statusService($this->clock), $this->storage, $this->clock, $this->createStub(LoggerInterface::class));
		$service = new SendService($this->configService(), $this->paperless, $state ?? $this->state, $this->storage, $publisher, $this->clock, $this->createStub(LoggerInterface::class));

		return new SendJob($this->clock, $service, $this->logger);
	}
}
