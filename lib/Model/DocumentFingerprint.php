<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

use RuntimeException;

/**
 * The checksums of a downloaded document, which tell whether a mirrored file still has the content
 * that Paperless reports, so that a change of the metadata alone moves the file instead of
 * downloading it again. Paperless-ngx reports MD5 up to 2.x and SHA-256 since 3.0, so the database
 * keeps both, as "<sha256> <md5>" in the column fingerprint. A file that an earlier version exported
 * has only its SHA-256 there until a run computes the checksums of the mirrored file or downloads
 * the document again.
 */
final class DocumentFingerprint {
	/**
	 * The value of the column fingerprint while the app replaces the content of its copy: it names
	 * no checksum, so the next run downloads the document again, and the file at the recorded path
	 * stays the app's own, also when a failed write left it incomplete.
	 */
	public const PENDING = 'pending';

	/** The bytes that one read hashes: the document never is in memory as a whole. */
	private const CHUNK_SIZE = 1024 * 1024;

	public function __construct(
		public readonly string $sha256,
		public readonly string $md5,
	) {
	}

	/**
	 * The checksums of a stream from its position to its end, in one pass, after which it is
	 * rewound for the write.
	 *
	 * @param resource $stream
	 * @throws RuntimeException when the stream can't be read to its end
	 */
	public static function of($stream): self {
		$sha256 = hash_init('sha256');
		$md5 = hash_init('md5');
		while (($chunk = fread($stream, self::CHUNK_SIZE)) !== false && $chunk !== '') {
			hash_update($sha256, $chunk);
			hash_update($md5, $chunk);
		}
		if (!feof($stream)) {
			throw new RuntimeException('Could not read the Paperless download to its end.');
		}
		rewind($stream);

		return new self(hash_final($sha256), hash_final($md5));
	}

	/** Whether a stored fingerprint, also one with only the SHA-256, names a checksum that Paperless reports. */
	public static function matches(string $fingerprint, string $checksum): bool {
		return $checksum !== '' && in_array(strtolower($checksum), explode(' ', $fingerprint), true);
	}

	/** The value of the column fingerprint: 97 characters of its 128. */
	public function value(): string {
		return $this->sha256 . ' ' . $this->md5;
	}
}
