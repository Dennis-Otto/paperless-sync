<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A document whose plan failed, such as one whose path the template can't render or whose copy
 * conflicts with a file of the user. A dry-run reports it as a run does.
 */
final class Failed implements ExportAction {
	/**
	 * @param array<string, int|string|null> $values what the run learned of the document before the failure, which the state keeps with the error
	 * @param bool $attempt whether the failure ends an export that took its place in the batch, such as at a conflict
	 */
	public function __construct(
		public readonly DocumentContext $context,
		public readonly \Throwable $exception,
		public readonly array $values = [],
		public readonly bool $attempt = false,
	) {
	}
}
