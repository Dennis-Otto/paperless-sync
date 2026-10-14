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
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatusServiceTest extends TestCase {
	use InMemoryConfiguration;

	private TestClock $clock;
	private StatusService $service;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->service = new StatusService($this->appConfig(), $this->clock);
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
			'paperlessVersion' => '',
			'paperlessApiVersion' => '',
		], $this->service->get());
	}

	public function testKeepsThePaperlessOfTheLastConnection(): void {
		$this->service->connected(['version' => '3.3.0', 'apiVersion' => '9']);

		$status = $this->service->get();
		self::assertSame('3.3.0', $status['paperlessVersion']);
		self::assertSame('9', $status['paperlessApiVersion']);

		$this->service->connected(['version' => '', 'apiVersion' => '9']);
		self::assertSame('', $this->service->get()['paperlessVersion'], 'A Paperless that names no version has none, not the one before.');
	}

	public function testStartedRunNamesNoPaperlessUntilItReachesOne(): void {
		$this->service->connected(['version' => '3.3.0', 'apiVersion' => '9']);

		$this->service->started(false);

		$status = $this->service->get();
		self::assertSame('', $status['paperlessVersion'], 'A run that fails to connect must not show the Paperless of the run before.');
		self::assertSame('', $status['paperlessApiVersion']);
	}

	public function testStartedRunClearsThePreviousError(): void {
		$this->settings['status_last_error'] = 'Paperless was unavailable';

		$this->service->started(false);

		$status = $this->service->get();
		self::assertSame('running', $status['state']);
		self::assertSame('', $status['error']);
		self::assertSame(TestClock::START, $status['lastStarted']);
		self::assertSame(TestClock::START, $this->settings['status_last_sync_started'], 'The schedule counts from the start of a real run.');

		$this->settings['status_last_sync_started'] = 1;
		$this->service->started(true);
		self::assertSame('dry-run-running', $this->service->get()['state']);
		self::assertSame(1, $this->settings['status_last_sync_started'], 'A dry-run is no start for the schedule.');
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

	public function testEveryKeyOfTheStatusIsLazy(): void {
		$this->service->started(false);
		$this->service->connected(['version' => '2.20.15', 'apiVersion' => '9']);
		$this->service->completed(new SyncReport(false, 1000, 1060));
		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(false, 1100));

		self::assertNotSame([], $this->settings);
		self::assertSame([], array_keys(array_diff_key($this->settings, $this->lazyKeys)), 'Nextcloud loads the status only when it is asked for, not with every request.');
		self::assertSame('failed', $this->service->get()['state']);
		self::assertSame(['Paperless is down.', '2.20.15'], [$this->service->get()['error'], $this->service->get()['paperlessVersion']]);
	}

	/** The status that an earlier version stored, which Nextcloud loaded with every request, becomes lazy. */
	public function testStatusOfAnEarlierVersionBecomesLazy(): void {
		$this->settings = ['status_last_state' => 'completed', 'status_last_summary' => '{}', 'status_last_report' => '{}', 'sync_enabled' => true];
		$this->lazyKeys = ['status_last_report' => true];

		self::assertSame(2, $this->service->storeLazily());

		$lazy = array_keys($this->lazyKeys);
		sort($lazy);
		self::assertSame(['status_last_report', 'status_last_state', 'status_last_summary'], $lazy, 'The settings of the app are the concern of its settings.');
		self::assertSame(0, $this->service->storeLazily());
		self::assertSame('completed', $this->service->get()['state']);
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

		$this->service->failed(new RuntimeException(str_repeat('ä', 1500)));

		$status = $this->service->get();
		self::assertSame('failed', $status['state']);
		self::assertSame(str_repeat('ä', 1000), $status['error'], 'The error keeps its first thousand characters, not bytes.');
		self::assertSame(1060, $status['lastCompleted'], 'A run that failed completed nothing.');
		self::assertSame(TestClock::START, $status['lastEnded']);
		self::assertSame(1060, $status['report']['completedAt'], 'Without a report of its own, the report of the last run stays.');
	}

	public function testCompletedRunAfterAFailedOneEndsAndClearsItsError(): void {
		$this->service->failed(new RuntimeException('Paperless was unavailable'));

		$this->service->completed(new SyncReport(false, TestClock::START + 60, TestClock::START + 120));

		$status = $this->service->get();
		self::assertSame(TestClock::START + 120, $status['lastEnded'], 'The end of the failed run is no longer the end of the last run.');
		self::assertSame('', $status['error']);
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

	/**
	 * A line of a run may quote a name from Paperless that is not valid UTF-8; the report keeps it,
	 * with a replacement for the invalid byte: a question mark from PHP 8.3, U+FFFD before.
	 */
	public function testReportKeepsALineThatIsNotValidUtf8(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->action("EXPORT P1: Archiv/Rechnung \xC3(.pdf");

		$this->service->completed($report);

		$actions = $this->service->get()['report']['actions'];
		self::assertIsArray($actions);
		self::assertCount(1, $actions);
		self::assertMatchesRegularExpression('#^EXPORT P1: Archiv/Rechnung [?\x{FFFD}]\(\.pdf$#u', $actions[0]);
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

		$this->settings['status_last_started'] = $this->settings['status_last_sync_started'] = 10_000;
		$this->settings['status_last_completed'] = $this->settings['status_last_ended'] = $this->settings['status_last_sync_ended'] = 10_060;
		self::assertSame(10_060 + 15 * 60, $this->service->nextRun($config));

		$this->settings['status_last_started'] = $this->settings['status_last_sync_started'] = 10_100;
		self::assertSame(10_100 + 15 * 60, $this->service->nextRun($config), 'A run that is still active counts from its start.');

		$this->settings['status_last_ended'] = $this->settings['status_last_sync_ended'] = 10_120;
		self::assertSame(10_120 + 15 * 60, $this->service->nextRun($config), 'A run that failed counts from its end.');

		self::assertNull($this->service->nextRun($this->config(false)), 'Without scheduled runs there is no next one.');
	}

	/**
	 * A dry-run changes nothing, so the schedule doesn't count from it: a dry-run at 10:00 with an
	 * interval of an hour leaves the next run where the last run put it. Only while the dry-run is
	 * active, which holds the lock of the synchronization, does the next run wait for it, at most
	 * the interval after its start, so that a dry-run that never ended holds back no more.
	 */
	public function testADryRunDoesNotPostponeTheNextScheduledRun(): void {
		$config = $this->config(true);
		$now = $this->clock->time;
		$this->settings['status_last_started'] = $this->settings['status_last_sync_started'] = $now - 600;
		$this->service->completed(new SyncReport(false, $now - 600, $now - 540));
		$scheduled = $now - 540 + 15 * 60;
		self::assertSame($scheduled, $this->service->nextRun($config));
		self::assertSame($now - 540, $this->settings['status_last_sync_ended'], 'The schedule counts from the end of a real run.');

		$this->service->started(true);
		self::assertSame($now - 600, $this->settings['status_last_sync_started'], 'A dry-run is no start of a run for the schedule.');
		self::assertSame((int)$this->settings['status_last_started'] + 15 * 60, $this->service->nextRun($config), 'An active dry-run holds the lock, so the next run waits for it.');

		// A dry-run that ends in the second it started.
		$this->service->completed(new SyncReport(true, $now, (int)$this->settings['status_last_started']));
		self::assertSame($scheduled, $this->service->nextRun($config), 'A dry-run that completed leaves the schedule alone.');
		self::assertSame($now - 540, $this->settings['status_last_sync_ended']);

		$this->service->started(true);
		$this->service->failed(new RuntimeException('Paperless was unavailable'), new SyncReport(true, $now));
		self::assertSame($scheduled, $this->service->nextRun($config), 'A dry-run that failed leaves the schedule alone.');
		self::assertSame($now - 540, $this->settings['status_last_sync_ended']);
	}

	/**
	 * A real run counts for the schedule however it ends; a failure without a report, which can't
	 * tell whether it was a dry-run, counts as well, as every run did before.
	 */
	public function testARealRunThatFailedCountsFromItsEnd(): void {
		$config = $this->config(true);
		$now = $this->clock->time;

		$this->service->failed(new RuntimeException('Paperless was unavailable'), new SyncReport(false, $now - 30));
		self::assertSame($now + 15 * 60, $this->service->nextRun($config), 'The schedule counts from the end of the failed run.');
		self::assertSame($now, $this->settings['status_last_sync_ended']);
		self::assertSame($now, $this->settings['status_last_ended']);

		$this->settings['status_last_sync_ended'] = 0;
		$this->clock->advance(60);
		$this->service->failed(new RuntimeException('Paperless was unavailable'));
		self::assertSame($now + 60, $this->settings['status_last_sync_ended']);
	}

	/**
	 * The status of an earlier version, which didn't tell runs and dry-runs apart, counts from the
	 * last run of any kind; the first run after the update takes that run over, also a dry-run, so
	 * that a dry-run after the update doesn't count either.
	 */
	public function testStatusOfAnEarlierVersionCountsFromItsLastRun(): void {
		$config = $this->config(true);
		$this->settings['status_last_started'] = 10_000;
		$this->settings['status_last_completed'] = 10_060;
		self::assertSame(10_060 + 15 * 60, $this->service->nextRun($config));

		$this->settings['status_last_ended'] = 10_120;
		self::assertSame(10_120 + 15 * 60, $this->service->nextRun($config));

		$this->service->started(true);
		self::assertSame(10_000, $this->settings['status_last_sync_started'], 'The first run after the update takes the last run over.');
		self::assertSame(10_120, $this->settings['status_last_sync_ended']);
		$this->service->completed(new SyncReport(true, (int)$this->settings['status_last_started'], $this->clock->time));
		self::assertSame(10_120 + 15 * 60, $this->service->nextRun($config), 'A dry-run after the update leaves the schedule alone.');

		$this->service->started(true);
		self::assertSame(10_000, $this->settings['status_last_sync_started'], 'Only the first run after the update takes the last run over.');
	}

	/**
	 * A status of an earlier version without the end of every run takes the end of its last
	 * completed run over.
	 */
	public function testStatusOfAnEarlierVersionWithoutTheEndOfEveryRunTakesItsLastCompletedRunOver(): void {
		$this->settings['status_last_started'] = 10_000;
		$this->settings['status_last_completed'] = 10_060;

		$this->service->started(true);

		self::assertSame(10_000, $this->settings['status_last_sync_started']);
		self::assertSame(10_060, $this->settings['status_last_sync_ended']);
	}

	/**
	 * A new installation runs dry-runs first and turns scheduled runs on after that, as the
	 * settings page recommends. It has no earlier run to take over, so once a dry-run has ended,
	 * the next background job starts the first scheduled run, however long the interval.
	 */
	public function testDryRunsOfANewInstallationDoNotPostponeTheFirstScheduledRun(): void {
		$config = $this->config(true);

		$this->service->started(true);
		self::assertSame(0, $this->settings['status_last_sync_started'], 'A new installation has no real run yet.');
		self::assertSame(0, $this->settings['status_last_sync_ended']);
		self::assertSame((int)$this->settings['status_last_started'] + 15 * 60, $this->service->nextRun($config), 'An active dry-run holds the lock, so the first run waits for it.');

		$this->service->completed(new SyncReport(true, (int)$this->settings['status_last_started'], $this->clock->time));
		self::assertSame(0, $this->service->nextRun($config), 'After the dry-run, the next background job starts the first run.');

		$this->service->started(true);
		$this->service->failed(new RuntimeException('Paperless was unavailable'), new SyncReport(true, $this->clock->time));
		self::assertSame(0, $this->service->nextRun($config), 'A dry-run that failed changes that neither.');
	}

	public function testNextScheduledRunReadsOnlyTheTimesOfTheLastRun(): void {
		$report = new SyncReport(false, 10_000, 10_060);
		$report->action('EXPORT P1: Archiv/P1.pdf');
		$this->service->completed($report);
		$config = $this->config(true);
		$this->lazyReads = [];

		self::assertSame(10_060 + 15 * 60, $this->service->nextRun($config));
		self::assertSame([], array_values(array_filter(array_keys($this->lazyReads), static fn (string $key): bool => !str_ends_with($key, '_started') && !str_ends_with($key, '_ended') && !str_ends_with($key, '_completed'))), 'The background job asks for the next run in every check.');
	}

	public function testOverviewHasTheStatusAndTheNextScheduledRun(): void {
		$this->service->completed(new SyncReport(false, 10_000, 10_060));

		$overview = $this->service->overview($this->config(true));

		self::assertSame($this->service->get() + ['nextRun' => 10_060 + 15 * 60], $overview);
		self::assertNull($this->service->overview($this->config(false))['nextRun']);
	}

	private function config(bool $enabled): SyncConfig {
		return $this->configService()->save(['enabled' => $enabled, 'sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
	}
}
