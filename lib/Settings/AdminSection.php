<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Settings;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return AppConstants::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Paperless Sync');
	}

	public function getPriority(): int {
		return 55;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(AppConstants::APP_ID, 'app.svg');
	}
}
