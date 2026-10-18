<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

use OCA\PaperlessSync\Model\SyncReport;

/**
 * The move of the copy of a document in the trash of Paperless, or of one that Paperless no longer
 * lists, to the deleted folder, with the new state of the document.
 */
final class MoveToDeleted implements ExportAction {
	/**
	 * @param SyncReport::TYPE_TRASH|SyncReport::TYPE_MISSING $type
	 * @param array<string, int|string|null> $values the new state, which the path of the moved copy completes
	 */
	public function __construct(
		public readonly DocumentContext $context,
		public readonly string $path,
		public readonly string $target,
		public readonly string $type,
		public readonly array $values,
	) {
	}
}
