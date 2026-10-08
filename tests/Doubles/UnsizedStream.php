<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

/**
 * A readable stream without a size, like a file on object storage that Nextcloud reads over
 * HTTP: fstat() of it fails.
 */
final class UnsizedStream {
	private const PROTOCOL = 'paperless-sync-unsized';

	/** @var resource|null */
	public $context;
	private string $content = '';
	private int $position = 0;

	/** @return resource */
	public static function open(string $content) {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		$stream = fopen(self::PROTOCOL . '://', 'rb', false, stream_context_create([self::PROTOCOL => ['content' => $content]]));
		if (!is_resource($stream)) {
			throw new \RuntimeException('Could not open the unsized test stream.');
		}

		return $stream;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$options = is_resource($this->context) ? stream_context_get_options($this->context) : [];
		$this->content = (string)($options[self::PROTOCOL]['content'] ?? '');

		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr($this->content, $this->position, $count);
		$this->position += strlen($chunk);

		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen($this->content);
	}

	public function stream_stat(): false {
		return false;
	}
}
