<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/** @psalm-suppress PossiblyUnusedProperty */
final class SyncReport implements \JsonSerializable {
	/** The most lines of changes that the report lists; it counts those it leaves out in $omittedActions. */
	public const ACTION_LIMIT = 100;
	/** The most error messages that the report keeps; $errors counts them all. */
	public const ERROR_LIMIT = 50;

	/**
	 * The priority of a line by its start, 1 the highest. A full report gives up its last line of
	 * the lowest priority for a line of a higher one, so that errors, deletions and moves to the
	 * deleted folder never hide behind exports, moves and imports.
	 */
	private const PRIORITIES = [
		'ERROR: ' => 1,
		'DELETE ' => 2,
		'REMOVE EXCLUDED ' => 2,
		'TRASH ' => 3,
		'MISSING ' => 3,
		'IMPORT ERROR: ' => 4,
		'IMPORT REJECTED: ' => 4,
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
	public int $unchanged = 0;
	public int $skipped = 0;
	public int $errors = 0;
	/** The lines of changes that the report leaves out, for want of room. */
	public int $omittedActions = 0;

	/** @var list<string> */
	public array $actions = [];
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
	) {
	}

	/**
	 * A line for a change of the run. In a full report, the line takes the place of the last line
	 * of the lowest priority, when that is lower than its own, and the report counts the line that
	 * it leaves out.
	 */
	public function action(string $message): void {
		$priority = self::priority($message);
		if (count($this->actions) - $this->notices < self::ACTION_LIMIT) {
			$this->append($message, $priority);
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
			array_splice($this->priorities, $replaced, 1);
			$this->append($message, $priority);
		}
	}

	/**
	 * A line that the report keeps past the limit of its actions: a run that stopped its exports or
	 * counted no document as missing says so, also after a hundred other actions.
	 */
	public function notice(string $message): void {
		$this->append($message, self::NOTICE);
		++$this->notices;
	}

	public function error(string $message): void {
		++$this->errors;
		if (count($this->errorMessages) < self::ERROR_LIMIT) {
			$this->errorMessages[] = $message;
		}
		$this->action('ERROR: ' . $message);
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

	/** @return array<string, bool|int|list<string>> */
	public function jsonSerialize(): array {
		$data = get_object_vars($this);
		unset($data['priorities'], $data['notices'], $data['changedFiles'], $data['changedFileCount'], $data['importResults']);

		/** @var array<string, bool|int|list<string>> $data */
		return $data;
	}

	/** @return array<string, bool|int> */
	public function summary(): array {
		$data = $this->jsonSerialize();
		unset($data['actions'], $data['errorMessages']);

		/** @var array<string, bool|int> $data */
		return $data;
	}

	private function append(string $message, int $priority): void {
		$this->actions[] = $message;
		$this->priorities[] = $priority;
	}

	private static function priority(string $message): int {
		foreach (self::PRIORITIES as $start => $priority) {
			if (str_starts_with($message, $start)) {
				return $priority;
			}
		}

		return self::LOWEST;
	}
}
