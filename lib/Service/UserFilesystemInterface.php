<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

/**
 * The filesystem in which a run changes the files of a user. Nextcloud's trash bin takes a deleted
 * file into the trash bin of its owner only when the filesystem of the process is the owner's.
 */
interface UserFilesystemInterface {
	/**
	 * Makes the filesystem of the user the filesystem of the process, unless it is already, so
	 * that a file of the user that is deleted next goes into the user's trash bin.
	 */
	public function enter(string $userId): void;
}
