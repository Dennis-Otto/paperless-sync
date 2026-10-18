<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

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
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What a run does with the files of the inbox, the same in a dry-run and in a run: first how the
 * uploads of earlier runs ended, by the tasks of Paperless, then which files go to Paperless. It
 * reads Paperless, the inbox and the states of the uploads, but it changes none of them: it
 * reaches the inbox through TargetFiles, which can't write, and ImportExecutor applies its plan,
 * or reports it in a dry-run.
 *
 * The plan is lazy, as that of the export: it plans each upload once the executor has applied
 * the one before, so that it sees the batch as it stands, and after a failure that may pass, such
 * as a Paperless that is down, the other files wait for the next run.
 */
final class ImportPlanner {
	/** The statuses of a task of Paperless that imported its file. */
	public const TASK_SUCCEEDED = ['SUCCESS', 'SUCCEEDED'];
	/** The statuses of a task that ended without a document; Paperless-ngx 3.x revokes a task that it cancels. */
	public const TASK_FAILED = ['FAILURE', 'FAILED', 'ERROR', 'REVOKED'];
	/** How long after its upload a task that Paperless has not finished, or doesn't list, may take, in seconds. */
	public const TASK_TIMEOUT = 86400;

	/** @param \Closure(): void $heartbeat the sign of life of the run, for each file that the plan may upload */
	public function __construct(
		private SyncConfig $config,
		private SyncStateRepositoryInterface $state,
		private SyncBatch $batch,
		private TargetFiles $files,
		private PaperlessClientInterface $paperless,
		private ITimeFactory $clock,
		private string $inboxRoot,
		private string $errorRoot,
		private \Closure $heartbeat,
	) {
	}

	/** @return \Generator<int, ImportAction, mixed, void> */
	public function plan(): \Generator {
		$byPath = [];
		foreach ($this->files->list($this->inboxRoot, $this->config->recursiveInbox) as $file) {
			$byPath[$file['path']] = $file;
		}
		/** @var array<string, int> $attempts the failed uploads of each file whose next attempt is due */
		$attempts = [];
		$now = $this->clock->getTime();

		foreach ($this->state->allImports($this->config->targetUser) as $pending) {
			$path = (string)($pending['path'] ?? '');
			$file = $byPath[$path] ?? null;
			if ($file === null) {
				yield new ForgetImport($path);
				continue;
			}
			$status = (string)($pending['status'] ?? '');
			$unchangedFile = ($pending['etag'] ?? '') === $file['etag'];
			if ($status === 'success' && $unchangedFile) {
				unset($byPath[$path]);
				yield new Unchanged();
				continue;
			}
			// A file that Paperless refused waits until it changes, and a file whose upload failed
			// for a reason that may pass waits until its next attempt is due.
			if ($status === 'retry' && $unchangedFile && (int)($pending['retry_at'] ?? 0) <= $now) {
				$attempts[$path] = (int)($pending['attempts'] ?? 0);
				continue;
			}
			if (($status === 'rejected' || $status === 'retry') && $unchangedFile) {
				unset($byPath[$path]);
				yield new Skipped();
				continue;
			}
			if ($status !== 'pending') {
				yield new ForgetImport($path);
				continue;
			}
			unset($byPath[$path]);
			yield from $this->checkTask(new ImportContext($path, $pending), $file['etag'], $unchangedFile, $now);
		}

		foreach ($byPath as $path => $file) {
			($this->heartbeat)();
			// Every upload takes its place in the batch, also one that fails. After a failure that may
			// pass, Paperless is down or overloaded, and the other files wait for the next run.
			if (!$this->batch->fits(SyncBatch::WRITE)) {
				yield new Skipped();
				continue;
			}
			yield new Upload($path, $file['name'], $file['etag'], $attempts[$path] ?? 0, $this->folderBelow($path));
		}
	}

