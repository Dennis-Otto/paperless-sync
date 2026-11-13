<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every change of the files of a user happens in the filesystem of that user (UserFilesystem), so
 * that what a run deletes or replaces goes into the user's trash bin, whoever started the run.
 */
final class NextcloudStorageService implements NextcloudStorageInterface {
	/** The name of the temporary file of a write: hidden, next to its target, with a random part and the ending .part. */
	private const TEMPORARY_NAME = '/^\..+\.paperless-[0-9a-f]{16}\.part$/';
	/** The folders that a run cleared of the temporary files of earlier runs, one "user/path" on each line. */
	private const CLEARED_KEY = 'temporary_files_cleared';
	/** The failed attempts to clear a folder, one "user/path" on each line for each attempt. */
	private const FAILED_KEY = 'temporary_files_failed';
	/** The attempts after which a folder that always fails is left to be cleared by hand. */
	private const MAX_ATTEMPTS = 3;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private PathTemplateService $pathTemplate,
		private UserFilesystemInterface $filesystem,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function test(string $userId, string $basePath): void {
		if ($this->userManager->get($userId) === null) {
			throw new InvalidSettingException('Nextcloud user %s does not exist.', [$userId], 'target_user');
		}
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$basePath = $this->pathTemplate->normalizeRelativePath($basePath);
		if ($userFolder->nodeExists($basePath)) {
			$node = $userFolder->get($basePath);
			if (!$node instanceof Folder || !$node->isCreatable()) {
				throw new InvalidSettingException('The configured Nextcloud base folder is not a writable folder.', [], 'base_path');
			}
		} elseif (!$userFolder->isCreatable()) {
			throw new InvalidSettingException('The configured Nextcloud user folder is not writable.', [], 'target_user');
		}
	}

	public function prepare(SyncConfig $config): void {
		$this->filesystem->enter($config->targetUser);
		$this->ensureFolder($config->targetUser, $config->basePath);
		/** @var array<string, Folder> $written the folders into which the run writes new files, by path */
		$written = [];
		if ($config->exportEnabled) {
			$archive = $this->normalize($this->join($config->basePath, $config->archiveFolder));
			$written[$archive] = $this->ensureFolder($config->targetUser, $archive);
		}
		if ($config->inboxEnabled) {
			$this->ensureFolder($config->targetUser, $this->join($config->basePath, $config->inboxFolder));
			$errors = $this->normalize($this->join($config->basePath, $config->errorFolder));
			$written[$errors] = $this->ensureFolder($config->targetUser, $errors);
		}
		$this->clearLeftoverTemporaryFiles($config->targetUser, $written);
	}

	/**
	 * On Nextcloud 33.0.11, 34.0.6, 35.0.3 and newer, the app up to 1.9.0 couldn't move the
	 * temporary file of a new file into its place, nor remove it after the failure, so that every
	 * run left one behind (#490). Nextcloud keeps such a file out of its file cache: Files doesn't
	 * show it, and nothing else removes it. The first run that prepares a folder of the archive or
	 * of the errors removes them below it, once: only the files with the name that a write of the
	 * app gives its temporary file. A folder that fails is tried again by the next run, which the
	 * failure doesn't stop, up to three attempts in all: a folder that always fails, such as one
	 * whose storage refuses to list or delete, is then left to be cleared by hand, so that the log
	 * doesn't repeat the warning every few minutes.
	 *
	 * @param array<string, Folder> $folders
	 */
	private function clearLeftoverTemporaryFiles(string $userId, array $folders): void {
		$cleared = $this->lines(self::CLEARED_KEY);
		$failed = $this->lines(self::FAILED_KEY);
		foreach ($folders as $path => $folder) {
			$line = $userId . '/' . $path;
			if (in_array($line, $cleared, true)) {
				continue;
			}
			$context = ['app' => AppConstants::APP_ID, 'path' => $path, 'user' => $userId];
			try {
				$removed = $this->removeTemporaryFiles($folder);
				if ($removed > 0) {
					$this->logger->info('Paperless Sync removed {count} temporary files that earlier runs left in {path} of {user}.', $context + ['count' => $removed]);
				}
			} catch (\Throwable $exception) {
				$failed[] = $line;
				if (count(array_keys($failed, $line, true)) < self::MAX_ATTEMPTS) {
					$this->logger->warning('Paperless Sync could not remove the temporary files that earlier runs left in {path} of {user}; the next run tries again.', $context + ['exception' => $exception]);
					$this->appConfig->setValueString(AppConstants::APP_ID, self::FAILED_KEY, implode("\n", $failed), lazy: true);
					continue;
				}
				$this->logger->warning('Paperless Sync could not remove the temporary files that earlier runs left in {path} of {user} for the third time and no longer tries; remove them by hand, as the troubleshooting page says under "Hidden .part files in the archive".', $context + ['exception' => $exception]);
			}
			$cleared[] = $line;
			$failed = array_values(array_diff($failed, [$line]));
			$this->appConfig->setValueString(AppConstants::APP_ID, self::FAILED_KEY, implode("\n", $failed), lazy: true);
			$this->appConfig->setValueString(AppConstants::APP_ID, self::CLEARED_KEY, implode("\n", $cleared), lazy: true);
		}
	}

