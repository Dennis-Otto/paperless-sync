<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Migration;

use Closure;
use OCA\PaperlessSync\Service\ConfigService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Stores the target user with the ID as Nextcloud spells it, as a save does from this version on.
 * An earlier version stored it as it was typed, such as Dennis for the user dennis: Nextcloud finds
 * the user whatever the case, so the runs wrote to the right folder, but the activities and the
 * notifications went to an ID that no user has, and the check of the shares of the users with tags
 * found none. A target user that Nextcloud doesn't have stays as it is, for the connection checklist
 * to name.
 */
final class Version000180Date20261112120000 extends SimpleMigrationStep {
	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the step through its container. */
	public function __construct(
		private ConfigService $configService,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$spelled = $this->configService->spellTargetUser();
		if ($spelled !== null) {
			$output->info("Stored the target user of Paperless Sync as Nextcloud spells the ID: {$spelled}.");
		}
	}
}
