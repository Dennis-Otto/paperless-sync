<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\StatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * The notifications that a NotificationPublisher sends and removes, as the notifications app of
 * Nextcloud would keep them: notify() adds one, and markProcessed() removes every one whose
 * fields match the fields that it sets. The groups are those of $groupMembers, by their IDs,
 * with the IDs of their members and whether each is enabled. Needs InMemoryConfiguration.
 */
trait RecordedNotifications {
	/** @var list<TestNotification> */
	private array $notifications = [];
	/** @var list<TestNotification> the notifications that markProcessed() named */
	private array $processedNotifications = [];
	/** @var array<string, array<string, bool>> */
	private array $groupMembers = ['admin' => ['e2e-admin' => true, 'second-admin' => true, 'former-admin' => false]];
	private ?\Throwable $notificationFailure = null;

	private function notificationPublisher(StatusService $status, NextcloudStorageInterface $storage, ITimeFactory $clock, LoggerInterface $logger): NotificationPublisher {
		return new NotificationPublisher($this->notificationManager(), $this->groupManager(), $this->appConfig(), $status, $storage, $clock, $logger);
	}

	private function notificationManager(): IManager {
		$manager = $this->createStub(IManager::class);
		$manager->method('createNotification')->willReturnCallback(static fn (): INotification => new TestNotification());
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			if ($this->notificationFailure !== null) {
				throw $this->notificationFailure;
			}
			self::assertInstanceOf(TestNotification::class, $notification);
			self::assertTrue($notification->isValid(), 'Nextcloud refuses a notification without its app, user, time, object or subject.');
			$this->notifications[] = $notification;
		});
		$manager->method('markProcessed')->willReturnCallback(function (INotification $notification): void {
			self::assertInstanceOf(TestNotification::class, $notification);
			$this->processedNotifications[] = $notification;
			$this->notifications = array_values(array_filter(
				$this->notifications,
				static fn (TestNotification $kept): bool => $kept->getApp() !== $notification->getApp()
					|| $kept->getObjectType() !== $notification->getObjectType()
					|| $kept->getObjectId() !== $notification->getObjectId()
					|| ($notification->getUser() !== '' && $kept->getUser() !== $notification->getUser()),
			));
		});

		return $manager;
	}

	private function groupManager(): IGroupManager {
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $groupId): ?IGroup {
			if (!isset($this->groupMembers[$groupId])) {
				return null;
			}
			$users = [];
			foreach ($this->groupMembers[$groupId] as $userId => $enabled) {
				$user = $this->createStub(IUser::class);
				$user->method('getUID')->willReturn($userId);
				$user->method('isEnabled')->willReturn($enabled);
				$users[] = $user;
			}
			$group = $this->createStub(IGroup::class);
			$group->method('getUsers')->willReturn($users);

			return $group;
		});

		return $groups;
	}

	/** @return list<string> the users of the notifications with the subject, in the order they got them */
	private function notifiedUsers(string $subject): array {
		return array_values(array_map(
			static fn (TestNotification $notification): string => $notification->getUser(),
			array_filter($this->notifications, static fn (TestNotification $notification): bool => $notification->getSubject() === $subject),
		));
	}
}
