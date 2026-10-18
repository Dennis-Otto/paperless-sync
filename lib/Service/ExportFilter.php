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
 * settings skip them, those with an excluded tag, and, while the settings name tags to mirror,
 * those without any of them, whatever the case of the names. An excluded tag and the inbox win
 * over a tag to mirror.
 */
final class ExportFilter {
	/** @var list<string> the excluded tags, in lower case */
	private array $excludedNames;
	/** @var list<string> the tags to mirror, in lower case; none mirrors every document */
	private array $includedNames;

	/**
	 * @param array{names: array<string, string>, inbox: list<string>} $tags the tags of Paperless, every one of them
	 * @throws InvalidSettingException when Paperless has no tag of a name to mirror
	 */
	public function __construct(
		private SyncConfig $config,
		private array $tags,
	) {
		$this->excludedNames = self::lowerCase(InboxTags::names($config->excludedTags));
		$this->includedNames = self::lowerCase(InboxTags::names($config->includedTags));
		// An excluded tag that Paperless doesn't have excludes nothing yet, but a tag to mirror
		// that it doesn't have, after a typo or a rename in Paperless, would remove the copies of
		// the documents that the archive should keep. The lists of Paperless are complete, or
		// PaperlessApiService fails the run, so a name that isn't there is missing indeed.
		$known = self::lowerCase(array_values($tags['names']));
		$missing = array_values(array_filter(
			InboxTags::names($config->includedTags),
			static fn (string $name): bool => !in_array(mb_strtolower($name), $known, true),
		));
		if ($missing !== []) {
			throw new InvalidSettingException('Paperless has no tag named %s, which "Only mirror documents with these tags" names; a run would remove the copies of the documents that should stay.', ['"' . implode('", "', $missing) . '"'], 'included_tags');
		}
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
		$names = [];
		foreach ($documentTags as $tagId) {
			if (isset($this->tags['names'][$tagId])) {
				$names[] = mb_strtolower($this->tags['names'][$tagId]);
			}
		}
		if (array_intersect($names, $this->excludedNames) !== []) {
			return true;
		}

		return $this->includedNames !== [] && array_intersect($names, $this->includedNames) === [];
	}

	/**
	 * @param list<string> $names
	 * @return list<string>
	 */
	private static function lowerCase(array $names): array {
		return array_map('mb_strtolower', $names);
	}
}
