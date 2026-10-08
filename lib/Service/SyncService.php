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
use Psr\Log\LogLevel;
use RuntimeException;

final class SyncService {
	private const LOCK_PATH = AppConstants::APP_ID . '::synchronization';
	/** The wait after the first failed upload of a file whose failure may pass, in seconds; it doubles with each further one. */
	private const RETRY_DELAY = 900;
	/** The longest wait between two uploads of a file that keep failing, in seconds. */
	private const MAX_RETRY_DELAY = 86400;

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
						if (!$report->dryRun) {
							$this->storage->delete($config->targetUser, $excludedPath);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $excludedPath, $archiveRoot);
							}
						}
						$report->action("REMOVE EXCLUDED P{$documentId}: {$excludedPath}");
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
					$stream = fopen('php://temp', 'w+b');
					if (!is_resource($stream)) {
						// @codeCoverageIgnoreStart
						// PHP opens php://temp in memory, so this fails only when memory runs out, which ends the request first.
						throw new RuntimeException('Could not create a temporary document stream.');
						// @codeCoverageIgnoreEnd
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
				$report->action(($canMove ? 'MOVE' : 'EXPORT') . " P{$documentId}: {$target}");
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
				$exists = $this->copyExists($config, $oldPath);
				if (($entry['state'] ?? '') === 'trash' && $exists) {
					++$report->unchanged;
					if (!$report->dryRun) {
						$this->state->saveExport($config->targetUser, $documentId, ['missing_runs' => 0, 'last_seen' => time(), 'last_error' => null]);
					}
					continue;
				}
				$deletedAt = $this->timestamp($document['deleted_at'] ?? null);
				$values = ['state' => 'trash', 'missing_runs' => 0, 'last_seen' => time(), 'trash_date' => $deletedAt, 'last_error' => null];
				// Only a copy that is still there moves and counts, as for missing documents. The keep
				// policy and a copy that is gone, or was never exported, only mark the document.
				if ($config->trashMode === 'move' && $exists) {
					if ($changes >= $config->batchSize) {
						++$report->skipped;
						continue;
					}
					$target = $this->deletedPath($config, $oldPath, $archiveRoot, $deletedAt);
					if ($report->dryRun) {
						$report->action("TRASH P{$documentId}: {$target}");
						++$report->movedToTrash;
						++$changes;
					} elseif ($this->storage->move($config->targetUser, $oldPath, $target, $config->conflictMode)) {
						$values['path'] = $target;
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $oldPath, $archiveRoot);
						}
						$report->action("TRASH P{$documentId}: {$target}");
						++$report->movedToTrash;
						++$changes;
					}
				}
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, $values);
				}
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

			// Only a copy that is still there is moved or deleted, and only that counts as a change.
			// When the batch is full, the copy and its state stay as they are, so the next run moves
			// or deletes it. An error concerns only this document.
			try {
				$canDelete = $config->permanentDelete && ($state === 'trash' || $config->allowDirectDelete);
				if ($canDelete) {
					$exists = $this->copyExists($config, $path);
					if ($exists && $changes >= $config->batchSize) {
						++$report->skipped;
						continue;
					}
					if ($exists) {
						if (!$report->dryRun) {
							$this->storage->delete($config->targetUser, $path);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
							}
						}
						$report->action("DELETE P{$documentId}: {$path}");
						++$report->permanentlyDeleted;
						++$changes;
					}
					if (!$report->dryRun) {
						$this->state->deleteExport($config->targetUser, $documentId);
					}
					continue;
				}

				$values = ['state' => 'missing', 'missing_runs' => $missingRuns, 'last_error' => null];
				if ($state === 'active' && $config->trashMode === 'move' && $this->copyExists($config, $path)) {
					if ($changes >= $config->batchSize) {
						++$report->skipped;
						continue;
					}
					$target = $this->deletedPath($config, $path, $archiveRoot, time());
					if ($report->dryRun) {
						$report->action("MISSING P{$documentId}: {$target}");
						++$report->movedToTrash;
						++$changes;
					} elseif ($this->storage->move($config->targetUser, $path, $target, $config->conflictMode)) {
						$values['path'] = $target;
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
						}
						$report->action("MISSING P{$documentId}: {$target}");
						++$report->movedToTrash;
						++$changes;
					}
				}
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, $values);
				}
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("Missing P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, ['last_error' => mb_substr($error, 0, 4000)]);
				}
			}
		}
	}

	private function copyExists(SyncConfig $config, string $path): bool {
		return $path !== '' && $this->storage->exists($config->targetUser, $path);
	}

	private function syncInbox(SyncConfig $config, SyncReport $report): void {
		$inboxRoot = $this->join($config->basePath, $config->inboxFolder);
		$errorRoot = $this->join($config->basePath, $config->errorFolder);
		$files = $this->storage->listFiles($config->targetUser, $inboxRoot, $config->recursiveInbox);
		$byPath = [];
		foreach ($files as $file) {
			$byPath[$file['path']] = $file;
		}
		/** @var array<string, int> $attempts the failed uploads of each file whose next attempt is due */
		$attempts = [];
		$now = time();

		foreach ($this->state->allImports($config->targetUser) as $pending) {
			$path = (string)($pending['path'] ?? '');
			$file = $byPath[$path] ?? null;
			if ($file === null) {
				if (!$report->dryRun) {
					$this->state->deleteImport($config->targetUser, $path);
				}
				continue;
			}
			$status = (string)($pending['status'] ?? '');
			$unchangedFile = ($pending['etag'] ?? '') === $file['etag'];
			if ($status === 'success' && $unchangedFile) {
				++$report->unchanged;
				unset($byPath[$path]);
				continue;
			}
			// A file that Paperless refused waits until it changes, and a file whose upload failed
			// for a reason that may pass waits until its next attempt is due.
			if ($status === 'retry' && $unchangedFile && (int)($pending['retry_at'] ?? 0) <= $now) {
				$attempts[$path] = (int)($pending['attempts'] ?? 0);
				continue;
			}
			if (($status === 'rejected' || $status === 'retry') && $unchangedFile) {
				++$report->skipped;
				unset($byPath[$path]);
				continue;
			}
			if ($status !== 'pending') {
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
						if (!$report->dryRun && $config->deleteInboxAfterSuccess) {
							$this->storage->delete($config->targetUser, $path);
							$this->state->deleteImport($config->targetUser, $path);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $inboxRoot);
							}
						} elseif (!$report->dryRun) {
							$this->state->saveImport($config->targetUser, $path, ['status' => 'success', 'last_error' => null]);
						}
						$report->action("IMPORT SUCCESS: {$path}");
					} elseif (!$report->dryRun) {
						$this->state->deleteImport($config->targetUser, $path);
					}
					++$report->importsSucceeded;
					unset($byPath[$path]);
				} elseif (in_array($task['status'], ['FAILURE', 'FAILED', 'ERROR'], true)) {
					$relative = $this->relativeTo($path, $inboxRoot);
					$destination = $this->join($errorRoot, $relative);
					if (!$report->dryRun) {
						$this->storage->move($config->targetUser, $path, $destination, $config->conflictMode);
						$this->storage->writeText($config->targetUser, $destination . '.error.txt', "Paperless import failed\n\n" . $task['message'] . "\n");
						$this->state->deleteImport($config->targetUser, $path);
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $inboxRoot);
						}
					}
					$report->action("IMPORT ERROR: {$path} -> {$destination}");
					++$report->importsFailed;
					unset($byPath[$path]);
				}
			} catch (\Throwable $exception) {
				$report->error("Import task {$path}: {$this->exceptionMessage($exception)}");
			}
		}

		$submitted = 0;
		$paperlessFailed = false;
		foreach ($byPath as $path => $file) {
			// Every upload takes its place in the batch, also one that fails. After a failure that may
			// pass, Paperless is down or overloaded, and the other files wait for the next run.
			if ($submitted >= $config->batchSize || $paperlessFailed) {
				++$report->skipped;
				continue;
			}
			++$submitted;
			if ($report->dryRun) {
				$report->action("IMPORT: {$path}");
				++$report->importsSubmitted;
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
					'attempts' => 0,
					'retry_at' => 0,
					'last_error' => null,
				]);
				$report->action("IMPORT: {$path}");
				++$report->importsSubmitted;
			} catch (PaperlessUploadException $exception) {
				if ($exception->permanent) {
					$this->rejectImport($config, $report, $path, $file['etag'], $exception);
				} else {
					$this->retryImportLater($config, $report, $path, $file['etag'], ($attempts[$path] ?? 0) + 1, $exception);
					$paperlessFailed = true;
				}
			} catch (\Throwable $exception) {
				$this->retryImportLater($config, $report, $path, $file['etag'], ($attempts[$path] ?? 0) + 1, $exception);
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
		}
	}

	/**
	 * Paperless would refuse the same file again, so it waits in the inbox until it changes. Only
	 * this run reports and logs the reason; the runs until then skip the file quietly.
	 */
	private function rejectImport(SyncConfig $config, SyncReport $report, string $path, string $etag, PaperlessUploadException $exception): void {
		$reason = $this->exceptionMessage($exception);
		$this->state->saveImport($config->targetUser, $path, [
			'etag' => $etag,
			'task_id' => '',
			'status' => 'rejected',
			'submitted_at' => time(),
			'attempts' => 0,
			'retry_at' => 0,
			'last_error' => mb_substr($reason, 0, 4000),
		]);
		$report->action("IMPORT REJECTED: {$path}: {$reason}");
		++$report->importsFailed;
		$this->logger->warning('Paperless refused the inbox file {path}; it stays in the inbox until it changes: {reason}', [
			'path' => $path,
			'reason' => $reason,
			'status' => $exception->statusCode,
		]);
	}

	/** The upload failed for a reason that may pass, so the file waits longer after each failure. */
	private function retryImportLater(SyncConfig $config, SyncReport $report, string $path, string $etag, int $attempts, \Throwable $exception): void {
		$error = $this->exceptionMessage($exception);
		$delay = min(self::RETRY_DELAY << min($attempts - 1, 10), self::MAX_RETRY_DELAY);
		$minutes = intdiv($delay, 60);
		$now = time();
		$this->state->saveImport($config->targetUser, $path, [
			'etag' => $etag,
			'task_id' => '',
			'status' => 'retry',
			'submitted_at' => $now,
			'attempts' => $attempts,
			'retry_at' => $now + $delay,
			'last_error' => mb_substr($error, 0, 4000),
		]);
		$report->error("Import {$path}: {$error} (attempt {$attempts}, the next in {$minutes} minutes)");
		// The first failure of a file is a warning, the ones after it only information.
		$this->logger->log($attempts === 1 ? LogLevel::WARNING : LogLevel::INFO, 'The upload of the inbox file {path} failed; the next attempt follows in {minutes} minutes: {error}', [
			'path' => $path,
			'error' => $error,
			'attempts' => $attempts,
			'minutes' => $minutes,
		]);
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
