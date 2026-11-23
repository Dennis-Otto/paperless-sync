<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncReport;
use Psr\Log\LoggerInterface;

/**
 * Settles the records of the uploads of per-user ownership in each scheduled run, before the run
 * routes the documents, so that a document that an upload created reaches its user in the same
 * run, and nobody else:
 *
 * - A task that ended with a document gives that document its owner, as the inbox and Send to
 *   Paperless do; while Paperless refuses the owner for a reason that may pass, the record waits.
 * - A task that ended without a document ends the record.
 * - A record without a task, after an answer that never came or a task that Paperless no longer
 *   lists, looks for its content: a document of the account with that content and a higher ID
 *   than the record's is the upload's, as Paperless numbers its documents in ascending order.
 * - A document that no task names, which the records of two users find, gets neither owner, and
 *   both reports name it, until someone in Paperless gives it its owner, which ends both records.
 * - A record ends when its document has its owner, and after UploadRecords::FOLLOW, which the
 *   report names when no document appeared.
 *
 * Nothing is deleted, and a file in the error folder stays there. A search or a task that
 * Paperless can't answer keeps the record, and the run holds every document of the account. A
 * dry-run reads the same and changes nothing in Paperless or in the records; it routes the
 * documents as the run would.
 */
final class UploadSettlement {
	public function __construct(
		private PaperlessClientInterface $paperless,
		private SyncStateRepositoryInterface $state,
		private Owners $owners,
		private LoggerInterface $logger,
		private int $now,
		private bool $dryRun,
	) {
	}

	/**
	 * Settles every record, with $documents, the documents that the run read for its export,
	 * whose owners it changes as it changes them in Paperless; without them, as when the export is
	 * off, it reads the owner of each document that it finds from Paperless. The
	 * errors of a record go to the report of its user, when the run has one, or to $report. The
	 * answer is whether the run holds every document of the account, as after a failure or when
	 * two records find one document.
	 *
	 * @param array<array-key, SyncReport> $reports the reports of the users of the run
	 * @param list<array<string, mixed>> $documents
	 */
	public function settle(SyncReport $report, array $reports, array &$documents = []): bool {
		$records = $this->state->allUploads();
		if ($records === []) {
			return false;
		}
		$account = $this->paperless->accountId();
		$knownContent = new KnownContent($this->paperless, $this->state);
		$hold = false;
		/** @var list<array{array<string, int|string|null>, int, int, bool}> $candidates the record, its user of Paperless, its document and whether its task named it */
		$candidates = [];
		/** @var array<int, int> $finders how many records found each document by its content */
		$finders = [];
		/** @var array<int, true> $named the documents that a task names */
		$named = [];
		foreach ($records as $record) {
			$user = (string)($record['user_id'] ?? '');
			$path = (string)($record['path'] ?? '');
			$owner = $this->owners->paperlessUserOf($user);
			if (UploadRecords::ended($record, $this->now)) {
				$this->end($record);
				($reports[$user] ?? $report)->error(new RunError('Import %s: Paperless created no document of its upload within 30 days, so Paperless Sync no longer follows it', [$path]), null, $path);
				continue;
			}
			// The user has no user of Paperless any more, or the account is theirs: their documents stay with the account.
			if ($owner === null || $owner === $account) {
				$this->end($record);
				continue;
			}
			try {
				$found = $this->documentOf($record, $knownContent);
			} catch (\Throwable $exception) {
				$hold = true;
				($reports[$user] ?? $report)->error(new RunError('Import %1$s: %2$s', [$path, RunError::of($exception)]), null, $path);
				continue;
			}
			if ($found === null) {
				continue;
			}
			[$document, $byTask] = $found;
			$candidates[] = [$record, $owner, $document, $byTask];
			if ($byTask) {
				$named[$document] = true;
			} else {
				$finders[$document] = ($finders[$document] ?? 0) + 1;
			}
		}
		foreach ($candidates as [$record, $owner, $document, $byTask]) {
			$user = (string)($record['user_id'] ?? '');
			$path = (string)($record['path'] ?? '');
			if (!$byTask && (isset($named[$document]) || $finders[$document] > 1)) {
				try {
					$current = $this->ownerOf($document, $documents);
				} catch (\Throwable $exception) {
					$hold = true;
					($reports[$user] ?? $report)->error(new RunError('Import %1$s: %2$s', [$path, RunError::of($exception)]), $document, $path);
					continue;
				}
				// The account no longer owns the document, as someone in Paperless gave it its owner: it holds
				// nothing more. The records that found it end, unless a task named it, which makes it that
				// task's document, and the record looks on for its own.
				if ($account !== null && $current !== $account) {
					if ($finders[$document] > 1 || $current === $owner) {
						$this->end($record);
					}
					continue;
				}
				$hold = true;
				($reports[$user] ?? $report)->error(new RunError('Import %1$s: Paperless created the document %2$s with its content after the uploads of more than one user, so it stays with the account of the app until someone in Paperless gives it its owner', [$path, (string)$document]), $document, $path);
				continue;
			}
			try {
				$this->giveOwner($record, $owner, $account, $document, $byTask, $reports[$user] ?? $report, $documents);
			} catch (\Throwable $exception) {
				$hold = true;
				($reports[$user] ?? $report)->error(new RunError('Import %1$s: %2$s', [$path, RunError::of($exception)]), $document, $path);
			}
		}
		$this->noticeOfWaitingUploads($report, $account);

		return $hold;
	}

