<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use LogicException;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\Plan\Copy;
use OCA\PaperlessSync\Model\Plan\DocumentContext;
use OCA\PaperlessSync\Model\Plan\ExportAction;
use OCA\PaperlessSync\Model\Plan\ExportCopy;
use OCA\PaperlessSync\Model\Plan\Failed;
use OCA\PaperlessSync\Model\Plan\ForgetState;
use OCA\PaperlessSync\Model\Plan\MoveToDeleted;
use OCA\PaperlessSync\Model\Plan\RemoveCopy;
use OCA\PaperlessSync\Model\Plan\SaveState;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\WaitForMissing;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Applies the plan of an export, action by action: it writes, moves and deletes the copies and
 * saves the states of their documents, takes the place of each change in the batch, and reports
 * each change. A dry-run reports the same changes, in the same batch, as if each succeeded, and
 * changes nothing: it is the one place where a dry-run and a run differ.
 *
 * An error concerns only its document: the report names it, the state of the document keeps it
 * and the log has it once, when it first occurs or changes. An error that concerns the whole
 * archive, such as a full quota, stops the other exports and moves of the run.
 *
 * With the metadata next to the archive, a run describes each copy that it exported, moved or
 * found unchanged at its path; a dry-run writes no metadata. With the tags of Paperless as tags of
 * Nextcloud, a run and a dry-run name each such copy to the tags of the run, which give the copies
 * their tags once the export is done.
 */
final class ExportExecutor {
	private bool $dryRun;

	public function __construct(
		private SyncConfig $config,
		private SyncReport $report,
		private ExportStates $states,
		private SyncBatch $batch,
		private NextcloudStorageInterface $storage,
		private PaperlessClientInterface $paperless,
		private LoggerInterface $logger,
		private ITimeFactory $clock,
		private string $archiveRoot,
		private ?MetadataSidecars $sidecars = null,
		private ?ArchiveTags $tags = null,
	) {
		$this->dryRun = $report->dryRun;
	}

	/** @throws LogicException for an action that this executor doesn't know, or that doesn't fit the batch */
	public function apply(ExportAction $action): void {
		if ($action instanceof ExportCopy) {
			$this->export($action);
		} elseif ($action instanceof MoveToDeleted) {
			$this->moveToDeleted($action);
		} elseif ($action instanceof RemoveCopy) {
			$this->remove($action);
		} elseif ($action instanceof Unchanged) {
			++$this->report->unchanged;
			$this->save($action->document, $action->values, $action->onFailure, $action->copy);
			if ($action->document !== null && $action->copy !== null) {
				$this->tags?->copy($action->document, $action->copy->document, $action->copy->path, $action->copy->path);
			}
		} elseif ($action instanceof Skipped) {
			++$this->report->skipped;
			$this->save($action->document, $action->values, $action->onFailure);
		} elseif ($action instanceof SaveState) {
			$this->save($action->document, $action->values, $action->onFailure);
		} elseif ($action instanceof ForgetState) {
			$this->forget($action);
		} elseif ($action instanceof WaitForMissing) {
			$this->save($action->document, ['missing_runs' => $action->scans]);
			$this->report->waiting($action->document, $action->path, $action->scans, $action->limit);
		} elseif ($action instanceof Failed) {
			if ($action->attempt) {
				$this->batch->take(SyncBatch::EXPORT);
			}
			$this->fail($action->context, $action->exception, $action->values);
		} else {
			throw new LogicException('An export has no action ' . $action::class . '.');
		}
	}

