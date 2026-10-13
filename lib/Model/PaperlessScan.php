<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * The documents of a list of Paperless, read page by page, and whether the run received all of
 * them. A page that came back short or empty, or a list that changed while the run read it, leaves
 * documents out, so a document that an incomplete scan lacks may still exist.
 */
final class PaperlessScan {
	/**
	 * @param list<array<string, mixed>> $documents the documents that the run received
	 * @param bool $complete whether the run received every document that Paperless listed
	 * @param int $listed the number of documents that Paperless listed on the last page
	 */
	public function __construct(
		public readonly array $documents,
		public readonly bool $complete,
		public readonly int $listed,
	) {
	}
}
