<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;

/**
 * The errors of a run as its report shows them and its states keep them: a state keeps the last
 * error of its document or file, so that an error that recurs in every run is logged once.
 */
final class SyncErrors {
	/** The longest error that a state keeps, in characters. */
	public const LENGTH = 4000;

	/** The message of the exception, or its class when it has none. */
	public static function message(\Throwable $exception): string {
		$message = trim($exception->getMessage());

		return $message !== '' ? $message : $exception::class;
	}

	/** The error as a state keeps it. */
	public static function stored(string $error): string {
		return mb_substr($error, 0, self::LENGTH);
	}

	/**
	 * Whether Nextcloud refused a write for the whole archive, not for one file alone, so that the
	 * other writes of the run would fail as well: a full quota or disk, an unavailable storage, or
	 * an archive folder in which the target user may no longer create files; a failure of that
	 * check counts as such a folder. A path that Nextcloud refuses on its own, such as a forbidden
	 * name or a read-only subfolder, concerns only its file.
	 */
	public static function archiveFailed(\Throwable $exception, NextcloudStorageInterface $storage, string $user, string $archiveRoot): bool {
		if ($exception instanceof NotEnoughSpaceException || $exception instanceof StorageNotAvailableException) {
			return true;
		}
		if (!$exception instanceof NotPermittedException) {
			return false;
		}
		try {
			return !$storage->isWritableFolder($user, $archiveRoot);
		} catch (\Throwable) {
			return true;
		}
	}

	/**
	 * Whether an error differs from the one that the state keeps.
	 *
	 * @param array<string, int|string|null>|null $state
	 */
	public static function changed(?array $state, string $error): bool {
		return ($state['last_error'] ?? null) !== self::stored($error);
	}
}
