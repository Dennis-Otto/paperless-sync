<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use LogicException;
use OCA\PaperlessSync\Model\Plan\ForgetImport;
use OCA\PaperlessSync\Model\Plan\ImportAction;
use OCA\PaperlessSync\Model\Plan\ImportCheckFailed;
use OCA\PaperlessSync\Model\Plan\ImportContext;
use OCA\PaperlessSync\Model\Plan\ImportFailed;
use OCA\PaperlessSync\Model\Plan\ImportSucceeded;
use OCA\PaperlessSync\Model\Plan\RetryUnfinished;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\Upload;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Applies the plan of the inbox, action by action: it uploads the files to Paperless, with the tags
 * of their folders, ends the uploads whose tasks ended, and saves the states of the uploads. A
 * dry-run reports the same uploads, in the same batch, and changes nothing: it creates no tag,
 * uploads no file and moves none.
 *
 * In per-user ownership, the inbox of a user of Nextcloud with a user of Paperless gives each
 * document that its uploads created that user as the owner, once Paperless imported it.
 */
final class ImportExecutor {
	/** The wait after the first failed upload of a file whose failure may pass, in seconds; it doubles with each further one. */
	public const RETRY_DELAY = 900;
	/** The longest wait between two uploads of a file that keep failing, in seconds. */
	public const MAX_RETRY_DELAY = 86400;

	private bool $dryRun;
	private InboxTags $tags;

	public function __construct(
		private SyncConfig $config,
		private SyncReport $report,
		private SyncStateRepositoryInterface $state,
		private SyncBatch $batch,
		private NextcloudStorageInterface $storage,
		private PaperlessClientInterface $paperless,
		private LoggerInterface $logger,
		private ITimeFactory $clock,
		private string $inboxRoot,
		/** The user of Paperless who owns the documents of the files of this inbox, in per-user ownership, or null to leave them to the account of the app. */
		private ?int $owner = null,
	) {
		$this->dryRun = $report->dryRun;
		$this->tags = new InboxTags($paperless, $config, $report);
	}

	/** @throws LogicException for an action that this executor doesn't know, or that doesn't fit the batch */
	public function apply(ImportAction $action): void {
		if ($action instanceof Upload) {
			$this->upload($action);
		} elseif ($action instanceof Unchanged) {
			++$this->report->unchanged;
		} elseif ($action instanceof Skipped) {
			++$this->report->skipped;
		} elseif ($action instanceof ForgetImport) {
			if (!$this->dryRun) {
				$this->state->deleteImport($this->config->targetUser, $action->path);
			}
		} elseif ($action instanceof ImportSucceeded) {
			$this->checked($action->context, fn () => $this->succeeded($action));
		} elseif ($action instanceof ImportFailed) {
			$this->checked($action->context, fn () => $this->failed($action));
		} elseif ($action instanceof RetryUnfinished) {
			$this->checked($action->context, fn () => $this->retryUnfinished($action));
		} elseif ($action instanceof ImportCheckFailed) {
			$this->checkFailed($action->context, $action->exception);
		} else {
			throw new LogicException('The inbox has no action ' . $action::class . '.');
		}
	}

	/**
	 * The names of tags that Paperless lacks, once for the run: the files went to Paperless
	 * without them, as "Create missing tags" is off.
	 */
	public function finish(): void {
		$names = $this->tags->unknown();
		if ($names === []) {
			return;
		}
		$list = implode(', ', $names);
		$missing = count($names) === 1 ? "no tag named {$list}, so the files of the inbox go to Paperless without it" : "no tags named {$list}, so the files of the inbox go to Paperless without them";
		$this->report->notice(SyncReport::TYPE_TAGS_UNKNOWN, "TAGS UNKNOWN: Paperless shows the account {$missing}. Create the tags in Paperless or share them with the account, or turn on \"Create missing tags\".", [
			'names' => $list,
			'count' => count($names),
		]);
		if (!$this->dryRun) {
			$this->logger->warning('Paperless shows the account of Paperless Sync no tags named {names}, so files of the inbox went to Paperless without them. Create the tags in Paperless or share them with the account, or turn on "Create missing tags" in the settings of Paperless Sync.', [
				'names' => $list,
			]);
		}
	}

