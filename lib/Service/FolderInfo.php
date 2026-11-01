<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * What js/files.js needs for its note above the list of Files, which says what a folder of the app
 * does with its files: which of the folders of the archive, the inbox and the failed imports the
 * user sees, and where, when the mirror was last complete, when the next run starts, and which
 * kinds of the note the user has hidden.
 *
 * A user sees their own folders when they are the target user or in per-user ownership, whose runs
 * look after the base folder of every user's files, and those of the target user that reach them
 * through a share, of the folder or of one above it, wherever they put that share. A folder that
 * the user can't see stays out of the answer, with its ID and its path, and a user who sees none
 * learns nothing about the runs either.
 *
 * @psalm-type Kind = 'archive'|'inbox'|'errors'
 * @psalm-type Folder = array{kind: Kind, id: int, path: string}
 */
final class FolderInfo {
	public const ARCHIVE = 'archive';
	public const INBOX = 'inbox';
	public const ERRORS = 'errors';
	/** @var list<Kind> */
	public const KINDS = [self::ARCHIVE, self::INBOX, self::ERRORS];

	/** The start of the name of the user setting that hides a kind of the note, before the kind. */
	private const DISMISSED = 'folder_note_dismissed_';

	/** @psalm-suppress PossiblyUnusedMethod Nextcloud creates the class through its container. */
	public function __construct(
		private ConfigService $configService,
		private StatusService $statusService,
		private NextcloudStorageInterface $storage,
		private IUserConfig $userConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What the note needs for the user: nextRun as StatusService::nextRun() has it, null when
	 * scheduled runs are off, and lastSuccess 0 before the first run that succeeded.
	 *
	 * @return array{folders: list<Folder>, lastSuccess: int, nextRun: int|null, dismissed: list<Kind>}
	 */
	public function of(string $userId): array {
		$config = $this->configService->get();
		$folders = $this->folders($config, $userId);
		if ($folders === []) {
			return ['folders' => [], 'lastSuccess' => 0, 'nextRun' => null, 'dismissed' => []];
		}

		return [
			'folders' => $folders,
			'lastSuccess' => $this->statusService->lastSuccess(),
			'nextRun' => $this->statusService->nextRun($config),
			'dismissed' => array_values(array_filter(self::KINDS, fn (string $kind): bool => $this->userConfig->getValueBool($userId, AppConstants::APP_ID, self::DISMISSED . $kind, lazy: true))),
		];
	}

	/**
	 * Hides or brings back a kind of the note for the user. Bringing it back leaves no setting behind.
	 *
	 * @param Kind $kind
	 */
	public function dismiss(string $userId, string $kind, bool $dismissed): void {
		if ($dismissed) {
			$this->userConfig->setValueBool($userId, AppConstants::APP_ID, self::DISMISSED . $kind, true, lazy: true);
		} else {
			$this->userConfig->deleteUserConfig($userId, AppConstants::APP_ID, self::DISMISSED . $kind);
		}
	}

	/**
	 * The folders of the app that the user sees. A note is a hint: when Nextcloud can't look a
	 * folder up, such as for a target user who is gone or a storage that is down, there is none,
	 * and Files goes on.
	 *
	 * @return list<Folder>
	 */
	private function folders(SyncConfig $config, string $userId): array {
		if (!$config->complete()) {
			return [];
		}
		$folders = [];
		try {
			foreach (self::paths($config) as $kind => $path) {
				if ($userId === $config->targetUser || $config->perUser()) {
					$id = $this->storage->findFileId($userId, $path);
					if ($id !== null) {
						$folders[] = ['kind' => $kind, 'id' => $id, 'path' => $path];
					}
				}
				if ($userId !== $config->targetUser) {
					$id = $this->storage->findFileId($config->targetUser, $path);
					$shared = $id === null ? null : $this->storage->nodeById($userId, $id);
					if ($id !== null && $shared !== null) {
						$folders[] = ['kind' => $kind, 'id' => $id, 'path' => $shared['path']];
					}
				}
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Paperless Sync could not look up its folders for a note in Files.', ['exception' => $exception]);

			return [];
		}

		return $folders;
	}

	/**
	 * The paths of the folders that have a note, below the home of a user. An archive that no run
	 * writes is no mirror, and an inbox that no run reads sends nothing; a failed import stays in
	 * the error folder whatever the settings say now.
	 *
	 * @return array<Kind, string>
	 */
	private static function paths(SyncConfig $config): array {
		$paths = [];
		if ($config->exportEnabled) {
			$paths[self::ARCHIVE] = Paths::join($config->basePath, $config->archiveFolder);
		}
		if ($config->inboxEnabled) {
			$paths[self::INBOX] = Paths::join($config->basePath, $config->inboxFolder);
		}
		$paths[self::ERRORS] = Paths::join($config->basePath, $config->errorFolder);

		return $paths;
	}
}
