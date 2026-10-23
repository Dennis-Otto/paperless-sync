<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
use OCP\IUserManager;
use RuntimeException;

/**
 * Every change of the files of a user happens in the filesystem of that user (UserFilesystem), so
 * that what a run deletes or replaces goes into the user's trash bin, whoever started the run.
 */
final class NextcloudStorageService implements NextcloudStorageInterface {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private PathTemplateService $pathTemplate,
		private UserFilesystemInterface $filesystem,
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
		if ($config->exportEnabled) {
			$this->ensureFolder($config->targetUser, $this->join($config->basePath, $config->archiveFolder));
		}
		if ($config->inboxEnabled) {
			$this->ensureFolder($config->targetUser, $this->join($config->basePath, $config->inboxFolder));
			$this->ensureFolder($config->targetUser, $this->join($config->basePath, $config->errorFolder));
		}
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
		$userFolder = $this->rootFolder->getUserFolder($userId);
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
	 * A new file is written next to its path first and then moved into place, so that readers never
	 * see a partial file. A folder in its place is deleted.
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
		try {
			$parent->newFile($temporaryName, $content);
			if ($parent->nodeExists($name)) {
				$parent->get($name)->delete();
			}
			$parent->get($temporaryName)->move($parent->getFullPath($name));
		} catch (\Throwable $exception) {
			try {
				if ($parent->nodeExists($temporaryName)) {
					$parent->get($temporaryName)->delete();
				}
			} catch (\Throwable) {
			}
			throw $exception;
		}
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
