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

	/** Whether the path is a folder in which the user may create files and folders. */
	public function isWritableFolder(string $userId, string $path): bool;

	/**
	 * Writes the stream to the path. A new file appears there only once it is complete. With the
	 * conflict mode replace, an existing file is updated in place, so that it keeps its file ID, and
	 * with it its shares, comments, tags, favorites and versions; with skip, an existing file is a
	 * conflict. The source stream may be consumed and closed by Nextcloud.
	 *
	 * @param resource $source
	 */
	public function writeStream(string $userId, string $path, $source, string $conflictMode): void;

	/** Moves the file with its file ID, so that it keeps its shares, comments, tags, favorites and versions. */
	public function move(string $userId, string $source, string $destination, string $conflictMode): bool;

	public function delete(string $userId, string $path): void;

	/** @return list<array{path: string, name: string, etag: string}> */
	public function listFiles(string $userId, string $path, bool $recursive): array;

	/** @return resource */
	public function openRead(string $userId, string $path);

	/** Writes the text to the path as writeStream() writes a stream. */
	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void;

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int;
}
