<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IGroupManager;

/**
 * The actions of the app in the menu of Files: who may send files to Paperless, which files a
 * click sends, and the document of Paperless that a file names.
 *
 * Sending uses the token of the app, so only the members of the group of the setting "Group that
 * may send files" may do it. A click queues the files and the files below the folders that it
 * names; SendService uploads them in the background and notifies the sender of each result.
 * The files stay where they are. Files that Paperless has already, whose names carry a marker
 * such as [P123], and the files of the folder of the app, which the runs look after, stay out.
 */
final class FileActions {
	/** A file or folder that the user doesn't see, or may not read. */
	public const REFUSED_MISSING = 'missing';
	/** A file or folder in the folder of the app, such as the archive or the inbox. */
	public const REFUSED_APP_FOLDER = 'app_folder';
	/** More files than the batch size, which a click may send at most. */
	public const REFUSED_TOO_MANY = 'too_many';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private IGroupManager $groupManager,
		private NextcloudStorageInterface $storage,
		private SyncStateRepositoryInterface $state,
	) {
	}

	/**
	 * The ID of the document of Paperless that the name of a file names with its marker, such as
	 * 123 for "2026-03-01 - Invoice [P123].pdf", the last one when it has several, or null. The
	 * path template keeps the marker in the name of every copy of the archive.
	 */
	public static function documentId(string $name): ?int {
		if (preg_match_all('/\[P([1-9][0-9]{0,17})\]/', $name, $matches) === 0) {
			return null;
		}

		return (int)end($matches[1]);
	}

	/**
	 * What the menu of Files offers the user: whether they may send files to Paperless, and the
	 * address of Paperless that "Open in Paperless" opens, '' when the app has none.
	 *
	 * @return array{send: bool, paperlessUrl: string}
	 */
	public function offers(string $userId): array {
		$config = $this->configService->get();

		return ['send' => $this->maySend($config, $userId), 'paperlessUrl' => $config->paperlessUrl];
	}

	/** Whether the user may send files to Paperless: the app is connected, and they are a member of the group of the setting. */
	public function maySend(SyncConfig $config, string $userId): bool {
		return $config->complete() && $config->sendGroup !== '' && $this->groupManager->isInGroup($userId, $config->sendGroup);
	}

	/**
	 * Queues the files and the files below the folders with the IDs, as the user sees them, for
	 * SendService, unless one of them is missing or in the folder of the app, or they are more than
	 * a batch. A file that waits for its upload already waits on; the files that Paperless has
	 * already, by the marker in their names, and those of the folder of the app below a folder
	 * stay out. The answer says why nothing was queued, and else how many files were.
	 *
	 * @param list<int> $fileIds
	 * @return array{refused: ''|self::REFUSED_*, name: string, limit: int, queued: int, waiting: int, skipped: int}
	 */
	public function send(SyncConfig $config, string $userId, array $fileIds): array {
		/** @var array<int, array{path: string, folder: string}> $files the files to queue, by their IDs */
		$files = [];
		$skipped = 0;
		foreach (array_values(array_unique($fileIds)) as $fileId) {
			$node = $this->storage->nodeById($userId, $fileId);
			if ($node === null || !$node['readable']) {
				return self::refused(self::REFUSED_MISSING, '', $config);
			}
			if ($this->inAppFolder($config, $userId, $fileId, $node['path'])) {
				return self::refused(self::REFUSED_APP_FOLDER, $node['name'], $config);
			}
			if (!$node['folder']) {
				if (self::documentId($node['name']) !== null) {
					++$skipped;
				} else {
					$files[$fileId] = ['path' => $node['path'], 'folder' => ''];
				}
				continue;
			}
			foreach ($this->storage->listFiles($userId, $node['path'], true) as $file) {
				if (self::documentId($file['name']) !== null || $this->inAppFolder($config, $userId, $file['id'], $file['path'])) {
					++$skipped;
					continue;
				}
				$files[$file['id']] ??= ['path' => $file['path'], 'folder' => self::folderBelow($node['path'], $file['path'])];
			}
			if (count($files) > $config->batchSize) {
				return self::refused(self::REFUSED_TOO_MANY, $node['name'], $config);
			}
		}
		if (count($files) > $config->batchSize) {
			return self::refused(self::REFUSED_TOO_MANY, '', $config);
		}

		$queued = 0;
		$waiting = 0;
		foreach ($files as $fileId => $file) {
			if ($this->state->findSend($userId, $fileId) !== null) {
				++$waiting;
				continue;
			}
			$this->state->saveSend($userId, $fileId, ['path' => $file['path'], 'folder' => $file['folder'], 'status' => SendService::QUEUED]);
			++$queued;
		}

		return ['refused' => '', 'name' => '', 'limit' => $config->batchSize, 'queued' => $queued, 'waiting' => $waiting, 'skipped' => $skipped];
	}

	/**
	 * Whether the file or folder is in the folder of the app, which the runs look after: it is
	 * there among the files of the target user, also when the user sees it through a share.
	 */
	private function inAppFolder(SyncConfig $config, string $userId, int $fileId, string $path): bool {
		if ($userId !== $config->targetUser) {
			$path = $this->storage->nodeById($config->targetUser, $fileId)['path'] ?? null;
		}
		$base = trim($config->basePath, '/');

		return $path !== null && ($path === $base || str_starts_with($path, $base . '/'));
	}

	/**
	 * The path of the folder of a file below the sent folder, such as Taxes/2026, or '' for a file
	 * in the sent folder itself: with "Subfolders become tags", the names of its tags.
	 */
	private static function folderBelow(string $folder, string $path): string {
		$relative = substr($path, strlen($folder) + 1);
		$slash = strrpos($relative, '/');

		return $slash === false ? '' : substr($relative, 0, $slash);
	}

	/** @return array{refused: self::REFUSED_*, name: string, limit: int, queued: int, waiting: int, skipped: int} */
	private static function refused(string $reason, string $name, SyncConfig $config): array {
		/** @var self::REFUSED_* $reason */
		return ['refused' => $reason, 'name' => $name, 'limit' => $config->batchSize, 'queued' => 0, 'waiting' => 0, 'skipped' => 0];
	}
}
