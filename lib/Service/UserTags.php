<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;

/**
 * The tags of Paperless that some users of Nextcloud may see, from the setting "Tags that a user
 * may see": one line for each such user, with their user ID of Nextcloud, an equals sign and the
 * names of the tags, separated by commas, such as katja = Katja, Household. Such a user gets, in
 * an archive of their own, every document with at least one of their tags, whatever the case of
 * the names, and no other document; a user without a line, or with a line without tags, gets what
 * they got before.
 *
 * Paperless Unified Search reads the same lines, the value user_tags of the app paperless_sync,
 * to show such a user only the documents with one of their tags: they are the contract between
 * both apps, which a save writes as user = Tag, Tag, with the ID of the user as Nextcloud spells
 * it, each user once, never the target user, and only lines with tags.
 */
final class UserTags {
	/** The name of the setting, which its messages name. */
	private const SETTING = 'user_tags';

	/**
	 * @param array<string, list<string>> $tags the names of the tags that each user with a line may see, by the IDs of the users, as the line writes them
	 */
	private function __construct(
		private array $tags,
	) {
	}

	/** The tags of the saved lines; a line that is no such line counts for nobody. */
	public static function of(SyncConfig $config): self {
		$tags = [];
		foreach (self::parse($config->userTags, false) as $line) {
			if ($line['tags'] !== []) {
				$tags[$line['user']] = $line['tags'];
			}
		}

		return new self($tags);
	}

	/**
	 * The lines of the setting: the user of each, as written, and their tags, each once, whatever
	 * its case. An empty line counts for nothing, and a line without an equals sign or without a
	 * user is refused when $strict, as a save checks the setting, and left out otherwise.
	 *
	 * @return list<array{line: int, user: string, tags: list<string>}>
	 * @throws InvalidSettingException for a line that is no such line, when $strict
	 */
	public static function parse(string $value, bool $strict = true): array {
		$lines = [];
		foreach (preg_split('/\R/u', $value) ?: [] as $index => $text) {
			$text = trim($text);
			if ($text === '') {
				continue;
			}
			$parts = explode('=', $text, 2);
			$user = trim($parts[0]);
			if (count($parts) !== 2 || $user === '') {
				if ($strict) {
					throw new InvalidSettingException('"Tags that a user may see", line %s: write the user ID of Nextcloud, an equals sign and the tags of Paperless, separated by commas, such as katja = Katja, Household.', [(string)($index + 1)], self::SETTING);
				}
				continue;
			}
			$lines[] = ['line' => $index + 1, 'user' => $user, 'tags' => InboxTags::names($parts[1])];
		}

		return $lines;
	}

	/**
	 * The users with tags, in the order of their IDs.
	 *
	 * @return list<string>
	 */
	public function users(): array {
		$users = array_keys($this->tags);
		sort($users, SORT_STRING);

		return array_map('strval', $users);
	}

	/** Whether the user may see only the documents with some tags. */
	public function restricts(string $user): bool {
		return isset($this->tags[$user]);
	}

	/**
	 * Whether the user may see the document: a user without tags every document, and a user with
	 * tags one with at least one of them, whatever the case of the names.
	 *
	 * @param array<string, mixed> $document
	 * @param array<string, string> $tagNames the names of the tags of Paperless, by their IDs
	 */
	public function allows(string $user, array $document, array $tagNames): bool {
		if (!isset($this->tags[$user])) {
			return true;
		}
		$names = [];
		foreach (ExportFilter::tagIds($document) as $tagId) {
			if (isset($tagNames[$tagId])) {
				$names[] = mb_strtolower($tagNames[$tagId]);
			}
		}

		return array_intersect($names, array_map('mb_strtolower', $this->tags[$user])) !== [];
	}

	/**
	 * A tag that Paperless doesn't have, after a typo or a rename in Paperless, would take from a
	 * user the copies of the documents that they may see, so a run stops before a copy moves. The
	 * lists of Paperless are complete, or PaperlessApiService fails the run, so a name that isn't
	 * there is missing indeed.
	 *
	 * @param array<string, string> $tagNames the names of the tags of Paperless, by their IDs
	 * @throws InvalidSettingException with the names that Paperless doesn't have
	 */
	public function requireKnown(array $tagNames): void {
		$known = array_map('mb_strtolower', array_values($tagNames));
		$missing = [];
		foreach ($this->tags as $tags) {
			foreach ($tags as $name) {
				if (!in_array(mb_strtolower($name), $known, true)) {
					$missing[mb_strtolower($name)] ??= $name;
				}
			}
		}
		if ($missing !== []) {
			throw new InvalidSettingException('Paperless has no tag named %s, which "Tags that a user may see" names; a run would take from a user the copies of the documents that they may see.', ['"' . implode('", "', $missing) . '"'], self::SETTING);
		}
	}
}
