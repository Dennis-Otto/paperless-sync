<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use RuntimeException;

/**
 * The files of the Nextcloud target user in memory, keyed by their path.
 */
final class TestStorage implements NextcloudStorageInterface {
	/** @var array<string, string> */
	public array $files = [];
	public ?\Throwable $writeTextException = null;
	public ?\Throwable $moveException = null;
	public ?\Throwable $deleteException = null;
	/** @var list<array{string, string}> the file paths and roots that pruneEmptyParents was asked about */
	public array $prunes = [];

	public function test(string $userId, string $basePath): void {
	}

	public function prepare(SyncConfig $config): void {
	}

	public function exists(string $userId, string $path): bool {
		return array_key_exists($path, $this->files);
	}

	public function writeAtomic(string $userId, string $path, $source, string $conflictMode): void {
		if ($conflictMode === 'skip' && isset($this->files[$path])) {
			throw new RuntimeException('conflict');
		}
		$content = stream_get_contents($source);
		$this->files[$path] = is_string($content) ? $content : '';
		fclose($source);
	}

	public function move(string $userId, string $source, string $destination, string $conflictMode): bool {
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
		unset($this->files[$source]);
		return true;
	}

	public function delete(string $userId, string $path): void {
		if ($this->deleteException !== null) {
			throw $this->deleteException;
		}
		unset($this->files[$path]);
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
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $this->files[$path]);
		rewind($stream);
		return $stream;
	}

	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void {
		if ($this->writeTextException !== null) {
			throw $this->writeTextException;
		}
		$this->files[$path] = $content;
	}

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int {
		$this->prunes[] = [$filePath, $stopAt];
		return 1;
	}
}