	/**
	 * How the upload of a file of an earlier run ended, by the task of Paperless; a task that has
	 * not ended within its time stays pending.
	 *
	 * @return list<ImportAction>
	 */
	private function checkTask(ImportContext $context, string $etag, bool $unchangedFile, int $now): array {
		$pending = $context->pending;
		try {
			$taskId = (string)($pending['task_id'] ?? '');
			$task = $this->paperless->taskStatus($taskId);
			if (in_array($task['status'], self::TASK_SUCCEEDED, true)) {
				return [new ImportSucceeded($context, $etag, $unchangedFile, $task['documentId'])];
			}
			if (in_array($task['status'], self::TASK_FAILED, true)) {
				$reason = self::failureReason($task);

				return [new ImportFailed($context, $this->errorPath($context->path), $reason, $this->diagnostic($taskId, $reason))];
			}
			if ($now - (int)($pending['submitted_at'] ?? 0) >= self::TASK_TIMEOUT) {
				return [$this->endUnfinished($context, $etag, $unchangedFile, (int)($pending['attempts'] ?? 0) + 1, $taskId, $task)];
			}

			return [];
		} catch (\Throwable $exception) {
			return [new ImportCheckFailed($context, $exception)];
		}
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
	private function endUnfinished(ImportContext $context, string $etag, bool $unchangedFile, int $attempts, string $taskId, array $task): ImportAction {
		$content = $this->files->fingerprint($context->path);
		$documentId = $content !== null ? $this->paperless->findDocument($content) : null;
		if ($documentId !== null) {
			return new ImportSucceeded($context, $etag, true, $documentId);
		}
		$state = $task['status'] . ($task['message'] !== '' ? ": {$task['message']}" : '');
		$error = "Paperless has not finished the import task {$taskId} a day after the upload ({$state})";
		if (!$task['listed'] || !$unchangedFile) {
			return new RetryUnfinished($context, $etag, $attempts, $taskId, $error);
		}

		return new ImportFailed($context, $this->errorPath($context->path), "Paperless has not finished the import a day after the upload ({$state}), so it may still create the document.", $this->unfinishedDiagnostic($taskId, $state), $taskId, $error);
	}

	/**
	 * What Paperless said about the task of a failed import, for the .error.txt next to the file and
	 * for the entry in the activity stream.
	 *
	 * @param array{status: string, message: string, documentId: int|null, listed: bool} $task
	 */
	public static function failureReason(array $task): string {
		if ($task['status'] === 'REVOKED') {
			return 'Paperless cancelled the task before it finished (REVOKED), so it created no document' . ($task['message'] !== '' ? ': ' . $task['message'] : '.');
		}

		return $task['message'] !== '' ? $task['message'] : 'Paperless gave no reason; its log may have one.';
	}

	/**
	 * The text of the .error.txt next to a file whose import failed: the reason, and the ID of the
	 * task, by which the task list of Paperless shows more.
	 */
	private function diagnostic(string $taskId, string $reason): string {
		return "Paperless import failed\n\n{$reason}\n\nPaperless task: {$taskId}\n";
	}

	/** The text of the .error.txt next to a file whose task Paperless still lists, unfinished, a day after the upload. */
	private function unfinishedDiagnostic(string $taskId, string $state): string {
		return "Paperless import not finished\n\n"
			. "Paperless has not finished the import task a day after the upload ({$state}). Its worker may be down, or the task may still wait in its queue, so Paperless may still create the document. The file was not uploaded again, because Paperless-ngx 3.x imports a duplicate as a second document.\n\n"
			. "Should the document not appear in Paperless, check that the worker of Paperless runs, then move the file back into the inbox.\n\n"
			. "Paperless task: {$taskId}\n";
	}

	/** Where a file of the inbox goes when its import failed: the same path below the error folder. */
	private function errorPath(string $path): string {
		return Paths::join($this->errorRoot, Paths::relativeTo($path, $this->inboxRoot));
	}

	/** The path of the folder of a file of the inbox below the inbox, such as Taxes/2026, or '' for the inbox itself. */
	private function folderBelow(string $path): string {
		// The inbox folder is never empty, so its files are below $inboxRoot/.
		$relative = substr($path, strlen($this->inboxRoot) + 1);
		$slash = strrpos($relative, '/');

		return $slash === false ? '' : substr($relative, 0, $slash);
	}
}
