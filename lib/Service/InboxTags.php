<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;

/**
 * The tags of Paperless that the files of the inbox get in one run: those of "Tags for every
 * import", and with "Subfolders become tags" one for each folder below the inbox that holds the
 * file, as PAPERLESS_CONSUMER_SUBDIRS_AS_TAGS does for the consume folder of Paperless. A name
 * matches the tag of Paperless with that name whatever its case. With "Create missing tags", a
 * name that Paperless has no tag of becomes a new tag, and otherwise the file goes without it and
 * the run reports the name, so that no name is lost without a word.
 *
 * It reads the tags of Paperless once, when the first file needs them; a dry-run reports the tags
 * that a run would create and creates none.
 */
final class InboxTags {
	/** @var list<string> the names of "Tags for every import" */
	private array $defaultNames;
	/** @var array<string, array{id: int|null, name: string}>|null the tags of Paperless by their names in lower case, once read; a tag that a dry-run would create has no ID */
	private ?array $tags = null;
	/** The failure of the read of the tags, which every file that needs them meets in this run. */
	private ?\Throwable $listFailure = null;
	/** @var array<string, \Throwable> the failed creation of a tag, by its name in lower case, which every file that needs it meets in this run */
	private array $creationFailures = [];
	/** @var array<string, string> the names that Paperless has no tag of, by their names in lower case */
	private array $unknown = [];

	public function __construct(
		private PaperlessClientInterface $paperless,
		private SyncConfig $config,
		private SyncReport $report,
	) {
		$this->defaultNames = self::names($config->inboxTags);
	}

	/**
	 * The names of a list of tags, as "Tags for every import" holds it: separated by commas or
	 * lines, without spaces at their ends, without empty ones, and each name once, whatever its case.
	 *
	 * @return list<string>
	 */
	public static function names(string $list): array {
		return array_values(self::unique(preg_split('/[,\r\n]+/', $list) ?: []));
	}

	/**
	 * The tags of a file in $folder, the path of its folder below the inbox, such as Taxes/2026, or
	 * '' for a file in the inbox itself: the IDs to upload it with, and the names of the tags as
	 * Paperless writes them, for the report.
	 *
	 * @return array{ids: list<int>, names: list<string>}
	 * @throws \Throwable when Paperless can't list its tags or create one that the file needs, so that the file waits
	 */
	public function forFolder(string $folder): array {
		$names = $this->defaultNames;
		if ($this->config->inboxFolderTags && $folder !== '') {
			array_push($names, ...explode('/', $folder));
		}
		$ids = [];
		$found = [];
		foreach (self::unique($names) as $key => $name) {
			// A name of digits alone, such as 2026, is a key of type int.
			$tag = $this->tag((string)$key, $name);
			if ($tag === null) {
				continue;
			}
			$found[] = $tag['name'];
			if ($tag['id'] !== null) {
				$ids[] = $tag['id'];
			}
		}

		return ['ids' => $ids, 'names' => $found];
	}

	/** Whether the files of the inbox get tags at all. */
	public function enabled(): bool {
		return $this->config->inboxFolderTags || $this->defaultNames !== [];
	}

	/** Whether Paperless couldn't list its tags in this run, so that no file that needs them can go. */
	public function unavailable(): bool {
		return $this->listFailure !== null;
	}

	/**
	 * The names that Paperless had no tag of in this run, in the order in which the files named
	 * them, as they were written first.
	 *
	 * @return list<string>
	 */
	public function unknown(): array {
		return array_values($this->unknown);
	}

	/** @return array{id: int|null, name: string}|null */
	private function tag(string $key, string $name): ?array {
		$tags = $this->tags();
		if (isset($tags[$key])) {
			return $tags[$key];
		}
		if (!$this->config->inboxCreateTags) {
			$this->unknown[$key] ??= $name;

			return null;
		}
		if (isset($this->creationFailures[$key])) {
			throw $this->creationFailures[$key];
		}
		$id = null;
		if (!$this->report->dryRun) {
			try {
				$id = $this->paperless->createTag($name);
			} catch (\Throwable $exception) {
				$this->creationFailures[$key] = $exception;
				throw $exception;
			}
		}
		$this->report->newTag($name);

		return $this->tags[$key] = ['id' => $id, 'name' => $name];
	}

	/** @return array<string, array{id: int|null, name: string}> */
	private function tags(): array {
		if ($this->listFailure !== null) {
			throw $this->listFailure;
		}
		if ($this->tags === null) {
			try {
				$names = $this->paperless->tags()['names'];
			} catch (\Throwable $exception) {
				$this->listFailure = $exception;
				throw $exception;
			}
			$tags = [];
			foreach ($names as $id => $name) {
				$key = mb_strtolower(trim($name));
				$id = (int)$id;
				// Of the tags whose names differ in their case alone, such as of two owners, the oldest.
				if (!isset($tags[$key]) || $id < $tags[$key]['id']) {
					$tags[$key] = ['id' => $id, 'name' => $name];
				}
			}
			$this->tags = $tags;
		}

		return $this->tags;
	}

	/**
	 * Each name once, by its name in lower case, as written first, without spaces at its ends and
	 * without empty ones.
	 *
	 * @param list<string> $names
	 * @return array<array-key, string>
	 */
	private static function unique(array $names): array {
		$unique = [];
		foreach ($names as $name) {
			$name = trim($name);
			if ($name !== '') {
				$unique[mb_strtolower($name)] ??= $name;
			}
		}

		return $unique;
	}
}
