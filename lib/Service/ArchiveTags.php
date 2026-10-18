<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagCreationForbiddenException;

/**
 * The tags of Paperless as tags of Nextcloud on the copies of one run, as the setting "Show
 * Paperless tags as Nextcloud tags" asks: the run names each copy that it exported, moved or found
 * unchanged at its path, and finish() gives each copy the tags of its document and takes from it
 * those that its document lost. So the tags follow every change in Paperless, also of a document
 * whose copy stays as it is, and a renamed tag of Paperless becomes a tag of its new name.
 *
 * A copy gets the tags that the app made, one for each name of a tag of Paperless, whatever its
 * case: visible to every user, so that Files filters and searches by them, and assignable by
 * administrators alone, so that they stay in step with Paperless. A tag that someone else made
 * stays theirs: a run never assigns, removes or deletes it, also one with the name of a tag of
 * Paperless, whose copies then go without it, which the report says. A tag of the app whose tag of
 * Paperless is gone, and which no file has any more, goes at the end of a run of the whole archive.
 *
 * A run changes the tags of at most as many copies as its batch size, as it makes at most as many
 * changes in a module: the first run after the setting is on, in an archive of many documents,
 * leaves the rest to the next runs. A dry-run reports the same changes and changes nothing.
 */
final class ArchiveTags {
	/** The most bytes of the name of a tag of Nextcloud, as many as its column holds. */
	public const NAME_BYTES = 64;
	/** The ID of a tag that a dry-run would create; Nextcloud gives no tag the ID 0. */
	private const PLANNED = 0;

	/** @var list<array{document: int, path: string, current: string, names: list<string>}> */
	private array $copies = [];
	/** @var array<int, string> the tags that the app made, with the name of the tag of Paperless of each, by their IDs */
	private array $managed = [];
	/** @var array<string, int> the ID of the tag of the app for each name in lower case, PLANNED for one that a dry-run would create */
	private array $ids = [];
	/** @var array<string, string> the names whose tags someone else made, in lower case */
	private array $taken = [];
	/** @var array<string, string> the names whose tags Nextcloud let the run not create, in lower case */
	private array $notCreated = [];
	/** @var array<string, string> the names whose tags failed to be found or created in this run, in lower case */
	private array $failed = [];
	private bool $changed = false;

