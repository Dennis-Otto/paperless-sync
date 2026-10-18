<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;

/**
 * What a planner may know of the files of the target user: the files of a folder, whether a file
 * is there, and its checksums. It can't change a file, so a plan changes nothing.
 */
final class TargetFiles {
	public function __construct(
		private NextcloudStorageInterface $storage,
		private string $user,
	) {
	}

	/**
	 * The files of a folder, and of its subfolders with $recursive.
	 *
	 * @return list<array{path: string, name: string, etag: string, id: int}>
	 */
	public function list(string $folder, bool $recursive): array {
		return $this->storage->listFiles($this->user, $folder, $recursive);
	}

	/** Whether there is a file at the path; no path has none. */
	public function exists(string $path): bool {
		return $path !== '' && $this->storage->exists($this->user, $path);
	}

	/** The checksums of the file at the path, from one read of it, or null when it is gone or can't be read. */
	public function fingerprint(string $path): ?DocumentFingerprint {
		if (!$this->exists($path)) {
			return null;
		}
		try {
			$stream = $this->storage->openRead($this->user, $path);
			try {
				return DocumentFingerprint::of($stream);
			} finally {
				fclose($stream);
			}
		} catch (\Throwable) {
			return null;
		}
	}
}
