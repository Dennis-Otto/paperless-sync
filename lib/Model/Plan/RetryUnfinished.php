<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * Paperless doesn't list the task of an upload a day after it and has no document with the content
 * of the file, so its queue lost the task, as after a restart of Redis; or the file changed since
 * its upload. The file goes up again after the wait of a failed upload.
 */
final class RetryUnfinished implements ImportAction {
	public function __construct(
		public readonly ImportContext $context,
		public readonly string $etag,
		public readonly int $attempts,
		public readonly string $taskId,
		public readonly string $error,
	) {
	}
}
