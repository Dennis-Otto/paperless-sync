<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\IAppConfig;

final class StatusService {
	private const LAST_STARTED = 'status_last_started';
	private const LAST_COMPLETED = 'status_last_completed';
	private const LAST_STATE = 'status_last_state';
	private const LAST_SUMMARY = 'status_last_summary';
	private const LAST_ERROR = 'status_last_error';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function started(bool $dryRun): void {
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_STARTED, time());
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, $dryRun ? 'dry-run-running' : 'running');
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, '');
	}

	public function completed(SyncReport $report): void {
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, $report->completedAt);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, $report->errors > 0 ? 'completed-with-errors' : ($report->dryRun ? 'dry-run-completed' : 'completed'));
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_SUMMARY, json_encode($report->summary(), JSON_THROW_ON_ERROR));
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, '');
	}

	public function failed(\Throwable $exception): void {
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, time());
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_STATE, 'failed');
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_ERROR, mb_substr($exception->getMessage(), 0, 1000));
	}

	/**
	 * @return array{lastStarted: int, lastCompleted: int, state: string, summary: array<string, mixed>, error: string}
	 * @psalm-suppress MixedAssignment
	 */
	public function get(): array {
		/** @psalm-suppress MixedAssignment */
		$decoded = json_decode($this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_SUMMARY, '{}'), true);
		$summary = [];
		if (is_array($decoded)) {
			foreach ($decoded as $key => $value) {
				if (is_string($key)) {
					/** @psalm-suppress MixedAssignment */
					$summary[$key] = $value;
				}
			}
		}

		return [
			'lastStarted' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_STARTED, 0),
			'lastCompleted' => $this->appConfig->getValueInt(AppConstants::APP_ID, self::LAST_COMPLETED, 0),
			'state' => $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_STATE, 'never-run'),
			'summary' => $summary,
			'error' => $this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_ERROR, ''),
		];
	}
}
