<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * @psalm-type ReportEntry = array{type: string, document?: int, path?: string, target?: string, detail?: string, values?: array<string, int|string>}
 * @psalm-suppress PossiblyUnusedProperty
 */
final class SyncReport implements \JsonSerializable {
	/** The most lines of changes that the report lists; it counts those it leaves out in $omittedActions. */
	public const ACTION_LIMIT = 100;
	/** The most error messages that the report keeps; $errors counts them all. */
	public const ERROR_LIMIT = 50;

	/** The copy of a document is written anew, at its path. */
	public const TYPE_EXPORT = 'export';
	/** The copy of a document moves to its new path, without a download. */
	public const TYPE_MOVE = 'move';
	/** The copy of a document that got an excluded tag is removed. */
	public const TYPE_REMOVE_EXCLUDED = 'remove-excluded';
	/** The copy of a document in the trash of Paperless moves to the deleted folder. */
	public const TYPE_TRASH = 'trash';
	/** The copy of a document that is gone from Paperless moves to the deleted folder. */
	public const TYPE_MISSING = 'missing';
	/** The copy of a document is deleted for good. */
	public const TYPE_DELETE = 'delete';
	/** A document is gone from Paperless, and its copy waits for more scans without it. */
	public const TYPE_WAIT = 'wait';
	/** A file of the inbox goes to Paperless. */
	public const TYPE_UPLOAD = 'import';
	/** Paperless imported a file of the inbox. */
	public const TYPE_UPLOAD_SUCCEEDED = 'import-success';
	/** Paperless could not import a file of the inbox, which moves to the error folder. */
	public const TYPE_UPLOAD_FAILED = 'import-error';
	/** Paperless refused a file of the inbox, which waits there until it changes. */
	public const TYPE_UPLOAD_REJECTED = 'import-rejected';
	/** A tag that the run creates in Paperless, for the folder of a file of the inbox. */
	public const TYPE_NEW_TAG = 'new-tag';
	/** The tags of Nextcloud of a copy follow the tags of its document in Paperless. */
	public const TYPE_TAGS = 'tags';
	/** An error of a single document or file. */
	public const TYPE_ERROR = 'error';
	/** A notice: a list of Paperless came back incomplete, so no document counts as missing. */
	public const TYPE_SCAN_INCOMPLETE = 'scan-incomplete';
	/** A notice: Nextcloud cannot store files in the archive, so the run stopped its exports. */
	public const TYPE_EXPORT_STOPPED = 'export-stopped';
	/** A notice of a dry-run: documents went missing while the trash of Paperless lists none. */
	public const TYPE_TRASH_EMPTY = 'trash-empty';
	/** A notice: Paperless lacks tags of the inbox, so its files went to Paperless without them. */
	public const TYPE_TAGS_UNKNOWN = 'tags-unknown';
	/** A notice: Nextcloud has tags with the names of tags of Paperless that the app did not make, so the copies don't get them. */
	public const TYPE_TAGS_TAKEN = 'tags-taken';
	/** A notice: Nextcloud let the run create no tag, so the copies get these tags with a later run. */
	public const TYPE_TAGS_NOT_CREATED = 'tags-not-created';

	/**
	 * The priority of a line by its type, 1 the highest. A full report gives up its last line of
	 * the lowest priority for a line of a higher one, so that errors, deletions and moves to the
	 * deleted folder never hide behind exports, moves and imports.
	 */
	private const PRIORITIES = [
		self::TYPE_ERROR => 1,
		self::TYPE_DELETE => 2,
		self::TYPE_REMOVE_EXCLUDED => 2,
		self::TYPE_TRASH => 3,
		self::TYPE_MISSING => 3,
		self::TYPE_UPLOAD_FAILED => 4,
		self::TYPE_UPLOAD_REJECTED => 4,
	];
	/** The priority of every other line: exports, moves, imports and documents that wait. */
	private const LOWEST = 5;
	/** The priority of a notice, which the report keeps past its limit and never gives up. */
	private const NOTICE = 0;
	/** The most files that the activity entry of a run names; it counts the others. */
	public const ACTIVITY_FILE_LIMIT = 5;
	/** A file of the inbox that Paperless imported. */
	public const IMPORTED = 'imported';
	/** A file of the inbox whose import failed in Paperless, now in the error folder. */
	public const IMPORT_FAILED = 'failed';
	/** A file of the inbox that Paperless refused, which waits in the inbox until it changes. */
	public const IMPORT_REJECTED = 'rejected';

	public int $activeDocuments = 0;
	public int $trashedDocuments = 0;
	public int $exported = 0;
	public int $moved = 0;
	public int $movedToTrash = 0;
	public int $permanentlyDeleted = 0;
	public int $removedExcluded = 0;
	public int $importsSubmitted = 0;
	public int $importsSucceeded = 0;
	public int $importsFailed = 0;
	public int $foldersPruned = 0;
	/** The copies whose tags of Nextcloud changed. */
	public int $tagged = 0;
	public int $unchanged = 0;
	public int $skipped = 0;
	public int $errors = 0;
	/** The lines of changes that the report leaves out, for want of room. */
	public int $omittedActions = 0;