	/**
	 * The lines of a lazy key of the app's configuration, without the empty ones.
	 *
	 * @return list<string>
	 */
	private function lines(string $key): array {
		return array_values(array_filter(
			explode("\n", $this->appConfig->getValueString(AppConstants::APP_ID, $key, '', lazy: true)),
			static fn (string $line): bool => $line !== '',
		));
	}

	/**
	 * Removes the temporary files of writes in the folder and in every folder below it, through its
	 * storage, which lists them although the file cache doesn't, and deletes them past the trash
	 * bin, which never takes a .part file.
	 *
	 * @return int the number of files removed
	 */
	private function removeTemporaryFiles(Folder $folder): int {
		$storage = $folder->getStorage();
		$names = [];
		$directory = $storage->opendir($folder->getInternalPath());
		if (is_resource($directory)) {
			while (($name = readdir($directory)) !== false) {
				if (preg_match(self::TEMPORARY_NAME, $name) === 1) {
					$names[] = $name;
				}
			}
			closedir($directory);
		}
		$removed = 0;
		foreach ($names as $name) {
			$path = self::internalPath($folder, $name);
			if ($storage->is_file($path) && $storage->unlink($path)) {
				++$removed;
			}
		}
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$removed += $this->removeTemporaryFiles($node);
			}
		}

		return $removed;
	}

	public function exists(string $userId, string $path): bool {
		return $this->rootFolder->getUserFolder($userId)->nodeExists($this->normalize($path));
	}

	public function findFileId(string $userId, string $path): ?int {
		try {
			return $this->rootFolder->getUserFolder($userId)->get($this->normalize($path))->getId();
		} catch (NotFoundException) {
			return null;
		}
	}

	public function isWritableFolder(string $userId, string $path): bool {
		$path = $this->normalize($path);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists($path)) {
			return false;
		}
		$node = $userFolder->get($path);

		return $node instanceof Folder && $node->isCreatable();
	}

	/** @psalm-suppress DocblockTypeContradiction */
	public function writeStream(string $userId, string $path, $source, string $conflictMode): void {
		if (!is_resource($source)) {
			throw new RuntimeException('A readable source stream is required.');
		}
		rewind($source);
		$this->write($userId, $path, $source, $conflictMode);
	}

	public function move(string $userId, string $source, string $destination, string $conflictMode): bool {
		$source = $this->normalize($source);
		$destination = $this->normalize($destination);
		$this->filesystem->enter($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists($source)) {
			return false;
		}
		[$parentPath, $name] = $this->split($destination);
		$parent = $this->ensureFolder($userId, $parentPath);
		if ($parent->nodeExists($name)) {
			if ($conflictMode === 'skip') {
				throw new RuntimeException("Nextcloud file conflict at {$destination}.");
			}
			$parent->get($name)->delete();
		}
		$userFolder->get($source)->move($parent->getFullPath($name));

		return true;
	}

	public function delete(string $userId, string $path): void {
		$path = $this->normalize($path);
		$this->filesystem->enter($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists($path)) {
			return;
		}
		$userFolder->get($path)->delete();
	}

	public function listFiles(string $userId, string $path, bool $recursive): array {
		$path = $this->normalize($path);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists($path)) {
			return [];
		}
		$root = $userFolder->get($path);
		if (!$root instanceof Folder) {
			throw new RuntimeException("Nextcloud inbox {$path} is not a folder.");
		}

		$files = [];
		$this->collectFiles($root, $path, $recursive, $files);
		usort(
			$files,
			/** @param array{path: string, name: string, etag: string, id: int} $left @param array{path: string, name: string, etag: string, id: int} $right */
			static fn (array $left, array $right): int => strcmp($left['path'], (string)$right['path']),
		);

		return $files;
	}

	public function nodeById(string $userId, int $fileId): ?array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $exception) {
			// Nextcloud has no folder for a user who is gone, and none while their user backend,
			// such as LDAP, can't answer, or while their storage can't be set up: only the first
			// has no files left.
			if ($this->userManager->userExists($userId)) {
				throw $exception;
			}

			return null;
		}
		$node = $userFolder->getFirstNodeById($fileId);
		$path = $node === null ? null : $userFolder->getRelativePath($node->getPath());
		// The folder of the files of the user itself is no file of theirs to send.
		if ($node === null || $path === null || trim($path, '/') === '') {
			return null;
		}

		return ['path' => trim($path, '/'), 'name' => $node->getName(), 'folder' => $node instanceof Folder, 'readable' => $node->isReadable() && self::downloadable($node)];
	}

	/**
	 * Whether the user may download the file or folder: not when it reaches them through a share
	 * whose owner allows viewing only, which Nextcloud enforces for downloads over WebDAV and
	 * which a copy in Paperless would get around.
	 */
	private static function downloadable(Node $node): bool {
		$storage = $node->getStorage();
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return true;
		}
		/** @var ISharedStorage $storage */
		return $storage->getShare()->getAttributes()?->getAttribute('permissions', 'download') !== false;
	}

	public function openRead(string $userId, string $path) {
		$node = $this->rootFolder->getUserFolder($userId)->get($this->normalize($path));
		if (!$node instanceof File) {
			throw new RuntimeException("Nextcloud path {$path} is not a file.");
		}
		$stream = $node->fopen('r');
		if (!is_resource($stream)) {
			throw new RuntimeException("Could not open Nextcloud file {$path}.");
		}

		return $stream;
	}

	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void {
		$this->write($userId, $path, $content, $conflictMode);
	}

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int {
		$filePath = $this->normalize($filePath);
		$stopAt = $this->normalize($stopAt);
		$directory = dirname($filePath);
		$removed = 0;
		$this->filesystem->enter($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		while ($directory !== '.' && $directory !== $stopAt && str_starts_with($directory . '/', $stopAt . '/')) {
			if (!$userFolder->nodeExists($directory)) {
				$directory = dirname($directory);
				continue;
			}
			$node = $userFolder->get($directory);
			if (!$node instanceof Folder || $node->getDirectoryListing() !== []) {
				break;
			}
			$node->delete();
			++$removed;
			$directory = dirname($directory);
		}

		return $removed;
	}

	/**
	 * An existing file is updated in place, under the exclusive lock that Nextcloud holds while it
	 * writes, so that it keeps its file ID with its shares, comments, tags, favorites and versions,
	 * and the versions app can keep its previous content. Deleting it and moving a new file into its
	 * place would lose all of these and leave the old copy in the trash bin. On local storage, an
	 * update that fails halfway leaves the file incomplete until the next run writes it again.
	 *
	 * A new file is written next to its path first, into a hidden temporary file with the ending
	 * .part, and then moved into place, so that readers never see a partial file. A folder in its
	 * place is deleted. The temporary file is written through the storage, as the WebDAV of
	 * Nextcloud writes an upload: Nextcloud keeps a .part file out of its file cache, and the node
	 * that Folder::newFile() gives one keeps no permissions, which Nextcloud 33.0.11, 34.0.6, 35.0.3
	 * and newer check before a move or a delete (#490). Written past it, the file has the
	 * permissions that Nextcloud gives a .part file, all of them, and only the move into place
	 * sends the events of a new file. A write that fails removes its temporary file through the
	 * storage as well.
	 *
	 * @param resource|string $content
	 */
	private function write(string $userId, string $path, $content, string $conflictMode): void {
		$path = $this->normalize($path);
		$this->filesystem->enter($userId);
		[$parentPath, $name] = $this->split($path);
		$this->requireSpace($userId, $parentPath, is_string($content) ? strlen($content) : $this->streamSize($content));
		$parent = $this->ensureFolder($userId, $parentPath);
		$existing = $parent->nodeExists($name) ? $parent->get($name) : null;
		if ($existing !== null && $conflictMode === 'skip') {
			throw new RuntimeException("Nextcloud file conflict at {$path}.");
		}
		if ($existing instanceof File) {
			$existing->putContent($content);

			return;
		}

		$temporaryName = '.' . $name . '.paperless-' . bin2hex(random_bytes(8)) . '.part';
		$storage = $parent->getStorage();
		$temporaryPath = self::internalPath($parent, $temporaryName);
		try {
			$this->writeTemporaryFile($storage, $temporaryPath, $parent->getFullPath($temporaryName), $content);
			if ($parent->nodeExists($name)) {
				$parent->get($name)->delete();
			}
			$parent->get($temporaryName)->move($parent->getFullPath($name));
		} catch (\Throwable $exception) {
			// The error of the write is the one to report; a temporary file that stays is removed
			// by no later run, so the log names it.
			try {
				$left = $storage->file_exists($temporaryPath) && !$storage->unlink($temporaryPath);
			} catch (\Throwable $cleanup) {
				$left = $cleanup;
			}
			if ($left !== false) {
				$this->logger->warning('Paperless Sync could not remove the temporary file {path}.', ['app' => AppConstants::APP_ID, 'path' => $parent->getFullPath($temporaryName)] + ($left instanceof \Throwable ? ['exception' => $left] : []));
			}
			throw $exception;
		}
	}

	/**
	 * Writes the content into a new file of the storage: the whole of it, or a
	 * NotPermittedException, as Folder::newFile() throws when the storage refuses a file.
	 *
	 * @param string $label the path of the file in Nextcloud, for the message of a failure
	 * @param resource|string $content
	 * @throws NotPermittedException
	 */
	private function writeTemporaryFile(IStorage $storage, string $path, string $label, $content): void {
		$target = $storage->fopen($path, 'w');
		if (!is_resource($target)) {
			throw new NotPermittedException("Could not create {$label}");
		}
		try {
			$written = is_string($content) ? fwrite($target, $content) : stream_copy_to_stream($content, $target);
		} finally {
			// A storage such as S3 uploads the file as it is closed.
			$closed = fclose($target);
		}
		if ($written === false || !$closed || (is_string($content) && $written !== strlen($content))) {
			throw new NotPermittedException("Could not write the whole of {$label}");
		}
	}

	/** The path of a file of the folder in the storage of the folder. */
	private static function internalPath(Folder $folder, string $name): string {
		$parent = $folder->getInternalPath();

		return $parent === '' ? $name : $parent . '/' . $name;
	}

	/**
	 * Nextcloud applies no quota to a file whose name ends in .part, such as the temporary file of a
	 * new file, so the write checks the free space itself: in the deepest folder of the path that
	 * exists, before it creates a folder, because a full quota refuses new folders as well.
	 * Unlimited, unknown and not yet computed space is negative and not checked.
	 *
	 * @throws FullStorageException
	 */
	private function requireSpace(string $userId, string $parentPath, int $size): void {
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (explode('/', $parentPath) as $component) {
			if ($component === '' || !$folder->nodeExists($component)) {
				break;
			}
			$node = $folder->get($component);
			if (!$node instanceof Folder) {
				break;
			}
			$folder = $node;
		}
		/** @var mixed $free Nextcloud declares an int, and its implementations return a float for large values. */
		$free = $folder->getFreeSpace();
		if ((is_int($free) || is_float($free)) && $free >= 0 && $free < $size) {
			throw new FullStorageException('Not enough free space in Nextcloud: the file needs %1$s bytes, and %2$s are free.', [(string)$size, (string)$free]);
		}
	}

	/** @param resource $stream */
	private function streamSize($stream): int {
		$stat = fstat($stream);

		return is_array($stat) ? $stat['size'] : 0;
	}

	/** @param list<array{path: string, name: string, etag: string, id: int}> $files */
	private function collectFiles(Folder $folder, string $path, bool $recursive, array &$files): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if (str_starts_with($node->getName(), '.')) {
				continue;
			}
			$childPath = $this->join($path, $node->getName());
			if ($node instanceof File) {
				$files[] = ['path' => $childPath, 'name' => $node->getName(), 'etag' => $node->getEtag(), 'id' => $node->getId()];
			} elseif ($recursive && $node instanceof Folder) {
				$this->collectFiles($node, $childPath, true, $files);
			}
		}
	}

	private function ensureFolder(string $userId, string $path): Folder {
		$path = $this->normalize($path);
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (explode('/', $path) as $component) {
			if ($folder->nodeExists($component)) {
				$node = $folder->get($component);
				if (!$node instanceof Folder) {
					throw new RuntimeException("Nextcloud path component {$component} is not a folder.");
				}
				$folder = $node;
			} else {
				$folder = $folder->newFolder($component);
			}
		}

		return $folder;
	}

	private function normalize(string $path): string {
		return $this->pathTemplate->normalizeRelativePath($path);
	}

	/** @return array{string, string} */
	private function split(string $path): array {
		$parent = dirname($path);
		return [$parent === '.' ? '' : $parent, basename($path)];
	}

	private function join(string ...$parts): string {
		return implode('/', array_map(static fn (string $part): string => trim($part, '/'), $parts));
	}
}
