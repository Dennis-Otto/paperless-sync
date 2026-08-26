<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;

interface NextcloudStorageInterface {
	public function test(string $userId, string $basePath): void;

	public function prepare(SyncConfig $config): void;

	public function exists(string $userId, string $path): bool;

	/**
	 * The source stream may be consumed and closed by Nextcloud.
	 *
	 * @param resource $source
	 */
	public function writeAtomic(string $userId, string $path, $source, string $conflictMode): void;

	public function move(string $userId, string $source, string $destination, string $conflictMode): bool;

	public function delete(string $userId, string $path): void;

	/** @return list<array{path: string, name: string, etag: string}> */
	public function listFiles(string $userId, string $path, bool $recursive): array;

	/** @return resource */
	public function openRead(string $userId, string $path);

	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void;

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int;
}
