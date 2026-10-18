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
 * The files that a rule of Flow sends to Paperless wait in the table of the sent files as well,
 * with the names of the tags of the rule, separated by commas, and the ID of the rule, which is 0
 * for a file sent from the menu of Files.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000150Date20261018120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('paperless_sync_send');
		if (!$table->hasColumn('tags')) {
			$table->addColumn('tags', 'text', ['notnull' => false]);
		}
		if (!$table->hasColumn('rule_id')) {
			$table->addColumn('rule_id', 'bigint', ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		}

		return $schema;
	}
}