	/**
	 * The document that the upload of a record created, as far as Paperless shows it, and whether
	 * its task named it: the task's document, or, for a record without a task, the newer document
	 * with its content. A task that ended without a document ends the record, and one that Paperless
	 * still lists unfinished keeps it waiting. A task that Paperless no longer lists, or that
	 * ended without naming its document, leaves the record to its content, as after an answer that
	 * never came.
	 *
	 * @param array<string, int|string|null> $record
	 * @return array{int, bool}|null the document and whether its task named it, or null for none
	 * @throws \Throwable when Paperless can't answer for the task or the search
	 */
	private function documentOf(array $record, KnownContent $knownContent): ?array {
		$taskId = (string)($record['task_id'] ?? '');
		if ($taskId !== '') {
			$task = $this->paperless->taskStatus($taskId);
			$succeeded = in_array($task['status'], ImportPlanner::TASK_SUCCEEDED, true);
			if ($succeeded && $task['documentId'] !== null) {
				return [$task['documentId'], true];
			}
			if (in_array($task['status'], ImportPlanner::TASK_FAILED, true)) {
				$this->end($record);
				return null;
			}
			if (!$succeeded && $task['listed']) {
				return null;
			}
			if (!$this->dryRun) {
				$this->state->saveUpload((string)($record['user_id'] ?? ''), (string)($record['sha256'] ?? ''), ['task_id' => '']);
			}
		}

		$document = $knownContent->newer(UploadRecords::content($record), (int)($record['known_id'] ?? 0));

		return $document !== null ? [$document, false] : null;
	}

