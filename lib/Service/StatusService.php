<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;

/**
 * The status of the latest run, kept in the app configuration: when it started and ended, how it
 * ended, its summary, its error, its report for the settings page, and the Paperless that it
 * reached. Every key is lazy: Nextcloud loads the status when the app asks for it, not with every
 * request of every user.
 *
 * Its state is one of never-run, queued (a run by hand waits for its background job), running,
 * dry-run-running, completed, dry-run-completed, completed-with-errors, failed, and interrupted: a
 * run that ended without saying how, because PHP stopped it, which the status tells from its lock
 * and from the signs of life of the run.
 *
 * It also counts the runs in a row that did not succeed, from which the notifications, the setup
 * checks and the status API tell a mirror that keeps failing from one that failed once.
 *
 * @psalm-type Status = array{lastStarted: int, lastCompleted: int, lastEnded: int, lastSuccess: int, state: string, summary: array<string, mixed>, error: string, report: array<string, mixed>, paperlessVersion: string, paperlessApiVersion: string}
 * @psalm-type Failures = array{runs: int, since: int, failed: bool, errors: int, error: string}
 * @psalm-type Health = 'off'|'waiting'|'ok'|'stale'|'errors'|'failed'
 */
final class StatusService {
	/** The most lines of changes that the kept report holds. */
	public const REPORT_ACTIONS = 200;
	/** The most characters of an error and of a line of the kept report. */
	private const LINE_LENGTH = 1000;
	/**
	 * How long an active run may go without a sign of life, in seconds, before the status takes it
	 * for a run that PHP stopped. Nextcloud lets the lock of a run expire after filelocking.ttl, an
	 * hour by default, also while the run is still active, such as the first import of a large
	 * archive; the signs of life tell such a run from one that ended.
	 */
	public const HEARTBEAT_GRACE = 900;
	/** How often a run writes its sign of life at most, in seconds. */
	public const HEARTBEAT_INTERVAL = 60;
	/**
	 * A mirror is stale when its last successful run is older than this many intervals of the
	 * settings, but at least STALE_MINIMUM seconds, so that a cron job that comes a few minutes
	 * late doesn't count as a stopped mirror.
	 */
	public const STALE_INTERVALS = 3;
	public const STALE_MINIMUM = 3600;

	/** The start of the last run, also of a dry-run. */
	private const LAST_STARTED = 'status_last_started';
	/** The last sign of life of the active run, which it writes while it works. */
	private const LAST_HEARTBEAT = 'status_last_heartbeat';
	/** The end of the last run that completed, also with errors. */
	private const LAST_COMPLETED = 'status_last_completed';
	/** The end of the last run, however it ended. */
	private const LAST_ENDED = 'status_last_ended';
	/** The end of the last run, not a dry-run, that completed without errors. */
	private const LAST_SUCCESS = 'status_last_success';
	private const LAST_STATE = 'status_last_state';
	private const LAST_SUMMARY = 'status_last_summary';
	private const LAST_ERROR = 'status_last_error';
	private const LAST_REPORT = 'status_last_report';
	/**
	 * The start and the end of the last run that was not a dry-run, from which the schedule
	 * counts: a dry-run changes nothing, so it doesn't push the next run back.
	 */
	private const LAST_SYNC_STARTED = 'status_last_sync_started';
	private const LAST_SYNC_ENDED = 'status_last_sync_ended';
	/** The version of Paperless-ngx that the last run reached, as Paperless names it, and the version of its API that the run used. */
	private const PAPERLESS_VERSION = 'status_paperless_version';
	private const PAPERLESS_API_VERSION = 'status_paperless_api_version';
	/**
	 * The runs in a row, not dry-runs, that did not succeed since the last one that did: how many,
	 * when the first of them ended, whether the last one failed or completed with errors, and its
	 * error. A successful run deletes it.
	 */
	private const FAILURES = 'status_failures';

	/** Every key of the status. */
	private const KEYS = [
		self::LAST_STARTED, self::LAST_COMPLETED, self::LAST_ENDED, self::LAST_SUCCESS, self::LAST_STATE,
		self::LAST_SUMMARY, self::LAST_ERROR, self::LAST_REPORT, self::LAST_SYNC_STARTED, self::LAST_SYNC_ENDED,
		self::PAPERLESS_VERSION, self::PAPERLESS_API_VERSION, self::FAILURES, self::LAST_HEARTBEAT,
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private ILockingProvider $lockingProvider,
	) {
	}

