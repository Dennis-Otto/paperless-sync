<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * The state of a file of the inbox that is gone, or whose upload ended, is forgotten, so that the
 * file goes to Paperless again should it come back; a dry-run leaves it.
 */
final class ForgetImport implements ImportAction {
	public function __construct(
		public readonly string $path,
	) {
	}
}
