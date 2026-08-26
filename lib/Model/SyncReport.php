<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/** @psalm-suppress PossiblyUnusedProperty */
final class SyncReport implements \JsonSerializable {
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

	/** @var list<string> */
	public array $actions = [];

	public function __construct(
		public readonly bool $dryRun,
		public readonly int $startedAt = 0,
		public int $completedAt = 0,
	) {
	}

	public function action(string $message): void {
		if (count($this->actions) < 100) {
			$this->actions[] = $message;
		}
	}

	public function error(string $message): void {
		++$this->errors;
		$this->action('ERROR: ' . $message);
	}

	/** @return array<string, bool|int|list<string>> */
	public function jsonSerialize(): array {
		return get_object_vars($this);
	}

	/** @return array<string, bool|int> */
	public function summary(): array {
		$data = $this->jsonSerialize();
		unset($data['actions']);

		/** @var array<string, bool|int> $data */
		return $data;
	}
}
