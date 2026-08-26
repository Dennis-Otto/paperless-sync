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
		$this->setInterval(300);
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		$config = $this->configService->get();
		if (!$config->enabled) {
			return;
		}
		$status = $this->statusService->get();
		$lastCompleted = max($status['lastCompleted'], $status['lastStarted']);
		if ($lastCompleted > 0 && time() - $lastCompleted < $config->syncIntervalMinutes * 60) {
			return;
		}
		try {
			$this->syncService->run(false);
		} catch (\Throwable $exception) {
			$this->logger->error('Scheduled Paperless synchronization failed', ['exception' => $exception]);
		}
	}
}
