<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
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
	/** The statuses of a task of Paperless that imported its file. */
	private const TASK_SUCCEEDED = ['SUCCESS', 'SUCCEEDED'];
	/** The statuses of a task that ended without a document; Paperless-ngx 3.x revokes a task that it cancels. */
	private const TASK_FAILED = ['FAILURE', 'FAILED', 'ERROR', 'REVOKED'];
	/** How long after its upload a task that Paperless has not finished, or doesn't list, may take, in seconds. */
	private const TASK_TIMEOUT = 86400;

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
		private ITimeFactory $timeFactory,
		private ActivityPublisher $activity,
		private UserTimeZones $timeZones,
		private NotificationPublisher $notifications,
	) {
	}

	/**
	 * A run with the saved settings, or a dry-run with $settings, such as those of the settings page
	 * before they are saved, which ConfigService::validate() checked: a dry-run changes nothing, so
	 * it may show what settings would do before anything uses them.
	 *
	 * @throws InvalidArgumentException when a run that is no dry-run comes with settings
	 */
	public function run(bool $dryRun = false, ?SyncConfig $settings = null): SyncReport {
		if ($settings !== null && !$dryRun) {
			throw new InvalidArgumentException('Only a dry-run can use settings that are not saved.');
		}
		$this->lockingProvider->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, AppConstants::APP_NAME);
		$report = new SyncReport($dryRun, $this->now(), unsavedSettings: $settings !== null);
		$this->status->started($dryRun);
		$config = null;
		try {
			$config = $settings ?? $this->configService->get();
			if ($config->paperlessUrl === '' || !$config->tokenConfigured || $config->targetUser === '') {
				throw new InvalidSettingException('Paperless Sync is not completely configured.');
			}
			$this->status->connected($this->paperless->testConnection($config->paperlessUrl, $this->configService->getToken(), $config->paperlessLocalNetwork));
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
			$report->completedAt = $this->now();
			$this->status->completed($report);
			$this->logger->info('Paperless synchronization completed', $report->summary());

			return $report;
		} catch (\Throwable $exception) {
			$this->status->failed($exception, $report);
			$this->logger->error('Paperless synchronization failed', ['exception' => $exception]);
			throw $exception;
		} finally {
			// The activity stream of the target user shows what the run changed, also when it failed
			// after its first changes.
			if ($config !== null) {
				$this->activity->publish($config, $report);
			}
			// The administrators hear of a mirror that keeps failing, and the target user of the
			// imports that failed, once the status has kept how the run ended.
			$this->notifications->publish($config, $report);
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
		[$users, $customFields] = $this->templateLists($config);
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
		// The run reads the state of every export once, and writes only the values that change.
		$states = new ExportStates($this->state, $config->targetUser);
		$sharedPaths = $this->sharedPaths($states);

		foreach ($this->exportQueue($states, $documents) as [$document, $documentId, $entry]) {
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
						$states->delete($documentId);
					}
				} catch (\Throwable $exception) {
					$error = $this->exceptionMessage($exception);
					$report->error("Excluded P{$documentId}: {$error}");
					$this->logDocumentError($documentId, (string)($entry['path'] ?? ''), $error, $entry);
					if (!$report->dryRun) {
						$states->save($documentId, ['last_seen' => $this->now(), 'last_error' => mb_substr($error, 0, 4000)]);
					}
				}
				continue;
			}

			$target = '';
			// The checksum and the fingerprint that a run learned of a changed document, written with
			// the next save of its state, so a document that finishes unchanged costs one write.
			$checksumValues = [];
			try {
				$relative = $this->pathTemplate->render($config, $document, $correspondents, $documentTypes, $storagePaths, $tags['names'], $users, $customFields);
				$target = $this->join($archiveRoot, $relative);
				$hasArchive = is_string($document['archived_file_name'] ?? null) && $document['archived_file_name'] !== '';
				$useOriginal = !$config->preferArchive || !$hasArchive;
				$sourceRevision = ($useOriginal ? 'original:' : 'archive:') . $this->scalarString($document['modified'] ?? null);
				$oldPathValue = $entry !== null ? ($entry['path'] ?? null) : null;
				$fingerprintValue = $entry !== null ? ($entry['fingerprint'] ?? null) : null;
				$oldPath = is_string($oldPathValue) ? $oldPathValue : '';
				$fingerprint = is_string($fingerprintValue) ? $fingerprintValue : '';
				// The batch is full when the run has tried as many exports and moves, or made as many
				// changes, as the batch size allows, or Nextcloud refused a write for the whole archive.
				$batchFull = $attempts >= $config->batchSize || $changes >= $config->batchSize || $storageFailed;
				// The mirrored file has the content of the document while its revision is the same, and
				// after a change while Paperless reports one of its checksums, MD5 or SHA-256.
				$sameContent = false;
				if ($entry !== null && $fingerprint !== '' && $fingerprint !== DocumentFingerprint::PENDING) {
					$sameContent = ($entry['source_revision'] ?? '') === $sourceRevision;
					if (!$sameContent) {
						// A document with a new path changes, whatever its content, so it waits for a
						// later run when the batch is full, and so does the question for its checksum.
						if ($batchFull && $oldPath !== $target) {
							++$report->skipped;
							continue;
						}
						// Paperless reads the checksum from the files of the document, so the state keeps it
						// with its revision, for a document that waits for a later run or whose change fails.
						$checksum = $this->knownChecksum($entry, $sourceRevision);
						if ($checksum === null) {
							$checksum = $this->paperless->documentChecksum($documentId, $useOriginal);
							$checksumValues = ['remote_checksum' => $checksum, 'remote_revision' => $sourceRevision];
						}
						$fingerprint = $this->completedFingerprint($config, $oldPath, $fingerprint, $checksum);
						// The completed fingerprint names the content of the mirrored file, whatever the run does
						// next, so a move that waits for the next run doesn't read the file once more.
						$checksumValues['fingerprint'] = $fingerprint;
						$sameContent = DocumentFingerprint::matches($fingerprint, $checksum);
					}
				}
				// The copy at the path of the document has its content, whatever the state of the
				// document, such as one that Paperless restored from its trash while the keep policy
				// left the copy in place: only the state changes.
				$unchanged = $sameContent
					&& $oldPath === $target
					&& $this->storage->exists($config->targetUser, $target);
				if ($unchanged) {
					++$report->unchanged;
					// Only what changed is written, so a document that stayed as it was costs no write.
					if (!$report->dryRun) {
						$states->save($documentId, $checksumValues + [
							'fingerprint' => $fingerprint,
							'source_revision' => $sourceRevision,
							'state' => 'active',
							'missing_runs' => 0,
							'trash_date' => null,
							'last_error' => null,
						]);
					}
					continue;
				}
				if ($batchFull) {
					++$report->skipped;
					$this->saveChecksum($states, $report, $documentId, $checksumValues);
					continue;
				}
				++$attempts;
				// When conflicts are skipped, a file at the path stays, unless it is the app's own copy,
				// so the download or the move would be in vain. A copy that the user changed is no
				// longer the app's own.
				$ownCopy = false;
				if ($config->conflictMode === 'skip' && $this->storage->exists($config->targetUser, $target)) {
					if (!$this->isOwnCopy($config, $oldPath, $target, $fingerprint)) {
						throw new RuntimeException("Nextcloud file conflict at {$target}.");
					}
					$ownCopy = true;
				}

				// The file keeps its identity in Nextcloud, its file ID with its shares, comments,
				// tags, favorites and versions: it moves to its new path, and a new content replaces
				// its content in place. A file that another document shares is not moved, because it
				// may have the content of the other one.
				$canMove = $oldPath !== ''
					&& $oldPath !== $target
					&& !isset($sharedPaths[$oldPath])
					&& $this->storage->exists($config->targetUser, $oldPath);
				$moveOnly = $sameContent && $canMove;
				// A copy that moves and then gets the new content in place has to be the app's own
				// as well, or the edit of someone in Nextcloud would be lost: it stays at its path.
				if ($config->conflictMode === 'skip' && $canMove && !$sameContent
					&& !$this->isOwnCopy($config, $oldPath, $oldPath, $fingerprint)) {
					throw new RuntimeException("Nextcloud file conflict at {$oldPath}.");
				}
				if ($report->dryRun) {
					$report->action(($moveOnly ? 'MOVE' : 'EXPORT') . " P{$documentId}: {$target}");
					$moveOnly ? ++$report->moved : ++$report->exported;
					++$changes;
					continue;
				}

				$this->saveChecksum($states, $report, $documentId, $checksumValues);
				$checksumValues = [];
				if ($canMove) {
					$this->storage->move($config->targetUser, $oldPath, $target, $config->conflictMode);
					// The state follows the file at once, so that the next run finds it at its new
					// path also when the download or the pruning that follows fails.
					$states->save($documentId, ['path' => $target]);
					if ($config->pruneEmptyFolders) {
						$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $oldPath, $archiveRoot);
					}
				}
				if (!$moveOnly) {
					// The file at the path is the app's own after a move or the check of a conflict.
					$ownTarget = $canMove || $ownCopy;
					$writeMode = $ownTarget ? 'replace' : $config->conflictMode;
					// The download goes to Nextcloud in chunks, so that a document of any size fits
					// into the memory of PHP.
					$fingerprint = $this->paperless->downloadDocument(
						$documentId,
						$useOriginal,
						/** @param resource $download */
						function ($download) use ($config, $states, $documentId, $target, $writeMode, $ownTarget): void {
							// While the new content replaces the content of the app's own copy, the
							// state says so: a write in place that fails halfway leaves the copy
							// incomplete, and the next run still takes it as its own and writes it
							// again, also when conflicts are skipped.
							if ($ownTarget) {
								$states->save($documentId, ['fingerprint' => DocumentFingerprint::PENDING]);
							}
							$this->storage->writeStream($config->targetUser, $target, $download, $writeMode);
						},
					)->value();
				}
				$removeOldCopy = !$canMove && $oldPath !== '' && $oldPath !== $target;
				if ($removeOldCopy) {
					$this->storage->delete($config->targetUser, $oldPath);
				}
				$moveOnly ? ++$report->moved : ++$report->exported;
				++$changes;
				$report->action(($moveOnly ? 'MOVE' : 'EXPORT') . " P{$documentId}: {$target}");
				$report->changedFile($target);
				$states->save($documentId, [
					'path' => $target,
					'fingerprint' => $fingerprint,
					'source_revision' => $sourceRevision,
					'state' => 'active',
					'missing_runs' => 0,
					'last_seen' => $this->now(),
					'trash_date' => null,
					'last_error' => null,
				]);
				if ($config->pruneEmptyFolders && $removeOldCopy) {
					$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $oldPath, $archiveRoot);
				}
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$states->save($documentId, $checksumValues + ['last_seen' => $this->now(), 'last_error' => mb_substr($error, 0, 4000)]);
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
			$entry = $states->find($documentId);
			if ($entry === null) {
				continue;
			}
			try {
				$oldPath = (string)($entry['path'] ?? '');
				$exists = $this->copyExists($config, $oldPath);
				if (($entry['state'] ?? '') === 'trash' && $exists) {
					++$report->unchanged;
					if (!$report->dryRun) {
						$states->save($documentId, ['missing_runs' => 0, 'last_error' => null]);
					}
					continue;
				}
				$deletedAt = $this->timestamp($document['deleted_at'] ?? null);
				$values = ['state' => 'trash', 'missing_runs' => 0, 'last_seen' => $this->now(), 'trash_date' => $deletedAt, 'last_error' => null];
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
						$report->changedFile($target);
						++$report->movedToTrash;
					}
				}
				if (!$report->dryRun) {
					$states->save($documentId, $values);
				}
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("Trash P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$states->save($documentId, ['last_error' => mb_substr($error, 0, 4000)]);
				}
				if (!$storageFailed && $this->stopsWrites($config, $report, $archiveRoot, $exception, $error)) {
					$storageFailed = true;
				} else {
					$this->logDocumentError($documentId, (string)($entry['path'] ?? ''), $error, $entry);
				}
			}
		}

		// The exported documents that the run counts as missing without having seen them in the trash.
		$missingActive = 0;
		foreach ($states->all() as $documentId => $entry) {
			if (isset($seen[$documentId])) {
				// Paperless listed the document, so its count of missing scans starts anew, also when
				// its export failed or waits for the next run.
				if ((int)($entry['missing_runs'] ?? 0) > 0 && !$report->dryRun) {
					$states->save($documentId, ['missing_runs' => 0]);
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
			if ($state === 'active') {
				++$missingActive;
			}
			if ($missingRuns < $config->missingGraceRuns) {
				if (!$report->dryRun) {
					$states->save($documentId, ['missing_runs' => $missingRuns]);
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
						$states->delete($documentId);
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
					$target = $this->deletedPath($config, $path, $archiveRoot, $this->now());
					if ($report->dryRun) {
						$report->action("MISSING P{$documentId}: {$target}");
						++$report->movedToTrash;
					} elseif ($this->storage->move($config->targetUser, $path, $target, $config->conflictMode)) {
						$values['path'] = $target;
						if ($config->pruneEmptyFolders) {
							$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $archiveRoot);
						}
						$report->action("MISSING P{$documentId}: {$target}");
						$report->changedFile($target);
						++$report->movedToTrash;
					}
				}
				if (!$report->dryRun) {
					$states->save($documentId, $values);
				}
			} catch (\Throwable $exception) {
				$error = $this->exceptionMessage($exception);
				$report->error("Missing P{$documentId}: {$error}");
				if (!$report->dryRun) {
					$states->save($documentId, ['last_error' => mb_substr($error, 0, 4000)]);
				}
				if (!$storageFailed && $this->stopsWrites($config, $report, $archiveRoot, $exception, $error)) {
					$storageFailed = true;
				} else {
					$this->logDocumentError($documentId, $path, $error, $entry);
				}
			}
		}
		$this->checkInvisibleTrash($config, $report, $missingActive, count($trash));
	}

	/**
	 * Paperless lists in its trash only the documents that the account of the app owns and those
	 * without an owner, unless it is a superuser. So documents of other owners that someone moved
	 * to the trash look gone without the trash: they reach the deleted folder only after the
	 * missing scans, or, with permanent and direct deletion both on, are deleted then, although
	 * they can still be restored in Paperless. A dry-run, which an administrator reads before
	 * turning the schedule on, says so when documents go missing while the trash lists none.
	 */
	private function checkInvisibleTrash(SyncConfig $config, SyncReport $report, int $missing, int $trashed): void {
		if (!$report->dryRun || $missing === 0 || $trashed > 0 || $config->trashMode !== 'move') {
			return;
		}
		$fate = $config->permanentDelete && $config->allowDirectDelete
			? "their copies are deleted after the required missing scans ({$config->missingGraceRuns} in a row), although the documents can still be restored in Paperless"
			: "their copies move to the deleted folder only after the required missing scans ({$config->missingGraceRuns} in a row), as those of documents deleted without the trash do";
		$report->notice("TRASH EMPTY: documents of the archive are gone from Paperless ({$missing}), and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner. Documents in the trash of other owners count as missing: {$fate}. Make the account of the app their owner.");
	}

	/**
	 * The paths that the newest documents of Paperless would have in the archive with $config, such
	 * as the settings of the page before they are saved, relative to the archive folder, for the
	 * preview of the path template; skipped is whether a run leaves the document out, as one of
	 * the inbox or with an excluded tag. It reads Paperless and nothing else, and changes nothing.
	 *
	 * @return list<array{id: int, title: string, path: string, skipped: bool}>
	 * @throws InvalidArgumentException when Paperless lacks a custom field of the template
	 */
	public function previewPaths(SyncConfig $config, int $count): array {
		$documents = $this->paperless->newestDocuments($count);
		$correspondents = $this->paperless->correspondents();
		$documentTypes = $this->paperless->documentTypes();
		$storagePaths = $this->paperless->storagePaths();
		$tags = $this->paperless->tags();
		[$users, $customFields] = $this->templateLists($config);
		/** @var list<string> $excludedNames */
		$excludedNames = array_values(array_map('mb_strtolower', array_filter(array_map('trim', explode(',', $config->excludedTags)))));
		$paths = [];
		foreach ($documents as $document) {
			// A document without a valid ID has no path; a run reports it as an error.
			$documentId = is_numeric($document['id'] ?? null) ? (int)$document['id'] : 0;
			if ($documentId < 1) {
				continue;
			}
			$paths[] = [
				'id' => $documentId,
				'title' => is_string($document['title'] ?? null) ? (string)$document['title'] : '',
				'path' => $this->pathTemplate->render($config, $document, $correspondents, $documentTypes, $storagePaths, $tags['names'], $users, $customFields),
				'skipped' => $this->isExcluded($config, $document, $tags, $excludedNames),
			];
		}

		return $paths;
	}

	/**
	 * The lists of Paperless that only some templates need, so that a run reads them only then: the
	 * users for {{ owner_username }} and the custom fields for {{ custom_fields.<name> }}. A list
	 * that Paperless refuses fails the run, with the permission that its token may lack, as does a
	 * custom field that Paperless doesn't have, before a path changes: the paths of every document
	 * would change without them.
	 *
	 * @return array{array<string, string>, array<string, array{name: string, type: string, options: array<string, string>}>}
	 */
	private function templateLists(SyncConfig $config): array {
		$users = [];
		$customFields = [];
		try {
			if ($this->pathTemplate->usesOwner($config->pathTemplate)) {
				$users = $this->paperless->users();
			}
		} catch (RuntimeException $exception) {
			throw new RuntimeException('Paperless did not list its users, which the archive path template needs for {{ owner_username }}; the user of the API token needs the permission to view users: ' . $exception->getMessage(), 0, $exception);
		}
		try {
			if ($this->pathTemplate->customFieldNames($config->pathTemplate) !== []) {
				$customFields = $this->paperless->customFields();
			}
		} catch (RuntimeException $exception) {
			throw new RuntimeException('Paperless did not list its custom fields, which the archive path template uses; the user of the API token needs the permission to view custom fields: ' . $exception->getMessage(), 0, $exception);
		}
		$this->pathTemplate->requireCustomFields($config->pathTemplate, $customFields);

		return [$users, $customFields];
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
	private function exportQueue(ExportStates $states, array $documents): array {
		$queue = [];
		$failed = [];
		foreach ($documents as $document) {
			$documentId = $this->documentId($document);
			$entry = $states->find($documentId);
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

	/**
	 * The stored fingerprint, completed with the MD5 that Paperless-ngx 2 reports when an earlier
	 * version saved the SHA-256 alone. The mirrored file still has the content of that download when
	 * its SHA-256 is the saved one, so its checksums take the place of the download's, and the first
	 * change of the metadata after the update moves the file instead of downloading the document
	 * once more. A file that can't be read leaves the fingerprint as it is, and the download replaces it.
	 */
	private function completedFingerprint(SyncConfig $config, string $path, string $fingerprint, string $checksum): string {
		if (str_contains($fingerprint, ' ') || strlen($checksum) !== 32) {
			return $fingerprint;
		}
		$mirrored = $this->mirroredFingerprint($config, $path);

		return $mirrored !== null && $mirrored->sha256 === $fingerprint ? $mirrored->value() : $fingerprint;
	}

	/**
	 * Whether the file at a path is the copy of a document that the app wrote: the state of the
	 * document records the path, and the file still has the content of the saved fingerprint, or
	 * the app was writing it when its last write failed. A copy that the user changed, and a file
	 * that can't be read, count as files of the user.
	 */
	private function isOwnCopy(SyncConfig $config, string $recordedPath, string $path, string $fingerprint): bool {
		if ($recordedPath !== $path) {
			return false;
		}
		if ($fingerprint === DocumentFingerprint::PENDING) {
			return true;
		}
		$mirrored = $this->mirroredFingerprint($config, $path);

		return $mirrored !== null && DocumentFingerprint::matches($fingerprint, $mirrored->sha256);
	}

	/**
	 * Writes the checksum and the fingerprint that the run learned of a document that doesn't finish
	 * as unchanged, before its file changes, so that they outlast a change that fails.
	 *
	 * @param array<string, int|string|null> $values
	 */
	private function saveChecksum(ExportStates $states, SyncReport $report, int $documentId, array $values): void {
		if ($values !== [] && !$report->dryRun) {
			$states->save($documentId, $values);
		}
	}

	/**
	 * The checksum that Paperless reported for the revision of the document, which an earlier run
	 * kept, or null when the run has to ask for it: a run asks once for each revision.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	private function knownChecksum(array $entry, string $sourceRevision): ?string {
		$checksum = $entry['remote_checksum'] ?? null;

		return ($entry['remote_revision'] ?? null) === $sourceRevision && is_string($checksum) && $checksum !== '' ? $checksum : null;
	}

	/** The checksums of the mirrored file at the path, from one read of it, or null when it is gone or can't be read. */
	private function mirroredFingerprint(SyncConfig $config, string $path): ?DocumentFingerprint {
		if (!$this->copyExists($config, $path)) {
			return null;
		}
		try {
			$stream = $this->storage->openRead($config->targetUser, $path);
			try {
				return DocumentFingerprint::of($stream);
			} finally {
				fclose($stream);
			}
		} catch (\Throwable) {
			return null;
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
		/** @var array<string, int> $attempts the failed uploads of each file whose next attempt is due */
		$attempts = [];
		$now = $this->now();

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
				$taskId = (string)($pending['task_id'] ?? '');
				$task = $this->paperless->taskStatus($taskId);
				if (in_array($task['status'], self::TASK_SUCCEEDED, true)) {
					$this->importSucceeded($config, $report, $path, $file['etag'], $unchangedFile, $task['documentId'], $inboxRoot);
				} elseif (in_array($task['status'], self::TASK_FAILED, true)) {
					$reason = $this->importFailureReason($task);
					$this->importFailed($config, $report, $path, $reason, $this->importDiagnostic($taskId, $reason), $inboxRoot, $errorRoot);
				} elseif ($now - (int)($pending['submitted_at'] ?? 0) >= self::TASK_TIMEOUT) {
					$this->endUnfinishedImport($config, $report, $path, $file['etag'], $unchangedFile, (int)($pending['attempts'] ?? 0) + 1, $taskId, $task, $inboxRoot, $errorRoot);
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
		$inboxTags = new InboxTags($this->paperless, $config, $report);
		foreach ($byPath as $path => $file) {
			// Every upload takes its place in the batch, also one that fails. After a failure that may
			// pass, Paperless is down or overloaded, and the other files wait for the next run.
			if ($submitted >= $config->batchSize || $paperlessFailed) {
				++$report->skipped;
				continue;
			}
			++$submitted;
			$tags = ['ids' => [], 'names' => []];
			if ($inboxTags->enabled()) {
				try {
					$tags = $inboxTags->forFolder(self::folderBelow($inboxRoot, $path));
				} catch (\Throwable $exception) {
					// The file waits for its tags, as for a failed upload. Without the list of the tags, or
					// without Paperless, every other file would fail the same way.
					if ($report->dryRun) {
						$report->error("Import {$path}: " . $this->exceptionMessage($exception));
					} else {
						$this->retryImportLater($config, $report, $path, $file['etag'], ($attempts[$path] ?? 0) + 1, $exception);
					}
					$paperlessFailed = $inboxTags->unavailable() || $exception instanceof PaperlessRequestException;
					continue;
				}
			}
			$line = "IMPORT: {$path}" . ($tags['names'] !== [] ? ' (tags: ' . implode(', ', $tags['names']) . ')' : '');
			if ($report->dryRun) {
				$report->action($line);
				++$report->importsSubmitted;
				continue;
			}
			$stream = null;
			try {
				$stream = $this->storage->openRead($config->targetUser, $path);
				$taskId = $this->paperless->uploadDocument($stream, $file['name'], $tags['ids']);
				$this->state->saveImport($config->targetUser, $path, [
					'etag' => $file['etag'],
					'task_id' => $taskId,
					'status' => 'pending',
					'submitted_at' => $this->now(),
					'attempts' => 0,
					'retry_at' => 0,
					'last_error' => null,
				]);
				$report->action($line);
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
		$this->reportUnknownTags($report, $inboxTags->unknown());
	}

	/**
	 * The names of tags that Paperless lacks, once for the run: the files went to Paperless
	 * without them, as "Create missing tags" is off.
	 *
	 * @param list<string> $names
	 */
	private function reportUnknownTags(SyncReport $report, array $names): void {
		if ($names === []) {
			return;
		}
		$list = implode(', ', $names);
		$missing = count($names) === 1 ? "no tag named {$list}, so the files of the inbox go to Paperless without it" : "no tags named {$list}, so the files of the inbox go to Paperless without them";
		$report->notice("TAGS UNKNOWN: Paperless has {$missing}. Create the tags in Paperless, or turn on \"Create missing tags\".");
		if (!$report->dryRun) {
			$this->logger->warning('Paperless has no tags named {names}, so files of the inbox went to Paperless without them. Create the tags in Paperless, or turn on "Create missing tags" in the settings of Paperless Sync.', [
				'names' => $list,
			]);
		}
	}

	/** The path of the folder of a file of the inbox below the inbox, such as Taxes/2026, or '' for the inbox itself. */
	private static function folderBelow(string $inboxRoot, string $path): string {
		// The inbox folder is never empty, so its files are below $inboxRoot/.
		$relative = substr($path, strlen($inboxRoot) + 1);
		$slash = strrpos($relative, '/');

		return $slash === false ? '' : substr($relative, 0, $slash);
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
			'submitted_at' => $this->now(),
			'attempts' => 0,
			'retry_at' => 0,
			'last_error' => mb_substr($reason, 0, 4000),
		]);
		$report->action("IMPORT REJECTED: {$path}: {$reason}");
		$report->importResult(SyncReport::IMPORT_REJECTED, $path, null, $reason);
		++$report->importsFailed;
		$this->logger->warning('Paperless refused the inbox file {path}; it stays in the inbox until it changes: {reason}', [
			'path' => $path,
			'reason' => $reason,
			'status' => $exception->statusCode,
		]);
	}

	/**
	 * What Paperless said about the task of a failed import, for the .error.txt next to the file and
	 * for the entry in the activity stream.
	 *
	 * @param array{status: string, message: string, documentId: int|null, listed: bool} $task
	 */
	private function importFailureReason(array $task): string {
		if ($task['status'] === 'REVOKED') {
			return 'Paperless cancelled the task before it finished (REVOKED), so it created no document' . ($task['message'] !== '' ? ': ' . $task['message'] : '.');
		}

		return $task['message'] !== '' ? $task['message'] : 'Paperless gave no reason; its log may have one.';
	}

	/**
	 * The text of the .error.txt next to a file whose import failed: the reason, and the ID of the
	 * task, by which the task list of Paperless shows more.
	 */
	private function importDiagnostic(string $taskId, string $reason): string {
		return "Paperless import failed\n\n{$reason}\n\nPaperless task: {$taskId}\n";
	}

	/**
	 * Paperless has the content of a file of the inbox. A file that changed since its upload, whose
	 * new content Paperless doesn't have, goes up again in the next run.
	 */
	private function importSucceeded(SyncConfig $config, SyncReport $report, string $path, string $etag, bool $sameContent, ?int $documentId, string $inboxRoot): void {
		if ($sameContent) {
			if (!$report->dryRun && $config->deleteInboxAfterSuccess) {
				$this->storage->delete($config->targetUser, $path);
				$this->state->deleteImport($config->targetUser, $path);
				if ($config->pruneEmptyFolders) {
					$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $inboxRoot);
				}
			} elseif (!$report->dryRun) {
				$this->state->saveImport($config->targetUser, $path, ['etag' => $etag, 'status' => 'success', 'last_error' => null]);
			}
			$report->action("IMPORT SUCCESS: {$path}" . ($documentId !== null ? " -> P{$documentId}" : ''));
		} elseif (!$report->dryRun) {
			$this->state->deleteImport($config->targetUser, $path);
		}
		$report->importResult(SyncReport::IMPORTED, $path, $documentId);
		++$report->importsSucceeded;
	}

	/**
	 * The import of a file of the inbox created no document, so it moves to the error folder, next to
	 * a text that says why; its entry in the activity stream gives the reason alone.
	 */
	private function importFailed(SyncConfig $config, SyncReport $report, string $path, string $reason, string $diagnostic, string $inboxRoot, string $errorRoot): void {
		$destination = $this->join($errorRoot, $this->relativeTo($path, $inboxRoot));
		if (!$report->dryRun) {
			$this->storage->move($config->targetUser, $path, $destination, $config->conflictMode);
			$this->storage->writeText($config->targetUser, $destination . '.error.txt', $diagnostic);
			$this->state->deleteImport($config->targetUser, $path);
			if ($config->pruneEmptyFolders) {
				$report->foldersPruned += $this->storage->pruneEmptyParents($config->targetUser, $path, $inboxRoot);
			}
		}
		$report->action("IMPORT ERROR: {$path} -> {$destination}");
		$report->importResult(SyncReport::IMPORT_FAILED, $destination, null, $reason);
		++$report->importsFailed;
	}

	/**
	 * Paperless has not finished the task of an upload a day after it. Without an end of the task,
	 * the file would wait in the inbox for good, so the run ends it:
	 *
	 * - Paperless has a document with the content of the file, as when the task was removed after
	 *   it finished: the import succeeded.
	 * - Paperless still lists the task, as pending or started: its worker may be down, or the task
	 *   may wait in a long queue. Paperless-ngx 3.x imports a duplicate as a second document unless
	 *   PAPERLESS_CONSUMER_DELETE_DUPLICATES is on, so a new upload could create the document
	 *   twice. The file moves to the error folder instead, with a note that the document may still
	 *   appear in Paperless.
	 * - Paperless doesn't list the task at all, so its queue lost it, as after a restart of Redis,
	 *   or the file changed since its upload, so Paperless doesn't have its content: the file is
	 *   uploaded again after the wait of a failed upload.
	 *
	 * @param array{status: string, message: string, documentId: int|null, listed: bool} $task
	 */
	private function endUnfinishedImport(SyncConfig $config, SyncReport $report, string $path, string $etag, bool $unchangedFile, int $attempts, string $taskId, array $task, string $inboxRoot, string $errorRoot): void {
		$content = $this->mirroredFingerprint($config, $path);
		$documentId = $content !== null ? $this->paperless->findDocument($content) : null;
		if ($documentId !== null) {
			$this->importSucceeded($config, $report, $path, $etag, true, $documentId, $inboxRoot);
			return;
		}
		$state = $task['status'] . ($task['message'] !== '' ? ": {$task['message']}" : '');
		$error = "Paperless has not finished the import task {$taskId} a day after the upload ({$state})";
		if (!$task['listed'] || !$unchangedFile) {
			$this->retryUnfinishedImport($config, $report, $path, $etag, $attempts, $taskId, $error);
			return;
		}
		$this->importFailed($config, $report, $path, "Paperless has not finished the import a day after the upload ({$state}), so it may still create the document.", $this->unfinishedDiagnostic($taskId, $state), $inboxRoot, $errorRoot);
		if (!$report->dryRun) {
			$this->logger->warning('Paperless has not finished the import of the inbox file {path} a day after its upload; it moves to the error folder, as Paperless may still import it: {error}', [
				'path' => $path,
				'error' => $error,
				'task' => $taskId,
			]);
		}
	}

	/** The text of the .error.txt next to a file whose task Paperless still lists, unfinished, a day after the upload. */
	private function unfinishedDiagnostic(string $taskId, string $state): string {
		return "Paperless import not finished\n\n"
			. "Paperless has not finished the import task a day after the upload ({$state}). Its worker may be down, or the task may still wait in its queue, so Paperless may still create the document. The file was not uploaded again, because Paperless-ngx 3.x imports a duplicate as a second document.\n\n"
			. "Should the document not appear in Paperless, check that the worker of Paperless runs, then move the file back into the inbox.\n\n"
			. "Paperless task: {$taskId}\n";
	}

	/**
	 * Paperless doesn't list the task of an upload a day after it and has no document with the
	 * content of the file, so its queue lost the task, as after a restart of Redis; or the file
	 * changed since its upload. The file is uploaded again after the wait of a failed upload.
	 */
	private function retryUnfinishedImport(SyncConfig $config, SyncReport $report, string $path, string $etag, int $attempts, string $taskId, string $error): void {
		if ($report->dryRun) {
			$report->error("Import {$path}: {$error}; a run uploads it again");
			return;
		}
		$minutes = $this->retryLater($config, $report, $path, $etag, $attempts, $error);
		$this->logger->warning('Paperless has not finished the import of the inbox file {path} a day after its upload; it is uploaded again in {minutes} minutes: {error}', [
			'path' => $path,
			'error' => $error,
			'task' => $taskId,
			'minutes' => $minutes,
		]);
	}

	/** The upload failed for a reason that may pass, so the file waits longer after each failure. */
	private function retryImportLater(SyncConfig $config, SyncReport $report, string $path, string $etag, int $attempts, \Throwable $exception): void {
		$error = $this->exceptionMessage($exception);
		$minutes = $this->retryLater($config, $report, $path, $etag, $attempts, $error);
		// The first failure of a file is a warning, the ones after it only information.
		$this->logger->log($attempts === 1 ? LogLevel::WARNING : LogLevel::INFO, 'The upload of the inbox file {path} failed; the next attempt follows in {minutes} minutes: {error}', [
			'path' => $path,
			'error' => $error,
			'attempts' => $attempts,
			'minutes' => $minutes,
		]);
	}

	/**
	 * Saves that a file of the inbox waits for its next upload, twice as long after each failure up
	 * to a day, and reports why. The answer is the wait in minutes.
	 */
	private function retryLater(SyncConfig $config, SyncReport $report, string $path, string $etag, int $attempts, string $error): int {
		$delay = min(self::RETRY_DELAY << min($attempts - 1, 10), self::MAX_RETRY_DELAY);
		$minutes = intdiv($delay, 60);
		$now = $this->now();
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

		return $minutes;
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
	private function sharedPaths(ExportStates $states): array {
		$counts = [];
		foreach ($states->all() as $entry) {
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

	/** The current time, from the clock of Nextcloud, which the tests set. */
	private function now(): int {
		return $this->timeFactory->getTime();
	}

	private function scalarString(mixed $value): string {
		return is_scalar($value) ? (string)$value : '';
	}

	/**
	 * Where a copy goes when its document went to the trash or vanished: below the deleted folder, in
	 * the folder of the day, in the time zone of the target user, from the personal settings or else
	 * the default of Nextcloud. In UTC, a document deleted in Berlin shortly after midnight would
	 * land in the folder of the day before.
	 */
	private function deletedPath(SyncConfig $config, string $oldPath, string $archiveRoot, int $timestamp): string {
		$relative = $this->relativeTo($oldPath, $archiveRoot);
		$timestamp = $timestamp > 0 ? $timestamp : $this->now();
		$day = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timeZones->of($config->targetUser))->format('Y-m-d');

		return $this->join($archiveRoot, $config->deletedFolder, $day, $relative);
	}

	private function relativeTo(string $path, string $root): string {
		$prefix = rtrim($root, '/') . '/';
		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : basename($path);
	}

	/** @param mixed $value */
	private function timestamp(mixed $value): int {
		if (!is_string($value) || $value === '') {
			return $this->now();
		}
		$timestamp = strtotime($value);

		return $timestamp !== false ? $timestamp : $this->now();
	}

	private function join(string ...$parts): string {
		return implode('/', array_filter(array_map(static fn (string $part): string => trim($part, '/'), $parts), static fn (string $part): bool => $part !== ''));
	}
}
