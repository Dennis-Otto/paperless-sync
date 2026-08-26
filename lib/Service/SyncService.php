<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SyncService {
	private const LOCK_PATH = AppConstants::APP_ID . '::synchronization';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessClientInterface $paperless,
		private NextcloudStorageInterface $storage,
		private SyncStateRepositoryInterface $state,
		private PathTemplateService $pathTemplate,
		private StatusService $status,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	public function run(bool $dryRun = false): SyncReport {
		$this->lockingProvider->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, AppConstants::APP_NAME);
		$report = new SyncReport($dryRun, time());
		$this->status->started($dryRun);
		try {
			$config = $this->configService->get();
			if ($config->paperlessUrl === '' || !$config->tokenConfigured || $config->targetUser === '') {
				throw new RuntimeException('Paperless Sync is not completely configured.');
			}
			$this->paperless->testConnection($config->paperlessUrl, $this->configService->getToken());
			$this->storage->test($config->targetUser, $config->basePath);
			if (!$dryRun) {
				$this->storage->prepare($config);
			}
			if ($config->inboxEnabled) {
				$this->syncInbox($config, $report);
			}
			if ($config->exportEnabled) {
				$this->syncExports($config, $report);
			}
			$report->completedAt = time();
			$this->status->completed($report);
			$this->logger->info('Paperless synchronization completed', $report->summary());

			return $report;
		} catch (\Throwable $exception) {
			$this->status->failed($exception);
			$this->logger->error('Paperless synchronization failed', ['exception' => $exception]);
			throw $exception;
		} finally {
			$this->lockingProvider->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function syncExports(SyncConfig $config, SyncReport $report): void {
		$documents = $this->paperless->documents();
		$trash = $this->paperless->trash();
		$correspondents = $this->paperless->correspondents();
		$documentTypes = $this->paperless->documentTypes();
		$storagePaths = $this->paperless->storagePaths();
		$tags = $this->paperless->tags();
		$report->activeDocuments = count($documents);
		$report->trashedDocuments = count($trash);

		$seen = [];
		$changes = 0;
		$archiveRoot = $this->join($config->basePath, $config->archiveFolder);
		/** @var list<string> $excludedNames */
		$excludedNames = array_values(array_map('mb_strtolower', array_filter(array_map('trim', explode(',', $config->excludedTags)))));

		foreach ($documents as $document) {
			$documentId = $this->documentId($document);
			$seen[$documentId] = true;
			$entry = $this->state->findExport($config->targetUser, $documentId);
			if ($this->isExcluded($config, $document, $tags, $excludedNames)) {
				++$report->skipped;
				if ($entry === null) {
					continue;
				}
				try {
					$excludedPath = (string)($entry['path'] ?? '');
					$exists = $excludedPath !== '' && $this->storage->exists($config->targetUser, $excludedPath);
					if ($exists && $changes >= $config->batchSize) {
						continue;
					}
					if ($exists) {
						if ($report->dryRun) {
							$report->action("REMOVE EXCLUDED P{$documentId}: {$excludedPath}");
						} else {
							$this->storage->delete($config->targetUser, $excludedPath);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $excludedPath, $archiveRoot);
							}
						}
						++$report->removedExcluded;
						++$changes;
					}
					if (!$report->dryRun) {
						$this->state->deleteExport($config->targetUser, $documentId);
					}
				} catch (\Throwable $exception) {
					$error = $this->exceptionMessage($exception);
					$report->error("Excluded P{$documentId}: {$error}");
					if (!$report->dryRun) {
						$this->state->saveExport($config->targetUser, $documentId, ['last_seen' => time(), 'last_error' => mb_substr($error, 0, 4000)]);
					}
				}
				continue;
			}

			try {
				$relative = $this->pathTemplate->render($config, $document, $correspondents, $documentTypes, $storagePaths);
				$target = $this->join($archiveRoot, $relative);
				$hasArchive = is_string($document['archived_file_name'] ?? null) && $document['archived_file_name'] !== '';
				$useOriginal = !$config->preferArchive || !$hasArchive;
				$sourceRevision = ($useOriginal ? 'original:' : 'archive:') . $this->scalarString($document['modified'] ?? null);
				$oldPathValue = $entry !== null ? ($entry['path'] ?? null) : null;
				$fingerprintValue = $entry !== null ? ($entry['fingerprint'] ?? null) : null;
				$oldPath = is_string($oldPathValue) ? $oldPathValue : '';
				$storedFingerprint = is_string($fingerprintValue) ? $fingerprintValue : '';
				$fingerprint = $storedFingerprint;
				if ($entry !== null && ($entry['source_revision'] ?? '') !== $sourceRevision && $storedFingerprint !== '') {
					$fingerprint = $this->paperless->documentChecksum($documentId, $useOriginal);
				}
				$unchanged = $entry !== null
					&& ($entry['state'] ?? '') === 'active'
					&& $oldPath === $target
					&& $storedFingerprint !== ''
					&& $storedFingerprint === $fingerprint
					&& $this->storage->exists($config->targetUser, $target);
				if ($unchanged) {
					++$report->unchanged;
					if (!$report->dryRun) {
						$this->state->saveExport($config->targetUser, $documentId, [
							'source_revision' => $sourceRevision,
							'missing_runs' => 0,
							'last_seen' => time(),
							'last_error' => null,
						]);
					}
					continue;
				}
				if ($changes >= $config->batchSize) {
					++$report->skipped;
					continue;
				}

				$canMove = $entry !== null
					&& $oldPath !== ''
					&& $oldPath !== $target
					&& $storedFingerprint !== ''
					&& $storedFingerprint === $fingerprint
					&& $this->storage->exists($config->targetUser, $oldPath);
				if ($report->dryRun) {
					$report->action(($canMove ? 'MOVE' : 'EXPORT') . " P{$documentId}: {$target}");
					$canMove ? ++$report->moved : ++$report->exported;
					++$changes;
					continue;
				}

				if ($canMove) {
					$this->storage->move($config->targetUser, $oldPath, $target, $config->conflictMode);
					++$report->moved;
				} else {
					$stream = tmpfile();
					if (!is_resource($stream)) {
						throw new RuntimeException('Could not create a temporary document stream.');
					}
					try {
						$this->paperless->downloadDocument($documentId, $useOriginal, $stream);
						$content = stream_get_contents($stream);
						$fingerprint = hash('sha256', $content !== false ? $content : '');
						rewind($stream);
						$this->storage->writeAtomic($config->targetUser, $target, $stream, $config->conflictMode);
					} finally {
						/** @psalm-suppress RedundantCondition Nextcloud may close the source stream. */
						if (is_resource($stream)) {
							fclose($stream);
						}
					}
					if ($oldPath !== '' && $oldPath !== $target) {
						$this->storage->delete($config->targetUser, $oldPath);
					}
					++$report->exported;
				}
				$this->state->saveExport($config->targetUser, $documentId, [
					'path' => $target,
					'fingerprint' => $fingerprint,
					'source_revision' => $sourceRevision,
					'state' => 'active',
					'missing_runs' => 0,
					'last_seen' => time(),
					'trash_date' => null,
					'last_error' => null,
				]);
				if ($config->pruneEmptyFolders && $oldPath !== '' && $oldPath !== $target) {
					$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $oldPath, $archiveRoot);
				}
				++$changes;
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, ['last_seen' => time(), 'last_error' => mb_substr($error, 0, 4000)]);
				}
			}
		}

		foreach ($trash as $document) {
			$documentId = $this->documentId($document);
			$seen[$documentId] = true;
			$entry = $this->state->findExport($config->targetUser, $documentId);
			if ($entry === null) {
				continue;
			}
			try {
				$oldPath = (string)($entry['path'] ?? '');
				if (($entry['state'] ?? '') === 'trash' && $oldPath !== '' && $this->storage->exists($config->targetUser, $oldPath)) {
					++$report->unchanged;
					if (!$report->dryRun) {
						$this->state->saveExport($config->targetUser, $documentId, ['missing_runs' => 0, 'last_seen' => time(), 'last_error' => null]);
					}
					continue;
				}
				$deletedAt = $this->timestamp($document['deleted_at'] ?? null);
				$target = $config->trashMode === 'move' ? $this->deletedPath($config, $oldPath, $archiveRoot, $deletedAt) : $oldPath;
				if ($config->trashMode === 'move' && $changes >= $config->batchSize) {
					++$report->skipped;
					continue;
				}
				if ($report->dryRun) {
					$report->action("TRASH P{$documentId}: {$target}");
					++$report->movedToTrash;
					++$changes;
					continue;
				}
				if ($config->trashMode === 'move' && $oldPath !== $target) {
					$this->storage->move($config->targetUser, $oldPath, $target, $config->conflictMode);
					++$report->movedToTrash;
					++$changes;
					if ($config->pruneEmptyFolders) {
						$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $oldPath, $archiveRoot);
					}
				}
				$this->state->saveExport($config->targetUser, $documentId, [
					'path' => $target,
					'state' => 'trash',
					'missing_runs' => 0,
					'last_seen' => time(),
					'trash_date' => $deletedAt,
					'last_error' => null,
				]);
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("Trash P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, ['last_error' => mb_substr($error, 0, 4000)]);
				}
			}
		}

		foreach ($this->state->allExports($config->targetUser) as $entry) {
			$documentId = (int)($entry['document_id'] ?? 0);
			if ($documentId < 1 || isset($seen[$documentId])) {
				continue;
			}
			$missingRuns = (int)($entry['missing_runs'] ?? 0) + 1;
			$state = (string)($entry['state'] ?? 'active');
			$path = (string)($entry['path'] ?? '');
			if ($missingRuns < $config->missingGraceRuns) {
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, ['missing_runs' => $missingRuns]);
				}
				$report->action("WAIT P{$documentId}: missing run {$missingRuns}/{$config->missingGraceRuns}");
				continue;
			}

			$canDelete = $config->permanentDelete && ($state === 'trash' || $config->allowDirectDelete);
			if ($canDelete) {
				if ($changes >= $config->batchSize) {
					++$report->skipped;
					continue;
				}
				if ($report->dryRun) {
					$report->action("DELETE P{$documentId}: {$path}");
				} else {
					$this->storage->delete($config->targetUser, $path);
					$this->state->deleteExport($config->targetUser, $documentId);
					if ($config->pruneEmptyFolders) {
						$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
					}
				}
				++$report->permanentlyDeleted;
				++$changes;
				continue;
			}

			$target = $path;
			if ($state === 'active' && $config->trashMode === 'move') {
				$target = $this->deletedPath($config, $path, $archiveRoot, time());
				if ($changes < $config->batchSize) {
					if ($report->dryRun) {
						$report->action("MISSING P{$documentId}: {$target}");
					} elseif ($path !== '' && $this->storage->move($config->targetUser, $path, $target, $config->conflictMode)) {
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
						}
					}
					++$report->movedToTrash;
					++$changes;
				}
			}
			if (!$report->dryRun) {
				$this->state->saveExport($config->targetUser, $documentId, ['path' => $target, 'state' => 'missing', 'missing_runs' => $missingRuns]);
			}
		}
	}

	private function syncInbox(SyncConfig $config, SyncReport $report): void {
		$inboxRoot = $this->join($config->basePath, $config->inboxFolder);
		$errorRoot = $this->join($config->basePath, $config->errorFolder);
		$files = $this->storage->listFiles($config->targetUser, $inboxRoot, $config->recursiveInbox);
		$byPath = [];
		foreach ($files as $file) {
			$byPath[$file['path']] = $file;
		}

		foreach ($this->state->allImports($config->targetUser) as $pending) {
			$path = (string)($pending['path'] ?? '');
			$file = $byPath[$path] ?? null;
			if ($file === null) {
				if (!$report->dryRun) {
					$this->state->deleteImport($config->targetUser, $path);
				}
				continue;
			}
			if (($pending['status'] ?? '') === 'success' && ($pending['etag'] ?? '') === $file['etag']) {
				++$report->unchanged;
				unset($byPath[$path]);
				continue;
			}
			if (($pending['status'] ?? '') !== 'pending') {
				if (!$report->dryRun) {
					$this->state->deleteImport($config->targetUser, $path);
				}
				continue;
			}
			unset($byPath[$path]);

			try {
				$task = $this->paperless->taskStatus((string)($pending['task_id'] ?? ''));
				if (in_array($task['status'], ['SUCCESS', 'SUCCEEDED'], true)) {
					if (($pending['etag'] ?? '') === $file['etag']) {
						if ($report->dryRun) {
							$report->action("IMPORT SUCCESS: {$path}");
						} elseif ($config->deleteInboxAfterSuccess) {
							$this->storage->delete($config->targetUser, $path);
							$this->state->deleteImport($config->targetUser, $path);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $inboxRoot);
							}
						} else {
							$this->state->saveImport($config->targetUser, $path, ['status' => 'success', 'last_error' => null]);
						}
					} elseif (!$report->dryRun) {
						$this->state->deleteImport($config->targetUser, $path);
					}
					++$report->importsSucceeded;
					unset($byPath[$path]);
				} elseif (in_array($task['status'], ['FAILURE', 'FAILED', 'ERROR'], true)) {
					$relative = $this->relativeTo($path, $inboxRoot);
					$destination = $this->join($errorRoot, $relative);
					if ($report->dryRun) {
						$report->action("IMPORT ERROR: {$path} -> {$destination}");
					} else {
						$this->storage->move($config->targetUser, $path, $destination, $config->conflictMode);
						$this->storage->writeText($config->targetUser, $destination . '.error.txt', "Paperless import failed\n\n" . $task['message'] . "\n");
						$this->state->deleteImport($config->targetUser, $path);
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $inboxRoot);
						}
					}
					++$report->importsFailed;
					unset($byPath[$path]);
				}
			} catch (\Throwable $exception) {
				$report->error("Import task {$path}: {$this->exceptionMessage($exception)}");
			}
		}

		$submitted = 0;
		foreach ($byPath as $path => $file) {
			if ($submitted >= $config->batchSize) {
				++$report->skipped;
				continue;
			}
			if ($report->dryRun) {
				$report->action("IMPORT: {$path}");
				++$report->importsSubmitted;
				++$submitted;
				continue;
			}
			$stream = null;
			try {
				$stream = $this->storage->openRead($config->targetUser, $path);
				$taskId = $this->paperless->uploadDocument($stream, $file['name']);
				$this->state->saveImport($config->targetUser, $path, [
					'etag' => $file['etag'],
					'task_id' => $taskId,
					'status' => 'pending',
					'submitted_at' => time(),
					'last_error' => null,
				]);
				++$report->importsSubmitted;
				++$submitted;
			} catch (\Throwable $exception) {
				$report->error("Import {$path}: {$this->exceptionMessage($exception)}");
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $document
	 * @param array{names: array<string, string>, inbox: list<string>} $tags
	 * @param list<string> $excludedNames
	 * @psalm-suppress MixedAssignment
	 */
	private function isExcluded(SyncConfig $config, array $document, array $tags, array $excludedNames): bool {
		$documentTags = [];
		$rawTags = $document['tags'] ?? null;
		foreach (is_array($rawTags) ? $rawTags : [] as $tag) {
			if (is_scalar($tag)) {
				$documentTags[] = (string)$tag;
			} elseif (is_array($tag) && isset($tag['id']) && is_scalar($tag['id'])) {
				$documentTags[] = (string)$tag['id'];
			}
		}
		if ($config->skipInbox && array_intersect($documentTags, $tags['inbox']) !== []) {
			return true;
		}
		foreach ($documentTags as $tagId) {
			if (isset($tags['names'][$tagId]) && in_array(mb_strtolower($tags['names'][$tagId]), $excludedNames, true)) {
				return true;
			}
		}

		return false;
	}

	private function exceptionMessage(\Throwable $exception): string {
		$message = trim($exception->getMessage());

		return $message !== '' ? $message : $exception::class;
	}

	/** @param array<string, mixed> $document */
	private function documentId(array $document): int {
		if (!isset($document['id']) || !is_numeric($document['id']) || (int)$document['id'] < 1) {
			throw new RuntimeException('Paperless returned a document without a valid ID.');
		}

		return (int)$document['id'];
	}

	private function scalarString(mixed $value): string {
		return is_scalar($value) ? (string)$value : '';
	}

	private function deletedPath(SyncConfig $config, string $oldPath, string $archiveRoot, int $timestamp): string {
		$relative = $this->relativeTo($oldPath, $archiveRoot);
		return $this->join($archiveRoot, $config->deletedFolder, gmdate('Y-m-d', $timestamp > 0 ? $timestamp : time()), $relative);
	}

	private function relativeTo(string $path, string $root): string {
		$prefix = rtrim($root, '/') . '/';
		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : basename($path);
	}

	/** @param mixed $value */
	private function timestamp(mixed $value): int {
		if (!is_string($value) || $value === '') {
			return time();
		}
		$timestamp = strtotime($value);

		return $timestamp !== false ? $timestamp : time();
	}

	private function join(string ...$parts): string {
		return implode('/', array_filter(array_map(static fn (string $part): string => trim($part, '/'), $parts), static fn (string $part): bool => $part !== ''));
	}
}