	/** @var list<string> the lines of the changes, in English, as the log and the command line show them */
	public array $actions = [];
	/**
	 * The same changes, one for each line of $actions and in its order, for the settings page,
	 * which shows them in the language of its reader: the type of the change, the ID of the
	 * document in Paperless, the file in Nextcloud, relative to the home of the target user, the
	 * file in the error folder that a failed import went to, a detail, such as the reason of
	 * Paperless or an error message, and the numbers of a notice or of a document that waits.
	 *
	 * @var list<ReportEntry>
	 */
	public array $entries = [];
	/** @var list<string> the first errors of the run, without the lines of its changes */
	public array $errorMessages = [];

	/** @var list<int> the priority of each line of $actions */
	private array $priorities = [];
	private int $notices = 0;
	/** @var list<string> the first files that the run wrote or moved, which its activity entry names */
	private array $changedFiles = [];
	/** The files that the run wrote or moved, also those that its activity entry leaves out. */
	private int $changedFileCount = 0;
	/** @var list<array{outcome: self::IMPORT*, path: string, document: int|null, reason: string}> */
	private array $importResults = [];

	public function __construct(
		public readonly bool $dryRun,
		public readonly int $startedAt = 0,
		public int $completedAt = 0,
		/** Whether a dry-run used settings that were not saved, those of the settings page before a save. */
		public readonly bool $unsavedSettings = false,
	) {
	}

	/**
	 * A change of the copy of a document: an export, a move, the removal of an excluded copy, a
	 * move to the deleted folder or a deletion; $path is the file that the change concerns, at its
	 * new path after an export or a move.
	 *
	 * @param self::TYPE_EXPORT|self::TYPE_MOVE|self::TYPE_REMOVE_EXCLUDED|self::TYPE_TRASH|self::TYPE_MISSING|self::TYPE_DELETE $type
	 */
	public function change(string $type, int $document, string $path): void {
		$this->action(strtoupper(str_replace('-', ' ', $type)) . " P{$document}: {$path}", ['type' => $type, 'document' => $document, 'path' => $path]);
	}

	/** A document that is gone from Paperless, whose copy at $path waits until $scans reaches $limit. */
	public function waiting(int $document, string $path, int $scans, int $limit): void {
		$this->action("WAIT P{$document}: missing run {$scans}/{$limit}", [
			'type' => self::TYPE_WAIT,
			'document' => $document,
			'path' => $path,
			'values' => ['scans' => $scans, 'limit' => $limit],
		]);
	}

	/**
	 * A file of the inbox that goes to Paperless, with the names of the tags that it gets there.
	 *
	 * @param list<string> $tags
	 */
	public function upload(string $path, array $tags = []): void {
		$entry = ['type' => self::TYPE_UPLOAD, 'path' => $path];
		if ($tags !== []) {
			$entry['values'] = ['tags' => implode(', ', $tags)];
		}
		$this->action("IMPORT: {$path}" . ($tags !== [] ? ' (tags: ' . implode(', ', $tags) . ')' : ''), $entry);
	}

	/** A tag that the run creates in Paperless, for the folder of a file of the inbox. */
	public function newTag(string $name): void {
		$this->action("NEW TAG: {$name}", ['type' => self::TYPE_NEW_TAG, 'detail' => $name]);
	}

	/**
	 * The tags of Nextcloud that the copy of a document at $path gets and loses, by their names, as
	 * its document in Paperless has them.
	 *
	 * @param list<string> $added
	 * @param list<string> $removed
	 */
	public function tags(int $document, string $path, array $added, array $removed): void {
		++$this->tagged;
		$changes = array_merge(
			array_map(static fn (string $name): string => '+' . $name, $added),
			array_map(static fn (string $name): string => '-' . $name, $removed),
		);
		$entry = ['type' => self::TYPE_TAGS, 'document' => $document, 'path' => $path];
		$values = array_filter(['added' => implode(', ', $added), 'removed' => implode(', ', $removed)], static fn (string $names): bool => $names !== '');
		if ($values !== []) {
			$entry['values'] = $values;
		}
		$this->action("TAGS P{$document}: " . implode(' ', $changes), $entry);
	}

	/** A file of the inbox that Paperless imported, as the document $document when Paperless names it. */
	public function uploadSucceeded(string $path, ?int $document): void {
		$entry = ['type' => self::TYPE_UPLOAD_SUCCEEDED, 'path' => $path];
		if ($document !== null) {
			$entry['document'] = $document;
		}
		$this->action("IMPORT SUCCESS: {$path}" . ($document !== null ? " -> P{$document}" : ''), $entry);
	}

	/** A file of the inbox that Paperless could not import, for $reason, which moves to $destination in the error folder. */
	public function uploadFailed(string $path, string $destination, string $reason): void {
		$this->action("IMPORT ERROR: {$path} -> {$destination}", ['type' => self::TYPE_UPLOAD_FAILED, 'path' => $path, 'target' => $destination, 'detail' => $reason]);
	}

