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
 * The records of the uploads of per-user ownership, which follow each upload from its start until
 * its document has its owner, also after the state of its file has gone: whose upload it is, from
 * the inbox or from Send to Paperless, the path of the file, the checksums of its content, the
 * highest ID of a document with that content before the first upload, the task of the upload once
 * Paperless names it, and the time of its last attempt. A user has one record for each content.
 *
 * The record proves a lost upload by the ID of its document, without the clocks, so the time of
 * the first upload that the imports and the sent files keep since Version000190Date20261123000000
 * is no longer read; an upgrade of the app only adds, so the column stays.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000200Date20261124000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('paperless_sync_upload')) {
			return null;
		}
		$table = $schema->createTable('paperless_sync_upload');
		$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
		$table->addColumn('source', 'string', ['notnull' => true, 'length' => 16, 'default' => 'inbox']);
		$table->addColumn('path', 'string', ['notnull' => true, 'length' => 1024, 'default' => '']);
		$table->addColumn('sha256', 'string', ['notnull' => true, 'length' => 64]);
		$table->addColumn('md5', 'string', ['notnull' => true, 'length' => 32]);
		$table->addColumn('known_id', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('task_id', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
		$table->addColumn('last_attempt_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'sha256'], 'psync_upload_user_sha');

		return $schema;
	}
}
