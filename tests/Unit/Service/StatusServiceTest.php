<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatusServiceTest extends TestCase {
	use InMemoryConfiguration;

	private StatusService $service;

	protected function setUp(): void {
		$this->service = new StatusService($this->appConfig());
	}

	public function testNothingRanYet(): void {
		self::assertSame([
			'lastStarted' => 0,
			'lastCompleted' => 0,
			'state' => 'never-run',
			'summary' => [],
			'error' => '',
		], $this->service->get());
	}

	public function testStartedRunClearsThePreviousError(): void {
		$this->settings['status_last_error'] = 'Paperless was unavailable';
		$before = time();

		$this->service->started(false);

		$status = $this->service->get();
		self::assertSame('running', $status['state']);
		self::assertSame('', $status['error']);
		self::assertGreaterThanOrEqual($before, $status['lastStarted']);

		$this->service->started(true);
		self::assertSame('dry-run-running', $this->service->get()['state']);
	}

	public function testCompletedRunKeepsItsSummary(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->exported = 2;
		$report->action('EXPORT P1: Archiv/P1.pdf');

		$this->service->completed($report);

		$status = $this->service->get();
		self::assertSame('completed', $status['state']);
		self::assertSame(1060, $status['lastCompleted']);
		self::assertSame(2, $status['summary']['exported']);
		self::assertFalse($status['summary']['dryRun']);
		self::assertArrayNotHasKey('actions', $status['summary']);
	}

	public function testCompletedStateTellsDryRunsAndErrorsApart(): void {
		$this->service->completed(new SyncReport(true));
		self::assertSame('dry-run-completed', $this->service->get()['state']);

		$report = new SyncReport(true);
		$report->error('P1: Paperless was unavailable');
		$this->service->completed($report);
		self::assertSame('completed-with-errors', $this->service->get()['state']);
	}

	public function testFailedRunKeepsAShortenedError(): void {
		$before = time();

		$this->service->failed(new RuntimeException(str_repeat('x', 1500)));

		$status = $this->service->get();
		self::assertSame('failed', $status['state']);
		self::assertSame(str_repeat('x', 1000), $status['error']);
		self::assertGreaterThanOrEqual($before, $status['lastCompleted']);
	}

	public function testUnreadableSummaryIsIgnored(): void {
		foreach (['not JSON', '"text"', '[1, 2]'] as $stored) {
			$this->settings['status_last_summary'] = $stored;

			self::assertSame([], $this->service->get()['summary'], $stored);
		}
	}
}
