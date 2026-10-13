<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Cron;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

final class SyncJob extends TimedJob {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		ITimeFactory $time,
		private ConfigService $configService,
		private StatusService $statusService,
		private SyncService $syncService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Every five minutes, the shortest interval that the settings accept.
		$this->setInterval(ConfigService::MINIMUM_INTERVAL_MINUTES * 60);
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		// The settings page shows the same time as the next scheduled run. It counts from the last
		// real run, so that a dry-run doesn't push the schedule back.
		$nextRun = $this->statusService->nextRun($this->configService->get());
		if ($nextRun === null || $this->time->getTime() < $nextRun) {
			return;
		}
		try {
			$this->syncService->run(false);
		} catch (\Throwable $exception) {
			// Only the message: the synchronization has logged the failure of a run with its trace
			// already. A run that didn't start, because another one holds the lock, is named here.
			$this->logger->error('Scheduled Paperless synchronization failed: {error}', ['error' => $exception->getMessage()]);
		}
	}
}
