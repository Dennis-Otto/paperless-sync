<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

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
use RuntimeException;

/**
 * What the export of a run does with each document, the same in a dry-run and in a run: which copy
 * it writes, moves, moves to the deleted folder or deletes, which documents stay as they are, which
 * wait for a later run, and what the states of the documents keep. It reads Paperless and the
 * archive, but it changes neither, nor the states: ExportExecutor applies its plan, or reports it
 * in a dry-run.
 *
 * The plan is lazy. It plans each document once the executor has applied the actions of the one
 * before, so that it sees the batch as it stands: an export that failed leaves its place to the
 * next document, and after Nextcloud refused a write for the whole archive the others wait. When
 * the batch is full, a document with a new path waits without a question to Paperless or a read of
 * its copy; one that keeps its path is still asked once for the checksum of its revision, which its
 * state keeps for a later run.
 */
final class ExportPlanner {
	/** @var array<string, true> the paths that the states give to more than one document */
	private array $sharedPaths;
	/** @var array<int, true> the documents that Paperless listed, active or in its trash */
	private array $seen = [];
	/** The exported documents that the plan counts as missing without having seen them in the trash. */
	private int $missingActive = 0;
	/**
	 * The questions to Paperless that confirm a missing scan, for copies to move or delete and for
	 * states alone, each at most the batch size per run.
	 *
	 * @var array{copy: int, state: int}
	 */
	private array $questions = ['copy' => 0, 'state' => 0];
	/** Whether a question to Paperless failed, which leaves the other missing documents for a later run. */
	private bool $unanswered = false;

	/**
	 * @param \Closure(array<string, mixed>): string $pathOf the path of a document below the archive folder, from the path template
	 * @param \Closure(): void $heartbeat the sign of life of the run, for each document that the plan reaches
	 */
	public function __construct(
		private SyncConfig $config,
		private ExportStates $states,
		private SyncBatch $batch,
		private TargetFiles $files,
		private PaperlessClientInterface $paperless,
		private \Closure $pathOf,
		private ExportFilter $filter,
		private UserTimeZones $timeZones,
		private ITimeFactory $clock,
		private string $archiveRoot,
		private \Closure $heartbeat,
	) {
		$this->sharedPaths = $this->sharedPaths();
	}

	/**
	 * The actions of the export, in their order: those of the active documents, then those of the
	 * documents in the trash, then those of the documents that Paperless no longer lists. Without
	 * $trash, the plan of one document, which knows neither the trash nor which documents are gone.
	 *
	 * @param list<array<string, mixed>> $documents the active documents
	 * @param list<array<string, mixed>>|null $trash the documents in the trash, or null for the plan of one document
	 * @param bool $complete whether both lists are complete: a document that an incomplete list left out may still exist, so the plan counts none as missing then
	 * @return \Generator<int, ExportAction, mixed, void>
	 * @throws RuntimeException for a document without a valid ID
	 */
	public function plan(array $documents, ?array $trash, bool $complete): \Generator {
		foreach ($this->queue($documents) as [$document, $documentId, $entry]) {
			($this->heartbeat)();
			$this->seen[$documentId] = true;
			yield from $this->active($document, $documentId, $entry);
		}
		if ($trash === null) {
			return;
		}
		foreach ($trash as $document) {
			($this->heartbeat)();
			$documentId = $this->documentId($document);
			$this->seen[$documentId] = true;
			$entry = $this->states->find($documentId);
			if ($entry !== null) {
				yield from $this->trashed($document, $documentId, $entry);
			}
		}
		foreach ($this->states->all() as $documentId => $entry) {
			($this->heartbeat)();
			yield from $this->missing($documentId, $entry, $complete);
		}
	}

