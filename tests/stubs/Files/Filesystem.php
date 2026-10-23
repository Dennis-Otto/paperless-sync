<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of Nextcloud's private filesystem class, as far as UserFilesystem reads it:
 * the root of the default view, such as /alice/files, or null before a user's filesystem is set up.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Files;

final class Filesystem {
	public static ?string $root = null;

	public static function getRoot(): ?string {
		return self::$root;
	}
}
