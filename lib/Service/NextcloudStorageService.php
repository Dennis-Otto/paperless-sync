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
use OCP\IUserManager;
use RuntimeException;

final class NextcloudStorageService implements NextcloudStorageInterface {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private PathTemplateService $pathTemplate,
	) {
	}

	public function test(string $userId, string $basePath): void {
		if ($this->userManager->get($userId) === null) {
			throw new RuntimeException("Nextcloud user {$userId} does not exist.");
		}
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$basePath = $this->pathTemplate->normalizeRelativePath($basePath);
		if ($userFolder->nodeExists($basePath)) {
			$node = $userFolder->get($basePath);
			if (!$node instanceof Folder || !$node->isCreatable()) {
				throw new RuntimeException('The configured Nextcloud base folder is not a writable folder.');
			}
		} elseif (!$userFolder->isCreatable()) {
			throw new RuntimeException('The configured Nextcloud user folder is not writable.');
		}
	}

	public function prepare(SyncConfig $config): void {
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

	/** @psalm-suppress DocblockTypeContradiction */
	public function writeAtomic(string $userId, string $path, $source, string $conflictMode): void {
		if (!is_resource($source)) {
			throw new RuntimeException('A readable source stream is required.');
		}
		$path = $this->normalize($path);
		[$parentPath, $name] = $this->split($path);
		$parent = $this->ensureFolder($userId, $parentPath);
		if ($parent->nodeExists($name) && $conflictMode === 'skip') {
			throw new RuntimeException("Nextcloud file conflict at {$path}.");
		}

		$temporaryName = '.' . $name . '.paperless-' . bin2hex(random_bytes(8)) . '.part';
		try {
			rewind($source);
			$parent->newFile($temporaryName, $source);
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

	public function move(string $userId, string $source, string $destination, string $conflictMode): bool {
		$source = $this->normalize($source);
		$destination = $this->normalize($destination);
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
			/** @param array{path: string, name: string, etag: string} $left @param array{path: string, name: string, etag: string} $right */
			static fn (array $left, array $right): int => strcmp($left['path'], (string)$right['path']),
		);

		return $files;
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
		$stream = fopen('php://temp', 'w+b');
		if (!is_resource($stream)) {
			throw new RuntimeException('Could not create a temporary text stream.');
		}
		try {
			fwrite($stream, $content);
			$this->writeAtomic($userId, $path, $stream, $conflictMode);
		} finally {
			/** @psalm-suppress RedundantCondition Nextcloud may close the source stream. */
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int {
		$filePath = $this->normalize($filePath);
		$stopAt = $this->normalize($stopAt);
		$directory = dirname($filePath);
		$removed = 0;
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

	/** @param list<array{path: string, name: string, etag: string}> $files */
	private function collectFiles(Folder $folder, string $path, bool $recursive, array &$files): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if (str_starts_with($node->getName(), '.')) {
				continue;
			}
			$childPath = $this->join($path, $node->getName());
			if ($node instanceof File) {
				$files[] = ['path' => $childPath, 'name' => $node->getName(), 'etag' => $node->getEtag()];
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