	/** When this process wrote the last sign of life of its run, so that it writes one a minute at most. */
	private int $heartbeat = 0;

	/**
	 * A run by hand waits for its background job, which Nextcloud starts with its next background
	 * jobs. The run says when it starts; until then, the status keeps the times of the last one.
	 */
	public function queued(): void {
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, 'queued', lazy: true);
	}

	/**
	 * Whether a run is active: it holds the lock, or it gave a sign of life a short while ago. A
	 * run may outlast its lock, which Nextcloud lets expire after filelocking.ttl, and an instance
	 * without locking (filelocking.enabled false) never has one.
	 */
	public function active(): bool {
		return $this->lockingProvider->isLocked(AppConstants::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE) || $this->alive();
	}

	/**
	 * Whether the last run says it is running and gave a sign of life a short while ago, as a run
	 * that outlasted its lock does: no other run may start beside it.
	 */
	public function alive(): bool {
		$state = $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_STATE, 'never-run', lazy: true);

		return str_ends_with($state, 'running') && $this->timeFactory->getTime() - $this->lastSignOfLife() <= self::HEARTBEAT_GRACE;
	}

	/** The active run is still working: it writes a sign of life, once a minute at most. */
	public function heartbeat(): void {
		$now = $this->timeFactory->getTime();
		if ($now - $this->heartbeat < self::HEARTBEAT_INTERVAL) {
			return;
		}
		$this->heartbeat = $now;
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_HEARTBEAT, $now, lazy: true);
	}

	/**
	 * Makes lazy the status that an earlier version stored to be loaded with every request of
	 * Nextcloud, and returns how many keys it changed.
	 */
	public function storeLazily(): int {
		$changed = 0;
		foreach (self::KEYS as $key) {
			if ($this->appConfig->updateLazy(AppConstants::APP_ID, $key, true)) {
				++$changed;
			}
		}

		return $changed;
	}

	public function started(bool $dryRun): void {
		$now = $this->timeFactory->getTime();
		$this->carryOverTheLastRun();
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_STARTED, $now, lazy: true);
		$this->heartbeat = $now;
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_HEARTBEAT, $now, lazy: true);
		if (!$dryRun) {
			$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, $now, lazy: true);
		}
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, $dryRun ? 'dry-run-running' : 'running', lazy: true);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, '', lazy: true);
		// Only a run that reaches Paperless names it, so that a failed connection doesn't show the Paperless of an earlier run.
		$this->appConfig->setValueString(AppConstants::APP_ID, self::PAPERLESS_VERSION, '', lazy: true);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::PAPERLESS_API_VERSION, '', lazy: true);
	}

	/**
	 * The Paperless that a run reached, which the settings page names, so that an administrator
	 * sees which version answers and a report of a problem can name it.
	 *
	 * @param array{version: string, apiVersion: string} $server
	 */
	public function connected(array $server): void {
		$this->appConfig->setValueString(AppConstants::APP_ID, self::PAPERLESS_VERSION, $server['version'], lazy: true);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::PAPERLESS_API_VERSION, $server['apiVersion'], lazy: true);
	}

	public function completed(SyncReport $report): void {
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, $report->completedAt, lazy: true);
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_ENDED, $report->completedAt, lazy: true);
		if (!$report->dryRun) {
			$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $report->completedAt, lazy: true);
			if ($report->errors === 0) {
				$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SUCCESS, $report->completedAt, lazy: true);
				$this->recovered();
			} else {
				// Some documents were not mirrored, so the mirror is not complete either.
				$this->unsuccessful(false, $report->errors, $report->errorMessages[0] ?? '');
			}
		}
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, $report->errors > 0 ? 'completed-with-errors' : ($report->dryRun ? 'dry-run-completed' : 'completed'), lazy: true);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, '', lazy: true);
		$this->keep($report);
	}

	/**
	 * A run that failed leaves the end of the last completed run as it is, and keeps the report of
	 * what it did before it failed. A failure without a report, which can't tell whether it was a
	 * dry-run, counts for the schedule, as every run did before.
	 */
	public function failed(\Throwable $exception, ?SyncReport $report = null): void {
		$now = $this->timeFactory->getTime();
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_ENDED, $now, lazy: true);
		if ($report === null || !$report->dryRun) {
			$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $now, lazy: true);
			$this->unsuccessful(true, $report === null ? 0 : $report->errors, $exception->getMessage());
		}
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, 'failed', lazy: true);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, mb_substr($exception->getMessage(), 0, self::LINE_LENGTH), lazy: true);
		if ($report !== null) {
			$this->keep($report);
		}
	}

	/** @return Status */
	public function get(): array {
		$lastCompleted = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0, lazy: true);
		$lastStarted = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0, lazy: true);
		$state = $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_STATE, 'never-run', lazy: true);
		if (str_ends_with($state, 'running') && !$this->active()) {
			$state = 'interrupted';
		}

		return [
			'lastStarted' => $lastStarted,
			'lastCompleted' => $lastCompleted,
			// A status from before the end of every run was kept: a run that failed set the end of
			// the last completed run then.
			'lastEnded' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_ENDED, $lastCompleted, lazy: true),
			'lastSuccess' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SUCCESS, 0, lazy: true),
			'state' => $state,
			'summary' => $this->decode($this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_SUMMARY, '{}', lazy: true)),
			'error' => $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_ERROR, '', lazy: true),
			'report' => $this->decode($this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_REPORT, '{}', lazy: true)),
			'paperlessVersion' => $this->appConfig->getValueString(AppConstants::APP_ID, self::PAPERLESS_VERSION, '', lazy: true),
			'paperlessApiVersion' => $this->appConfig->getValueString(AppConstants::APP_ID, self::PAPERLESS_API_VERSION, '', lazy: true),
		];
	}

	/**
	 * The runs in a row, not dry-runs, that did not succeed: none after a successful run, and before
	 * the first run.
	 *
	 * @return Failures
	 */
	public function failures(): array {
		$values = $this->decode($this->appConfig->getValueString(AppConstants::APP_ID, self::FAILURES, '{}', lazy: true));

		return [
			'runs' => max(0, self::integer($values, 'runs')),
			'since' => self::integer($values, 'since'),
			'failed' => ($values['failed'] ?? false) === true,
			'errors' => max(0, self::integer($values, 'errors')),
			'error' => isset($values['error']) && is_string($values['error']) ? $values['error'] : '',
		];
	}

	/** @param array<string, mixed> $values */
	private static function integer(array $values, string $key): int {
		return isset($values[$key]) && is_int($values[$key]) ? $values[$key] : 0;
	}

	/**
	 * How the mirror is, as the setup checks and the status API say it:
	 *
	 * - failed or errors: the last run, not a dry-run, failed or completed with errors, also one by
	 *   hand while scheduled runs are off: the mirror is incomplete until a run succeeds.
	 * - off: scheduled runs are off.
	 * - waiting: no run has succeeded yet.
	 * - stale: the last successful run is older than staleAfter(), so runs have stopped.
	 * - ok: the last run succeeded in time.
	 *
	 * @return Health
	 */
	public function health(SyncConfig $config): string {
		$failures = $this->failures();
		if ($failures['runs'] > 0) {
			return $failures['failed'] ? 'failed' : 'errors';
		}
		if (!$config->enabled) {
			return 'off';
		}
		$lastSuccess = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SUCCESS, 0, lazy: true);
		if ($lastSuccess === 0) {
			return 'waiting';
		}

		return $this->timeFactory->getTime() - $lastSuccess > self::staleAfter($config) ? 'stale' : 'ok';
	}

	/** After how many seconds without a successful run a mirror counts as stale. */
	public static function staleAfter(SyncConfig $config): int {
		return max(self::STALE_INTERVALS * $config->syncIntervalMinutes * 60, self::STALE_MINIMUM);
	}

	/**
	 * The status as the settings page shows it, with the time of the next scheduled run.
	 *
	 * @return array{lastStarted: int, lastCompleted: int, lastEnded: int, lastSuccess: int, state: string, summary: array<string, mixed>, error: string, report: array<string, mixed>, paperlessVersion: string, paperlessApiVersion: string, nextRun: int|null}
	 */
	public function overview(SyncConfig $config): array {
		$status = $this->get();
		$status['nextRun'] = $this->nextRun($config);

		return $status;
	}

	/**
	 * When the background job starts the next scheduled run: the interval of the settings after
	 * the start or the end of the last run that was not a dry-run, whichever is later. A dry-run
	 * counts only while it is active, because it holds the lock that the next run needs, and at
	 * most from its start, so that one that never ended holds back no more. A time that has
	 * passed, or 0 before the first run, means the next background job. Null when scheduled runs
	 * are off.
	 *
	 * It reads only the times of the last run, which the background job asks for in every check.
	 */
	public function nextRun(SyncConfig $config): ?int {
		if (!$config->enabled) {
			return null;
		}
		$started = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0, lazy: true);
		$ended = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_ENDED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0, lazy: true), lazy: true);
		// A status of an earlier version, which didn't tell runs and dry-runs apart, counts from its
		// last run of any kind until the first run after the update takes that run over.
		// started() writes both keys from then on, also on a new installation.
		$last = max(
			$this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, $started, lazy: true),
			$this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $ended, lazy: true),
		);
		if ($started > $ended) {
			$last = max($last, $started);
		}

		return $last > 0 ? $last + $config->syncIntervalMinutes * 60 : 0;
	}

	/** The start of the last run or its last sign of life, whichever came later. */
	private function lastSignOfLife(): int {
		return max(
			$this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0, lazy: true),
			$this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_HEARTBEAT, 0, lazy: true),
		);
	}

	/**
	 * Before the first run of this version, of either kind, takes over the last run of the status
	 * as the last real run, once: an earlier version didn't tell runs and dry-runs apart, so its
	 * last run counts for the schedule, as it did then. A new installation takes over no run, so
	 * that the dry-runs it starts first, before scheduled runs are turned on, never count once they
	 * have ended.
	 */
	private function carryOverTheLastRun(): void {
		if ($this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, -1, lazy: true) >= 0) {
			return;
		}
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0, lazy: true), lazy: true);
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_ENDED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0, lazy: true), lazy: true), lazy: true);
	}

	/** A run, not a dry-run, that did not succeed: one more in the row, which starts with the first. */
	private function unsuccessful(bool $failed, int $errors, string $error): void {
		$failures = $this->failures();
		$this->appConfig->setValueString(AppConstants::APP_ID, self::FAILURES, json_encode([
			'runs' => $failures['runs'] + 1,
			'since' => $failures['runs'] > 0 ? $failures['since'] : $this->timeFactory->getTime(),
			'failed' => $failed,
			'errors' => $errors,
			'error' => mb_substr($error, 0, self::LINE_LENGTH),
		], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), lazy: true);
	}

	/** A successful run ends the row of those that did not succeed; one after another success writes nothing. */
	private function recovered(): void {
		if ($this->failures()['runs'] > 0) {
			$this->appConfig->deleteKey(AppConstants::APP_ID, self::FAILURES);
		}
	}

	/**
	 * Keeps the summary and the report of a run: its counts, its first errors and its first lines
	 * of changes, each shortened; the lines that it leaves out count as omitted.
	 */
	private function keep(SyncReport $report): void {
		$summary = $report->summary();
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_SUMMARY, json_encode($summary, JSON_THROW_ON_ERROR), lazy: true);
		$shorten = static fn (string $line): string => mb_substr($line, 0, self::LINE_LENGTH);
		$kept = $summary;
		$kept['omittedActions'] = $report->omittedActions + max(0, count($report->actions) - self::REPORT_ACTIONS);
		$kept['errorMessages'] = array_map($shorten, $report->errorMessages);
		$kept['actions'] = array_map($shorten, array_slice($report->actions, 0, self::REPORT_ACTIONS));
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_REPORT, json_encode($kept, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), lazy: true);
	}

	/** @return array<string, mixed> the members of the JSON object, or none when it holds no object */
	private function decode(string $json): array {
		/** @psalm-suppress MixedAssignment */
		$decoded = json_decode($json, true);
		$values = [];
		if (is_array($decoded)) {
			/** @psalm-suppress MixedAssignment */
			foreach ($decoded as $key => $value) {
				if (is_string($key)) {
					$values[$key] = $value;
				}
			}
		}

		return $values;
	}
}
