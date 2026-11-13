<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Sends the files that people queued with "Send to Paperless" in Files, and those of the rules of
 * Flow, in the background, as a run sends the files of the inbox: with the tags for every import,
 * the tags of its subfolders below the sent folder when subfolders become tags, those of its rule,
 * and its name. It follows the task of each upload and tells the sender how the import ended; the
 * owner of a file of a rule hears of an import that Paperless refused or that failed alone, as a
 * rule may send many files.
 * The files stay where they are.
 *
 * - A file whose content Paperless has already isn't uploaded again, as Paperless-ngx 3.x would
 *   import it a second time: the sender learns the document that has it.
 * - A file that Paperless refuses isn't uploaded again either; the sender learns why.
 * - A file or a user that is gone ends the import. A lookup of the file that fails otherwise, such
 *   as while the user backend of the sender can't answer, waits as a failed upload does.
 * - An upload that fails for a reason that may pass, such as Paperless being down, follows after
 *   a wait that doubles each time, up to MAX_ATTEMPTS uploads, and the other files wait for the
 *   next round.
 * - A task that Paperless has not finished a day after the upload ends the import, as Paperless
 *   may still create the document; the file is not uploaded again.
 * - In per-user ownership, a document whose owner Paperless refuses to change waits for it while
 *   the refusal may pass, up to a day after the upload; then, or at once for a refusal that
 *   repeats, such as 403, the import ends as imported, and the document keeps its owner.
 *
 * A round uploads at most a batch of files; while the app is not connected to Paperless, the files
 * wait.
 */