	/**
	 * Gives the document of an upload its owner, the user of Paperless of the record's user, when
	 * the account of the app still owns it, and names it in the user's report. A document that has
	 * its owner already ends the record, as after the inbox gave it; one of another owner ends it
	 * only when the task named it, as someone in Paperless then gave it its owner, and else isn't
	 * the upload's. While Paperless refuses the owner for a reason that may pass, the record waits
	 * for it, and the run holds the documents of the account; a refusal for good, such as 403 for an
	 * account that may not change the document, ends the record, and the document keeps the owner
	 * that Paperless gave it, as for the inbox, which the report says.
	 *
	 * @param array<string, int|string|null> $record
	 * @param list<array<string, mixed>> $documents
	 * @throws \Throwable when Paperless can't name the owner, or refuses the new one for a reason that may pass, so that the record waits for it
	 */
	private function giveOwner(array $record, int $owner, ?int $account, int $document, bool $byTask, SyncReport $report, array &$documents): void {
		$path = (string)($record['path'] ?? '');
		$current = $this->ownerOf($document, $documents);
		if ($current === $owner) {
			$this->end($record);
			return;
		}
		if ($current === null || $current !== $account) {
			if ($byTask) {
				$this->end($record);
			}
			return;
		}
		if (!$this->dryRun) {
			try {
				$this->paperless->setOwner($document, $owner);
			} catch (\Throwable $exception) {
				// Only a refusal that repeats for good ends the wait: the document may appear long after
				// the upload, so the time since the upload says nothing, and the record ends anyway.
				if (!$exception instanceof PaperlessOwnerException || !$exception->permanent()) {
					throw $exception;
				}
				$this->end($record);
				$report->error(new RunError('Import %1$s: Paperless refused to change the owner of the document %2$s, which keeps the owner that Paperless gave it: %3$s', [$path, (string)$document, RunError::of($exception)]), $document, $path);
				return;
			}
			$this->end($record);
			$this->logger->info('The document {document} of the upload of {path} by {user} got the user of Paperless of {user} as its owner', [
				'document' => $document,
				'path' => $path,
				'user' => (string)($record['user_id'] ?? ''),
			]);
		}
		$documents = array_map(
			/**
			 * @param array<string, mixed> $read
			 * @return array<string, mixed>
			 */
			static fn (array $read): array => is_numeric($read['id'] ?? null) && (int)$read['id'] === $document ? array_replace($read, ['owner' => $owner]) : $read,
			$documents,
		);
		$report->uploadSucceeded($path, $document);
		$report->importResult(SyncReport::IMPORTED, $path, $document);
	}

	/**
	 * The owner of a document, from the documents that the run read, or from Paperless, or null for
	 * a document without one or that Paperless doesn't show.
	 *
	 * @param list<array<string, mixed>> $documents
	 */
	private function ownerOf(int $document, array $documents): ?int {
		$read = null;
		foreach ($documents as $candidate) {
			if (is_numeric($candidate['id'] ?? null) && (int)$candidate['id'] === $document) {
				$read = $candidate;
			}
		}
		$read ??= $this->paperless->document($document);
		/** @psalm-suppress MixedAssignment */
		$owner = $read['owner'] ?? null;

		return is_numeric($owner) ? (int)$owner : null;
	}

	/**
	 * The records that hold the documents of the account after the settling, in one notice of the
	 * report: whose uploads, and since when. The notice names no document.
	 */
	private function noticeOfWaitingUploads(SyncReport $report, ?int $account): void {
		$since = [];
		foreach ($this->state->allUploads() as $record) {
			$user = (string)($record['user_id'] ?? '');
			$owner = $this->owners->paperlessUserOf($user);
			if ($owner !== null && $owner !== $account && UploadRecords::holds($record, $this->now)) {
				$since[$user] = min($since[$user] ?? PHP_INT_MAX, (int)($record['created_at'] ?? 0));
			}
		}
		if ($since === []) {
			return;
		}
		$uploads = implode(', ', array_map(static fn (int|string $user, int $time): string => $user . ' (' . gmdate('Y-m-d H:i', $time) . ' UTC)', array_keys($since), $since));
		$report->notice(SyncReport::TYPE_UPLOADS_WAITING, "UPLOADS WAITING: the documents of the account of the app stay where they are while an upload may still create its document, of {$uploads}.", [
			'uploads' => $uploads,
			'count' => count($since),
		]);
	}

	/** @param array<string, int|string|null> $record */
	private function end(array $record): void {
		if (!$this->dryRun) {
			$this->state->deleteUpload((string)($record['user_id'] ?? ''), (string)($record['sha256'] ?? ''));
		}
	}
}
