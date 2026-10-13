<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
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
			$this->paperless->testConnection($config->paperlessUrl, $this->configService->getToken(), $config->paperlessLocalNetwork);
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
			$this->status->failed($exception, $report);
			$this->logger->error('Paperless synchronization failed', ['exception' => $exception]);
			throw $exception;
		} finally {
			$this->lockingProvider->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function syncExports(SyncConfig $config, SyncReport $report): void {
		$documentScan = $this->paperless->documents();
		$trashScan = $this->paperless->trash();
		$documents = $documentScan->documents;
		$trash = $trashScan->documents;
		$documentsComplete = $this->checkScan($report, 'documents', $documentScan);
		$trashComplete = $this->checkScan($report, 'trashed documents', $trashScan);
		$correspondents = $this->paperless->correspondents();
		$documentTypes = $this->paperless->documentTypes();
		$storagePaths = $this->paperless->storagePaths();
		$tags = $this->paperless->tags();
		$report->activeDocuments = count($documents);
		$report->trashedDocuments = count($trash);

		$seen = [];
		// The changes of the run: the exports and moves that succeeded, and every removal, move to the
		// deleted folder and deletion that the run tried, also one that failed.
		$changes = 0;
		// The exports and moves of documents that the run tried, also those that failed, so that a run
		// that can't write downloads no more documents than the batch size allows. A failed export is
		// no change, so documents whose export keeps failing leave the batch of the trashed and the
		// missing documents alone.
		$attempts = 0;
		// After Nextcloud refused a write for the whole archive, the other exports and moves wait for the next run.
		$storageFailed = false;
		$archiveRoot = $this->join($config->basePath, $config->archiveFolder);
		/** @var list<string> $excludedNames */
		$excludedNames = array_values(array_map('mb_strtolower', array_filter(array_map('trim', explode(',', $config->excludedTags)))));
		$sharedPaths = $this->sharedPaths($config);

		foreach ($this->exportQueue($config, $documents) as [$document, $documentId, $entry]) {
			$seen[$documentId] = true;
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
						// A removal takes its place in the batch before it is tried, also one that fails.
						++$changes;
						if (!$report->dryRun) {
							$this->storage->delete($config->targetUser, $excludedPath);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $excludedPath, $archiveRoot);
							}
						}
						$report->action("REMOVE EXCLUDED P{$documentId}: {$excludedPath}");
						++$report->removedExcluded;
					}
					if (!$report->dryRun) {
						$this->state->deleteExport($config->targetUser, $documentId);
					}
				} catch (\Throwable $exception) {
					$error = $this->exceptionMessage($exception);
					$report->error("Excluded P{$documentId}: {$error}");
					$this->logDocumentError($documentId, (string)($entry['path'] ?? ''), $error, $entry);
					if (!$report->dryRun) {
						$this->state->saveExport($config->targetUser, $documentId, ['last_seen' => time(), 'last_error' => mb_substr($error, 0, 4000)]);
					}
				}
				continue;
			}

			$target = '';
			try {
				$relative = $this->pathTemplate->render($config, $document, $correspondents, $documentTypes, $storagePaths);
				$target = $this->join($archiveRoot, $relative);
				$hasArchive = is_string($document['archived_file_name'] ?? null) && $document['archived_file_name'] !== '';
				$useOriginal = !$config->preferArchive || !$hasArchive;
				$sourceRevision = ($useOriginal ? 'original:' : 'archive:') . $this->scalarString($document['modified'] ?? null);
				$oldPathValue = $entry !== null ? ($entry['path'] ?? null) : null;
				$fingerprintValue = $entry !== null ? ($entry['fingerprint'] ?? null) : null;
				$oldPath = is_string($oldPathValue) ? $oldPathValue : '';
				$fingerprint = is_string($fingerprintValue) ? $fingerprintValue : '';
				// The mirrored file has the content of the document while its revision is the same, and
				// after a change while Paperless reports one of its checksums, MD5 or SHA-256.
				$sameContent = $entry !== null && $fingerprint !== '' && (
					($entry['source_revision'] ?? '') === $sourceRevision
					|| DocumentFingerprint::matches($fingerprint, $this->paperless->documentChecksum($documentId, $useOriginal))
				);
				$unchanged = $sameContent
					&& ($entry['state'] ?? '') === 'active'
					&& $oldPath === $target
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
				if ($attempts >= $config->batchSize || $changes >= $config->batchSize || $storageFailed) {
					++$report->skipped;
					continue;
				}
				++$attempts;
				// When conflicts are skipped, Nextcloud refuses to write over a file, so the download or
				// the move would be in vain.
				if ($config->conflictMode === 'skip' && $this->storage->exists($config->targetUser, $target)) {
					throw new RuntimeException("Nextcloud file conflict at {$target}.");
				}

				$canMove = $sameContent
					&& $oldPath !== ''
					&& $oldPath !== $target
					&& !isset($sharedPaths[$oldPath])
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
					// The download goes to Nextcloud in chunks, so that a document of any size fits
					// into the memory of PHP.
					$fingerprint = $this->paperless->downloadDocument(
						$documentId,
						$useOriginal,
						/** @param resource $download */
						function ($download) use ($config, $target): void {
							$this->storage->writeAtomic($config->targetUser, $target, $download, $config->conflictMode);
						},
					)->value();
					if ($oldPath !== '' && $oldPath !== $target) {
						$this->storage->delete($config->targetUser, $oldPath);
					}
					++$report->exported;
				}
				++$changes;
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
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, ['last_seen' => time(), 'last_error' => mb_substr($error, 0, 4000)]);
				}
				// The warning about the archive names the error that stops the writes.
				if ($this->stopsWrites($config, $report, $archiveRoot, $exception, $error)) {
					$storageFailed = true;
				} else {
					$this->logDocumentError($documentId, $target !== '' ? $target : (string)($entry['path'] ?? ''), $error, $entry);
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
					if ($changes >= $config->batchSize || $storageFailed) {
						++$report->skipped;
						continue;
					}
					// A move takes its place in the batch before it is tried, also one that fails.
					++$changes;
					$target = $this->deletedPath($config, $oldPath, $archiveRoot, $deletedAt);
					if ($report->dryRun) {
						$report->action("TRASH P{$documentId}: {$target}");
						++$report->movedToTrash;
					} elseif ($this->storage->move($config->targetUser, $oldPath, $target, $config->conflictMode)) {
						$values['path'] = $target;
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $oldPath, $archiveRoot);
						}
						$report->action("TRASH P{$documentId}: {$target}");
						++$report->movedToTrash;
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
				if (!$storageFailed && $this->stopsWrites($config, $report, $archiveRoot, $exception, $error)) {
					$storageFailed = true;
				} else {
					$this->logDocumentError($documentId, (string)($entry['path'] ?? ''), $error, $entry);
				}
			}
		}

		foreach ($this->state->allExports($config->targetUser) as $entry) {
			$documentId = (int)($entry['document_id'] ?? 0);
			if (isset($seen[$documentId])) {
				// Paperless listed the document, so its count of missing scans starts anew, also when
				// its export failed or waits for the next run.
				if ((int)($entry['missing_runs'] ?? 0) > 0 && !$report->dryRun) {
					$this->state->saveExport($config->targetUser, $documentId, ['missing_runs' => 0]);
				}
				continue;
			}
			// A document that an incomplete scan left out may still exist, so such a run counts none as missing.
			if ($documentId < 1 || !$documentsComplete || !$trashComplete) {
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

			// Only a copy that is still there is moved or deleted, and only that counts as a change,
			// also when it fails. When the batch is full, or Nextcloud cannot store files in the
			// archive, the copy and its state stay as they are, so the next run moves or deletes it.
			// An error concerns only this document.
			try {
				$canDelete = $config->permanentDelete && ($state === 'trash' || $config->allowDirectDelete);
				if ($canDelete) {
					$exists = $this->copyExists($config, $path);
					if ($exists && $changes >= $config->batchSize) {
						++$report->skipped;
						continue;
					}
					if ($exists) {
						++$changes;
						if (!$report->dryRun) {
							$this->storage->delete($config->targetUser, $path);
							if ($config->pruneEmptyFolders) {
								$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
							}
						}
						$report->action("DELETE P{$documentId}: {$path}");
						++$report->permanentlyDeleted;
					}
					if (!$report->dryRun) {
						$this->state->deleteExport($config->targetUser, $documentId);
					}
					continue;
				}

				$values = ['state' => 'missing', 'missing_runs' => $missingRuns, 'last_error' => null];
				if ($state === 'active' && $config->trashMode === 'move' && $this->copyExists($config, $path)) {
					if ($changes >= $config->batchSize || $storageFailed) {
						++$report->skipped;
						continue;
					}
					++$changes;
					$target = $this->deletedPath($config, $path, $archiveRoot, time());
					if ($report->dryRun) {
						$report->action("MISSING P{$documentId}: {$target}");
						++$report->movedToTrash;
					} elseif ($this->storage->move($config->targetUser, $path, $target, $config->conflictMode)) {
						$values['path'] = $target;
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
						}
						$report->action("MISSING P{$documentId}: {$target}");
						++$report->movedToTrash;
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
				if (!$storageFailed && $this->stopsWrites($config, $report, $archiveRoot, $exception, $error)) {
					$storageFailed = true;
				} else {
					$this->logDocumentError($documentId, $path, $error, $entry);
				}
			}
		}
	}

	/**
	 * The documents in the order of the run, each with its ID and its state: the most recently added
	 * first, so that a new document does not wait behind a backlog. A document whose last change
	 * failed follows the others, the one that failed longest ago first, so that documents that keep
	 * failing take turns and leave the places of the batch to those that would succeed.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @return list<array{array<string, mixed>, int, array<string, int|string|null>|null}>
	 */
	private function exportQueue(SyncConfig $config, array $documents): array {
		$queue = [];
		$failed = [];
		foreach ($documents as $document) {
			$documentId = $this->documentId($document);
			$entry = $this->state->findExport($config->targetUser, $documentId);
			if (($entry['last_error'] ?? null) !== null) {
				$failed[] = [$document, $documentId, $entry];
			} else {
				$queue[] = [$document, $documentId, $entry];
			}
		}
		usort($queue, static fn (array $left, array $right): int => $right[1] <=> $left[1]);
		usort($failed, static fn (array $left, array $right): int => [(int)($left[2]['last_seen'] ?? 0), $right[1]] <=> [(int)($right[2]['last_seen'] ?? 0), $left[1]]);

		return [...$queue, ...$failed];
	}

	/** Reports and logs a scan that may have left documents out; returns whether the scan was complete. */
	private function checkScan(SyncReport $report, string $list, PaperlessScan $scan): bool {
		if ($scan->complete) {
			return true;
		}
		$received = count($scan->documents);
		$report->notice("SCAN INCOMPLETE: Paperless's list of {$list} came back incomplete or changed while the run read it ({$received} of {$scan->listed} received); no document counts as missing in this run.");
		$this->logger->warning("Paperless's list of {list} came back incomplete or changed while the run read it ({received} of {listed} received), so no document counts as missing in this run.", [
			'list' => $list,
			'received' => $received,
			'listed' => $scan->listed,
		]);

		return false;
	}

	/**
	 * Whether a failure concerns the whole archive, so that the other exports and moves of the run
	 * would fail as well: a full quota or disk, an unavailable storage, or an archive folder in which
	 * the target user may no longer create files. A path that Nextcloud refuses on its own, such as a
	 * forbidden name or a read-only subfolder, concerns only its document. The stop is reported and
	 * logged.
	 */
	private function stopsWrites(SyncConfig $config, SyncReport $report, string $archiveRoot, \Throwable $exception, string $error): bool {
		$archiveFailed = $exception instanceof NotEnoughSpaceException
			|| $exception instanceof StorageNotAvailableException
			|| ($exception instanceof NotPermittedException && !$this->archiveWritable($config, $archiveRoot));
		if (!$archiveFailed) {
			return false;
		}
		$report->notice('EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.');
		$this->logger->warning('Nextcloud cannot store files in the archive, so this run exports and moves no further documents: {error}', [
			'error' => $error,
			'exception' => $exception,
		]);

		return true;
	}

	/** Whether the target user may still create files in the archive; a failure of the check counts as no. */
	private function archiveWritable(SyncConfig $config, string $archiveRoot): bool {
		try {
			return $this->storage->isWritableFolder($config->targetUser, $archiveRoot);
		} catch (\Throwable) {
			return false;
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
				$error = $this->exceptionMessage($exception);
				$report->error("Import task {$path}: {$error}");
				// The task stays pending, with its error, so that a lasting failure is logged once.
				if ($this->changedError($pending, $error)) {
					// Without the exception, as for the error of a document.
					$this->logger->warning('Paperless Sync could not check the import of the inbox file {path}: {error}', [
						'path' => $path,
						'error' => $error,
					]);
				}
				if (!$report->dryRun) {
					$this->state->saveImport($config->targetUser, $path, ['last_error' => mb_substr($error, 0, 4000)]);
				}
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

	/**
	 * Logs the error of a document as a warning, unless its state keeps the same error from an
	 * earlier run: an error that recurs in every run is logged once, when it first occurs or changes.
	 *
	 * The warning carries the message of the error but not the exception: its trace would add little
	 * to the message, for every document, and leaving it out keeps the log safe should a request to
	 * Paperless ever put the API token into a trace again.
	 *
	 * @param array<string, int|string|null>|null $entry the state of the document
	 */
	private function logDocumentError(int $documentId, string $path, string $error, ?array $entry): void {
		if (!$this->changedError($entry, $error)) {
			return;
		}
		$this->logger->warning('Paperless Sync could not synchronize the document P{documentId}: {error}', [
			'documentId' => $documentId,
			'path' => $path,
			'error' => $error,
		]);
	}

	/**
	 * Whether an error differs from the one that the state keeps.
	 *
	 * @param array<string, int|string|null>|null $entry
	 */
	private function changedError(?array $entry, string $error): bool {
		return ($entry['last_error'] ?? null) !== mb_substr($error, 0, 4000);
	}

	private function exceptionMessage(\Throwable $exception): string {
		$message = trim($exception->getMessage());

		return $message !== '' ? $message : $exception::class;
	}

	/**
	 * The paths that the state gives to more than one document. Older versions could cut the
	 * marker [P123] off two long names, and then both documents wrote to one file, which keeps
	 * the content of the one written last. Such a file is not moved to either of them; each
	 * document is downloaded again.
	 *
	 * @return array<string, true>
	 */
	private function sharedPaths(SyncConfig $config): array {
		$counts = [];
		foreach ($this->state->allExports($config->targetUser) as $entry) {
			$path = $entry['path'] ?? null;
			if (is_string($path) && $path !== '') {
				$counts[$path] = ($counts[$path] ?? 0) + 1;
			}
		}

		return array_fill_keys(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)), true);
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
