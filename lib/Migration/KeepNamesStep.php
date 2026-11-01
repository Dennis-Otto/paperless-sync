<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Migration;

use OCA\PaperlessSync\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\SimpleMigrationStep;

/**
 * A step of an update that keeps the names of the folders and the fallbacks of an installation in
 * use, when the version that it brings would propose others. A folder of another name is another
 * archive, which the next run would fill anew.
 */
abstract class KeepNamesStep extends SimpleMigrationStep {
	/** The tables in which a run leaves its state: the copies of the archive and the imports of the inbox. */
	private const STATE_TABLES = ['paperless_sync_export', 'paperless_sync_import'];

	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the step through its container. */
	public function __construct(
		protected ConfigService $configService,
		private IDBConnection $connection,
	) {
	}

	/** Whether the installation is in use: it has a target user, or a run left a state. */
	protected function inUse(): bool {
		if ($this->configService->targetUser() !== '') {
			return true;
		}
		foreach (self::STATE_TABLES as $table) {
			$query = $this->connection->getQueryBuilder();
			$query->select('id')->from($table)->setMaxResults(1);
			$result = $query->executeQuery();
			$row = $result->fetchOne();
			$result->closeCursor();
			if ($row !== false) {
				return true;
			}
		}

		return false;
	}
}
