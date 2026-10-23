<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of Nextcloud's private setup manager, which behaves towards the default view
 * as Nextcloud's does: tearing down forgets it, and the first user set up afterwards gets it. It
 * notes each call.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Files;

use OCP\IUser;

final class SetupManager {
	/** @var list<string> tearDown, or setupForUser with the user */
	public array $calls = [];

	public function tearDown(): void {
		$this->calls[] = 'tearDown';
		Filesystem::$root = null;
	}

	public function setupForUser(IUser $user): void {
		$this->calls[] = 'setupForUser ' . $user->getUID();
		Filesystem::$root ??= '/' . $user->getUID() . '/files';
	}
}
