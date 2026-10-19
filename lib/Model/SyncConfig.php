<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

final class SyncConfig implements \JsonSerializable {
	/** The target user gets every document, as before per-user ownership. */
	public const SINGLE = 'single';
	/** Each user of Nextcloud gets the documents that their user of Paperless owns. */
	public const PER_USER = 'per-user';

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
		/**
		 * Whether a run writes what Paperless knows of each document next to the archive, in the
		 * folder .paperless of the archive: a sidecar P123.json for each copy and the index index.csv.
		 */
		public readonly bool $writeMetadata = false,
		/**
		 * The ID of the group of Nextcloud whose members may send files and folders to Paperless from
		 * the menu of Files, with the token of the app; '' turns the action off.
		 */
		public readonly string $sendGroup = 'admin',
		/**
		 * The names of the tags of Paperless whose documents the archive mirrors, separated by
		 * commas: a document needs one of them, whatever the case of its name; '' mirrors every
		 * document. Excluded tags and the inbox of Paperless still leave a document out.
		 */
		public readonly string $includedTags = '',
		/**
		 * Whether a run gives each copy of the archive the tags of its document as tags of Nextcloud,
		 * which the app makes visible to every user and assignable by administrators alone, and keeps
		 * them in step with Paperless.
		 */
		public readonly bool $nextcloudTags = false,
		/**
		 * Who gets the documents: single, the target user all of them, or per-user, each user of
		 * Nextcloud those that their user of Paperless owns, and the target user the others.
		 */
		public readonly string $ownership = self::SINGLE,
		/**
		 * The users of Paperless whose documents go to a user of Nextcloud with another ID, one on
		 * each line, as paperless-name = nextcloud-id; they win over the users with the same ID.
		 */
		public readonly string $userMap = '',
		/** The ID of the group of Nextcloud whose members alone get their documents in per-user ownership; '' for every user. */
		public readonly string $userGroup = '',
	) {
	}

	/** Whether each user of Nextcloud gets the documents that their user of Paperless owns. */
	public function perUser(): bool {
		return $this->ownership === self::PER_USER;
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

	/**
	 * The same settings for the archive of another user of Nextcloud, in per-user ownership: the
	 * folders below the same base folder of that user, whose files, states, activity and time zone
	 * a run then takes.
	 */
	public function withTargetUser(string $targetUser): self {
		$values = get_object_vars($this);
		$values['targetUser'] = $targetUser;

		return new self(...$values);
	}

	/** @return array<string, bool|int|string> */
	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
