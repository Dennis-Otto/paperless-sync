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
 * The ID of the file of each upload of the inbox, so that its state follows the file when it is
 * renamed or moved inside the inbox, instead of the file going to Paperless a second time. A state
 * that an earlier version saved has none, 0, and its file is looked up in Paperless by its content
 * before an upload.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000170Date20261112000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('paperless_sync_import');
		if ($table->hasColumn('file_id')) {
			return null;
		}
		$table->addColumn('file_id', 'bigint', ['notnull' => true, 'default' => 0]);

		return $schema;
	}
}
