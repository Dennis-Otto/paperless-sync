<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

use OCA\PaperlessSync\Model\SyncReport;

/**
 * The export of an active document to its path, or the move of its copy there. The copy keeps its
 * identity in Nextcloud, its file ID with its shares, comments, tags, favorites and versions: it
 * moves to its new path, and a new content replaces its content in place.
 */
final class ExportCopy implements ExportAction {
	/**
	 * @param array<string, mixed> $document the document as Paperless lists it, which the metadata next to the archive describes
	 * @param string $oldPath the path of the copy that the state records, or '' for a new document
	 * @param string $fingerprint the fingerprint of the content of the copy, which a move keeps
	 * @param array<string, int|string|null> $checksum what the run learned of the content of the document, which the state keeps before the copy changes
	 * @param bool $move whether the copy moves from $oldPath to $target
	 * @param bool $download whether the run downloads the document, or its copy keeps its content
	 * @param bool $ownTarget whether the file at $target is the copy of the app, whose content the download replaces
	 */
	public function __construct(
		public readonly DocumentContext $context,
		public readonly array $document,
		public readonly string $target,
		public readonly string $oldPath,
		public readonly bool $useOriginal,
		public readonly string $sourceRevision,
		public readonly string $fingerprint,
		public readonly array $checksum,
		public readonly bool $move,
		public readonly bool $download,
		public readonly bool $ownTarget,
	) {
	}

	/** @return SyncReport::TYPE_EXPORT|SyncReport::TYPE_MOVE */
	public function type(): string {
		return $this->download ? SyncReport::TYPE_EXPORT : SyncReport::TYPE_MOVE;
	}

	/** Whether the run removes the copy at the old path after the export, as one that it doesn't move, such as a file that another document shares. */
	public function removesOldCopy(): bool {
		return !$this->move && $this->oldPath !== '' && $this->oldPath !== $this->target;
	}
}
