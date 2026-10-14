<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use DateTimeZone;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;

/**
 * The time zone of a user: the one from the personal settings, or else the default of Nextcloud.
 *
 * Not IDateTimeZone::getTimeZone() with the user: for a user without a time zone of their own, that
 * takes the browser offset of whoever is logged in, so the same run would date its folders by the
 * administrator who starts it by hand and by the server in the background job.
 */
final class UserTimeZones {
	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the class through its container. */
	public function __construct(
		private IUserConfig $userConfig,
		private IDateTimeZone $timeZones,
	) {
	}

	public function of(string $userId): DateTimeZone {
		$name = $this->userConfig->getValueString($userId, 'core', 'timezone');
		if ($name !== '') {
			try {
				return new DateTimeZone($name);
			} catch (\Exception) {
				// A time zone that PHP no longer knows: the default of Nextcloud instead.
			}
		}

		return $this->timeZones->getDefaultTimeZone();
	}
}
