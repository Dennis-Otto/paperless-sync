<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Cron;

use OCA\PaperlessSync\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The run that "Synchronize now" queues: Nextcloud starts it with its next background jobs, once,
 * and the request that queued it has answered long before. A run may take longer than a web server
 * or a proxy waits for an answer; here, it takes as long as it needs, as a scheduled run does, and
 * the settings page follows it through the status.
 */
final class ManualSyncJob extends QueuedJob {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		ITimeFactory $time,
		private SyncService $syncService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument the administrator who pressed the button, as {"author": user ID} */
	protected function run(mixed $argument): void {
		/** @psalm-suppress MixedAssignment the argument is the JSON that the job list stored, checked below */
		$author = is_array($argument) ? ($argument['author'] ?? '') : '';
		$author = is_string($author) ? $author : '';
		try {
			$this->syncService->run(false, author: $author);
		} catch (LockedException) {
			// A run that started after the button was pressed, such as a scheduled one, holds the
			// lock: it makes the same changes, and the status follows it.
			$this->logger->info('Paperless synchronization by hand skipped: another run is active.');
		} catch (\Throwable $exception) {
			// Only the message: the synchronization has logged the failure with its trace already.
			$this->logger->error('Paperless synchronization by hand failed: {error}', ['error' => $exception->getMessage()]);
		}
	}
}
