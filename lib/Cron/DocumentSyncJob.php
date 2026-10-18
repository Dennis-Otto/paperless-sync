<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Cron;

use OCA\PaperlessSync\Service\RunsOffException;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The run of one document that the webhook of a Paperless workflow named, when the webhook leaves
 * it to the background jobs or another run held the lock: Nextcloud starts it with its next
 * background jobs, once for each document however often Paperless called, as it queues a job with
 * the same argument once.
 */
final class DocumentSyncJob extends QueuedJob {
	/** How long the run waits for another run to end before it tries again, in seconds. */
	public const RETRY_DELAY = 60;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		ITimeFactory $time,
		private SyncService $syncService,
		private WebhookService $webhook,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument the document, as {"document": ID} */
	protected function run(mixed $argument): void {
		/** @psalm-suppress MixedAssignment the argument is the JSON that the job list stored, checked below */
		$document = is_array($argument) ? ($argument['document'] ?? null) : null;
		if (!is_int($document) || $document < 1) {
			$this->logger->warning('Paperless synchronization of a document skipped: the job names no document.');

			return;
		}
		try {
			$report = $this->syncService->syncDocument($document);
			$this->webhook->called($document, WebhookService::result($report), $report->errorMessages[0] ?? '');
		} catch (LockedException) {
			// Another run holds the lock, such as a scheduled run. The run of the document waits a
			// minute, not for the next job of this cron run, which would start it again at once.
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAY, $argument);
			$this->logger->info('Paperless synchronization of document {document} postponed: another run is active.', ['document' => $document]);
		} catch (RunsOffException $exception) {
			// An administrator turned synchronization off since Paperless called: the call changes nothing.
			$this->webhook->called($document, 'off', $exception->getMessage());
			$this->logger->info('Paperless synchronization of document {document} skipped: {reason}', ['document' => $document, 'reason' => $exception->getMessage()]);
		} catch (\Throwable $exception) {
			// Only the message: the synchronization has logged the failure with its trace already.
			$this->webhook->called($document, 'failed', $exception->getMessage());
			$this->logger->error('Paperless synchronization of document {document} failed: {error}', ['document' => $document, 'error' => $exception->getMessage()]);
		}
	}
}