	private function export(ExportCopy $action): void {
		$this->batch->take(SyncBatch::EXPORT);
		$documentId = $action->context->document;
		try {
			$fingerprint = $this->dryRun ? $action->fingerprint : $this->writeCopy($action);
			$action->download ? ++$this->report->exported : ++$this->report->moved;
			$this->batch->succeeded();
			$this->report->change($action->type(), $documentId, $action->target);
			$this->report->changedFile($action->target);
			if (!$this->dryRun) {
				$this->states->save($documentId, [
					'path' => $action->target,
					'fingerprint' => $fingerprint,
					'source_revision' => $action->sourceRevision,
					'state' => 'active',
					'missing_runs' => 0,
					'last_seen' => $this->now(),
					'trash_date' => null,
					'last_error' => null,
				]);
				$this->sidecars?->describe($action->document, $documentId, $action->target, $fingerprint, $action->useOriginal);
				if ($action->removesOldCopy()) {
					$this->prune($action->oldPath);
				}
			}
			// A dry-run moves no copy, so the copy that it would move is still at its old path.
			$this->tags?->copy($documentId, $action->document, $action->target, $this->dryRun && $action->move ? $action->oldPath : $action->target);
		} catch (\Throwable $exception) {
			$this->fail($action->context, $exception);
		}
	}

	/**
	 * Moves the copy and downloads its new content, and returns the fingerprint of the content of
	 * the copy. The state keeps what the run learned of the document before the copy changes, so
	 * that it outlasts a change that fails, and it follows a moved file at once, so that the next
	 * run finds it at its new path also when the download or the pruning that follows fails.
	 */
	private function writeCopy(ExportCopy $action): string {
		$user = $this->config->targetUser;
		$documentId = $action->context->document;
		if ($action->checksum !== []) {
			$this->states->save($documentId, $action->checksum);
		}
		if ($action->move) {
			$this->storage->move($user, $action->oldPath, $action->target, $this->config->conflictMode);
			$this->states->save($documentId, ['path' => $action->target]);
			$this->prune($action->oldPath);
		}
		$fingerprint = $action->fingerprint;
		if ($action->download) {
			$ownTarget = $action->ownTarget;
			$writeMode = $ownTarget ? 'replace' : $this->config->conflictMode;
			$target = $action->target;
			// The download goes to Nextcloud in chunks, so that a document of any size fits into the
			// memory of PHP.
			$fingerprint = $this->paperless->downloadDocument(
				$documentId,
				$action->useOriginal,
				/** @param resource $download */
				function ($download) use ($user, $documentId, $target, $writeMode, $ownTarget): void {
					// While the new content replaces the content of the app's own copy, the state says
					// so: a write in place that fails halfway leaves the copy incomplete, and the next
					// run still takes it as its own and writes it again, also when conflicts are skipped.
					if ($ownTarget) {
						$this->states->save($documentId, ['fingerprint' => DocumentFingerprint::PENDING]);
					}
					$this->storage->writeStream($user, $target, $download, $writeMode);
				},
			)->value();
		}
		if ($action->removesOldCopy()) {
			$this->storage->delete($user, $action->oldPath);
		}

		return $fingerprint;
	}

	/** A move takes its place in the batch before it is tried, also one that fails. */
	private function moveToDeleted(MoveToDeleted $action): void {
		$this->batch->take(SyncBatch::WRITE);
		$documentId = $action->context->document;
		try {
			$values = $action->values;
			if ($this->dryRun || $this->storage->move($this->config->targetUser, $action->path, $action->target, $this->config->conflictMode)) {
				if (!$this->dryRun) {
					$values['path'] = $action->target;
					$this->prune($action->path);
				}
				$this->report->change($action->type, $documentId, $action->target);
				$this->report->changedFile($action->target);
				++$this->report->movedToTrash;
			}
			if (!$this->dryRun) {
				$this->states->save($documentId, $values);
			}
		} catch (\Throwable $exception) {
			$this->fail($action->context, $exception);
		}
	}

	/** A removal takes its place in the batch before it is tried, also one that fails. */
	private function remove(RemoveCopy $action): void {
		$this->batch->take(SyncBatch::DELETE);
		$documentId = $action->context->document;
		try {
			if (!$this->dryRun) {
				$this->storage->delete($this->config->targetUser, $action->path);
				$this->prune($action->path);
			}
			$this->report->change($action->type, $documentId, $action->path);
			$action->type === SyncReport::TYPE_DELETE ? ++$this->report->permanentlyDeleted : ++$this->report->removedExcluded;
			if (!$this->dryRun) {
				$this->states->delete($documentId);
			}
		} catch (\Throwable $exception) {
			$this->fail($action->context, $exception);
		}
	}

