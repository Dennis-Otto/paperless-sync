<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * The line of the app in the activity settings of each user, among the files: the entries always
 * appear in the activity stream, and each user may also get them by mail or as a notification,
 * which is off until the user turns it on.
 */
final class Setting extends ActivitySettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function getIdentifier(): string {
		return ActivityPublisher::TYPE;
	}

	public function getName(): string {
		return $this->l10n->t('<strong>Paperless Sync</strong> changed the document archive or imported a file');
	}

	public function getGroupIdentifier(): string {
		return 'files';
	}

	public function getGroupName(): string {
		return $this->l10n->t('Files');
	}

	/** After the lines of the Files app of Nextcloud. */
	public function getPriority(): int {
		return 70;
	}
}
