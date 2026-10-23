<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * The document of an action, and what a failure of the action does: the line of the report, the
 * path that the log names, whether the state keeps when the run last saw the document, and whether
 * the failure may stop the writes of the run, as a full quota does.
 */
final class DocumentContext {
	/**
	 * @param ''|'Excluded '|'Reassigned '|'Trash '|'Missing ' $label the start of the line of the report, such as "Trash " for "Trash P123: …", which ExportExecutor::ERRORS translates
	 * @param array<string, int|string|null>|null $entry the state of the document before the run, whose error is logged only when it changes
	 * @param string $reportPath the file that the line of the report names, or '' for none
	 */
	private function __construct(
		public readonly int $document,
		public readonly string $label,
		public readonly ?array $entry,
		public readonly string $reportPath,
		public readonly string $logPath,
		public readonly bool $seen,
		public readonly bool $stopsWrites,
	) {
	}

	/**
	 * A document with an excluded tag, whose copy the run removes. A removal needs no space, so its
	 * failure stops no writes.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	public static function excluded(int $document, array $entry): self {
		$path = (string)($entry['path'] ?? '');

		return new self($document, 'Excluded ', $entry, $path, $path, true, false);
	}

	/**
	 * A document that belongs to another user of Nextcloud now, in per-user ownership, whose copy
	 * the run removes. A removal needs no space, so its failure stops no writes.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	public static function reassigned(int $document, array $entry): self {
		$path = (string)($entry['path'] ?? '');

		return new self($document, 'Reassigned ', $entry, $path, $path, true, false);
	}

	/**
	 * An active document, which the run exports or moves to $target, or '' before the run knows its path.
	 *
	 * @param array<string, int|string|null>|null $entry
	 */
	public static function export(int $document, ?array $entry, string $target): self {
		return new self($document, '', $entry, '', $target !== '' ? $target : (string)($entry['path'] ?? ''), true, true);
	}

	/**
	 * A document in the trash of Paperless.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	public static function trash(int $document, array $entry): self {
		return new self($document, 'Trash ', $entry, '', (string)($entry['path'] ?? ''), false, true);
	}

	/**
	 * A document that Paperless no longer lists.
	 *
	 * @param array<string, int|string|null> $entry
	 */
	public static function missing(int $document, array $entry): self {
		$path = (string)($entry['path'] ?? '');

		return new self($document, 'Missing ', $entry, $path, $path, false, true);
	}
}
