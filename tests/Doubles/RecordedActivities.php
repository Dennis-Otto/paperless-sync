<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use Psr\Log\LoggerInterface;

/**
 * The activity manager of Nextcloud, which keeps every entry that the app publishes in
 * $activities, or fails each publication with $activityFailure, as a full database would.
 */
trait RecordedActivities {
	/** @var list<TestActivityEvent> */
	private array $activities = [];
	private ?\Throwable $activityFailure = null;

	private function activityPublisher(NextcloudStorageInterface $storage, LoggerInterface $logger): ActivityPublisher {
		$manager = $this->createStub(IManager::class);
		$manager->method('generateEvent')->willReturnCallback(static fn (): IEvent => new TestActivityEvent());
		$manager->method('publish')->willReturnCallback(function (IEvent $event): void {
			if ($this->activityFailure !== null) {
				throw $this->activityFailure;
			}
			self::assertInstanceOf(TestActivityEvent::class, $event);
			$this->activities[] = $event;
		});

		return new ActivityPublisher($manager, $storage, $logger);
	}
}
