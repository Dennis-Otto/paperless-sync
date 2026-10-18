<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A document or a file of the inbox that the run leaves out, such as one with an excluded tag, or
 * leaves for a later run, as its batch is full. The state of a document may keep what the run
 * learned of it, such as its checksum, so that a later run doesn't ask for it again.
 */
final class Skipped implements ExportAction, ImportAction {
	/**
	 * @param int|null $document the document whose state the run saves, or null for none
	 * @param array<string, int|string|null> $values
	 * @param DocumentContext|null $onFailure what a failure of the save reports, or null when it fails the run
	 */
	public function __construct(
		public readonly ?int $document = null,
		public readonly array $values = [],
		public readonly ?DocumentContext $onFailure = null,
	) {
	}
}
