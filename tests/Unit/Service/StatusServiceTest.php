<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncConfig;
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
			'lastEnded' => 0,
			'lastSuccess' => 0,
			'state' => 'never-run',
			'summary' => [],
			'error' => '',
			'report' => [],
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

	public function testCompletedRunKeepsItsSummaryAndItsReport(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->exported = 2;
		$report->action('EXPORT P1: Archiv/P1.pdf');

		$this->service->completed($report);

		$status = $this->service->get();
		self::assertSame('completed', $status['state']);
		self::assertSame(1060, $status['lastCompleted']);
		self::assertSame(1060, $status['lastEnded']);
		self::assertSame(1060, $status['lastSuccess']);
		self::assertSame(2, $status['summary']['exported']);
		self::assertFalse($status['summary']['dryRun']);
		self::assertArrayNotHasKey('actions', $status['summary']);
		self::assertSame(2, $status['report']['exported']);
		self::assertSame(1060, $status['report']['completedAt']);
		self::assertSame(['EXPORT P1: Archiv/P1.pdf'], $status['report']['actions']);
		self::assertSame([], $status['report']['errorMessages']);
		self::assertSame(0, $status['report']['omittedActions']);
	}

	public function testReportIsKeptAsALazyValue(): void {
		$this->service->completed(new SyncReport(true, 1000, 1060));

		self::assertArrayHasKey('status_last_report', $this->lazyKeys, 'Nextcloud loads the report only when it is asked for.');
		self::assertArrayNotHasKey('status_last_state', $this->lazyKeys, 'The background job reads the rest of the status in every check.');
		self::assertTrue($this->service->get()['report']['dryRun']);
	}

	public function testCompletedStateTellsDryRunsAndErrorsApart(): void {
		$this->service->completed(new SyncReport(true, 1000, 1060));
		self::assertSame('dry-run-completed', $this->service->get()['state']);

		$report = new SyncReport(false, 1100, 1160);
		$report->error('P1: Paperless was unavailable');
		$this->service->completed($report);
		$status = $this->service->get();
		self::assertSame('completed-with-errors', $status['state']);
		self::assertSame(['P1: Paperless was unavailable'], $status['report']['errorMessages']);
		self::assertSame(1160, $status['lastEnded']);
		self::assertSame(0, $status['lastSuccess'], 'Neither a dry-run nor a run with errors is a successful run.');
	}

	public function testSuccessfulRunIsKeptAfterRunsThatFailedOrHadErrors(): void {
		$this->service->completed(new SyncReport(false, 1000, 1060));
		$withErrors = new SyncReport(false, 1100, 1160);
		$withErrors->error('P1: Paperless was unavailable');
		$this->service->completed($withErrors);
		$this->service->failed(new RuntimeException('Paperless was unavailable'));

		self::assertSame(1060, $this->service->get()['lastSuccess']);
	}

	public function testFailedRunKeepsAShortenedErrorAndTheEndOfTheLastCompletedRun(): void {
		$this->service->completed(new SyncReport(false, 1000, 1060));
		$before = time();

		$this->service->failed(new RuntimeException(str_repeat('x', 1500)));

		$status = $this->service->get();
		self::assertSame('failed', $status['state']);
		self::assertSame(str_repeat('x', 1000), $status['error']);
		self::assertSame(1060, $status['lastCompleted'], 'A run that failed completed nothing.');
		self::assertGreaterThanOrEqual($before, $status['lastEnded']);
		self::assertSame(1060, $status['report']['completedAt'], 'Without a report of its own, the report of the last run stays.');
	}

	public function testFailedRunKeepsTheReportOfWhatItDid(): void {
		$report = new SyncReport(false, 1000);
		$report->exported = 1;
		$report->action('EXPORT P1: Archiv/P1.pdf');
		$report->error('P2: Paperless was unavailable');

		$this->service->failed(new RuntimeException('Paperless returned a document without a valid ID.'), $report);

		$status = $this->service->get();
		self::assertSame('Paperless returned a document without a valid ID.', $status['error']);
		self::assertSame(['EXPORT P1: Archiv/P1.pdf', 'ERROR: P2: Paperless was unavailable'], $status['report']['actions']);
		self::assertSame(['P2: Paperless was unavailable'], $status['report']['errorMessages']);
		self::assertSame(0, $status['report']['completedAt']);
		self::assertSame(1, $status['summary']['exported']);
	}

	public function testKeptReportHoldsAtMostItsLimitOfShortenedLines(): void {
		$report = new SyncReport(true, 1000, 1060);
		$report->error(str_repeat('é', 1500));
		foreach (range(1, StatusService::REPORT_ACTIONS) as $number) {
			$report->notice("SCAN INCOMPLETE: notice {$number}");
		}
		foreach (range(1, SyncReport::ACTION_LIMIT + 3) as $id) {
			$report->action("EXPORT P{$id}: Archiv/{$id}.pdf");
		}
		self::assertSame(4, $report->omittedActions);

		$this->service->completed($report);

		$kept = $this->service->get()['report'];
		self::assertIsArray($kept['actions']);
		self::assertCount(StatusService::REPORT_ACTIONS, $kept['actions']);
		self::assertSame('ERROR: ' . str_repeat('é', 993), $kept['actions'][0]);
		self::assertSame('SCAN INCOMPLETE: notice 199', $kept['actions'][199]);
		self::assertSame([str_repeat('é', 1000)], $kept['errorMessages']);
		self::assertSame(4 + 100, $kept['omittedActions'], 'The changes that the report leaves out, and the 100 lines past the limit of the kept report.');
	}

	public function testUnreadableSummaryOrReportIsIgnored(): void {
		$this->lazyKeys['status_last_report'] = true;
		foreach (['not JSON', '"text"', '[1, 2]'] as $stored) {
			$this->settings['status_last_summary'] = $stored;
			$this->settings['status_last_report'] = $stored;

			$status = $this->service->get();
			self::assertSame([], $status['summary'], $stored);
			self::assertSame([], $status['report'], $stored);
		}
	}

	public function testStatusOfAnEarlierVersionEndedWithTheLastCompletedRun(): void {
		$this->settings['status_last_completed'] = 1060;

		self::assertSame(1060, $this->service->get()['lastEnded']);
	}

	public function testNextScheduledRunFollowsTheIntervalAfterTheLastRun(): void {
		$config = $this->config(true);
		self::assertSame(0, $this->service->nextRun($config), 'Before the first run, the next background job starts one.');

		$this->settings['status_last_started'] = 10_000;
		$this->settings['status_last_completed'] = 10_060;
		self::assertSame(10_060 + 15 * 60, $this->service->nextRun($config));

		$this->settings['status_last_ended'] = 10_120;
		self::assertSame(10_120 + 15 * 60, $this->service->nextRun($config), 'A run that failed counts from its end.');

		$this->settings['status_last_started'] = 10_500;
		self::assertSame(10_500 + 15 * 60, $this->service->nextRun($config), 'A run that is still active counts from its start.');

		self::assertNull($this->service->nextRun($this->config(false)), 'Without scheduled runs there is no next one.');
	}

	public function testOverviewHasTheStatusAndTheNextScheduledRun(): void {
		$this->service->completed(new SyncReport(false, 10_000, 10_060));

		$overview = $this->service->overview($this->config(true));

		self::assertSame($this->service->get() + ['nextRun' => 10_060 + 15 * 60], $overview);
		self::assertNull($this->service->overview($this->config(false))['nextRun']);
	}

	private function config(bool $enabled): SyncConfig {
		$this->token = 'secret-token';

		return $this->configService()->save(['enabled' => $enabled, 'sync_interval_minutes' => 15] + self::validSettings());
	}
}
