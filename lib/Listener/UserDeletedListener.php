<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Listener;

use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Forgets a user whom Nextcloud deleted, as Nextcloud lets an administrator create a new account
 * with the same ID later, which must get nothing of theirs: their lines of the settings, which a
 * save would refuse from then on, and every state of their archive, their inbox and the files that
 * they sent from Files, which holds the paths and the names of documents. When the user was the
 * target user, every run fails until the settings name another, and the administrators hear of
 * it. A failure is logged, never thrown: the user is deleted all the same.
 *
 * @template-implements IEventListener<Event>
 */
final class UserDeletedListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $config,
		private SyncStateRepositoryInterface $state,
		private NotificationPublisher $notifications,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$userId = $event->getUser()->getUID();
		try {
			$settings = $this->config->forgetUser($userId);
			$states = $this->state->deleteOwner($userId);
			if (mb_strtolower($this->config->targetUser()) === mb_strtolower($userId)) {
				$this->notifications->targetUserDeleted($userId);
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Paperless Sync could not forget the deleted user {user}: {error}', [
				'user' => $userId,
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);
			return;
		}
		if ($settings !== [] || $states > 0) {
			$this->logger->info('Paperless Sync forgot the deleted user {user}: {settings} of its settings and {states} states.', [
				'user' => $userId,
				'settings' => $settings !== [] ? implode(', ', $settings) : 'none',
				'states' => $states,
			]);
		}
	}
}