final class SendService {
	/** The states of a sent file: waiting for its upload, for its next upload, and for the task of its upload. */
	public const QUEUED = 'queued';
	public const RETRY = 'retry';
	public const PENDING = 'pending';
	/** The uploads of a file at most, of which all but the last failed for a reason that may pass. */
	public const MAX_ATTEMPTS = 6;
	/** The ends of an import that the owner of a file of a rule of Flow doesn't hear of. */
	private const QUIET_FOR_RULES = [NotificationPublisher::SUBJECT_SEND_IMPORTED, NotificationPublisher::SUBJECT_SEND_KNOWN, NotificationPublisher::SUBJECT_SEND_GONE];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessClientFactoryInterface $clients,
		private SyncStateRepositoryInterface $state,
		private NextcloudStorageInterface $storage,
		private NotificationPublisher $notifications,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private OwnerMapping $ownerMapping,
	) {
	}

	/** One round over the sent files: the uploads that are due, and the tasks of the earlier ones. */
	public function process(): void {
		$sends = $this->state->allSends();
		if ($sends === []) {
			return;
		}
		[$config, $connection] = $this->configService->snapshot();
		if (!$config->complete()) {
			return;
		}
		$paperless = $this->clients->connect($connection);
		$tags = new InboxTags($paperless, $config, new SyncReport(false));
		$now = $this->timeFactory->getTime();
		$uploads = 0;
		$paperlessFailed = false;
		/** @var Owners|null $owners the owners of per-user ownership, once a document of the round needs them */
		$owners = null;
		foreach ($sends as $send) {
			$status = (string)($send['status'] ?? '');
			if ($status === self::PENDING) {
				$this->followTask($paperless, $config, $send, $now, $owners);
				continue;
			}
			if (($status === self::RETRY && (int)($send['retry_at'] ?? 0) > $now) || $paperlessFailed || $uploads >= $config->batchSize) {
				continue;
			}
			++$uploads;
			$paperlessFailed = !$this->upload($paperless, $tags, $config, $send, $now);
		}
	}

	/**
	 * Uploads a file, unless Paperless has its content already. The answer is false after a
	 * failure that every other upload of the round would meet as well, such as Paperless being down.
	 *
	 * @param array<string, int|string|null> $send
	 */
	private function upload(PaperlessClientInterface $paperless, InboxTags $tags, SyncConfig $config, array $send, int $now): bool {
		$userId = (string)$send['user_id'];
		$fileId = (int)$send['file_id'];
		$path = (string)$send['path'];
		try {
			// A file or a user that is gone ends the import; a lookup that fails, such as while the
			// user backend of the sender can't answer, waits for the next attempt, as an upload does.
			$node = $this->storage->nodeById($userId, $fileId);
			if ($node === null || $node['folder'] || !$node['readable']) {
				$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_GONE);
				return true;
			}
			$path = $node['path'];
			$tagIds = $tags->forFolder((string)$send['folder'], InboxTags::names((string)($send['tags'] ?? '')))['ids'];
			$known = $paperless->findDocument($this->fingerprint($userId, $path));
			if ($known !== null) {
				$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_KNOWN, $known);
				return true;
			}
			$stream = null;
			try {
				$stream = $this->storage->openRead($userId, $path);
				$taskId = $paperless->uploadDocument($stream, $node['name'], $tagIds);
			} finally {
				// The client of Paperless may have closed it.
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$this->state->saveSend($userId, $fileId, [
				'path' => $path,
				'task_id' => $taskId,
				'status' => self::PENDING,
				'submitted_at' => $now,
				'last_error' => null,
			]);

			return true;
		} catch (PaperlessUploadException $exception) {
			if ($exception->permanent) {
				$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_REFUSED, null, $exception->getMessage());
				return true;
			}
			$this->retryLater($config, $send, $path, $exception, $now);

			return false;
		} catch (\Throwable $exception) {
			$this->retryLater($config, $send, $path, $exception, $now);

			return !$tags->unavailable() && !$exception instanceof PaperlessRequestException;
		}
	}

	/** The checksums of the content of a file, by which Paperless finds a document that has it. */
	private function fingerprint(string $userId, string $path): DocumentFingerprint {
		$stream = $this->storage->openRead($userId, $path);
		try {
			return DocumentFingerprint::of($stream);
		} finally {
			fclose($stream);
		}
	}

	/**
	 * The upload failed for a reason that may pass, so the file waits twice as long after each
	 * failure, up to a day, until its last attempt failed as well.
	 *
	 * @param array<string, int|string|null> $send
	 */
	private function retryLater(SyncConfig $config, array $send, string $path, \Throwable $exception, int $now): void {
		$error = $exception->getMessage();
		$attempts = (int)$send['attempts'] + 1;
		if ($attempts >= self::MAX_ATTEMPTS) {
			$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_FAILED, null, $error);
			$this->logger->warning('Paperless Sync gave up sending {path} of {user} to Paperless after {attempts} failed uploads: {error}', [
				'path' => $path,
				'user' => (string)$send['user_id'],
				'attempts' => $attempts,
				'error' => $error,
			]);
			return;
		}
		$delay = min(ImportExecutor::RETRY_DELAY << ($attempts - 1), ImportExecutor::MAX_RETRY_DELAY);
		$this->state->saveSend((string)$send['user_id'], (int)$send['file_id'], [
			'path' => $path,
			'status' => self::RETRY,
			'attempts' => $attempts,
			'retry_at' => $now + $delay,
			'last_error' => mb_substr($error, 0, 4000),
		]);
		// The first failure of a file is a warning, the ones after it only information.
		$this->logger->log($attempts === 1 ? LogLevel::WARNING : LogLevel::INFO, 'The upload of {path} of {user} to Paperless failed; the next attempt follows in {minutes} minutes: {error}', [
			'path' => $path,
			'user' => (string)$send['user_id'],
			'attempts' => $attempts,
			'minutes' => intdiv($delay, 60),
			'error' => $error,
		]);
	}

	/**
	 * Asks Paperless for the task of an upload, and ends the import once the task did, or a day
	 * after the upload. While Paperless can't answer, the task waits, and the log says so once.
	 *
	 * @param array<string, int|string|null> $send
	 */
	private function followTask(PaperlessClientInterface $paperless, SyncConfig $config, array $send, int $now, ?Owners &$owners): void {
		$path = (string)$send['path'];
		$taskId = (string)$send['task_id'];
		try {
			$task = $paperless->taskStatus($taskId);
		} catch (\Throwable $exception) {
			$error = $exception->getMessage();
			if (($send['last_error'] ?? null) !== $error) {
				$this->logger->warning('Paperless Sync could not check the import of {path}, which {user} sent to Paperless: {error}', [
					'path' => $path,
					'user' => (string)$send['user_id'],
					'error' => $error,
				]);
				$this->state->saveSend((string)$send['user_id'], (int)$send['file_id'], ['last_error' => mb_substr($error, 0, 4000)]);
			}
			return;
		}
		if (in_array($task['status'], ImportPlanner::TASK_SUCCEEDED, true)) {
			$reason = '';
			if ($config->perUser() && $task['documentId'] !== null) {
				$refusal = $this->giveOwner($paperless, $config, $send, $task['documentId'], $owners);
				if ($refusal !== null) {
					if (!PaperlessOwnerException::endsTheWait($refusal, (int)($send['submitted_at'] ?? 0), $now)) {
						$this->waitForOwner($send, $task['documentId'], $refusal);
						return;
					}
					$reason = $this->keepsItsOwner($send, $task['documentId'], $refusal);
				}
			}
			$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_IMPORTED, $task['documentId'], $reason);
		} elseif (in_array($task['status'], ImportPlanner::TASK_FAILED, true)) {
			$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_FAILED, null, ImportPlanner::failureReason($task));
		} elseif ($now - (int)($send['submitted_at'] ?? 0) >= ImportPlanner::TASK_TIMEOUT) {
			$state = $task['status'] . ($task['message'] !== '' ? ": {$task['message']}" : '');
			$this->end($config, $send, $path, NotificationPublisher::SUBJECT_SEND_FAILED, null, "Paperless has not finished the import a day after the upload ({$state}), so it may still create the document.");
		}
	}

	/**
	 * In per-user ownership, the document of a sent file becomes the document of the user of
	 * Paperless of the user of Nextcloud whose file it is, as Paperless makes the account that
	 * uploads a file the owner of its document, so that the next runs mirror it into that user's
	 * archive. A user without a user of Paperless leaves it to the account. Only the document that
	 * the task of the upload names changes, never one that Paperless had with the content of the
	 * file already.
	 *
	 * @param array<string, int|string|null> $send
	 * @return \Throwable|null why Paperless did not change the owner, or null when the import may end
	 */
	private function giveOwner(PaperlessClientInterface $paperless, SyncConfig $config, array $send, int $documentId, ?Owners &$owners): ?\Throwable {
		try {
			$owners ??= $this->ownerMapping->owners($config, $paperless->users());
			$owner = $owners->paperlessUserOf((string)$send['user_id']);
			if ($owner !== null) {
				$paperless->setOwner($documentId, $owner);
			}

			return null;
		} catch (\Throwable $exception) {
			return $exception;
		}
	}

	/**
	 * Paperless refused the owner for a reason that may pass, so the import stays pending, the log
	 * says so once, and the next round asks again.
	 *
	 * @param array<string, int|string|null> $send
	 */
	private function waitForOwner(array $send, int $documentId, \Throwable $refusal): void {
		$error = $refusal->getMessage();
		if (($send['last_error'] ?? null) === $error) {
			return;
		}
		$this->logger->warning('Paperless Sync could not give the document {document} of {path}, which {user} sent to Paperless, its owner: {error}', [
			'document' => $documentId,
			'path' => (string)$send['path'],
			'user' => (string)$send['user_id'],
			'error' => $error,
		]);
		$this->state->saveSend((string)$send['user_id'], (int)$send['file_id'], ['last_error' => mb_substr($error, 0, 4000)]);
	}

	/**
	 * Paperless keeps refusing the owner, for good or for as long as a task may take: the document
	 * is in Paperless, so the import ends as imported and the file may be sent again. The document
	 * keeps the owner that Paperless gave it, which the log and the sender learn with the reason of
	 * Paperless. The answer is that reason, for the notification.
	 *
	 * @param array<string, int|string|null> $send
	 */
	private function keepsItsOwner(array $send, int $documentId, \Throwable $refusal): string {
		$error = $refusal->getMessage();
		$this->logger->warning('The document {document} of {path}, which {user} sent to Paperless, keeps the owner that Paperless gave it, as Paperless did not make the user of Paperless of {user} its owner: {error}', [
			'document' => $documentId,
			'path' => (string)$send['path'],
			'user' => (string)$send['user_id'],
			'error' => $error,
		]);

		return "The document keeps the owner that Paperless gave it, as Paperless refused to change it: {$error}";
	}

	/**
	 * The import of a sent file ended: the sender learns how, and the file is no longer sent. The
	 * owner of a file that a rule of Flow sent learns of an import that Paperless refused or that
	 * failed alone, which needs them; the log keeps the others, as a rule may send many files, and
	 * a file that its owner deleted before its upload is no news to them.
	 *
	 * @param array<string, int|string|null> $send
	 * @param NotificationPublisher::SUBJECT_SEND_* $subject
	 */
	private function end(SyncConfig $config, array $send, string $path, string $subject, ?int $documentId = null, string $reason = ''): void {
		$userId = (string)$send['user_id'];
		$fileId = (int)$send['file_id'];
		$this->state->deleteSend($userId, $fileId);
		$ruleId = (int)($send['rule_id'] ?? 0);
		if ($ruleId > 0 && in_array($subject, self::QUIET_FOR_RULES, true)) {
			$this->logger->info('The import of {path} of {user}, which the rule {rule} of Flow sent to Paperless, ended: {subject}, document {document}.', [
				'path' => $path,
				'user' => $userId,
				'rule' => $ruleId,
				'subject' => $subject,
				'document' => $documentId,
			]);
			return;
		}
		$this->notifications->sendEnded($userId, $fileId, $path, $subject, $documentId, $reason, $config->linkUrl());
	}
}
