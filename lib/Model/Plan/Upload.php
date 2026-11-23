<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/** A file of the inbox that goes to Paperless, with the tags of its folder. */
final class Upload implements ImportAction {
	/**
	 * @param int $attempts the uploads of the file that failed before, for a reason that may pass
	 * @param string $folder the folder of the file below the inbox, such as Taxes/2026, or '' for the inbox itself
	 * @param int $fileId the ID of the file in Nextcloud, by which its state follows it when it is renamed or moved, or 0 when it has none
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $name,
		public readonly string $etag,
		public readonly int $attempts,
		public readonly string $folder,
		public readonly int $fileId = 0,
	) {
	}
}
