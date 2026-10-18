<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Notification;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\StatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Tells people in the notifications of Nextcloud what needs them after a run:
 *
 * - The administrators, or the group of the setting GROUP_SETTING, get one notification once the
 *   mirror keeps failing: ALERT_RUNS runs in a row, not dry-runs, did not succeed, or the first of
 *   them ended ALERT_AFTER seconds ago. The next successful run removes it again.
 * - The target user gets one for each file of the inbox that Paperless could not import or refused.
 * - Whoever sent a file to Paperless from Files gets one when its import ended, whatever its end.
 *
 * A dry-run notifies nobody. Notifier puts the notifications into words in the language of the
 * reader.
 */
final class NotificationPublisher {
	public const SUBJECT_FAILING = 'sync_failing';
	public const SUBJECT_IMPORT_FAILED = 'import_failed';
	public const SUBJECT_IMPORT_REJECTED = 'import_rejected';
	/** The ends of the import of a file sent from Files: Paperless has its document now, or had it already, or didn't create it, or refused it, or the file was gone before its upload. */
	public const SUBJECT_SEND_IMPORTED = 'send_imported';
	public const SUBJECT_SEND_KNOWN = 'send_known';
	public const SUBJECT_SEND_FAILED = 'send_failed';
	public const SUBJECT_SEND_REFUSED = 'send_refused';
	public const SUBJECT_SEND_GONE = 'send_gone';
	public const SEND_SUBJECTS = [self::SUBJECT_SEND_IMPORTED, self::SUBJECT_SEND_KNOWN, self::SUBJECT_SEND_FAILED, self::SUBJECT_SEND_REFUSED, self::SUBJECT_SEND_GONE];
	/** The object of the notification of a failing mirror, the same for every recipient, by which a success removes it. */
	public const OBJECT_SYNCHRONIZATION = 'synchronization';
	public const OBJECT_IMPORT = 'import';
	public const OBJECT_SEND = 'send';
	/** The runs in a row that did not succeed before the administrators hear of it. */
	public const ALERT_RUNS = 3;
	/** The seconds since the first run of the row that did not succeed after which they hear of it all the same, for long intervals. */
	public const ALERT_AFTER = 86400;
	/** The most characters of an error or a reason that a notification keeps. */
	public const ERROR_LIMIT = 200;
	/** The app setting with the ID of the group that hears of a failing mirror, `admin` by default. */
	public const GROUP_SETTING = 'notification_group';
	public const DEFAULT_GROUP = 'admin';
	/** When the notification of the current row of failures went out; deleted once a run succeeds. */
	private const ALERTED = 'notification_failing_sent';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IManager $notifications,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private StatusService $status,
		private NextcloudStorageInterface $storage,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The notifications of a run, after StatusService has kept how it ended. A notification that
	 * Nextcloud refuses is logged without failing the run, whose changes are done.
	 *
	 * @param SyncConfig|null $config the settings of the run, null when it failed before it read them
	 */
	public function publish(?SyncConfig $config, SyncReport $report): void {
		if ($report->dryRun) {
			return;
		}
		try {
			$this->alertOrClear();
			if ($config !== null) {
				foreach ($report->importResults() as $result) {
					if ($result['outcome'] !== SyncReport::IMPORTED) {
						$this->notifyImport($config, $result);
					}
				}
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Paperless Sync could not send its notifications: {error}', [
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	/**
	 * Tells whoever sent a file from Files how its import ended: with the document of Paperless,
	 * which the notification links to, or with the reason why there is none. A notification that
	 * Nextcloud refuses is logged; the import has ended all the same.
	 *
	 * @param self::SUBJECT_SEND_* $subject
	 */
	public function sendEnded(string $userId, int $fileId, string $path, string $subject, ?int $documentId = null, string $reason = '', string $paperlessUrl = ''): void {
		try {
			$this->notifications->notify(
				$this->notifications->createNotification()
					->setApp(AppConstants::APP_ID)
					->setUser($userId)
					->setDateTime($this->timeFactory->getDateTime())
					->setObject(self::OBJECT_SEND, (string)$fileId)
					->setSubject($subject, [
						'file' => ['id' => $fileId, 'path' => $path],
						'document' => $documentId,
						'url' => $paperlessUrl,
						'reason' => mb_substr($reason, 0, self::ERROR_LIMIT),
					]),
			);
		} catch (\Throwable $exception) {
			$this->logger->warning('Paperless Sync could not tell {user} how the import of {path} ended: {error}', [
				'user' => $userId,
				'path' => $path,
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	/**
	 * One notification for a row of failures, sent once the row is long or old enough, and removed
	 * by the first success after it.
	 */
	private function alertOrClear(): void {
		$failures = $this->status->failures();
		$alerted = $this->appConfig->getValueInt(AppConstants::APP_ID, self::ALERTED, 0, lazy: true) > 0;
		if ($failures['runs'] === 0) {
			if ($alerted) {
				$this->notifications->markProcessed($this->failingNotification());
				$this->appConfig->deleteKey(AppConstants::APP_ID, self::ALERTED);
			}
			return;
		}
		$now = $this->timeFactory->getTime();
		if ($alerted || ($failures['runs'] < self::ALERT_RUNS && $now - $failures['since'] < self::ALERT_AFTER)) {
			return;
		}
		foreach ($this->recipients() as $userId) {
			$this->notifications->notify(
				$this->failingNotification()
					->setUser($userId)
					->setDateTime($this->timeFactory->getDateTime())
					->setSubject(self::SUBJECT_FAILING, [
						'runs' => $failures['runs'],
						'since' => $failures['since'],
						'failed' => $failures['failed'],
						'error' => mb_substr($failures['error'], 0, self::ERROR_LIMIT),
					]),
			);
		}
		$this->appConfig->setValueInt(AppConstants::APP_ID, self::ALERTED, $now, lazy: true);
	}

	private function failingNotification(): INotification {
		return $this->notifications->createNotification()
			->setApp(AppConstants::APP_ID)
			->setObject(self::OBJECT_SYNCHRONIZATION, 'failing');
	}

	/**
	 * The enabled members of the group of the setting, or of the administrators when no such group
	 * exists.
	 *
	 * @return list<string>
	 */
	private function recipients(): array {
		$groupId = $this->appConfig->getValueString(AppConstants::APP_ID, self::GROUP_SETTING, self::DEFAULT_GROUP, lazy: true);
		$group = $this->groupManager->get($groupId);
		if ($group === null && $groupId !== self::DEFAULT_GROUP) {
			$this->logger->warning('The group {group} of the notifications of Paperless Sync does not exist; the administrators get them.', ['group' => $groupId]);
			$group = $this->groupManager->get(self::DEFAULT_GROUP);
		}
		if ($group === null) {
			return [];
		}

		return array_values(array_map(
			static fn (IUser $user): string => $user->getUID(),
			array_filter($group->getUsers(), static fn (IUser $user): bool => $user->isEnabled()),
		));
	}

	/** @param array{outcome: SyncReport::IMPORT*, path: string, document: int|null, reason: string} $result */
	private function notifyImport(SyncConfig $config, array $result): void {
		$id = $this->storage->findFileId($config->targetUser, $result['path']) ?? 0;
		$notification = $this->notifications->createNotification()
			->setApp(AppConstants::APP_ID)
			->setUser($config->targetUser)
			->setDateTime($this->timeFactory->getDateTime())
			// The ID of the file, or a hash of its path for one that is gone: the column of the ID holds 64 characters.
			->setObject(self::OBJECT_IMPORT, $id > 0 ? (string)$id : md5($result['path']))
			->setSubject($result['outcome'] === SyncReport::IMPORT_FAILED ? self::SUBJECT_IMPORT_FAILED : self::SUBJECT_IMPORT_REJECTED, [
				'file' => ['id' => $id, 'path' => $result['path']],
				'reason' => mb_substr($result['reason'], 0, self::ERROR_LIMIT),
			]);
		$this->notifications->notify($notification);
	}
}
