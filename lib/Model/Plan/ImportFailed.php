<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * The import of a file of the inbox created no document, so it moves to the error folder, next to
 * a text that says why.
 */
final class ImportFailed implements ImportAction {
	/**
	 * @param string $diagnostic the text of the .error.txt next to the file
	 * @param string $unfinished for a task that Paperless still lists, unfinished, a day after the upload, the error that the log names, or ''
	 */
	public function __construct(
		public readonly ImportContext $context,
		public readonly string $destination,
		public readonly string $reason,
		public readonly string $diagnostic,
		public readonly string $taskId = '',
		public readonly string $unfinished = '',
	) {
	}
}
