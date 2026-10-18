<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A document that Paperless no longer lists, whose copy waits until the run has missed it in as
 * many complete scans in a row as the settings require.
 */
final class WaitForMissing implements ExportAction {
	public function __construct(
		public readonly int $document,
		public readonly string $path,
		public readonly int $scans,
		public readonly int $limit,
	) {
	}
}
