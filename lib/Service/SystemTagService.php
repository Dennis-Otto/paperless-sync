<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\IAppConfig;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

/**
 * The tags of Nextcloud that the app makes for the tags of Paperless, which it alone assigns,
 * removes and deletes. The config of the app keeps the ID of each, with the name of the tag of
 * Paperless that it stands for, so that a run never touches a tag that someone else made, also
 * one with the name of a tag of Paperless, and finds its own tags also after an administrator
 * renamed one in Nextcloud.
 */
final class SystemTagService {
	/** The type of the objects of Nextcloud whose tags are those of the copies: files. */
	public const OBJECT_TYPE = 'files';
	/** The key of the config of the app with the tags that the app made, as JSON: the name of each by its ID. */
	public const MANAGED = 'managed_tags';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $objectMapper,
		private IAppConfig $appConfig,
		private NextcloudStorageInterface $storage,
	) {
	}

	/**
	 * The tags of the copies of a run, with the names of the tags of Paperless by their IDs.
	 *
	 * @param array<string, string> $tagNames
	 */
	public function forRun(SyncConfig $config, SyncReport $report, array $tagNames): ArchiveTags {
		return new ArchiveTags($this->tagManager, $this->objectMapper, $this->storage, $this, $config, $report, $tagNames);
	}

	/**
	 * The tags that the app made and that Nextcloud still has, with the name of the tag of
	 * Paperless of each, by their IDs. The app forgets a tag that an administrator deleted, and the
	 * next copy with its name gets a new one.
	 *
	 * @return array<int, string>
	 */
	public function managed(): array {
		$managed = $this->stored();
		$found = $managed;
		while ($found !== []) {
			try {
				$this->tagManager->getTagsByIds(array_map('strval', array_keys($found)));
				break;
			} catch (TagNotFoundException $exception) {
				$missing = array_map('intval', $exception->getMissingTags());
				// A failure that names no missing tag would never end: the run keeps the tags as they are.
				if ($missing === []) {
					throw $exception;
				}
				$found = array_diff_key($found, array_flip($missing));
			}
		}
		if ($found !== $managed) {
			$this->remember($found);
		}

		return $found;
	}

	/**
	 * Keeps the tags that the app made, with the name of the tag of Paperless of each, by their IDs.
	 *
	 * @param array<int, string> $managed
	 */
	public function remember(array $managed): void {
		ksort($managed);
		$this->appConfig->setValueString(AppConstants::APP_ID, self::MANAGED, json_encode($managed, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), lazy: true);
	}

	/**
	 * The tags that the config of the app keeps; a value that is no such list, such as one that
	 * someone changed by hand, counts as none.
	 *
	 * @return array<int, string>
	 * @psalm-suppress MixedAssignment the value of the config is checked before it is used
	 */
	private function stored(): array {
		$stored = json_decode($this->appConfig->getValueString(AppConstants::APP_ID, self::MANAGED, '{}', lazy: true), true);
		$managed = [];
		foreach (is_array($stored) ? $stored : [] as $id => $name) {
			if (is_int($id) && $id > 0 && is_string($name) && $name !== '') {
				$managed[$id] = $name;
			}
		}

		return $managed;
	}
}
