<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Migration;

use Closure;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The checksum that Paperless reported for a document and the revision that it belongs to, so
 * that a run asks Paperless for it once for each revision, also when the document waits for a
 * later run. And the settings and the status of earlier versions become lazy, so that Nextcloud no
 * longer loads them with every request of every user.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000120Date20261014000000 extends SimpleMigrationStep {
	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the step through its container. */
	public function __construct(
		private ConfigService $configService,
		private StatusService $status,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('paperless_sync_export');
		if (!$table->hasColumn('remote_checksum')) {
			$table->addColumn('remote_checksum', 'string', ['notnull' => false, 'length' => 128]);
		}
		if (!$table->hasColumn('remote_revision')) {
			$table->addColumn('remote_revision', 'string', ['notnull' => false, 'length' => 255]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$changed = $this->configService->storeLazily() + $this->status->storeLazily();
		if ($changed > 0) {
			$output->info("Stored {$changed} settings of Paperless Sync as lazy, which Nextcloud loads only when the app asks for them.");
		}
	}
}
