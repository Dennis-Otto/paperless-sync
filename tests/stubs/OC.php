<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of Nextcloud's server class, whose container OCP's App and
 * background jobs reach through \OC::$server. Tests that need it put a
 * OCA\PaperlessSync\Tests\Doubles\TestServer here and remove it afterwards.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class OC {
	public static ?object $server = null;
}
