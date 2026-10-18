<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A file of the inbox whose upload the run checks, and its state before the run: a failure of the
 * check, or of its end, keeps the file pending, with the error, which is logged only when it changes.
 */
final class ImportContext {
	/** @param array<string, int|string|null> $pending */
	public function __construct(
		public readonly string $path,
		public readonly array $pending,
	) {
	}
}
