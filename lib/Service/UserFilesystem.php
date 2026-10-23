<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OC\Files\Filesystem;
use OC\Files\SetupManager;
use OCP\IUserManager;

/**
 * Nextcloud's trash bin moves a deleted file into the trash bin of its owner through the default
 * filesystem of the process, that of the first user whose files the process set up: in a request
 * of an administrator, the administrator's, and in a run of per-user ownership, that of whichever
 * user came first. A file outside it, such as a copy among the files of the target user, is
 * deleted for good (LegacyTrashBackend::moveToTrash() of the app files_trashbin). Only a background
 * job that touches the files of the target user first, such as the scheduled run, got the trash bin.
 *
 * The public API of Nextcloud has no way to choose that filesystem, so this class uses the one
 * that Nextcloud's own background jobs use for the files of one user after another, such as
 * ExpireTrash of files_trashbin: it tears the filesystem down and sets it up for the user, who
 * becomes the first. It does so only when another user's filesystem is the default, so a run of
 * one user does it at most once.
 *
 * @psalm-suppress UndefinedClass Filesystem and SetupManager are Nextcloud's private API, which
 *                 OCP and with it Psalm leave out; the end-to-end tests run them in every supported
 *                 version of Nextcloud.
 */
final class UserFilesystem implements UserFilesystemInterface {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IUserManager $userManager,
		private SetupManager $setupManager,
	) {
	}

	public function enter(string $userId): void {
		$user = $this->userManager->get($userId);
		// A user who does not exist has no files; the change itself says so.
		if ($user === null || Filesystem::getRoot() === '/' . $user->getUID() . '/files') {
			return;
		}
		$this->setupManager->tearDown();
		$this->setupManager->setupForUser($user);
	}
}
