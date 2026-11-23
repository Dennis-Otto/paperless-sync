<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * The document of Paperless that counts as the document of a file, as KnownContent::own() found
 * it: it has the content of the file, and it is the file's user's.
 */
final class OwnDocument {
	/**
	 * @param int $id the ID of the document
	 * @param bool $lostUpload whether the document is the user's own upload whose answer never came, which the account of the app still owns, so that it gets the user of Paperless of the user as its owner
	 */
	public function __construct(
		public readonly int $id,
		public readonly bool $lostUpload = false,
	) {
	}
}
