<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\SyncController;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncControllerTest extends TestCase {
	use InMemoryConfiguration;

	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	private SyncController $controller;

	protected function setUp(): void {
		$this->token = 'secret-token';
		$configService = $this->configService();
		$configService->save(self::validSettings());
		$this->locking = $this->createMock(ILockingProvider::class);
		$status = new StatusService($this->appConfig());
		$sync = new SyncService(
			$configService,
			new TestPaperlessClient(),
			new TestStorage(),
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->controller = new SyncController(AppConstants::APP_ID, $this->createMock(IRequest::class), $sync, $status);
	}

	public function testRunReturnsTheReport(): void {
		$response = $this->controller->run();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$report = $response->getData();
		self::assertInstanceOf(SyncReport::class, $report);
		self::assertTrue($report->dryRun, 'A run from the settings page is a dry run unless asked otherwise.');

		$report = $this->controller->run(false)->getData();
		self::assertInstanceOf(SyncReport::class, $report);
		self::assertFalse($report->dryRun);
	}

	public function testRunWhileAnotherRunIsActiveIsAConflict(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Another synchronization run is already active.'], $response->getData());
	}

	public function testFailedRunReturnsItsError(): void {
		$this->settings = [];

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Paperless Sync is not completely configured.'], $response->getData());
	}

	public function testStatusOfTheLastRun(): void {
		$this->controller->run(false);

		$status = $this->controller->status()->getData();

		self::assertIsArray($status);
		self::assertSame('completed', $status['state']);
		self::assertSame(0, $status['summary']['errors']);
	}
}
