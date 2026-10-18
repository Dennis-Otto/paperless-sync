<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * The state of a document that has no copy to remove, such as one with an excluded tag that was
 * never exported, is forgotten; a dry-run leaves it.
 */
final class ForgetState implements ExportAction {
	public function __construct(
		public readonly int $document,
		public readonly DocumentContext $onFailure,
	) {
	}
}
