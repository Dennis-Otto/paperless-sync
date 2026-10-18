<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/** A change of the state of a document alone, such as its count of missing scans, which a dry-run leaves out. */
final class SaveState implements ExportAction {
	/**
	 * @param array<string, int|string|null> $values
	 * @param DocumentContext|null $onFailure what a failure of the save reports, or null when it fails the run
	 */
	public function __construct(
		public readonly int $document,
		public readonly array $values,
		public readonly ?DocumentContext $onFailure = null,
	) {
	}
}
