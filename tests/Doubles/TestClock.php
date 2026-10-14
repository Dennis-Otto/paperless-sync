<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Nextcloud's clock, standing still until a test sets or advances it, so that no test depends on
 * the time it runs at: a run at midnight sees the same day as the test that checks it, and a test
 * waits for a retry by advancing the clock instead of editing the state.
 */
final class TestClock implements ITimeFactory {
	/** 2026-08-28 10:00:00 UTC, a day after the deletion date of the trashed documents of the tests. */
	public const START = 1787911200;

	public function __construct(
		public int $time = self::START,
	) {
	}

	public function advance(int $seconds): void {
		$this->time += $seconds;
	}

	/** The day of the clock, as the deleted folder names it. */
	public function today(): string {
		return gmdate('Y-m-d', $this->time);
	}

	public function getTime(): int {
		return $this->time;
	}

	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		$dateTime = new DateTime('@' . $this->time);
		$dateTime->setTimezone($timezone ?? new DateTimeZone('UTC'));

		if ($time === 'now') {
			return $dateTime;
		}
		$modified = $dateTime->modify($time);
		if ($modified === false) {
			throw new InvalidArgumentException("Not a time: {$time}");
		}

		return $modified;
	}

	public function withTimeZone(DateTimeZone $timezone): static {
		return clone $this;
	}

	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return new DateTimeZone($timezone ?? 'UTC');
	}

	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('@' . $this->time);
	}
}
