<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;

/**
 * The storage paths of Paperless in a run, as PathTemplateService::storagePaths() reads them: their
 * names, for {{ storage_path }} and the metadata next to the archive, and with "Use the storage path
 * of a document in Paperless" the settings with the template of each storage path that the archive
 * follows, and the names of those that it can't follow.
 */
final class StoragePaths {
	/**
	 * @param array<string, string> $names the names of the storage paths, by their IDs
	 * @param array<string, SyncConfig> $followed the settings with the template of each storage path that the archive follows, by its ID
	 * @param array<string, string> $unsupported the names of the storage paths whose templates the archive can't follow, by their IDs
	 */
	public function __construct(
		public readonly array $names,
		private readonly array $followed = [],
		private readonly array $unsupported = [],
	) {
	}

	/**
	 * The settings that give the path of $document: those with the template of its storage path
	 * when the archive follows it, and $config, with the path template, otherwise.
	 *
	 * @param array<string, mixed> $document
	 */
	public function configOf(SyncConfig $config, array $document): SyncConfig {
		$id = self::idOf($document);

		return $id !== null && isset($this->followed[$id]) ? $this->followed[$id] : $config;
	}

	/**
	 * The names of the storage paths of $documents that the archive can't follow, each once and
	 * sorted, for the report of a run and the preview: their documents follow the path template.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @return list<string>
	 */
	public function unsupported(array $documents): array {
		$names = [];
		foreach ($documents as $document) {
			$id = self::idOf($document);
			if ($id !== null && isset($this->unsupported[$id])) {
				$names[$id] = $this->unsupported[$id];
			}
		}
		sort($names, SORT_STRING);

		return $names;
	}

	/**
	 * The ID of the storage path of a document, or null for one without.
	 *
	 * @param array<string, mixed> $document
	 */
	private static function idOf(array $document): ?string {
		/** @psalm-suppress MixedAssignment */
		$id = $document['storage_path'] ?? null;

		return is_int($id) || is_string($id) ? (string)$id : null;
	}
}
