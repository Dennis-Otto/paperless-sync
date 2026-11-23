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
 * The time of the first upload of each file of the inbox and of Send to Paperless that may have
 * reached Paperless, which an upload whose answer never came needs in per-user ownership: only a
 * document of the account of the app that Paperless added at or after it may be that upload's.
 * A state that an earlier version saved has none, 0, so a file that waits for its next upload goes
 * up again rather than taking a document of the account for its own; an upload that waits for its
 * task has the time of that upload.
 *
 * Doctrine's schema classes are supplied by Nextcloud at runtime.
 *
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UnnecessaryVarAnnotation
 */
final class Version000190Date20261123000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (['paperless_sync_import', 'paperless_sync_send'] as $name) {
			$table = $schema->getTable($name);
			if (!$table->hasColumn('first_attempt_at')) {
				$table->addColumn('first_attempt_at', 'bigint', ['notnull' => true, 'default' => 0]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
