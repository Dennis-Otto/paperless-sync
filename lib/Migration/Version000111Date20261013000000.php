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
 * The API token goes only to the URL that it was saved with, so it is stored together with it.
 * A token that an earlier version stored on its own is bound to the configured URL here; without
 * that, the app would ask for the token again after the update.
 */
final class Version000111Date20261013000000 extends SimpleMigrationStep {
	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the step through its container. */
	public function __construct(
		private ConfigService $configService,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->configService->bindUnboundToken()) {
			$output->info('Bound the stored Paperless API token to its Paperless URL.');
		}
	}
}