	/** @param array<string, string> $tagNames the names of the tags of Paperless by their IDs */
	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $objectMapper,
		private NextcloudStorageInterface $storage,
		private SystemTagService $service,
		private SyncConfig $config,
		private SyncReport $report,
		private array $tagNames,
	) {
	}

	/**
	 * A copy that the run exported, moved or found unchanged at $path, with its document as
	 * Paperless lists it. $current is the path of the copy before the run: a dry-run moves none.
	 *
	 * @param array<string, mixed> $document
	 */
	public function copy(int $documentId, array $document, string $path, string $current): void {
		$this->copies[] = ['document' => $documentId, 'path' => $path, 'current' => $current, 'names' => $this->names($document['tags'] ?? null)];
	}

	/**
	 * Gives the copies of the run the tags of their documents, and, at the end of a run of the whole
	 * archive ($complete), deletes the tags of the app that no tag of Paperless and no file has any
	 * more. A failure of Nextcloud for one copy, such as for its file or for one tag that it would
	 * create, is an error of its document, once per name of a tag in a run, and the other copies get
	 * their tags; one for all of them, such as of its database when it asks which tags the files
	 * have, is an error of the run, whose copies stay as they are.
	 */
	public function finish(bool $complete): void {
		try {
			$this->apply();
			if ($complete && !$this->report->dryRun) {
				$this->deleteUnused();
			}
		} catch (\Throwable $exception) {
			$this->report->error('Tags: ' . SyncErrors::message($exception));
		} finally {
			if ($this->changed) {
				$this->service->remember($this->managed);
			}
		}
		$this->notices();
	}

	/**
	 * The name of a tag of Paperless as a tag of Nextcloud has it: without control characters, with
	 * single spaces and at most NAME_BYTES bytes, as Nextcloud keeps it.
	 */
	public static function normalize(string $name): string {
		$name = preg_replace('/\p{C}+/u', '', $name) ?? $name;
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

		return rtrim(mb_strcut($name, 0, self::NAME_BYTES, 'UTF-8'));
	}

	private function apply(): void {
		$this->managed = $this->service->managed();
		foreach ($this->managed as $id => $name) {
			$this->ids[self::key($name)] = $id;
		}
		$fileIds = [];
		$broken = [];
		foreach ($this->copies as $index => $copy) {
			try {
				$fileId = $this->storage->findFileId($this->config->targetUser, $copy['current']);
			} catch (\Throwable $exception) {
				$this->copyError($copy, $exception);
				$broken[$index] = true;
				continue;
			}
			if ($fileId !== null) {
				$fileIds[$index] = (string)$fileId;
			}
		}
		$assigned = $fileIds === [] ? [] : $this->objectMapper->getTagIdsForObjects(array_values(array_unique($fileIds)), SystemTagService::OBJECT_TYPE);
		$changes = 0;
		foreach ($this->copies as $index => $copy) {
			$fileId = $fileIds[$index] ?? null;
			// A copy that a run wrote is there; a dry-run finds no file for a copy that it would create.
			if (isset($broken[$index]) || ($fileId === null && !$this->report->dryRun)) {
				continue;
			}
			$wanted = [];
			foreach ($copy['names'] as $name) {
				try {
					$id = $this->tagFor($name);
				} catch (\Throwable $exception) {
					// The copy goes without that tag, which it doesn't have; the others of the run too.
					$this->failed[self::key($name)] = $name;
					$this->copyError($copy, $exception);
					continue;
				}
				if ($id !== null) {
					$wanted[$id === self::PLANNED ? 'planned:' . self::key($name) : $id] = $name;
				}
			}
			// The tags of the app that the file has; those of others stay as they are.
			$tagIds = $fileId !== null && isset($assigned[$fileId]) && is_array($assigned[$fileId]) ? $assigned[$fileId] : [];
			$has = array_values(array_filter(
				array_map('intval', $tagIds),
				fn (int $id): bool => isset($this->managed[$id]),
			));
			$added = array_diff_key($wanted, array_flip($has));
			$removed = array_values(array_filter($has, static fn (int $id): bool => !isset($wanted[$id])));
			if ($added === [] && $removed === []) {
				continue;
			}
			// The other copies wait for the next run, which finds their tags as they are.
			if ($changes >= $this->config->batchSize) {
				continue;
			}
			++$changes;
			$this->change($copy['document'], $copy['path'], $fileId, $added, $removed);
		}
	}

	/**
	 * Reports a failure of Nextcloud for one copy as an error of its document.
	 *
	 * @param array{document: int, path: string, current: string, names: list<string>} $copy
	 */
	private function copyError(array $copy, \Throwable $exception): void {
		$this->report->error("Tags P{$copy['document']}: " . SyncErrors::message($exception), $copy['document'], $copy['path']);
	}

	/**
	 * Assigns and removes the tags of one copy, and reports it.
	 *
	 * @param array<int|string, string> $added the names of the tags that the copy gets, by their IDs
	 * @param list<int> $removed the IDs of the tags of the app that the copy loses
	 */
	private function change(int $documentId, string $path, ?string $fileId, array $added, array $removed): void {
		try {
			if (!$this->report->dryRun && $fileId !== null) {
				if ($added !== []) {
					$this->objectMapper->assignTags($fileId, SystemTagService::OBJECT_TYPE, array_map('strval', array_keys($added)));
				}
				if ($removed !== []) {
					$this->objectMapper->unassignTags($fileId, SystemTagService::OBJECT_TYPE, array_map('strval', $removed));
				}
			}
			$this->report->tags($documentId, $path, array_values($added), array_map(fn (int $id): string => $this->managed[$id], $removed));
		} catch (\Throwable $exception) {
			$this->report->error("Tags P{$documentId}: " . SyncErrors::message($exception), $documentId, $path);
		}
	}

	/**
	 * The ID of the tag of the app for a name, which the run creates when Nextcloud has none of that
	 * name; null when someone else made a tag of the name, whatever its case, or when Nextcloud let
	 * the run create none. A dry-run creates no tag, and returns PLANNED for it.
	 */
	private function tagFor(string $name): ?int {
		$key = self::key($name);
		if (isset($this->ids[$key])) {
			return $this->ids[$key];
		}
		if (isset($this->taken[$key]) || isset($this->notCreated[$key]) || isset($this->failed[$key])) {
			return null;
		}
		// Nextcloud keeps one tag of a name, whatever its case, and lists those that contain it.
		foreach ($this->tagManager->getAllTags(null, $name) as $tag) {
			if (self::key(self::normalize($tag->getName())) === $key) {
				$this->taken[$key] = $name;

				return null;
			}
		}
		if ($this->report->dryRun) {
			return $this->ids[$key] = self::PLANNED;
		}
		try {
			$tag = $this->tagManager->createTag($name, true, false);
		} catch (TagAlreadyExistsException) {
			// Someone made a tag of the name since the question above.
			$this->taken[$key] = $name;

			return null;
		} catch (TagCreationForbiddenException) {
			// Nextcloud lets only a user or the command line create tags, not a request of Paperless
			// to the webhook or background jobs by AJAX or Webcron.
			$this->notCreated[$key] = $name;

			return null;
		}
		$id = (int)$tag->getId();
		$this->managed[$id] = $name;
		$this->changed = true;

		return $this->ids[$key] = $id;
	}

	/**
	 * Deletes the tags of the app whose tag of Paperless is gone, such as one renamed or deleted
	 * there, once no file has them, not even a copy in the deleted folder.
	 */
	private function deleteUnused(): void {
		$names = [];
		foreach ($this->tagNames as $name) {
			$names[self::key(self::normalize($name))] = true;
		}
		foreach ($this->managed as $id => $name) {
			if (isset($names[self::key($name)]) || $this->objectMapper->getObjectIdsForTags((string)$id, SystemTagService::OBJECT_TYPE, 1) !== []) {
				continue;
			}
			$this->tagManager->deleteTags((string)$id);
			unset($this->managed[$id]);
			$this->changed = true;
		}
	}

	/** The notices of the names whose copies went without their tags. */
	private function notices(): void {
		if ($this->taken !== []) {
			$names = implode(', ', $this->taken);
			$this->report->notice(SyncReport::TYPE_TAGS_TAKEN, "TAGS TAKEN: Nextcloud has tags named {$names} that Paperless Sync did not make, so the copies go without them. Rename the tag in Nextcloud or in Paperless.", [
				'names' => $names,
				'count' => count($this->taken),
			]);
		}
		if ($this->notCreated !== []) {
			$names = implode(', ', $this->notCreated);
			$this->report->notice(SyncReport::TYPE_TAGS_NOT_CREATED, "TAGS NOT CREATED: Nextcloud lets only users and the command line create tags, so the copies get the tags named {$names} with the next run of the background jobs by cron or of occ paperless_sync:run.", [
				'names' => $names,
				'count' => count($this->notCreated),
			]);
		}
	}

	/**
	 * The names of the tags of a document, each once, whatever its case; a tag that Paperless shows
	 * the account by its ID alone has no name to give.
	 *
	 * @return list<string>
	 * @psalm-suppress MixedAssignment the values of Paperless are checked before they are used
	 */
	private function names(mixed $ids): array {
		$names = [];
		foreach (is_array($ids) ? $ids : [] as $id) {
			if (!is_int($id) && !is_string($id)) {
				continue;
			}
			$name = self::normalize($this->tagNames[(string)$id] ?? '');
			if ($name !== '') {
				$names[self::key($name)] ??= $name;
			}
		}

		return array_values($names);
	}

	private static function key(string $name): string {
		return mb_strtolower($name, 'UTF-8');
	}
}
