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
use OCP\IAppConfig;

/**
 * The status of the latest run, kept in the app configuration: when it started and ended, how it
 * ended, its summary, its error, its report for the settings page, and the Paperless that it
 * reached.
 *
 * @psalm-type Status = array{lastStarted: int, lastCompleted: int, lastEnded: int, lastSuccess: int, state: string, summary: array<string, mixed>, error: string, report: array<string, mixed>, paperlessVersion: string, paperlessApiVersion: string}
 */
final class StatusService {
	/** The most lines of changes that the kept report holds. */
	public const REPORT_ACTIONS = 200;
	/** The most characters of an error and of a line of the kept report. */
	private const LINE_LENGTH = 1000;

	/** The start of the last run, also of a dry-run. */
	private const LAST_STARTED = 'status_last_started';
	/** The end of the last run that completed, also with errors. */
	private const LAST_COMPLETED = 'status_last_completed';
	/** The end of the last run, however it ended. */
	private const LAST_ENDED = 'status_last_ended';
	/** The end of the last run, not a dry-run, that completed without errors. */
	private const LAST_SUCCESS = 'status_last_success';
	private const LAST_STATE = 'status_last_state';
	private const LAST_SUMMARY = 'status_last_summary';
	private const LAST_ERROR = 'status_last_error';
	/** The report of the last run; lazy, so that Nextcloud loads it only when it is asked for. */
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

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function started(bool $dryRun): void {
		$now = time();
		$this->carryOverTheLastRun();
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_STARTED, $now);
		if (!$dryRun) {
			$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, $now);
		}
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, $dryRun ? 'dry-run-running' : 'running');
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, '');
	}

	/**
	 * The Paperless that a run reached, which the settings page names, so that an administrator
	 * sees which version answers and a report of a problem can name it.
	 *
	 * @param array{version: string, apiVersion: string} $server
	 */
	public function connected(array $server): void {
		$this->appConfig->setValueString(AppConstants::APP_ID, self::PAPERLESS_VERSION, $server['version']);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::PAPERLESS_API_VERSION, $server['apiVersion']);
	}

	public function completed(SyncReport $report): void {
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, $report->completedAt);
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_ENDED, $report->completedAt);
		if (!$report->dryRun) {
			$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $report->completedAt);
			if ($report->errors === 0) {
				$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SUCCESS, $report->completedAt);
			}
		}
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, $report->errors > 0 ? 'completed-with-errors' : ($report->dryRun ? 'dry-run-completed' : 'completed'));
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, '');
		$this->keep($report);
	}

	/**
	 * A run that failed leaves the end of the last completed run as it is, and keeps the report of
	 * what it did before it failed. A failure without a report, which can't tell whether it was a
	 * dry-run, counts for the schedule, as every run did before.
	 */
	public function failed(\Throwable $exception, ?SyncReport $report = null): void {
		$now = time();
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_ENDED, $now);
		if ($report === null || !$report->dryRun) {
			$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $now);
		}
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, 'failed');
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, mb_substr($exception->getMessage(), 0, self::LINE_LENGTH));
		if ($report !== null) {
			$this->keep($report);
		}
	}

	/** @return Status */
	public function get(): array {
		$lastCompleted = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0);

		return [
			'lastStarted' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0),
			'lastCompleted' => $lastCompleted,
			// A status from before the end of every run was kept: a run that failed set the end of
			// the last completed run then.
			'lastEnded' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_ENDED, $lastCompleted),
			'lastSuccess' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SUCCESS, 0),
			'state' => $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_STATE, 'never-run'),
			'summary' => $this->decode($this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_SUMMARY, '{}')),
			'error' => $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_ERROR, ''),
			'report' => $this->decode($this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_REPORT, '{}', lazy: true)),
			'paperlessVersion' => $this->appConfig->getValueString(AppConstants::APP_ID, self::PAPERLESS_VERSION, ''),
			'paperlessApiVersion' => $this->appConfig->getValueString(AppConstants::APP_ID, self::PAPERLESS_API_VERSION, ''),
		];
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
	 * It reads only the times of the last run, because the background job asks for it in every
	 * check, and the lazy report stays unloaded then.
	 */
	public function nextRun(SyncConfig $config): ?int {
		if (!$config->enabled) {
			return null;
		}
		$started = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0);
		$ended = $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_ENDED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0));
		// A status of an earlier version, which didn't tell runs and dry-runs apart, counts from its
		// last run of any kind until the first run after the update takes that run over.
		// started() writes both keys from then on, also on a new installation.
		$last = max(
			$this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, $started),
			$this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $ended),
		);
		if ($started > $ended) {
			$last = max($last, $started);
		}

		return $last > 0 ? $last + $config->syncIntervalMinutes * 60 : 0;
	}

	/**
	 * Before the first run of this version, of either kind, takes over the last run of the status
	 * as the last real run, once: an earlier version didn't tell runs and dry-runs apart, so its
	 * last run counts for the schedule, as it did then. A new installation takes over no run, so
	 * that the dry-runs it starts first, before scheduled runs are turned on, never count once they
	 * have ended.
	 */
	private function carryOverTheLastRun(): void {
		if ($this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, -1) >= 0) {
			return;
		}
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_STARTED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0));
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_SYNC_ENDED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_ENDED, $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0)));
	}

	/**
	 * Keeps the summary and the report of a run: its counts, its first errors and its first lines
	 * of changes, each shortened; the lines that it leaves out count as omitted.
	 */
	private function keep(SyncReport $report): void {
		$summary = $report->summary();
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_SUMMARY, json_encode($summary, JSON_THROW_ON_ERROR));
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
