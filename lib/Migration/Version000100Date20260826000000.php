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
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000100Date20260826000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('paperless_sync_export')) {
			$table = $schema->createTable('paperless_sync_export');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('owner_uid', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('document_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('path', 'string', ['notnull' => true, 'length' => 1024, 'default' => '']);
			$table->addColumn('fingerprint', 'string', ['notnull' => true, 'length' => 128, 'default' => '']);
			$table->addColumn('source_revision', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('missing_runs', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_seen', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('trash_date', 'bigint', ['notnull' => false]);
			$table->addColumn('last_error', 'text', ['notnull' => false]);
			$table->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['owner_uid', 'document_id'], 'psync_export_owner_doc');
		}

		if (!$schema->hasTable('paperless_sync_import')) {
			$table = $schema->createTable('paperless_sync_import');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('owner_uid', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('path_hash', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('path', 'string', ['notnull' => true, 'length' => 1024]);
			$table->addColumn('etag', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('task_id', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('submitted_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_error', 'text', ['notnull' => false]);
			$table->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['owner_uid', 'path_hash'], 'psync_import_owner_path');
		}

		return $schema;
	}
}
