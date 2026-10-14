<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use DateTimeZone;
use OCP\IDateTimeZone;

/**
 * The time zones of Nextcloud: that of each user, from the personal settings as a test sets them,
 * and else the default of the server, UTC unless a test sets another one.
 */
final class TestTimeZones implements IDateTimeZone {
	/** @param array<string, string> $users the time zone of a user, by the ID of the user */
	public function __construct(
		public string $default = 'UTC',
		public array $users = [],
	) {
	}

	/** @param bool|int $timestamp */
	public function getTimeZone($timestamp = false, ?string $userId = null): DateTimeZone {
		return new DateTimeZone($this->users[$userId ?? ''] ?? $this->default);
	}

	public function getDefaultTimeZone(): DateTimeZone {
		return new DateTimeZone($this->default);
	}
}
