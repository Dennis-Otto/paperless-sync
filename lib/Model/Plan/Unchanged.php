<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A document or a file of the inbox that stays as it is. The state of a document may still change,
 * such as the checksum that the run learned, without a write when it doesn't.
 */
final class Unchanged implements ExportAction, ImportAction {
	/**
	 * @param int|null $document the document whose state the run saves, or null for none
	 * @param array<string, int|string|null> $values
	 * @param DocumentContext|null $onFailure what a failure of the save reports, or null when it fails the run
	 * @param Copy|null $copy the copy of the document at its path, which the metadata next to the archive describes
	 */
	public function __construct(
		public readonly ?int $document = null,
		public readonly array $values = [],
		public readonly ?DocumentContext $onFailure = null,
		public readonly ?Copy $copy = null,
	) {
	}
}
