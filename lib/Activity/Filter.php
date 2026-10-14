<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Activity;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/** The entry "Paperless Sync" in the navigation of the Activity app, which shows the entries of the app alone. */
final class Filter implements IFilter {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getIdentifier(): string {
		return AppConstants::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Paperless Sync');
	}

	/** Below the filters of Nextcloud, such as Files with 30 and Comments with 40. */
	public function getPriority(): int {
		return 70;
	}

	public function getIcon(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg'));
	}

	/**
	 * @param string[] $types
	 * @return list<string>
	 */
	public function filterTypes(array $types): array {
		return array_values(array_intersect($types, [ActivityPublisher::TYPE]));
	}

	/** @return list<string> */
	public function allowedApps(): array {
		return [AppConstants::APP_ID];
	}
}
