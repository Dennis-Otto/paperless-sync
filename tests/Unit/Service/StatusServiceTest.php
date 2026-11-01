<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatusServiceTest extends TestCase {
	use InMemoryConfiguration;

	private TestClock $clock;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** Whether a run holds its lock. */
	private bool $locked = false;
	private StatusService $service;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->locking = $this->createStub(ILockingProvider::class);
		$this->locking->method('isLocked')->willReturnCallback(fn (string $path, int $type): bool => $this->locked
			&& $path === 'paperless_sync::synchronization'
			&& $type === ILockingProvider::LOCK_EXCLUSIVE);
		$this->service = $this->statusService($this->clock, $this->locking);
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
			'errorRecord' => [],
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

	/** "Synchronize now" queues a run in a background job, which hasn't started yet. */
	public function testQueuedRunKeepsTheTimesAndTheReportOfTheLastRun(): void {
		$this->service->completed(new SyncReport(false, 1000, 1060));

		$this->service->queued();

		$status = $this->service->get();
		self::assertSame('queued', $status['state']);
		self::assertSame(1000, $status['report']['startedAt']);
		self::assertSame(1060, $status['lastEnded']);
		self::assertSame(1060, $status['lastSuccess']);

		$this->service->started(false);
		self::assertSame('running', $this->service->get()['state'], 'The run says when it starts.');
	}

	public function testRunIsActiveWhileItHoldsItsLock(): void {
		self::assertFalse($this->service->active());

		$this->locked = true;

		self::assertTrue($this->service->active());
	}

	/**
	 * PHP may stop a run before it can say how it ended, such as a web server that ends a long
	 * request or a container that stops: a run whose lock is gone and that gave no sign of life
	 * for a while was interrupted.
	 */
	public function testRunThatLostItsLockAndItsSignsOfLifeWasInterrupted(): void {
		$this->service->completed(new SyncReport(false, 1000, 1060));
		foreach (['running', 'dry-run-running'] as $state) {
			$this->settings['status_last_state'] = $state;
			$this->settings['status_last_started'] = $this->clock->time - StatusService::HEARTBEAT_GRACE - 1;

			$status = $this->service->get();

			self::assertSame('interrupted', $status['state'], $state);
			self::assertFalse($this->service->active());
			self::assertSame($state, $this->settings['status_last_state'], 'Only what the status says changes, not what it keeps.');
			self::assertSame(1060, $status['lastEnded']);
		}
	}

	public function testRunThatHoldsItsLockIsActiveAlsoWithoutSignsOfLife(): void {
		$this->settings['status_last_state'] = 'running';
		$this->settings['status_last_started'] = $this->clock->time - 7200;
		$this->locked = true;

		self::assertSame('running', $this->service->get()['state']);
	}

	/**
	 * Nextcloud lets the lock of a run expire after filelocking.ttl, an hour by default, also while
	 * the run is still active, such as the first import of a large archive; and an instance
	 * without locking never has one. Its signs of life keep it active, so that no other run starts
	 * beside it.
	 */
	public function testRunThatOutlastedItsLockStaysActiveWhileItGivesSignsOfLife(): void {
		$this->service->started(false);
		$this->clock->advance(7200);
		$this->service->heartbeat();
		$this->clock->advance(StatusService::HEARTBEAT_GRACE);

		self::assertTrue($this->service->alive());
		self::assertTrue($this->service->active());
		self::assertSame('running', $this->service->get()['state']);

		$this->clock->advance(1);
		self::assertFalse($this->service->active(), 'A run that gave no sign of life for a while has ended.');
		self::assertSame('interrupted', $this->service->get()['state']);
	}

	public function testRecentRunWithoutALockIsActive(): void {
		$this->service->started(true);
		$this->clock->advance(StatusService::HEARTBEAT_GRACE);

		self::assertTrue($this->service->active());
		self::assertSame('dry-run-running', $this->service->get()['state']);
	}

	/** A run writes a sign of life once a minute at most, not for every document. */
	public function testSignsOfLifeAreWrittenOnceAMinuteAtMost(): void {
		$this->service->started(false);
		$started = $this->clock->time;

		$this->clock->advance(StatusService::HEARTBEAT_INTERVAL - 1);
		$this->service->heartbeat();
		self::assertSame($started, $this->settings['status_last_heartbeat']);

		$this->clock->advance(1);
		$this->service->heartbeat();
		self::assertSame($started + StatusService::HEARTBEAT_INTERVAL, $this->settings['status_last_heartbeat']);
	}

	public function testOnlyARunningRunIsAlive(): void {
		$this->settings['status_last_started'] = $this->clock->time;
		foreach (['never-run', 'queued', 'completed', 'failed', 'interrupted'] as $state) {
			$this->settings['status_last_state'] = $state;

			self::assertFalse($this->service->alive(), $state);
		}
	}

	public function testOnlyARunCanBeInterrupted(): void {
		$this->settings['status_last_started'] = $this->clock->time - 7200;
		foreach (['queued', 'completed', 'failed'] as $state) {
			$this->settings['status_last_state'] = $state;

			self::assertSame($state, $this->service->get()['state']);
		}
	}

	public function testCompletedRunKeepsItsSummaryAndItsReport(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->exported = 2;
		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/P1.pdf');

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
		self::assertSame(1060, $this->service->lastSuccess(), 'The note in Files asks for this time alone.');
	}

	public function testNoRunHasSucceededYet(): void {
		self::assertSame(0, $this->service->lastSuccess());
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

	/**
	 * The error of a failed run stays English, as in the log, with its record, its text and its
	 * parameters, each value shortened, which the settings page translates; the next run clears it.
	 */
	public function testFailedRunKeepsTheRecordOfItsError(): void {
		$this->service->failed(new InvalidSettingException('Nextcloud user %s does not exist.', [str_repeat('ä', 1500)], 'target_user'));

		$status = $this->service->get();
		self::assertSame('Nextcloud user ' . str_repeat('ä', 985), $status['error']);
		self::assertSame([
			'message' => 'Nextcloud user ' . str_repeat('ä', 1000) . ' does not exist.',
			'text' => 'Nextcloud user %s does not exist.',
			'parameters' => [str_repeat('ä', 1000)],
			'setting' => 'target_user',
		], $status['errorRecord']);

		$this->service->started(false);
		self::assertSame([], $this->service->get()['errorRecord']);
		$this->service->failed(new RuntimeException('Paperless was unavailable'));
		self::assertSame(['message' => 'Paperless was unavailable'], $this->service->get()['errorRecord'], 'What Paperless said stays as it came.');
		$this->service->completed(new SyncReport(false, TestClock::START, TestClock::START + 60));
		self::assertSame([], $this->service->get()['errorRecord']);
	}

	/** The report keeps the record of each error next to its English text, also in its entries, each value shortened. */
	public function testKeptReportHoldsTheRecordsOfItsErrors(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->error(new RunError('Tags P%1$s: %2$s', ['7', RunError::plain(str_repeat('é', 1500))]), 7, 'Archiv/a.pdf');
		$report->error('Paperless timed out');

		$this->service->completed($report);

		$kept = $this->service->get()['report'];
		$record = ['message' => 'Tags P7: ' . str_repeat('é', 1000), 'text' => 'Tags P%1$s: %2$s', 'parameters' => ['7', ['message' => str_repeat('é', 1000)]]];
		self::assertSame(['Tags P7: ' . str_repeat('é', 991), 'Paperless timed out'], $kept['errorMessages']);
		self::assertSame([$record, ['message' => 'Paperless timed out']], $kept['errorRecords']);
		self::assertIsArray($kept['entries']);
		self::assertSame($record, $kept['entries'][0]['error']);
		self::assertSame(['type' => 'error', 'detail' => 'Paperless timed out'], $kept['entries'][1], 'A plain error is its detail.');
	}

	public function testFailedRunKeepsTheReportOfWhatItDid(): void {
		$report = new SyncReport(false, 1000);
		$report->exported = 1;
		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/P1.pdf');
		$report->error('P2: Paperless was unavailable');

		$this->service->failed(new RuntimeException('Paperless returned a document without a valid ID.'), $report);

		$status = $this->service->get();
		self::assertSame('Paperless returned a document without a valid ID.', $status['error']);
		self::assertSame(['EXPORT P1: Archiv/P1.pdf', 'ERROR: P2: Paperless was unavailable'], $status['report']['actions']);
		self::assertSame(['P2: Paperless was unavailable'], $status['report']['errorMessages']);
		self::assertSame([
			['type' => 'export', 'document' => 1, 'path' => 'Archiv/P1.pdf'],
			['type' => 'error', 'detail' => 'P2: Paperless was unavailable'],
		], $status['report']['entries'], 'The settings page shows the lines by their entries.');
		self::assertSame(0, $status['report']['completedAt']);
		self::assertSame(1, $status['summary']['exported']);
	}

	public function testKeptReportHoldsAtMostItsLimitOfShortenedLines(): void {
		$report = new SyncReport(true, 1000, 1060);
		$report->error(str_repeat('é', 1500));
		foreach (range(1, StatusService::REPORT_ACTIONS) as $number) {
			$report->notice(SyncReport::TYPE_SCAN_INCOMPLETE, "SCAN INCOMPLETE: notice {$number}");
		}
		foreach (range(1, SyncReport::ACTION_LIMIT + 3) as $id) {
			$report->change(SyncReport::TYPE_EXPORT, $id, "Archiv/{$id}.pdf");
		}
		self::assertSame(4, $report->omittedActions);

		$this->service->completed($report);

		$kept = $this->service->get()['report'];
		self::assertIsArray($kept['actions']);
		self::assertCount(StatusService::REPORT_ACTIONS, $kept['actions']);
		self::assertSame('ERROR: ' . str_repeat('é', 993), $kept['actions'][0]);
		self::assertSame('SCAN INCOMPLETE: notice 199', $kept['actions'][199]);
		self::assertIsArray($kept['entries']);
		self::assertCount(StatusService::REPORT_ACTIONS, $kept['entries'], 'An entry for every kept line.');
		self::assertSame(['type' => 'error', 'detail' => str_repeat('é', 1000)], $kept['entries'][0]);
		self::assertSame(['type' => 'scan-incomplete'], $kept['entries'][199]);
		self::assertSame([str_repeat('é', 1000)], $kept['errorMessages']);
		self::assertSame(4 + 100, $kept['omittedActions'], 'The changes that the report leaves out, and the 100 lines past the limit of the kept report.');
	}

	/**
	 * A line of a run may quote a name from Paperless that is not valid UTF-8; the report keeps it,
	 * with a replacement for the invalid byte: a question mark from PHP 8.3, U+FFFD before.
	 */
	public function testReportKeepsALineThatIsNotValidUtf8(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->change(SyncReport::TYPE_EXPORT, 1, "Archiv/Rechnung \xC3(.pdf");

		$this->service->completed($report);

		$actions = $this->service->get()['report']['actions'];
		self::assertIsArray($actions);
		self::assertCount(1, $actions);
		self::assertMatchesRegularExpression('#^EXPORT P1: Archiv/Rechnung [?\x{FFFD}]\(\.pdf$#u', $actions[0]);
	}

	public function testKeptEntriesShortenTheirTextsAndKeepTheirNumbers(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->uploadFailed('Eingang/' . str_repeat('a', 1200), 'Fehler/' . str_repeat('b', 1200), str_repeat('c', 1200));
		$report->waiting(7, 'Archiv/7.pdf', 1, 3);

		$this->service->completed($report);

		self::assertSame([
			[
				'type' => 'import-error',
				'path' => 'Eingang/' . str_repeat('a', 992),
				'target' => 'Fehler/' . str_repeat('b', 993),
				'detail' => str_repeat('c', 1000),
			],
			['type' => 'wait', 'document' => 7, 'path' => 'Archiv/7.pdf', 'values' => ['scans' => 1, 'limit' => 3]],
		], $this->service->get()['report']['entries']);
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
		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/P1.pdf');
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

	public function testNoRunFailedBeforeTheFirstRun(): void {
		self::assertSame(['runs' => 0, 'since' => 0, 'failed' => false, 'errors' => 0, 'error' => ''], $this->service->failures());
	}

	/**
	 * Runs that failed and runs that completed with errors count in one row, which the first
	 * keeps the start of and the last its error, until a successful run ends it.
	 */
	public function testRunsThatDidNotSucceedCountInARowUntilASuccess(): void {
		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(false, TestClock::START));
		self::assertSame(['runs' => 1, 'since' => TestClock::START, 'failed' => true, 'errors' => 0, 'error' => 'Paperless is down.'], $this->service->failures());

		$this->clock->advance(900);
		$withErrors = new SyncReport(false, $this->clock->time, $this->clock->time + 60);
		$withErrors->error('P123: the download failed');
		$withErrors->error('P124: the download failed');
		$this->service->completed($withErrors);
		self::assertSame(['runs' => 2, 'since' => TestClock::START, 'failed' => false, 'errors' => 2, 'error' => 'P123: the download failed'], $this->service->failures());

		$this->clock->advance(900);
		$this->service->failed(new RuntimeException(str_repeat('x', 1200)));
		$failures = $this->service->failures();
		self::assertSame(3, $failures['runs'], 'A failure without a report counts as a run.');
		self::assertSame(1000, mb_strlen($failures['error']));

		$this->service->completed(new SyncReport(false, $this->clock->time, $this->clock->time + 60));
		self::assertSame(0, $this->service->failures()['runs']);
		self::assertArrayNotHasKey('status_failures', $this->settings, 'A success deletes the row.');
	}

	public function testDryRunsNeitherCountNorEndTheRow(): void {
		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(true, TestClock::START));
		$withErrors = new SyncReport(true, TestClock::START, TestClock::START + 60);
		$withErrors->error('P123: the download failed');
		$this->service->completed($withErrors);
		self::assertSame(0, $this->service->failures()['runs'], 'A dry-run changes nothing in the mirror.');

		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(false, TestClock::START));
		$this->service->completed(new SyncReport(true, TestClock::START, TestClock::START + 60));
		self::assertSame(1, $this->service->failures()['runs'], 'A dry-run that succeeds does not mean that the mirror works.');
	}

	public function testASuccessAfterASuccessWritesNothing(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);
		$appConfig->expects(self::never())->method('deleteKey');

		(new StatusService($appConfig, $this->clock, $this->locking, $this->systemConfig()))->completed(new SyncReport(false, 1000, 1060));
	}

	public function testUnreadableFailuresCountAsNone(): void {
		$this->settings['status_failures'] = '{"runs":"3","since":"yesterday","failed":1,"errors":-2,"error":[]}';

		self::assertSame(['runs' => 0, 'since' => 0, 'failed' => false, 'errors' => 0, 'error' => ''], $this->service->failures());
	}

	public function testHealthFollowsTheRunsThatAreNoDryRuns(): void {
		self::assertSame('off', $this->service->health($this->config(false)));
		$config = $this->config(true);
		self::assertSame('waiting', $this->service->health($config), 'Before the first success, the mirror waits for one.');

		$this->service->completed(new SyncReport(false, TestClock::START - 60, TestClock::START));
		self::assertSame('ok', $this->service->health($config));

		$this->clock->advance(StatusService::STALE_MINIMUM);
		self::assertSame('ok', $this->service->health($config), 'Three intervals of 15 minutes are less than the minimum of an hour.');
		$this->clock->advance(1);
		self::assertSame('stale', $this->service->health($config));

		$withErrors = new SyncReport(false, $this->clock->time, $this->clock->time + 60);
		$withErrors->error('P123: the download failed');
		$this->service->completed($withErrors);
		self::assertSame('errors', $this->service->health($config));

		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(false, $this->clock->time));
		self::assertSame('failed', $this->service->health($config));
		self::assertSame('failed', $this->service->health($this->config(false)), 'Turning scheduled runs off does not complete the mirror.');
	}

	/**
	 * Settings that no run can use, without the URL of Paperless, its token or the target user, are
	 * not set up, whatever the runs before them did: those of a new installation, and those after
	 * Disconnect, which deletes the token.
	 */
	public function testSettingsThatNoRunCanUseAreUnconfigured(): void {
		self::assertSame('unconfigured', $this->service->health($this->configService()->get()), 'A new installation has neither a URL nor a token.');

		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(false, TestClock::START));
		self::assertSame('failed', $this->service->health($this->config(true)));
		self::assertSame('unconfigured', $this->service->health($this->configService()->reset()), 'No run can follow Disconnect until the token is saved again.');
	}

	/**
	 * With the schedule on, settings that became incomplete, such as a URL that occ changed and the
	 * token does not follow, make every scheduled run fail: the failure stays the health, so that a
	 * monitor keeps alarming, until a run failed no more.
	 */
	public function testAScheduleWithIncompleteSettingsThatFailsStaysFailed(): void {
		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
		$this->settings['paperless_url'] = 'https://elsewhere.example.test';
		$config = $this->configService()->get();
		self::assertTrue($config->enabled && !$config->complete());
		self::assertSame('unconfigured', $this->service->health($config), 'Before a run failed.');

		$this->service->failed(new RuntimeException('Paperless Sync is not completely configured.'), new SyncReport(false, TestClock::START));

		self::assertSame('failed', $this->service->health($config));
	}

	/**
	 * The last dry-run that completed, also with errors, stays with the documents that Paperless
	 * listed to it, through later runs of either kind; a dry-run that failed completed nothing.
	 */
	public function testKeepsTheLastDryRunThatCompleted(): void {
		self::assertSame(['ended' => 0, 'documents' => 0], $this->service->lastDryRun());
		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(true, TestClock::START));
		self::assertSame(['ended' => 0, 'documents' => 0], $this->service->lastDryRun(), 'A dry-run that failed completed nothing.');

		$dryRun = new SyncReport(true, TestClock::START, TestClock::START + 60);
		$dryRun->activeDocuments = 5000;
		$dryRun->error('P123: the download failed');
		$this->service->completed($dryRun);
		self::assertSame(['ended' => TestClock::START + 60, 'documents' => 5000], $this->service->lastDryRun());

		$this->service->completed(new SyncReport(false, TestClock::START + 120, TestClock::START + 180));
		$this->service->failed(new RuntimeException('Paperless is down.'), new SyncReport(true, TestClock::START + 240));
		self::assertSame(['ended' => TestClock::START + 60, 'documents' => 5000], $this->service->lastDryRun(), 'A run and a dry-run that failed leave it as it is.');
	}

	public function testAMirrorIsStaleAfterThreeIntervals(): void {
		$config = $this->configService()->save(['enabled' => true, 'sync_interval_minutes' => 120] + self::validSettings(), 'secret-token');

		self::assertSame(3 * 120 * 60, StatusService::staleAfter($config));
		self::assertSame(StatusService::STALE_MINIMUM, StatusService::staleAfter($this->config(true)));
	}

	private function config(bool $enabled): SyncConfig {
		return $this->configService()->save(['enabled' => $enabled, 'sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
	}
}
