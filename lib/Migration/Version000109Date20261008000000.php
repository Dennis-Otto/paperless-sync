<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The failed uploads of a file of the inbox and the time of its next attempt, so that a file
 * waits longer after each failure instead of being uploaded again in every run.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000109Date20261008000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('paperless_sync_import');
		if (!$table->hasColumn('attempts')) {
			$table->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('retry_at')) {
			$table->addColumn('retry_at', 'bigint', ['notnull' => true, 'default' => 0]);
		}

		return $schema;
	}
}
