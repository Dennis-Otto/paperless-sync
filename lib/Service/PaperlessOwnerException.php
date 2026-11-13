<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use RuntimeException;

/**
 * Paperless did not change the owner of a document. A permanent refusal, such as of an account that
 * may not change the document (403) or of an owner that Paperless doesn't take (400), repeats as
 * long as nothing changes in Paperless; any other failure, such as an answer with 5xx, may pass.
 */
final class PaperlessOwnerException extends RuntimeException {
	/** The answers of Paperless to the change of an owner that repeat for the same document. */
	private const PERMANENT_STATUSES = [400, 403];

	public function __construct(
		string $message,
		public readonly int $statusCode,
	) {
		parent::__construct($message);
	}

	public function permanent(): bool {
		return in_array($this->statusCode, self::PERMANENT_STATUSES, true);
	}

	/**
	 * Whether the wait for the owner of the document of an upload ends, after Paperless refused it:
	 * the refusal repeats for good, or the upload is as old as the wait for its task may be, so that
	 * a refusal that never passes doesn't hold the import back for good (#439). The document then
	 * keeps the owner that Paperless gave it.
	 */
	public static function endsTheWait(\Throwable $refusal, int $submittedAt, int $now): bool {
		return ($refusal instanceof self && $refusal->permanent()) || $now - $submittedAt >= ImportPlanner::TASK_TIMEOUT;
	}
}