	/** A file of the inbox that Paperless refused, for $reason, which waits there until it changes. */
	public function uploadRejected(string $path, string $reason): void {
		$this->action("IMPORT REJECTED: {$path}: {$reason}", ['type' => self::TYPE_UPLOAD_REJECTED, 'path' => $path, 'detail' => $reason]);
	}

	/**
	 * A line that the report keeps past the limit of its actions: a run that stopped its exports or
	 * counted no document as missing says so, also after a hundred other actions. $message is the
	 * line in English, $values the numbers and names that the settings page puts into its
	 * translation.
	 *
	 * @param self::TYPE_SCAN_INCOMPLETE|self::TYPE_EXPORT_STOPPED|self::TYPE_TRASH_EMPTY|self::TYPE_TAGS_UNKNOWN|self::TYPE_TAGS_TAKEN|self::TYPE_TAGS_NOT_CREATED $type
	 * @param array<string, int|string> $values
	 */
	public function notice(string $type, string $message, array $values = []): void {
		$entry = ['type' => $type];
		if ($values !== []) {
			$entry['values'] = $values;
		}
		$this->append($message, self::NOTICE, $entry);
		++$this->notices;
	}

	/** An error of a single document or file, with the ID of the document in Paperless or the path of the file where they are known. */
	public function error(string $message, ?int $document = null, string $path = ''): void {
		++$this->errors;
		if (count($this->errorMessages) < self::ERROR_LIMIT) {
			$this->errorMessages[] = $message;
		}
		$entry = ['type' => self::TYPE_ERROR];
		if ($document !== null) {
			$entry['document'] = $document;
		}
		if ($path !== '') {
			$entry['path'] = $path;
		}
		$entry['detail'] = $message;
		$this->action('ERROR: ' . $message, $entry);
	}

	/**
	 * A file that the run wrote or moved, for its entry in the activity stream, which names the first
	 * files of a run and counts the others. A dry-run changes no file.
	 */
	public function changedFile(string $path): void {
		if ($this->dryRun) {
			return;
		}
		++$this->changedFileCount;
		if (count($this->changedFiles) < self::ACTIVITY_FILE_LIMIT) {
			$this->changedFiles[] = $path;
		}
	}

	/**
	 * The result of the import of a file of the inbox, for its own entry in the activity stream: the
	 * path of the file, in the error folder after a failure, the ID of its new document in Paperless,
	 * when Paperless names it, and the reason of a failure or a refusal.
	 *
	 * @param self::IMPORT* $outcome
	 */
	public function importResult(string $outcome, string $path, ?int $document = null, string $reason = ''): void {
		if (!$this->dryRun) {
			$this->importResults[] = ['outcome' => $outcome, 'path' => $path, 'document' => $document, 'reason' => $reason];
		}
	}

	/** @return list<string> */
	public function changedFiles(): array {
		return $this->changedFiles;
	}

	public function changedFileCount(): int {
		return $this->changedFileCount;
	}

	/** @return list<array{outcome: self::IMPORT*, path: string, document: int|null, reason: string}> */
	public function importResults(): array {
		return $this->importResults;
	}

	/** @return array<string, bool|int|list<string>|list<ReportEntry>> */
	public function jsonSerialize(): array {
		$data = get_object_vars($this);
		unset($data['priorities'], $data['notices'], $data['changedFiles'], $data['changedFileCount'], $data['importResults']);

		/** @var array<string, bool|int|list<string>|list<ReportEntry>> $data */
		return $data;
	}

	/** @return array<string, bool|int> */
	public function summary(): array {
		$data = $this->jsonSerialize();
		unset($data['actions'], $data['entries'], $data['errorMessages']);

		/** @var array<string, bool|int> $data */
		return $data;
	}

	/**
	 * A line and its entry. In a full report, the line takes the place of the last line of the
	 * lowest priority, when that is lower than its own, and the report counts the line that it
	 * leaves out.
	 *
	 * @param ReportEntry $entry
	 */
	private function action(string $message, array $entry): void {
		$priority = self::PRIORITIES[$entry['type']] ?? self::LOWEST;
		if (count($this->actions) - $this->notices < self::ACTION_LIMIT) {
			$this->append($message, $priority, $entry);
			return;
		}
		++$this->omittedActions;
		$replaced = null;
		$lowest = $priority;
		foreach ($this->priorities as $index => $candidate) {
			if ($candidate > $priority && $candidate >= $lowest) {
				$replaced = $index;
				$lowest = $candidate;
			}
		}
		if ($replaced !== null) {
			array_splice($this->actions, $replaced, 1);
			array_splice($this->entries, $replaced, 1);
			array_splice($this->priorities, $replaced, 1);
			$this->append($message, $priority, $entry);
		}
	}

	/** @param ReportEntry $entry */
	private function append(string $message, int $priority, array $entry): void {
		$this->actions[] = $message;
		$this->entries[] = $entry;
		$this->priorities[] = $priority;
	}
}
