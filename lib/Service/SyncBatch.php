<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use LogicException;

/**
 * The places of a run for the changes of files, which a dry-run counts as a run does: a run makes
 * at most as many changes as its batch size, and a change takes its place before it is tried, so
 * that one that fails counts as well.
 *
 * - An export or a move of an active document is an attempt, and a change once it succeeded: a run
 *   that can't write downloads no more documents than its batch size, and documents whose export
 *   keeps failing leave the places of the trashed and the missing documents alone.
 * - A move to the deleted folder, and an upload from the inbox, are a change.
 * - A removal or a deletion is a change as well, and it still has a place after the writes
 *   stopped, as it needs no space.
 *
 * A planner asks whether an action fits before it plans it, and an executor takes its place; the
 * executor stops the writes when Nextcloud refuses them for the whole archive, or Paperless fails
 * for every upload.
 */
final class SyncBatch {
	/** An export or a move of an active document. */
	public const EXPORT = 'export';
	/** A move to the deleted folder, or an upload from the inbox. */
	public const WRITE = 'write';
	/** The removal of an excluded copy, or the deletion of the copy of a missing document. */
	public const DELETE = 'delete';

	private int $attempts = 0;
	private int $changes = 0;
	private bool $stopped = false;

	public function __construct(
		public readonly int $size,
	) {
	}

	/** @param self::EXPORT|self::WRITE|self::DELETE $kind */
	public function fits(string $kind): bool {
		return match ($kind) {
			self::EXPORT => $this->attempts < $this->size && $this->changes < $this->size && !$this->stopped,
			self::WRITE => $this->changes < $this->size && !$this->stopped,
			self::DELETE => $this->changes < $this->size,
		};
	}

	/**
	 * Takes the place of an action, before it is tried.
	 *
	 * @param self::EXPORT|self::WRITE|self::DELETE $kind
	 * @throws LogicException for an action that doesn't fit, which its planner should have left for a later run
	 */
	public function take(string $kind): void {
		if (!$this->fits($kind)) {
			throw new LogicException("The batch of {$this->size} has no place for another {$kind}.");
		}
		if ($kind === self::EXPORT) {
			++$this->attempts;
		} else {
			++$this->changes;
		}
	}

	/** An export or a move of an active document succeeded, so its attempt counts as a change. */
	public function succeeded(): void {
		++$this->changes;
	}

	/** The other exports and moves, and the other uploads, wait for the next run. */
	public function stop(): void {
		$this->stopped = true;
	}

	public function stopped(): bool {
		return $this->stopped;
	}
}
