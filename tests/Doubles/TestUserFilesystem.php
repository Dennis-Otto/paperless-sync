<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use Closure;
use OCA\PaperlessSync\Service\UserFilesystemInterface;

/**
 * The filesystem of the process, which notes each user whose filesystem a change entered, with
 * what the test knows at that moment.
 */
final class TestUserFilesystem implements UserFilesystemInterface {
	/** @var list<mixed> what the observer returned for each entry */
	public array $entered = [];

	/** @param Closure(string): mixed $observer */
	public function __construct(
		private Closure $observer,
	) {
	}

	public function enter(string $userId): void {
		$this->entered[] = ($this->observer)($userId);
	}
}
