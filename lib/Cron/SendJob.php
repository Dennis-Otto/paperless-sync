<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Cron;

use OCA\PaperlessSync\Service\SendService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sends the files that people queued with "Send to Paperless" in Files and follows the tasks of
 * their uploads, with every run of the background jobs: a round without such files ends after one
 * query of the database.
 */
final class SendJob extends TimedJob {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		ITimeFactory $time,
		private SendService $sendService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// With each run of cron, which Nextcloud starts every five minutes, so that a file goes soon.
		$this->setInterval(60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		try {
			$this->sendService->process();
		} catch (\Throwable $exception) {
			// Such as Paperless refusing the token for every request: the files wait for the next round.
			$this->logger->error('Paperless Sync could not send the files queued in Files: {error}', [
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}
}