	private function upload(Upload $action): void {
		$this->batch->take(SyncBatch::WRITE);
		$path = $action->path;
		$tags = ['ids' => [], 'names' => []];
		if ($this->tags->enabled()) {
			try {
				$tags = $this->tags->forFolder($action->folder);
			} catch (\Throwable $exception) {
				// The file waits for its tags, as for a failed upload. Without the list of the tags, or
				// without Paperless, every other file would fail the same way.
				if ($this->dryRun) {
					$this->report->error("Import {$path}: " . SyncErrors::message($exception));
				} else {
					$this->retryUploadLater($action, $exception);
				}
				if ($this->tags->unavailable() || $exception instanceof PaperlessRequestException) {
					$this->batch->stop();
				}
				return;
			}
		}
		if ($this->dryRun) {
			$this->report->upload($path, $tags['names']);
			++$this->report->importsSubmitted;
			return;
		}
		$stream = null;
		try {
			$stream = $this->storage->openRead($this->config->targetUser, $path);
			$taskId = $this->paperless->uploadDocument($stream, $action->name, $tags['ids']);
			$this->state->saveImport($this->config->targetUser, $path, [
				'etag' => $action->etag,
				'task_id' => $taskId,
				'status' => 'pending',
				'submitted_at' => $this->now(),
				'attempts' => 0,
				'retry_at' => 0,
				'last_error' => null,
			]);
			$this->report->upload($path, $tags['names']);
			++$this->report->importsSubmitted;
		} catch (PaperlessUploadException $exception) {
			if ($exception->permanent) {
				$this->reject($action, $exception);
			} else {
				$this->retryUploadLater($action, $exception);
				$this->batch->stop();
			}
		} catch (\Throwable $exception) {
			$this->retryUploadLater($action, $exception);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	/**
	 * Paperless would refuse the same file again, so it waits in the inbox until it changes. Only
	 * this run reports and logs the reason; the runs until then skip the file quietly.
	 */
	private function reject(Upload $action, PaperlessUploadException $exception): void {
		$path = $action->path;
		$reason = SyncErrors::message($exception);
		$this->state->saveImport($this->config->targetUser, $path, [
			'etag' => $action->etag,
			'task_id' => '',
			'status' => 'rejected',
			'submitted_at' => $this->now(),
			'attempts' => 0,
			'retry_at' => 0,
			'last_error' => SyncErrors::stored($reason),
		]);
		$this->report->uploadRejected($path, $reason);
		$this->report->importResult(SyncReport::IMPORT_REJECTED, $path, null, $reason);
		++$this->report->importsFailed;
		$this->logger->warning('Paperless refused the inbox file {path}; it stays in the inbox until it changes: {reason}', [
			'path' => $path,
			'reason' => $reason,
			'status' => $exception->statusCode,
		]);
	}

	/** The upload failed for a reason that may pass, so the file waits longer after each failure. */
	private function retryUploadLater(Upload $action, \Throwable $exception): void {
		$error = SyncErrors::message($exception);
		$attempts = $action->attempts + 1;
		$minutes = $this->retryLater($action->path, $action->etag, $attempts, $error);
		// The first failure of a file is a warning, the ones after it only information.
		$this->logger->log($attempts === 1 ? LogLevel::WARNING : LogLevel::INFO, 'The upload of the inbox file {path} failed; the next attempt follows in {minutes} minutes: {error}', [
			'path' => $action->path,
			'error' => $error,
			'attempts' => $attempts,
			'minutes' => $minutes,
		]);
	}

	/**
	 * Paperless has the content of a file of the inbox. A file that changed since its upload, whose
	 * new content Paperless doesn't have, goes up again in the next run.
	 */
	private function succeeded(ImportSucceeded $action): void {
		$path = $action->context->path;
		if ($this->owner !== null && !$this->dryRun) {
			$this->giveOwner($action);
		}
		if ($action->sameContent) {
			if (!$this->dryRun && $this->config->deleteInboxAfterSuccess) {
				$this->storage->delete($this->config->targetUser, $path);
				$this->state->deleteImport($this->config->targetUser, $path);
				$this->prune($path);
			} elseif (!$this->dryRun) {
				$this->state->saveImport($this->config->targetUser, $path, ['etag' => $action->etag, 'status' => 'success', 'last_error' => null]);
			}
			$this->report->uploadSucceeded($path, $action->document);
		} elseif (!$this->dryRun) {
			$this->state->deleteImport($this->config->targetUser, $path);
		}
		$this->report->importResult(SyncReport::IMPORTED, $path, $action->document);
		++$this->report->importsSucceeded;
	}

	/**
	 * Gives the document of an upload its owner, the user of Paperless of the user of Nextcloud whose
	 * inbox held the file, as Paperless makes the account that uploads a file its owner. Only the
	 * document that the task of the upload names is the upload's: another document with the content
	 * of the file, which a search found, may be someone else's, so it stays as it is, and so does a
	 * document that Paperless didn't name, which the report names as an error. When Paperless
	 * refuses the owner, the upload stays pending, so that the next run asks again.
	 */
	private function giveOwner(ImportSucceeded $action): void {
		if ($action->document === null || !$action->uploaded) {
			$this->report->error("Import {$action->context->path}: Paperless did not name the document that the upload created, so its owner stays as Paperless has it", null, $action->context->path);
			return;
		}
		$this->paperless->setOwner($action->document, (int)$this->owner);
	}

	/**
	 * The import of a file of the inbox created no document, so it moves to the error folder, next to
	 * a text that says why; its entry in the activity stream gives the reason alone.
	 */
	private function failed(ImportFailed $action): void {
		$path = $action->context->path;
		if (!$this->dryRun) {
			$this->storage->move($this->config->targetUser, $path, $action->destination, $this->config->conflictMode);
			$this->storage->writeText($this->config->targetUser, $action->destination . '.error.txt', $action->diagnostic);
			$this->state->deleteImport($this->config->targetUser, $path);
			$this->prune($path);
		}
		$this->report->uploadFailed($path, $action->destination, $action->reason);
		$this->report->importResult(SyncReport::IMPORT_FAILED, $action->destination, null, $action->reason);
		++$this->report->importsFailed;
		if ($action->unfinished !== '' && !$this->dryRun) {
			$this->logger->warning('Paperless has not finished the import of the inbox file {path} a day after its upload; it moves to the error folder, as Paperless may still import it: {error}', [
				'path' => $path,
				'error' => $action->unfinished,
				'task' => $action->taskId,
			]);
		}
	}

	private function retryUnfinished(RetryUnfinished $action): void {
		$path = $action->context->path;
		if ($this->dryRun) {
			$this->report->error("Import {$path}: {$action->error}; a run uploads it again", null, $path);
			return;
		}
		$minutes = $this->retryLater($path, $action->etag, $action->attempts, $action->error);
		$this->logger->warning('Paperless has not finished the import of the inbox file {path} a day after its upload; it is uploaded again in {minutes} minutes: {error}', [
			'path' => $path,
			'error' => $action->error,
			'task' => $action->taskId,
			'minutes' => $minutes,
		]);
	}

	/**
	 * Saves that a file of the inbox waits for its next upload, twice as long after each failure up
	 * to a day, and reports why. The answer is the wait in minutes.
	 */
	private function retryLater(string $path, string $etag, int $attempts, string $error): int {
		$delay = min(self::RETRY_DELAY << min($attempts - 1, 10), self::MAX_RETRY_DELAY);
		$minutes = intdiv($delay, 60);
		$now = $this->now();
		$this->state->saveImport($this->config->targetUser, $path, [
			'etag' => $etag,
			'task_id' => '',
			'status' => 'retry',
			'submitted_at' => $now,
			'attempts' => $attempts,
			'retry_at' => $now + $delay,
			'last_error' => SyncErrors::stored($error),
		]);
		$this->report->error("Import {$path}: {$error} (attempt {$attempts}, the next in {$minutes} minutes)", null, $path);

		return $minutes;
	}

	/**
	 * Ends the upload of a file whose task ended; a failure of the end, such as of the move to the
	 * error folder, keeps the upload pending, as a failure of the check does.
	 *
	 * @param \Closure(): void $end
	 */
	private function checked(ImportContext $context, \Closure $end): void {
		try {
			$end();
		} catch (\Throwable $exception) {
			$this->checkFailed($context, $exception);
		}
	}

	/** The upload stays pending, with its error, so that a lasting failure is logged once. */
	private function checkFailed(ImportContext $context, \Throwable $exception): void {
		$path = $context->path;
		$error = SyncErrors::message($exception);
		$this->report->error("Import task {$path}: {$error}", null, $path);
		if (SyncErrors::changed($context->pending, $error)) {
			// Without the exception, as for the error of a document.
			$this->logger->warning('Paperless Sync could not check the import of the inbox file {path}: {error}', [
				'path' => $path,
				'error' => $error,
			]);
		}
		if (!$this->dryRun) {
			$this->state->saveImport($this->config->targetUser, $path, ['last_error' => SyncErrors::stored($error)]);
		}
	}

	/** Removes the folders of the inbox that a file left empty, when the settings say so. */
	private function prune(string $path): void {
		if ($this->config->pruneEmptyFolders) {
			$this->report->foldersPruned += $this->storage->pruneEmptyParents($this->config->targetUser, $path, $this->inboxRoot);
		}
	}

	private function now(): int {
		return $this->clock->getTime();
	}
}
