<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PerUserScope;

/**
 * The records of the uploads of per-user ownership. Paperless makes the account of the app the
 * owner of every upload, and nothing in Paperless ties a task to the client that uploaded its
 * file, so the app keeps a record of each upload, from right before it until its document has its
 * owner: also after the file has left the inbox, after an answer that never came, after a send
 * that gave up, and when Paperless finishes the task more than a day late. A user has one record
 * for each content, which a further upload of that content takes over; it keeps the highest ID of
 * a document with the content from the search before its first upload, and the task of the upload
 * once Paperless names it.
 *
 * A record holds the documents of the account, as WaitingUploads explains, while it is open:
 *
 * - while its task may still create the document, up to FOLLOW after its start;
 * - without a task, after an answer that never came, for LOST_ANSWER after its last attempt.
 *
 * After that, each run still looks for its document until FOLLOW after its start, without holding
 * any other, and UploadSettlement gives the document that it finds its owner. A record ends when
 * its document has its owner, when its task ended without one, or after FOLLOW.
 *
 * Only the uploads of a user whose user of Paperless is another than the account get a record:
 * single ownership, and a user whose documents stay with the account anyway, save none.
 */
final class UploadRecords {
	/** The sources of an upload: the inbox, and Send to Paperless with the rules of Flow. */
	public const INBOX = 'inbox';
	public const SEND = 'send';
	/**
	 * How long the app follows an upload after its start, in seconds: 30 days. Paperless keeps a
	 * task that its worker has not started as long as its queue lasts, so a worker that stopped
	 * over the holidays, or a queue that many large uploads filled, still gives the document its
	 * owner. After that the record ends, so that nothing is held for good.
	 */
	public const FOLLOW = 30 * 86400;
	/**
	 * How long a record without a task holds the documents of the account after its last attempt,
	 * in seconds: the day that the app gives Paperless to finish a task, ImportPlanner::TASK_TIMEOUT.
	 * The document of an upload whose answer never came has appeared by then, unless its task waits
	 * in a long queue, which the look of each run until FOLLOW still finds.
	 */
	public const LOST_ANSWER = ImportPlanner::TASK_TIMEOUT;

	/**
	 * The user of Paperless whose upload of a file of this scope gets a record: the user of
	 * Paperless of the scope's user, unless it is the account, whose documents stay with it anyway,
	 * or null in single ownership and for a user without one. While Paperless doesn't name its
	 * account, every upload of a user of Paperless gets one.
	 */
	public static function owner(?PerUserScope $scope, PaperlessClientInterface $paperless): ?int {
		$owner = $scope !== null ? $scope->owners->paperlessUserOf($scope->user) : null;

		return $owner !== null && $owner !== $paperless->accountId() ? $owner : null;
	}

	/**
	 * Saves the record of an upload right before it: a new one with $knownId, the highest ID of a
	 * document with the content from the search before it, or the record of an earlier upload of
	 * the content, which keeps its first ID and its start. The answer is whether the record is new,
	 * so that a refusal of Paperless, after which it has nothing of the upload, ends it again.
	 */
	public static function begin(SyncStateRepositoryInterface $state, string $user, string $source, string $path, DocumentFingerprint $content, int $knownId, int $now): bool {
		$new = $state->findUpload($user, $content->sha256) === null;
		$state->saveUpload($user, $content->sha256, [
			'source' => $source,
			'path' => $path,
			'md5' => $content->md5,
			'task_id' => '',
			'last_attempt_at' => $now,
		] + ($new ? ['known_id' => $knownId, 'created_at' => $now] : []));

		return $new;
	}

	/** Paperless named the task of the upload, which the record follows from now on. */
	public static function started(SyncStateRepositoryInterface $state, string $user, DocumentFingerprint $content, string $taskId): void {
		$state->saveUpload($user, $content->sha256, ['task_id' => $taskId]);
	}

	/**
	 * Whether a record holds the documents of the account: while its task may still create its
	 * document, up to FOLLOW after its start, or, without a task, for LOST_ANSWER after its last
	 * attempt.
	 *
	 * @param array<string, int|string|null> $record
	 */
	public static function holds(array $record, int $now): bool {
		if (self::ended($record, $now)) {
			return false;
		}

		return ($record['task_id'] ?? '') !== '' || $now < (int)($record['last_attempt_at'] ?? 0) + self::LOST_ANSWER;
	}

	/**
	 * Whether the app has followed the upload of a record for FOLLOW since its start.
	 *
	 * @param array<string, int|string|null> $record
	 */
	public static function ended(array $record, int $now): bool {
		return $now >= (int)($record['created_at'] ?? 0) + self::FOLLOW;
	}

	/**
	 * The checksums of the content of a record.
	 *
	 * @param array<string, int|string|null> $record
	 */
	public static function content(array $record): DocumentFingerprint {
		return new DocumentFingerprint((string)($record['sha256'] ?? ''), (string)($record['md5'] ?? ''));
	}
}
