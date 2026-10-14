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
 * The files of Nextcloud in memory, each user's apart and keyed by their path, with a record of
 * every call: a run that names another user than the target user, or that prepares folders in a
 * dry-run, shows in the files and in the calls. Each file has its file ID as Nextcloud gives it: a
 * new file gets a new ID, a move takes the ID along, and a write over an existing file updates it
 * in place and keeps its ID.
 */
final class TestStorage implements NextcloudStorageInterface {
	/** The target user of the tests, whose files $files holds. */
	public const USER = 'paperless';

	/** @var array<string, string> the files of the target user */
	public array $files = [];
	/** @var list<array{string, 'created'|'updated'}> every write of a file, with whether it created the file or updated it in place */
	public array $writes = [];
	/** @var array<string, array<string, int>> the file ID of every file whose ID was asked for or that a write created, by user */
	private array $fileIds = [];
	private int $lastFileId = 0;
	/** @var array<string, array<string, string>> the files of every other user, by user */
	public array $filesOfOthers = [];
	/** @var array<string, list<string>> the folders that prepare() created, by user */
	public array $folders = [];
	/** @var list<array{string, string, list<string>}> every call: the method, the user and the paths it names */
	public array $calls = [];
	/** @var list<resource> every stream that openRead() opened */
	public array $openedStreams = [];
	/** The failure of the test of the base folder, such as of a user that does not exist. */
	public ?\Throwable $testException = null;
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
	/** The number of empty folders that each pruneEmptyParents() removes. */
	public int $foldersPerPrune = 1;
	/** Whether the user may create files in every folder, or the failure of that check. */
	public bool|\Throwable $writable = true;
	/** @var list<string> the folders that isWritableFolder was asked about */
	public array $writableChecks = [];

	public function test(string $userId, string $basePath): void {
		$this->record('test', $userId, $basePath);
		if ($this->testException !== null) {
			throw $this->testException;
		}
	}

	/** Creates the folders of the enabled directions, as Nextcloud's storage does. */
	public function prepare(SyncConfig $config): void {
		$this->record('prepare', $config->targetUser, $config->basePath);
		$folders = [$config->basePath];
		if ($config->exportEnabled) {
			$folders[] = $config->basePath . '/' . $config->archiveFolder;
		}
		if ($config->inboxEnabled) {
			$folders[] = $config->basePath . '/' . $config->inboxFolder;
			$folders[] = $config->basePath . '/' . $config->errorFolder;
		}
		$this->folders[$config->targetUser] = array_values(array_unique([...($this->folders[$config->targetUser] ?? []), ...$folders]));
	}

	public function exists(string $userId, string $path): bool {
		$files = &$this->filesOf('exists', $userId, $path);
		$this->requirePath($path);
		return array_key_exists($path, $files);
	}

	/** A folder exists while a file is in it, and has an ID of its own as well. */
	public function findFileId(string $userId, string $path): ?int {
		$this->requirePath($path);
		if (isset($this->files[$path])) {
			return $this->fileId($path);
		}
		foreach (array_keys($this->files) as $file) {
			if (str_starts_with($file, rtrim($path, '/') . '/')) {
				return $this->fileIds[$path] ??= ++$this->lastFileId;
			}
		}

		return null;
	}

	public function isWritableFolder(string $userId, string $path): bool {
		$this->record('isWritableFolder', $userId, $path);
		$this->writableChecks[] = $path;
		if ($this->writable instanceof \Throwable) {
			throw $this->writable;
		}
		return $this->writable;
	}

	public function writeStream(string $userId, string $path, $source, string $conflictMode): void {
		$this->record('writeStream', $userId, $path);
		$failure = $this->writeFailures[$path] ?? $this->writeException;
		if ($failure !== null) {
			throw $failure;
		}
		$content = stream_get_contents($source);
		$this->write($userId, $path, is_string($content) ? $content : '', $conflictMode);
		fclose($source);
	}

	public function move(string $userId, string $source, string $destination, string $conflictMode): bool {
		$files = &$this->filesOf('move', $userId, $source, $destination);
		$this->requirePath($source);
		$this->requirePath($destination);
		if ($this->moveException !== null) {
			throw $this->moveException;
		}
		if (!isset($files[$source])) {
			return false;
		}
		if ($conflictMode === 'skip' && isset($files[$destination])) {
			throw new RuntimeException('conflict');
		}
		$files[$destination] = $files[$source];
		$this->fileIds[$userId][$destination] = $this->fileId($source, $userId);
		unset($files[$source], $this->fileIds[$userId][$source]);
		return true;
	}

	public function delete(string $userId, string $path): void {
		$files = &$this->filesOf('delete', $userId, $path);
		$this->requirePath($path);
		if ($this->deleteException !== null) {
			throw $this->deleteException;
		}
		unset($files[$path], $this->fileIds[$userId][$path]);
	}

	public function listFiles(string $userId, string $path, bool $recursive): array {
		$files = &$this->filesOf('listFiles', $userId, $path);
		$result = [];
		foreach ($files as $filePath => $content) {
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
		$files = &$this->filesOf('openRead', $userId, $path);
		$this->reads[] = $path;
		if ($this->readException !== null) {
			throw $this->readException;
		}
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $files[$path]);
		rewind($stream);
		$this->openedStreams[] = $stream;
		return $stream;
	}

	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void {
		$this->record('writeText', $userId, $path);
		if ($this->writeTextException !== null) {
			throw $this->writeTextException;
		}
		$this->write($userId, $path, $content, $conflictMode);
	}

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int {
		$this->record('pruneEmptyParents', $userId, $filePath, $stopAt);
		$this->prunes[] = [$filePath, $stopAt];
		return $this->foldersPerPrune;
	}

	/** @return list<string> the users that the calls named, each once */
	public function users(): array {
		return array_values(array_unique(array_column($this->calls, 1)));
	}

	/** @return list<string> the methods of the calls, in their order */
	public function methods(): array {
		return array_column($this->calls, 0);
	}

	/**
	 * Records a call and returns the files of its user, to change them in place.
	 *
	 * @return array<string, string>
	 */
	private function &filesOf(string $method, string $userId, string ...$paths): array {
		$this->record($method, $userId, ...$paths);

		return $this->filesOfUser($userId);
	}

	/**
	 * The files of the user, to change them in place.
	 *
	 * @return array<string, string>
	 */
	private function &filesOfUser(string $userId): array {
		if ($userId === self::USER) {
			return $this->files;
		}
		$this->filesOfOthers[$userId] ??= [];

		return $this->filesOfOthers[$userId];
	}

	private function record(string $method, string $userId, string ...$paths): void {
		$this->calls[] = [$method, $userId, array_values($paths)];
	}

	/** The file ID of the file at the path, which Nextcloud gives a file when it creates it. */
	public function fileId(string $path, string $userId = self::USER): int {
		if (!isset($this->filesOfUser($userId)[$path])) {
			throw new InvalidArgumentException("No file of {$userId} at {$path}.");
		}
		return $this->fileIds[$userId][$path] ??= ++$this->lastFileId;
	}

	/** Like the Nextcloud storage: a new file gets a new file ID, an existing one is updated in place. */
	private function write(string $userId, string $path, string $content, string $conflictMode): void {
		$this->requirePath($path);
		$files = &$this->filesOfUser($userId);
		$exists = isset($files[$path]);
		if ($exists && $conflictMode === 'skip') {
			throw new RuntimeException('conflict');
		}
		$files[$path] = $content;
		if ($exists) {
			$this->writes[] = [$path, 'updated'];
		} else {
			$this->fileIds[$userId][$path] = ++$this->lastFileId;
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
