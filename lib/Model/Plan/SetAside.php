<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A file of the inbox of a type that never goes to Paperless, such as a video. It stays where it
 * is, without a state and without a place in the batch, and every run names it, until someone
 * moves or deletes it.
 */
final class SetAside implements ImportAction {
	/**
	 * @param string $path the file, from the home of the target user
	 * @param string $name its name, which the notice of the run gives
	 * @param string $kind its kind, one of those of InboxFileTypes
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $name,
		public readonly string $kind,
	) {
	}
}
