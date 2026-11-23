<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * Whose a file of the inbox or of Send to Paperless is in per-user ownership, for the question
 * whether a document that Paperless has with its content counts as the file's own: the user of
 * Nextcloud of the file, the owners of the run, and the owner whose documents the run holds.
 */
final class PerUserScope {
	/**
	 * @param string $user the user of Nextcloud whose inbox held the file, or who sent it
	 * @param int|false|null $held the account of the app while an upload of another user waits for its owner, false when Paperless doesn't name its account then, or null when nothing is held
	 */
	public function __construct(
		public readonly Owners $owners,
		public readonly string $user,
		public readonly int|false|null $held = null,
	) {
	}
}
