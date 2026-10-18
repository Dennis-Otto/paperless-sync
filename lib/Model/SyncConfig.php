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
		/** Whether Nextcloud may connect to Paperless at an address of the local network, such as http://paperless:8000. */
		public readonly bool $paperlessLocalNetwork = false,
		/** Whether a file of the inbox gets a tag of Paperless for each folder below the inbox that holds it. */
		public readonly bool $inboxFolderTags = false,
		/** The names of the tags of Paperless that every file of the inbox gets, separated by commas. */
		public readonly string $inboxTags = '',
		/** Whether a run creates the tags that Paperless lacks for the files of the inbox. */
		public readonly bool $inboxCreateTags = false,
		/**
		 * Whether a call of the webhook of Paperless mirrors its document at once, while Paperless
		 * waits for the answer, or leaves it to the next background jobs.
		 */
		public readonly bool $webhookImmediate = true,
	) {
	}

	/** Whether a run can start: with the address of Paperless, its token and the target user. */
	public function complete(): bool {
		return $this->paperlessUrl !== '' && $this->tokenConfigured && $this->targetUser !== '';
	}

	/** The same settings with another batch size, for a run by hand that makes more or fewer changes. */
	public function withBatchSize(int $batchSize): self {
		$values = get_object_vars($this);
		$values['batchSize'] = $batchSize;

		return new self(...$values);
	}

	/** @return array<string, bool|int|string> */
	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
