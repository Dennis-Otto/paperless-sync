<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A file of the inbox with a state moved inside the inbox, by a rename of the file or of one of its
 * folders: its state follows it to its new path, so that its upload goes on there and the file
 * doesn't go to Paperless a second time; a dry-run leaves it.
 */
final class MoveImport implements ImportAction {
	public function __construct(
		public readonly string $from,
		public readonly string $to,
	) {
	}
}
