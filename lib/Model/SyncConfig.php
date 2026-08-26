<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

final class SyncConfig implements \JsonSerializable {
	public function __construct(
		public readonly string $paperlessUrl,
		public readonly bool $tokenConfigured,
		public readonly bool $enabled,
		public readonly string $targetUser,
		public readonly string $basePath,
		public readonly string $archiveFolder,
		public readonly string $inboxFolder,
		public readonly string $errorFolder,
		public readonly string $deletedFolder,
		public readonly string $pathTemplate,
		public readonly bool $exportEnabled,
		public readonly bool $inboxEnabled,
		public readonly bool $preferArchive,
		public readonly bool $skipInbox,
		public readonly string $excludedTags,
		public readonly int $syncIntervalMinutes,
		public readonly int $batchSize,
		public readonly string $trashMode,
		public readonly bool $permanentDelete,
		public readonly bool $allowDirectDelete,
		public readonly int $missingGraceRuns,
		public readonly bool $pruneEmptyFolders,
		public readonly bool $deleteInboxAfterSuccess,
		public readonly bool $recursiveInbox,
		public readonly string $conflictMode,
		public readonly string $emptyCorrespondent,
		public readonly string $emptyDocumentType,
		public readonly string $emptyDate,
		public readonly string $untitled,
	) {
	}

	/** @return array<string, bool|int|string> */
	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
