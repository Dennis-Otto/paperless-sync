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
use OCP\Teams\ITeamManager;
use OCP\Teams\Team;

/**
 * The shares that show a user with tags more than their tags: a share of the target user, made by
 * hand in Files, of the base folder, of a folder or a file in it, or of a folder that holds it,
 * with that user, with a group or a Team that they are in, or with a conversation of Talk or a
 * board of Deck. Such a share shows them every document that it holds, whatever their tags, so the
 * settings page, the connection checklist and occ paperless_sync:status name each, and the settings
 * page removes them on request, never by itself (decision 0038). Nextcloud names the members of a
 * group and of a Team, but not those of a conversation or a board, so such a share counts as one
 * that may reach a user with tags, with known false and no users. A link of a share names nobody,
 * and a share with another server or an email address reaches no user of this one, so neither is
 * such a share; the guide says so.
 *
 * @psalm-type TagShare = array{id: string, path: string, type: 'user'|'group'|'team'|'conversation'|'board', with: string, users: list<string>, known: bool}
 */
final class TagShares {
	/** The setting that the line of the checklist names, whose part of the page shows the shares. */
	private const SETTING = 'user_tags';

	/**
	 * The kinds of shares that reach a user of this Nextcloud, by their type: those with a user or
	 * a group, and those with a Team, a conversation of Talk or a board of Deck, which exist only
	 * while their app does.
	 */
	private const KINDS = [
		IShare::TYPE_USER => 'user',
		IShare::TYPE_GROUP => 'group',
		IShare::TYPE_CIRCLE => 'team',
		IShare::TYPE_ROOM => 'conversation',
		IShare::TYPE_DECK => 'board',
	];

	/** The types whose shares come from an app that may be missing, whose shares the check then has none of. */
	private const OF_AN_APP = [IShare::TYPE_CIRCLE, IShare::TYPE_ROOM, IShare::TYPE_DECK];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IManager $shareManager,
		private IGroupManager $groupManager,
		private ITeamManager $teamManager,
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
	 * Removes the shares of $ids that still show a user with tags more than their tags, or may,
	 * and no other share: an ID that names another share, or one that someone removed or changed
	 * since the page listed it, changes nothing.
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
	 * one of them more than their tags, which a save goes on with, as the copies are right, and a
	 * warning for a share whose members Nextcloud doesn't name, which may.
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
		$known = self::described(array_filter($shares, static fn (array $share): bool => $share['known']));
		$unknown = self::described(array_filter($shares, static fn (array $share): bool => !$share['known']));
		if ($known === '') {
			return new ConnectionCheck(
				ConnectionCheck::TAG_SHARES,
				ConnectionCheck::WARNING,
				'These shares of %1$s may show a user with tags every document in them, as Nextcloud does not name who is in them: %2$s.',
				[$config->targetUser, $unknown],
				'Check who is in them, and remove those that reach a user with tags under "Tags that a user may see", or in Files.',
				[],
				false,
				self::SETTING,
			);
		}
		if ($unknown !== '') {
			return new ConnectionCheck(
				ConnectionCheck::TAG_SHARES,
				ConnectionCheck::FAILED,
				'These shares of %1$s show a user with tags every document in them: %2$s. These may too, as Nextcloud does not name who is in them: %3$s.',
				[$config->targetUser, $known, $unknown],
				'Remove them under "Tags that a user may see", or in Files.',
				[],
				false,
				self::SETTING,
			);
		}

		return new ConnectionCheck(
			ConnectionCheck::TAG_SHARES,
			ConnectionCheck::FAILED,
			'These shares of %1$s show a user with tags every document in them: %2$s.',
			[$config->targetUser, $known],
			'Remove them under "Tags that a user may see", or in Files.',
			[],
			false,
			self::SETTING,
		);
	}

	/**
	 * A share in a line: its path, and the users with tags that it reaches, or whom it reaches when
	 * Nextcloud doesn't name them.
	 *
	 * @param TagShare $share
	 */
	public static function describe(array $share): string {
		$users = implode(', ', $share['users']);

		return $share['path'] . ' → ' . match ($share['type']) {
			'user' => $users,
			'group' => $users . ' (' . $share['with'] . ')',
			'team' => $share['known'] ? $users . ' (Team ' . $share['with'] . ')' : 'the members of the Team ' . $share['with'],
			'conversation' => 'the members of the Talk conversation ' . $share['with'],
			'board' => 'the members of the Deck board ' . $share['with'],
		};
	}

	/** @param array<array-key, TagShare> $shares */
	private static function described(array $shares): string {
		return implode('; ', array_map(self::describe(...), $shares));
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
		foreach (self::KINDS as $type => $kind) {
			// Without Talk, say, Nextcloud has no shares with a conversation and fails to list them.
			if (in_array($type, self::OF_AN_APP, true) && !$this->shareManager->shareProviderExists($type)) {
				continue;
			}
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
				$users = $this->reached($kind, $with, $tags->users());
				if ($users !== []) {
					$shown = substr($path, strlen($home));
					$found[] = [
						'share' => [
							'id' => $share->getFullId(),
							'path' => $shown === '' ? '/' : $shown,
							'type' => $kind,
							'with' => $kind === 'user' || $kind === 'group' ? $with : self::named($share, $with),
							'users' => $users ?? [],
							'known' => $users !== null,
						],
						'object' => $share,
					];
				}
			}
		}
		usort($found, self::byPath(...));

		return $found;
	}

	/**
	 * The users with tags whom a share of $kind with $with reaches, or null when Nextcloud doesn't
	 * name its members: those of a conversation of Talk and of a board of Deck, and those of a Team
	 * when Nextcloud fails to list the Teams of a user.
	 *
	 * @param 'user'|'group'|'team'|'conversation'|'board' $kind
	 * @param list<string> $users the users with tags
	 * @return list<string>|null
	 */
	private function reached(string $kind, string $with, array $users): ?array {
		return match ($kind) {
			'user' => array_values(array_filter($users, static fn (string $user): bool => mb_strtolower($user) === mb_strtolower($with))),
			'group' => array_values(array_filter($users, fn (string $user): bool => $this->groupManager->isInGroup($user, $with))),
			'team' => $this->inTeam($with, $users),
			'conversation', 'board' => null,
		};
	}

	/**
	 * The users of $users who are in the Team $team, as Nextcloud lists the Teams of each, or null
	 * when it fails to: the share then counts as one that may reach them, as a failure of the Teams
	 * must neither hide it nor break the check.
	 *
	 * @param list<string> $users
	 * @return list<string>|null
	 */
	private function inTeam(string $team, array $users): ?array {
		try {
			return array_values(array_filter($users, fn (string $user): bool => in_array($team, array_map(static fn (Team $of): string => $of->getId(), $this->teamManager->getTeamsForUser($user)), true)));
		} catch (\Throwable) {
			return null;
		}
	}

	/** The name of the Team, the conversation or the board of a share, as Nextcloud shows it, or its ID. */
	private static function named(IShare $share, string $with): string {
		$name = $share->getSharedWithDisplayName() ?? '';

		return $name !== '' ? $name : $with;
	}
}
