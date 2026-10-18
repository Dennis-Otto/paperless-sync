<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * Paperless imported a file of the inbox. A file that changed since its upload, whose new content
 * Paperless doesn't have, goes up again in the next run.
 */
final class ImportSucceeded implements ImportAction {
	/** @param int|null $document the new document, when Paperless names it */
	public function __construct(
		public readonly ImportContext $context,
		public readonly string $etag,
		public readonly bool $sameContent,
		public readonly ?int $document,
	) {
	}
}