	private function forget(ForgetState $action): void {
		if ($this->dryRun) {
			return;
		}
		try {
			$this->states->delete($action->document);
		} catch (\Throwable $exception) {
			$this->fail($action->onFailure, $exception);
		}
	}

	/**
	 * Saves the values that changed in the state of a document, and describes its copy in the
	 * metadata next to the archive; a dry-run saves nothing.
	 *
	 * @param array<string, int|string|null> $values
	 * @param DocumentContext|null $onFailure what a failure reports, or null when it fails the run
	 */
	private function save(?int $documentId, array $values, ?DocumentContext $onFailure = null, ?Copy $copy = null): void {
		if ($this->dryRun || $documentId === null || $values === []) {
			return;
		}
		try {
			$this->states->save($documentId, $values);
			if ($copy !== null) {
				$this->sidecars?->describe($copy->document, $documentId, $copy->path, $copy->fingerprint, $copy->original);
			}
		} catch (\Throwable $exception) {
			if ($onFailure === null) {
				throw $exception;
			}
			$this->fail($onFailure, $exception);
		}
	}

	/**
	 * Reports the error of a document and keeps it in its state. An error that stops the writes for
	 * the whole archive is reported once, as the warning about the archive, and the others are
	 * logged with their document.
	 *
	 * @param array<string, int|string|null> $values what the run learned of the document, which the state keeps with the error
	 */
	private function fail(DocumentContext $context, \Throwable $exception, array $values = []): void {
		$error = SyncErrors::message($exception);
		$this->report->error("{$context->label}P{$context->document}: {$error}", $context->document, $context->reportPath);
		if (!$this->dryRun) {
			$this->states->save($context->document, $values + ($context->seen ? ['last_seen' => $this->now()] : []) + ['last_error' => SyncErrors::stored($error)]);
		}
		if ($context->stopsWrites && !$this->batch->stopped() && $this->stopsWrites($exception, $error)) {
			$this->batch->stop();
		} else {
			$this->logDocumentError($context, $error);
		}
	}

	/**
	 * Whether a failure concerns the whole archive, so that the other exports and moves of the run
	 * would fail as well (SyncErrors::archiveFailed()). The stop is reported and logged.
	 */
	private function stopsWrites(\Throwable $exception, string $error): bool {
		if (!SyncErrors::archiveFailed($exception, $this->storage, $this->config->targetUser, $this->archiveRoot)) {
			return false;
		}
		$this->report->notice(SyncReport::TYPE_EXPORT_STOPPED, 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.');
		$this->logger->warning('Nextcloud cannot store files in the archive, so this run exports and moves no further documents: {error}', [
			'error' => $error,
			'exception' => $exception,
		]);

		return true;
	}

	/**
	 * Logs the error of a document as a warning, unless its state keeps the same error from an
	 * earlier run: an error that recurs in every run is logged once, when it first occurs or changes.
	 *
	 * The warning carries the message of the error but not the exception: its trace would add little
	 * to the message, for every document, and leaving it out keeps the log safe should a request to
	 * Paperless ever put the API token into a trace again.
	 */
	private function logDocumentError(DocumentContext $context, string $error): void {
		if (!SyncErrors::changed($context->entry, $error)) {
			return;
		}
		$this->logger->warning('Paperless Sync could not synchronize the document P{documentId}: {error}', [
			'documentId' => $context->document,
			'path' => $context->logPath,
			'error' => $error,
		]);
	}

	/** Removes the folders that a moved or removed copy left empty, when the settings say so. */
	private function prune(string $path): void {
		if ($this->config->pruneEmptyFolders) {
			$this->report->foldersPruned += $this->storage->pruneEmptyParents($this->config->targetUser, $path, $this->archiveRoot);
		}
	}

	private function now(): int {
		return $this->clock->getTime();
	}
}
