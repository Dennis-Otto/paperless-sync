<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use RuntimeException;

/**
 * The files of the Nextcloud target user in memory, keyed by their path, each with its file ID as
 * Nextcloud gives it: a new file gets a new ID, a move takes the ID along, and a write over an
 * existing file updates it in place and keeps its ID.
 */
final class TestStorage implements NextcloudStorageInterface {
	/** @var array<string, string> */
	public array $files = [];
	/** @var list<array{string, 'created'|'updated'}> every write of a file, with whether it created the file or updated it in place */
	public array $writes = [];
	/** @var array<string, int> the file ID of every file whose ID was asked for or that a write created */
	private array $fileIds = [];
	private int $lastFileId = 0;
	/** The failure of every write of a document, such as of a full quota or a read-only storage. */
	public ?\Throwable $writeException = null;
	public ?\Throwable $writeTextException = null;
	public ?\Throwable $moveException = null;
	public ?\Throwable $deleteException = null;
	/** The failure of every read of a file, such as of a storage that is not available. */
	public ?\Throwable $readException = null;
	/** @var list<string> the paths that openRead was asked for */
	public array $reads = [];
	/** @var array<string, \Throwable> the failure of the write of one path, such as of a name that Nextcloud refuses */
	public array $writeFailures = [];
	/** @var list<array{string, string}> the file paths and roots that pruneEmptyParents was asked about */
	public array $prunes = [];
	/** Whether the user may create files in every folder, or the failure of that check. */
	public bool|\Throwable $writable = true;
	/** @var list<string> the folders that isWritableFolder was asked about */
	public array $writableChecks = [];

	public function test(string $userId, string $basePath): void {
	}

	public function prepare(SyncConfig $config): void {
	}

	public function exists(string $userId, string $path): bool {
		$this->requirePath($path);
		return array_key_exists($path, $this->files);
	}

	public function isWritableFolder(string $userId, string $path): bool {
		$this->writableChecks[] = $path;
		if ($this->writable instanceof \Throwable) {
			throw $this->writable;
		}
		return $this->writable;
	}

	public function writeStream(string $userId, string $path, $source, string $conflictMode): void {
		$failure = $this->writeFailures[$path] ?? $this->writeException;
		if ($failure !== null) {
			throw $failure;
		}
		$content = stream_get_contents($source);
		$this->write($path, is_string($content) ? $content : '', $conflictMode);
		fclose($source);
	}

	public function move(string $userId, string $source, string $destination, string $conflictMode): bool {
		$this->requirePath($source);
		$this->requirePath($destination);
		if ($this->moveException !== null) {
			throw $this->moveException;
		}
		if (!isset($this->files[$source])) {
			return false;
		}
		if ($conflictMode === 'skip' && isset($this->files[$destination])) {
			throw new RuntimeException('conflict');
		}
		$this->files[$destination] = $this->files[$source];
		$this->fileIds[$destination] = $this->fileId($source);
		unset($this->files[$source], $this->fileIds[$source]);
		return true;
	}

	public function delete(string $userId, string $path): void {
		$this->requirePath($path);
		if ($this->deleteException !== null) {
			throw $this->deleteException;
		}
		unset($this->files[$path], $this->fileIds[$path]);
	}

	public function listFiles(string $userId, string $path, bool $recursive): array {
		$result = [];
		foreach ($this->files as $filePath => $content) {
			if (!str_starts_with($filePath, rtrim($path, '/') . '/')) {
				continue;
			}
			$relative = substr($filePath, strlen(rtrim($path, '/') . '/'));
			if (!$recursive && str_contains($relative, '/')) {
				continue;
			}
			$result[] = ['path' => $filePath, 'name' => basename($filePath), 'etag' => hash('sha256', $content)];
		}
		return $result;
	}

	public function openRead(string $userId, string $path) {
		$this->reads[] = $path;
		if ($this->readException !== null) {
			throw $this->readException;
		}
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $this->files[$path]);
		rewind($stream);
		return $stream;
	}

	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void {
		if ($this->writeTextException !== null) {
			throw $this->writeTextException;
		}
		$this->write($path, $content, $conflictMode);
	}

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int {
		$this->prunes[] = [$filePath, $stopAt];
		return 1;
	}

	/** The file ID of the file at the path, which Nextcloud gives a file when it creates it. */
	public function fileId(string $path): int {
		if (!isset($this->files[$path])) {
			throw new InvalidArgumentException("No file at {$path}.");
		}
		return $this->fileIds[$path] ??= ++$this->lastFileId;
	}

	/** Like the Nextcloud storage: a new file gets a new file ID, an existing one is updated in place. */
	private function write(string $path, string $content, string $conflictMode): void {
		$this->requirePath($path);
		$exists = isset($this->files[$path]);
		if ($exists && $conflictMode === 'skip') {
			throw new RuntimeException('conflict');
		}
		$this->files[$path] = $content;
		if ($exists) {
			$this->writes[] = [$path, 'updated'];
		} else {
			$this->fileIds[$path] = ++$this->lastFileId;
			$this->writes[] = [$path, 'created'];
		}
	}

	/** Like the Nextcloud storage, which normalizes every path, refuse an empty one. */
	private function requirePath(string $path): void {
		if ($path === '') {
			throw new InvalidArgumentException('A relative folder path is required.');
		}
	}
}
