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
 * The files that people send to Paperless from the menu of Files, until Paperless has imported
 * them: who sent which file, its path then and the folder below the sent folder that holds it,
 * and the state of its upload, as the table of the inbox keeps it for a file of the inbox. A
 * file is there once for each user, so that a second click doesn't send it twice.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000140Date20261018000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('paperless_sync_send')) {
			return $schema;
		}
		$table = $schema->createTable('paperless_sync_send');
		$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
		$table->addColumn('file_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('path', 'string', ['notnull' => true, 'length' => 1024, 'default' => '']);
		$table->addColumn('folder', 'string', ['notnull' => true, 'length' => 1024, 'default' => '']);
		$table->addColumn('task_id', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
		$table->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'queued']);
		$table->addColumn('submitted_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
		$table->addColumn('retry_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_error', 'text', ['notnull' => false]);
		$table->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'file_id'], 'psync_send_user_file');

		return $schema;
	}
}
