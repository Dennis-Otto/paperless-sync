<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

/**
 * The streams of a storage of Nextcloud in memory: a file opened for writing, which hands its
 * content over when it is closed, as a storage such as S3 uploads it then, and the listing of a
 * folder, which the storage gives as a directory handle for readdir().
 */
final class StorageStreams {
	private const PROTOCOL = 'paperless-sync-storage';

	/** @var array<string, array{close?: \Closure(string): void, refuse?: bool, names?: list<string>}> the streams to open, by their ID */
	private static array $opening = [];
	private static int $lastId = 0;

	/** @var resource|null */
	public $context;
	/** @var array{close?: \Closure(string): void, refuse?: bool, names?: list<string>} */
	private array $stream = [];
	private string $content = '';
	private int $position = 0;

	/**
	 * A file opened for writing, which takes every byte, or none with $refuseWrites, as a full disk.
	 *
	 * @param \Closure(string): void $close receives the content when the file is closed
	 * @return resource
	 */
	public static function file(\Closure $close, bool $refuseWrites = false) {
		$stream = fopen(self::register(['close' => $close, 'refuse' => $refuseWrites]), 'wb');
		if (!is_resource($stream)) {
			throw new \RuntimeException('Could not open the file of the test storage.');
		}

		return $stream;
	}

	/**
	 * The listing of a folder.
	 *
	 * @param list<string> $names
	 * @return resource
	 */
	public static function directory(array $names) {
		$directory = opendir(self::register(['names' => $names]));
		if (!is_resource($directory)) {
			throw new \RuntimeException('Could not open the folder of the test storage.');
		}

		return $directory;
	}

	/** @param array{close?: \Closure(string): void, refuse?: bool, names?: list<string>} $stream */
	private static function register(array $stream): string {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		$id = (string)++self::$lastId;
		self::$opening[$id] = $stream;

		return self::PROTOCOL . '://' . $id;
	}

	private function take(string $path): bool {
		$id = substr($path, strlen(self::PROTOCOL . '://'));
		if (!isset(self::$opening[$id])) {
			return false;
		}
		$this->stream = self::$opening[$id];
		unset(self::$opening[$id]);

		return true;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		return $this->take($path);
	}

	public function stream_write(string $data): int {
		if ($this->stream['refuse'] ?? false) {
			return 0;
		}
		$this->content .= $data;

		return strlen($data);
	}

	public function stream_close(): void {
		if (isset($this->stream['close'])) {
			($this->stream['close'])($this->content);
		}
	}

	public function dir_opendir(string $path, int $options): bool {
		return $this->take($path);
	}

	public function dir_readdir(): string|false {
		return $this->stream['names'][$this->position++] ?? false;
	}

	public function dir_rewinddir(): bool {
		$this->position = 0;

		return true;
	}

	public function dir_closedir(): bool {
		return true;
	}
}