	/** The exported documents that the plan counted as missing, without having seen them in the trash. */
	public function missingActive(): int {
		return $this->missingActive;
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
	private function queue(array $documents): array {
		$queue = [];
		$failed = [];
		foreach ($documents as $document) {
			$documentId = $this->documentId($document);
			$entry = $this->states->find($documentId);
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

	/**
	 * An active document: its copy stays, or it is exported or moved to the path of the template.
	 *
	 * @param array<string, mixed> $document
	 * @param array<string, int|string|null>|null $entry
	 * @return list<ExportAction>
	 */
	private function active(array $document, int $documentId, ?array $entry): array {
		if ($this->filter->excludes($document)) {
			return [new Skipped(), ...$this->excluded($documentId, $entry)];
		}
		$target = '';
		// The checksum and the fingerprint that the run learned of a changed document, written with
		// the next save of its state, so a document that finishes unchanged costs one write.
		$checksum = [];
		$attempt = false;
		try {
			$target = Paths::join($this->archiveRoot, ($this->pathOf)($document));
			$hasArchive = is_string($document['archived_file_name'] ?? null) && $document['archived_file_name'] !== '';
			$useOriginal = !$this->config->preferArchive || !$hasArchive;
			$sourceRevision = ($useOriginal ? 'original:' : 'archive:') . $this->scalarString($document['modified'] ?? null);
			$oldPathValue = $entry !== null ? ($entry['path'] ?? null) : null;
			$fingerprintValue = $entry !== null ? ($entry['fingerprint'] ?? null) : null;
			$oldPath = is_string($oldPathValue) ? $oldPathValue : '';
			$fingerprint = is_string($fingerprintValue) ? $fingerprintValue : '';
			// The batch is full when the run has tried as many exports and moves, or made as many
			// changes, as the batch size allows, or Nextcloud refused a write for the whole archive.
			$batchFull = !$this->batch->fits(SyncBatch::EXPORT);
			// The mirrored file has the content of the document while its revision is the same, and
			// after a change while Paperless reports one of its checksums, MD5 or SHA-256.
			$sameContent = false;
			if ($entry !== null && $fingerprint !== '' && $fingerprint !== DocumentFingerprint::PENDING) {
				$sameContent = ($entry['source_revision'] ?? '') === $sourceRevision;
				if (!$sameContent) {
					// A document with a new path changes, whatever its content, so it waits for a
					// later run when the batch is full, and so does the question for its checksum.
					if ($batchFull && $oldPath !== $target) {
						return [new Skipped()];
					}
					// Paperless reads the checksum from the files of the document, so the state keeps it
					// with its revision, for a document that waits for a later run or whose change fails.
					$known = $this->knownChecksum($entry, $sourceRevision);
					if ($known === null) {
						$known = $this->paperless->documentChecksum($documentId, $useOriginal);
						$checksum = ['remote_checksum' => $known, 'remote_revision' => $sourceRevision];
					}
					$fingerprint = $this->completedFingerprint($oldPath, $fingerprint, $known);
					// The completed fingerprint names the content of the mirrored file, whatever the run does
					// next, so a move that waits for the next run doesn't read the file once more.
					$checksum['fingerprint'] = $fingerprint;
					$sameContent = DocumentFingerprint::matches($fingerprint, $known);
				}
			}
			$context = DocumentContext::export($documentId, $entry, $target);
			// The copy at the path of the document has its content, whatever the state of the
			// document, such as one that Paperless restored from its trash while the keep policy
			// left the copy in place: only the state changes, and only what changed is written.
			if ($sameContent && $oldPath === $target && $this->files->exists($target)) {
				return [new Unchanged($documentId, $checksum + [
					'fingerprint' => $fingerprint,
					'source_revision' => $sourceRevision,
					'state' => 'active',
					'missing_runs' => 0,
					'trash_date' => null,
					'last_error' => null,
				], $context, new Copy($document, $target, $fingerprint, $useOriginal))];
			}
			if ($batchFull) {
				return [new Skipped($documentId, $checksum, $context)];
			}
			// From here, the export has its place in the batch, also when a conflict stops it.
			$attempt = true;
			// When conflicts are skipped, a file at the path stays, unless it is the app's own copy,
			// so the download or the move would be in vain. A copy that the user changed is no
			// longer the app's own.
			$ownCopy = false;
			if ($this->config->conflictMode === 'skip' && $this->files->exists($target)) {
				if (!$this->isOwnCopy($oldPath, $target, $fingerprint)) {
					throw new RuntimeException("Nextcloud file conflict at {$target}.");
				}
				$ownCopy = true;
			}
			// A file that another document shares is not moved, because it may have the content of
			// the other one.
			$canMove = $oldPath !== ''
				&& $oldPath !== $target
				&& !isset($this->sharedPaths[$oldPath])
				&& $this->files->exists($oldPath);
			// A copy that moves and then gets the new content in place has to be the app's own
			// as well, or the edit of someone in Nextcloud would be lost: it stays at its path.
			if ($this->config->conflictMode === 'skip' && $canMove && !$sameContent
				&& !$this->isOwnCopy($oldPath, $oldPath, $fingerprint)) {
				throw new RuntimeException("Nextcloud file conflict at {$oldPath}.");
			}

			// The file at the path is the app's own after a move or the check of a conflict.
			return [new ExportCopy($context, $document, $target, $oldPath, $useOriginal, $sourceRevision, $fingerprint, $checksum, $canMove, !($sameContent && $canMove), $canMove || $ownCopy)];
		} catch (\Throwable $exception) {
			return [new Failed(DocumentContext::export($documentId, $entry, $target), $exception, $checksum, $attempt)];
		}
	}

	/**
	 * A document with an excluded tag: its copy is removed, and its state is forgotten. When the
	 * batch is full, both wait for a later run.
	 *
	 * @param array<string, int|string|null>|null $entry
	 * @return list<ExportAction>
	 */
	private function excluded(int $documentId, ?array $entry): array {
		if ($entry === null) {
			return [];
		}
		$context = DocumentContext::excluded($documentId, $entry);
		try {
			$path = (string)($entry['path'] ?? '');
			if (!$this->files->exists($path)) {
				return [new ForgetState($documentId, $context)];
			}

			return $this->batch->fits(SyncBatch::DELETE) ? [new RemoveCopy($context, $path, SyncReport::TYPE_REMOVE_EXCLUDED)] : [];
		} catch (\Throwable $exception) {
			return [new Failed($context, $exception)];
		}
	}

	/**
	 * A document in the trash of Paperless. Only a copy that is still there moves and counts, as for
	 * missing documents. The keep policy and a copy that is gone, or was never exported, only mark
	 * the document.
	 *
	 * @param array<string, mixed> $document
	 * @param array<string, int|string|null> $entry
	 * @return list<ExportAction>
	 */
	private function trashed(array $document, int $documentId, array $entry): array {
		$context = DocumentContext::trash($documentId, $entry);
		try {
			$oldPath = (string)($entry['path'] ?? '');
			$exists = $this->files->exists($oldPath);
			if (($entry['state'] ?? '') === 'trash' && $exists) {
				return [new Unchanged($documentId, ['missing_runs' => 0, 'last_error' => null], $context)];
			}
			$deletedAt = $this->timestamp($document['deleted_at'] ?? null);
			$values = ['state' => 'trash', 'missing_runs' => 0, 'last_seen' => $this->now(), 'trash_date' => $deletedAt, 'last_error' => null];
			if ($this->config->trashMode === 'move' && $exists) {
				return $this->batch->fits(SyncBatch::WRITE)
					? [new MoveToDeleted($context, $oldPath, $this->deletedPath($oldPath, $deletedAt), SyncReport::TYPE_TRASH, $values)]
					: [new Skipped()];
			}

			return [new SaveState($documentId, $values, $context)];
		} catch (\Throwable $exception) {
			return [new Failed($context, $exception)];
		}
	}

	/**
	 * An exported document. Paperless listed it, so its count of missing scans starts anew, also
	 * when its export failed or waits for the next run; or the plan counts it as missing.
	 *
	 * Pages that shift while a scan reads them, as when one document is deleted and another one is
	 * added, keep the count of Paperless and may leave out a document that still exists, so a
	 * complete scan is not proof enough (decision 0004). Before a missing scan counts, the plan
	 * asks Paperless for the document itself: when Paperless has it, its count starts anew as if
	 * the scan had listed it; when Paperless answers with an error, it counts nothing in this run
	 * and the report names it. A scan that would change nothing costs no question (confirms()).
	 * The questions stop after the first error, and at the batch size, once for copies to move or
	 * delete and once for states alone, so that a mass vanish or a
	 * Paperless that hangs keeps a run short; the documents left over count nothing in this run.
	 *
	 * @param array<string, int|string|null> $entry
	 * @return list<ExportAction>
	 */
	private function missing(int $documentId, array $entry, bool $complete): array {
		if (isset($this->seen[$documentId])) {
			return self::listed($documentId, $entry);
		}
		if ($documentId < 1 || !$complete) {
			return [];
		}
		$context = DocumentContext::missing($documentId, $entry);
		try {
			$action = $this->missingScan($documentId, $entry, $context);
		} catch (\Throwable $exception) {
			return [new Failed($context, $exception)];
		}
		if (self::confirms($action, $entry)) {
			// After a failed question, or past the batch size, a document counts nothing in this
			// run, as one that waits for room in the batch. A change of a state alone takes no
			// place in the batch, so its questions have a budget of their own.
			$kind = $action instanceof MoveToDeleted || $action instanceof RemoveCopy ? 'copy' : 'state';
			if ($this->unanswered || $this->questions[$kind] >= $this->config->batchSize) {
				return [];
			}
			++$this->questions[$kind];
			try {
				// Paperless answers 404 for a document that it no longer shows the account; any
				// other answer than the document throws.
				if ($this->paperless->document($documentId) !== null) {
					return self::listed($documentId, $entry);
				}
			} catch (\Throwable $exception) {
				$this->unanswered = true;

				return [new Failed($context, $exception)];
			}
		}
		if (($entry['state'] ?? 'active') === 'active') {
			++$this->missingActive;
		}

		return [$action];
	}

	/**
	 * What one more missing scan does with an exported document that Paperless no longer lists.
	 *
	 * Only a copy that is still there is moved or deleted, and only that counts as a change, also
	 * when it fails. When the batch is full, or Nextcloud cannot store files in the archive, the
	 * copy and its state stay as they are, so the next run moves or deletes it.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	private function missingScan(int $documentId, array $entry, DocumentContext $context): ExportAction {
		$missingRuns = (int)($entry['missing_runs'] ?? 0) + 1;
		$state = (string)($entry['state'] ?? 'active');
		$path = (string)($entry['path'] ?? '');
		if ($missingRuns < $this->config->missingGraceRuns) {
			return new WaitForMissing($documentId, $path, $missingRuns, $this->config->missingGraceRuns);
		}
		if ($this->config->permanentDelete && ($state === 'trash' || $this->config->allowDirectDelete)) {
			if (!$this->files->exists($path)) {
				return new ForgetState($documentId, $context);
			}

			return $this->batch->fits(SyncBatch::DELETE) ? new RemoveCopy($context, $path, SyncReport::TYPE_DELETE) : new Skipped();
		}
		$values = ['state' => 'missing', 'missing_runs' => $missingRuns, 'last_error' => null];
		if ($state === 'active' && $this->config->trashMode === 'move' && $this->files->exists($path)) {
			return $this->batch->fits(SyncBatch::WRITE)
				? new MoveToDeleted($context, $path, $this->deletedPath($path, $this->now()), SyncReport::TYPE_MISSING, $values)
				: new Skipped();
		}

		return new SaveState($documentId, $values, $context);
	}

	/**
	 * Whether Paperless confirms a missing scan before it counts. A document whose move or deletion
	 * waits for room in the batch counts nothing in this run, and one that is marked missing already
	 * only counts on, as its copy has moved or stays where it is, until a deletion would remove the
	 * copy or forget the state; neither costs a request to Paperless.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	private static function confirms(ExportAction $action, array $entry): bool {
		return match (true) {
			$action instanceof Skipped => false,
			($entry['state'] ?? 'active') === 'missing' => $action instanceof RemoveCopy || $action instanceof ForgetState,
			default => true,
		};
	}

	/**
	 * A document that Paperless has: its count of missing scans starts anew.
	 *
	 * @param array<string, int|string|null> $entry
	 * @return list<ExportAction>
	 */
	private static function listed(int $documentId, array $entry): array {
		return (int)($entry['missing_runs'] ?? 0) > 0 ? [new SaveState($documentId, ['missing_runs' => 0])] : [];
	}

	/**
	 * The stored fingerprint, completed with the MD5 that Paperless-ngx 2 reports when an earlier
	 * version saved the SHA-256 alone. The mirrored file still has the content of that download when
	 * its SHA-256 is the saved one, so its checksums take the place of the download's, and the first
	 * change of the metadata after the update moves the file instead of downloading the document
	 * once more. A file that can't be read leaves the fingerprint as it is, and the download replaces it.
	 */
	private function completedFingerprint(string $path, string $fingerprint, string $checksum): string {
		if (str_contains($fingerprint, ' ') || strlen($checksum) !== 32) {
			return $fingerprint;
		}
		$mirrored = $this->files->fingerprint($path);

		return $mirrored !== null && $mirrored->sha256 === $fingerprint ? $mirrored->value() : $fingerprint;
	}

	/**
	 * Whether the file at a path is the copy of a document that the app wrote: the state of the
	 * document records the path, and the file still has the content of the saved fingerprint, or
	 * the app was writing it when its last write failed. A copy that the user changed, and a file
	 * that can't be read, count as files of the user.
	 */
	private function isOwnCopy(string $recordedPath, string $path, string $fingerprint): bool {
		if ($recordedPath !== $path) {
			return false;
		}
		if ($fingerprint === DocumentFingerprint::PENDING) {
			return true;
		}
		$mirrored = $this->files->fingerprint($path);

		return $mirrored !== null && DocumentFingerprint::matches($fingerprint, $mirrored->sha256);
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

	/**
	 * The paths that the states give to more than one document. Older versions could cut the
	 * marker [P123] off two long names, and then both documents wrote to one file, which keeps
	 * the content of the one written last. Such a file is not moved to either of them; each
	 * document is downloaded again.
	 *
	 * @return array<string, true>
	 */
	private function sharedPaths(): array {
		$counts = [];
		foreach ($this->states->all() as $entry) {
			$path = $entry['path'] ?? null;
			if (is_string($path) && $path !== '') {
				$counts[$path] = ($counts[$path] ?? 0) + 1;
			}
		}

		return array_fill_keys(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)), true);
	}

	/**
	 * Where a copy goes when its document went to the trash or vanished: below the deleted folder, in
	 * the folder of the day, in the time zone of the target user, from the personal settings or else
	 * the default of Nextcloud. In UTC, a document deleted in Berlin shortly after midnight would
	 * land in the folder of the day before.
	 */
	private function deletedPath(string $oldPath, int $timestamp): string {
		$relative = Paths::relativeTo($oldPath, $this->archiveRoot);
		$timestamp = $timestamp > 0 ? $timestamp : $this->now();
		$day = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timeZones->of($this->config->targetUser))->format('Y-m-d');

		return Paths::join($this->archiveRoot, $this->config->deletedFolder, $day, $relative);
	}

	/**
	 * @param array<string, mixed> $document
	 * @throws RuntimeException for a document without a valid ID
	 */
	private function documentId(array $document): int {
		if (!isset($document['id']) || !is_numeric($document['id']) || (int)$document['id'] < 1) {
			throw new RuntimeException('Paperless returned a document without a valid ID.');
		}

		return (int)$document['id'];
	}

	private function timestamp(mixed $value): int {
		if (!is_string($value) || $value === '') {
			return $this->now();
		}
		$timestamp = strtotime($value);

		return $timestamp !== false ? $timestamp : $this->now();
	}

	private function scalarString(mixed $value): string {
		return is_scalar($value) ? (string)$value : '';
	}

	/** The current time, from the clock of Nextcloud, which the tests set. */
	private function now(): int {
		return $this->clock->getTime();
	}
}
