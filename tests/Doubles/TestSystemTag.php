<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCP\SystemTag\ISystemTag;

/** A collaborative tag of Nextcloud, as TestSystemTags keeps it. */
final class TestSystemTag implements ISystemTag {
	public function __construct(
		private string $id,
		private string $name,
		private bool $userVisible,
		private bool $userAssignable,
	) {
	}

	public function getId(): string {
		return $this->id;
	}

	public function getName(): string {
		return $this->name;
	}

	public function isUserVisible(): bool {
		return $this->userVisible;
	}

	public function isUserAssignable(): bool {
		return $this->userAssignable;
	}

	public function getAccessLevel(): int {
		if (!$this->userVisible) {
			return self::ACCESS_LEVEL_INVISIBLE;
		}

		return $this->userAssignable ? self::ACCESS_LEVEL_PUBLIC : self::ACCESS_LEVEL_RESTRICTED;
	}

	public function getETag(): ?string {
		return null;
	}

	public function getColor(): ?string {
		return null;
	}
}
