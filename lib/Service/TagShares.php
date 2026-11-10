<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * The shares that show a user with tags more than their tags: a share of the target user, made by
 * hand in Files, of the base folder, of a folder or a file in it, or of a folder that holds it,
 * with that user or with a group that they are in. Such a share shows them every document that it
 * holds, whatever their tags, so the settings page, the connection checklist and occ
 * paperless_sync:status name each, and the settings page removes them on request, never by itself
 * (decision 0038). A link of a share names nobody, so it is no such share; the guide says so.
 *
 * @psalm-type TagShare = array{id: string, path: string, type: 'user'|'group', with: string, users: list<string>}
 */
final class TagShares {
	/** The setting that the line of the checklist names, whose part of the page shows the shares. */
	private const SETTING = 'user_tags';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IManager $shareManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * The shares that show a user with tags more than their tags, in the order of their paths.
	 *
	 * @return list<TagShare>
	 */
	public function find(SyncConfig $config): array {
		return array_map(static fn (array $found): array => $found['share'], $this->shares($config));
	}

	/**
	 * Removes the shares of $ids that still show a user with tags more than their tags, and no
	 * other share: an ID that names another share, or one that someone removed or changed since the
	 * page listed it, changes nothing.
	 *
	 * @param list<string> $ids the IDs of the shares, as find() names them
	 * @return int how many shares it removed
	 */
	public function remove(SyncConfig $config, array $ids): int {
		$removed = 0;
		foreach ($this->shares($config) as $found) {
			if (in_array($found['share']['id'], $ids, true)) {
				$this->shareManager->deleteShare($found['object']);
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * The line of the connection checklist, once a user has tags: failed for each share that shows
	 * one of them more than their tags, which a save goes on with, as the copies are right.
	 */
	public function check(SyncConfig $config): ?ConnectionCheck {
		if (UserTags::of($config)->users() === []) {
			return null;
		}
		try {
			$shares = $this->find($config);
		} catch (\Throwable $exception) {
			return new ConnectionCheck(ConnectionCheck::TAG_SHARES, ConnectionCheck::WARNING, 'Nextcloud did not list the shares of %1$s: %2$s', [$config->targetUser, $exception->getMessage()], 'Check in Files that no share of the base folder reaches a user with tags.', [], false, self::SETTING);
		}
		if ($shares === []) {
			return ConnectionCheck::passed(ConnectionCheck::TAG_SHARES, 'No share of %s shows a user with tags more than their tags.', [$config->targetUser]);
		}

		return new ConnectionCheck(
			ConnectionCheck::TAG_SHARES,
			ConnectionCheck::FAILED,
			'These shares of %1$s show a user with tags every document in them: %2$s.',
			[$config->targetUser, implode('; ', array_map(self::describe(...), $shares))],
			'Remove them under "Tags that a user may see", or in Files.',
			[],
			false,
			self::SETTING,
		);
	}

	/** @param TagShare $share */
	public static function describe(array $share): string {
		return $share['path'] . ' → ' . implode(', ', $share['users']) . ($share['type'] === 'group' ? ' (' . $share['with'] . ')' : '');
	}

	/**
	 * @param array{share: TagShare, object: IShare} $first
	 * @param array{share: TagShare, object: IShare} $second
	 */
	private static function byPath(array $first, array $second): int {
		return [$first['share']['path'], $first['share']['id']] <=> [$second['share']['path'], $second['share']['id']];
	}

	/**
	 * @return list<array{share: TagShare, object: IShare}>
	 */
	private function shares(SyncConfig $config): array {
		$tags = UserTags::of($config);
		if ($tags->users() === [] || $config->targetUser === '') {
			return [];
		}
		$home = '/' . $config->targetUser . '/files';
		$base = rtrim($home . '/' . trim($config->basePath, '/'), '/');
		$found = [];
		foreach ([IShare::TYPE_USER => 'user', IShare::TYPE_GROUP => 'group'] as $type => $kind) {
			foreach ($this->shareManager->getSharesBy($config->targetUser, $type, null, true, -1) as $share) {
				try {
					$path = $share->getNode()->getPath();
				} catch (NotFoundException) {
					continue;
				}
				// The base folder, what is in it, and a folder that holds it.
				if ($path !== $base && !str_starts_with($path, $base . '/') && !str_starts_with($base, $path . '/')) {
					continue;
				}
				$with = $share->getSharedWith();
				$users = array_values(array_filter($tags->users(), fn (string $user): bool => $kind === 'user'
					? mb_strtolower($user) === mb_strtolower($with)
					: $this->groupManager->isInGroup($user, $with)));
				if ($users !== []) {
					$shown = substr($path, strlen($home));
					$found[] = [
						'share' => ['id' => $share->getFullId(), 'path' => $shown === '' ? '/' : $shown, 'type' => $kind, 'with' => $with, 'users' => $users],
						'object' => $share,
					];
				}
			}
		}
		usort($found, self::byPath(...));

		return $found;
	}
}
