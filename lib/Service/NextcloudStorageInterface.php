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

	/** The ID of the file or folder at the path, which it keeps through moves and new content, or null when there is none. */
	public function findFileId(string $userId, string $path): ?int;

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

	/**
	 * The files below the folder, without hidden ones, whose names start with a dot, and without
	 * those in hidden folders, with the ID of each.
	 *
	 * @return list<array{path: string, name: string, etag: string, id: int}>
	 */
	public function listFiles(string $userId, string $path, bool $recursive): array;

	/**
	 * The file or folder with the ID as the user sees it among their files, also one that is shared
	 * with them, or null when they see none with the ID: its path among their files, its name,
	 * whether it is a folder and whether they may read it. A file or folder of a share whose owner
	 * allows viewing only, without downloads, counts as not readable: a copy in Paperless would be
	 * a download.
	 *
	 * @return array{path: string, name: string, folder: bool, readable: bool}|null
	 */
	public function nodeById(string $userId, int $fileId): ?array;

	/** @return resource */
	public function openRead(string $userId, string $path);

	/** Writes the text to the path as writeStream() writes a stream. */
	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void;

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int;
}
