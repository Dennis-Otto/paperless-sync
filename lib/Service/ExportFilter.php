<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;

/**
 * The documents that a run leaves out of the archive: those of the inbox of Paperless, while the
 * settings skip them, and those with an excluded tag, whatever the case of its name.
 */
final class ExportFilter {
	/** @var list<string> the excluded tags, in lower case */
	private array $excludedNames;

	/** @param array{names: array<string, string>, inbox: list<string>} $tags the tags of Paperless */
	public function __construct(
		private SyncConfig $config,
		private array $tags,
	) {
		$this->excludedNames = array_values(array_map('mb_strtolower', array_filter(array_map('trim', explode(',', $config->excludedTags)))));
	}

	/**
	 * @param array<string, mixed> $document
	 * @psalm-suppress MixedAssignment
	 */
	public function excludes(array $document): bool {
		$documentTags = [];
		$rawTags = $document['tags'] ?? null;
		foreach (is_array($rawTags) ? $rawTags : [] as $tag) {
			if (is_scalar($tag)) {
				$documentTags[] = (string)$tag;
			} elseif (is_array($tag) && isset($tag['id']) && is_scalar($tag['id'])) {
				$documentTags[] = (string)$tag['id'];
			}
		}
		if ($this->config->skipInbox && array_intersect($documentTags, $this->tags['inbox']) !== []) {
			return true;
		}
		foreach ($documentTags as $tagId) {
			if (isset($this->tags['names'][$tagId]) && in_array(mb_strtolower($this->tags['names'][$tagId]), $this->excludedNames, true)) {
				return true;
			}
		}

		return false;
	}
}
