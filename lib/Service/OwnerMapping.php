<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\DocumentPermissions;
use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Which user of Nextcloud gets the documents of each user of Paperless in per-user ownership. A
 * user of Paperless gets the user of Nextcloud that the table "Paperless users with another
 * Nextcloud ID" names, or else the one with its ID, whatever the case, as single sign-on such as
 * OpenID Connect gives both the same; with a group in the settings, only a member of it.
 *
 * Nothing is guessed: two users of Paperless with the same user of Nextcloud, such as Alice and
 * alice, get neither, and the runs hold their documents, whose copies stay where they are, until
 * each has a user of Nextcloud of its own. A user of Nextcloud that the table names but that no
 * longer exists gets nothing either: those documents, and those of every user of Paperless without
 * a user of Nextcloud, go to the target user, as every document did before per-user ownership.
 * The connection checklist names each of them.
 *
 * With the documents that Paperless shares, the same table and group decide who gets a copy of a
 * document that their user of Paperless may view, by itself or through a group of Paperless.
 *
 * @psalm-import-type Route from Owners
 */
final class OwnerMapping {
	/** The most users of Paperless that the connection checklist names, each with a question for the number of their documents. */
	public const CHECKED_USERS = 25;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * The owners of a run, from the users of Paperless that PaperlessClientInterface::users()
	 * lists: their names by their IDs.
	 *
	 * @param array<string, string> $paperlessUsers
	 * @param array<int, list<int>> $groups the users of each group of Paperless, as PaperlessClientInterface::groupMembers() lists them, when the run reads who may view a document
	 */
	public function owners(SyncConfig $config, array $paperlessUsers, array $groups = []): Owners {
		$table = [];
		foreach (self::parse($config->userMap, false) as $line) {
			$table[mb_strtolower($line['paperless'])] = $line['nextcloud'];
		}
		$routes = [];
		$byUser = [];
		$matched = [];
		foreach ($paperlessUsers as $id => $name) {
			$id = (int)$id;
			$key = mb_strtolower($name);
			if (isset($table[$key])) {
				$matched[$key] = true;
				$user = $this->userManager->get($table[$key]);
				if ($user === null) {
					$routes[$id] = self::route($id, $name, null, Owners::UNKNOWN, $table[$key]);
					continue;
				}
				$reason = Owners::TABLE;
			} else {
				$user = $this->userManager->get($name);
				// Nextcloud finds a user whatever the case of the ID; only the same ID counts.
				if ($user === null || mb_strtolower($user->getUID()) !== $key) {
					$routes[$id] = self::route($id, $name, null, Owners::NONE);
					continue;
				}
				$reason = Owners::NAME;
			}
			$uid = $user->getUID();
			if ($config->userGroup !== '' && !$this->groupManager->isInGroup($uid, $config->userGroup)) {
				$routes[$id] = self::route($id, $name, null, Owners::OUTSIDE_GROUP, $uid);
				continue;
			}
			$routes[$id] = self::route($id, $name, $uid, $reason, $reason === Owners::TABLE ? $uid : '');
			$byUser[$uid][] = $id;
		}
		// Two users of Paperless for one user of Nextcloud: which one is meant is not for the app to guess.
		foreach ($byUser as $uid => $ids) {
			if (count($ids) > 1) {
				foreach ($ids as $id) {
					$others = array_map(static fn (int $other): string => $routes[$other]['name'], array_values(array_diff($ids, [$id])));
					$routes[$id] = self::route($id, $routes[$id]['name'], null, Owners::SHARED, $uid . ' (' . implode(', ', $others) . ')');
				}
			}
		}
		$unmatched = [];
		foreach (self::parse($config->userMap, false) as $line) {
			if (!isset($matched[mb_strtolower($line['paperless'])])) {
				$unmatched[] = $line['paperless'];
			}
		}

		return new Owners($config->targetUser, $routes, $unmatched, $groups);
	}

	/**
	 * The lines of the connection checklist for per-user ownership: which user of Nextcloud gets the
	 * documents of each user of Paperless, with their number, those without an owner, the lines
	 * of the table that name no user of Paperless, and whether each user of Nextcloud may create
	 * files in their base folder. A user of Paperless whom the run would give to the target user
	 * for a mistake of the settings, such as a typo in the table, or whose documents it holds, as
	 * for two users of Paperless for one user of Nextcloud, is a warning that names the setting to
	 * change. Without the list of
	 * the users of Paperless, per-user ownership can't route a document, so the line of the users
	 * fails then, and so does one for the groups, with the documents that Paperless shares, for which
	 * the lines end with who sees how many documents of whom.
	 *
	 * @return list<ConnectionCheck>
	 */
	public function checks(SyncConfig $config, PaperlessClientInterface $paperless, NextcloudStorageInterface $storage): array {
		try {
			$users = $paperless->users();
			try {
				$groups = $config->sharedDocuments() ? $paperless->groupMembers() : [];
			} catch (\RuntimeException $exception) {
				return [new ConnectionCheck(
					ConnectionCheck::OWNERS,
					ConnectionCheck::FAILED,
					'Paperless did not list its groups: %s',
					[$exception->getMessage()],
					'In Paperless, under Users & Groups, give the account the permission View for Groups: the documents that Paperless shares need it to find who may view each through a group, and a run fails without it.',
				)];
			}
			$owners = $this->owners($config, $users, $groups);
			$checks = [];
			$routes = $owners->routes();
			foreach (array_slice($routes, 0, self::CHECKED_USERS) as $route) {
				$checks[] = self::routeCheck($route, $config->targetUser, (string)$paperless->ownedDocuments($route['id']));
			}
			if (count($routes) > self::CHECKED_USERS) {
				$checks[] = ConnectionCheck::passed(ConnectionCheck::OWNERS, 'And %s more users of Paperless, which a run gives to their users of Nextcloud in the same way.', [(string)(count($routes) - self::CHECKED_USERS)]);
			}
			$checks[] = ConnectionCheck::passed(ConnectionCheck::OWNERS, 'Without an owner → %1$s, the target user, documents: %2$s', [$config->targetUser, (string)$paperless->ownedDocuments(null)]);
			if ($config->sharedDocuments()) {
				array_push($checks, ...self::sharedChecks($owners, $paperless));
			}
		} catch (\RuntimeException $exception) {
			return [new ConnectionCheck(
				ConnectionCheck::OWNERS,
				ConnectionCheck::FAILED,
				'Paperless did not list its users: %s',
				[$exception->getMessage()],
				'In Paperless, under Users & Groups, give the account the permission View for Users: per-user ownership needs it to find the user of each document, and a run fails without it.',
			)];
		}
		foreach ($owners->unmatched as $name) {
			$checks[] = new ConnectionCheck(ConnectionCheck::OWNERS, ConnectionCheck::WARNING, 'The table names %s, but Paperless has no user of that name.', [$name], 'Check the name of the user of Paperless in "Paperless users with another Nextcloud ID".', setting: 'user_map');
		}
		foreach (array_slice(array_values(array_diff($owners->users(), [$config->targetUser])), 0, self::CHECKED_USERS) as $user) {
			try {
				$storage->test($user, $config->basePath);
			} catch (\Throwable $exception) {
				$checks[] = new ConnectionCheck(ConnectionCheck::FOLDER, ConnectionCheck::WARNING, 'Nextcloud cannot store the files of %1$s there: %2$s', [$user, $exception->getMessage()], 'Until it can, the documents of the user wait; check that the base folder of the user is a folder in which they may create files.');
			}
		}

		return $checks;
	}

	/**
	 * The lines of the documents that Paperless shares: how many documents from the archive of whom
	 * each user of Nextcloud also gets, how many a user of Paperless without a user of Nextcloud may
	 * view without getting a copy, and how many documents don't say who may view them. The account
	 * of the app needs no line of its own: it may view the documents so that a run can read them.
	 *
	 * @return list<ConnectionCheck>
	 */
	private static function sharedChecks(Owners $owners, PaperlessClientInterface $paperless): array {
		try {
			$documents = $paperless->documents(false, true)->documents;
		} catch (\RuntimeException $exception) {
			return [new ConnectionCheck(
				ConnectionCheck::OWNERS,
				ConnectionCheck::FAILED,
				'Paperless did not list who may view its documents: %s',
				[$exception->getMessage()],
				'A run reads that list to give each user the documents that they may view, and fails without it; check the line of the documents above.',
			)];
		}
		$account = $paperless->accountId();
		/** @var array<string, int> $shares the documents of each pair of users of Nextcloud, the viewer and the owner, as a line */
		$shares = [];
		/** @var array<array-key, int> $withoutUser the documents of each user of Paperless without a user of Nextcloud, by its name, which PHP turns into a number when it is one */
		$withoutUser = [];
		$unknown = 0;
		foreach ($documents as $document) {
			$owner = array_key_exists('owner', $document) ? $owners->userOf($document['owner']) : null;
			// The run holds a document whose owner it can't tell, whoever may view it.
			if ($owner === null) {
				continue;
			}
			$permissions = DocumentPermissions::of($document);
			$viewers = $permissions !== null ? $owners->viewersOf($permissions) : null;
			if ($viewers === null) {
				++$unknown;
				continue;
			}
			foreach ($viewers as $viewer) {
				if ($viewer['user'] === null) {
					if ($viewer['id'] !== $account) {
						$withoutUser[$viewer['name']] = ($withoutUser[$viewer['name']] ?? 0) + 1;
					}
				} elseif ($viewer['user'] !== $owner) {
					$pair = $viewer['user'] . "\n" . $owner;
					$shares[$pair] = ($shares[$pair] ?? 0) + 1;
				}
			}
		}
		ksort($shares, SORT_STRING);
		ksort($withoutUser, SORT_STRING);
		$checks = [];
		foreach ($shares as $pair => $count) {
			[$viewer, $owner] = explode("\n", $pair);
			$checks[] = ConnectionCheck::passed(ConnectionCheck::OWNERS, '%1$s also gets documents from the archive of %2$s: %3$s', [$viewer, $owner, (string)$count]);
		}
		foreach ($withoutUser as $name => $count) {
			$checks[] = ConnectionCheck::passed(ConnectionCheck::OWNERS, '%1$s may view documents of others in Paperless but gets no copies, without a user of Nextcloud of its own: %2$s', [(string)$name, (string)$count]);
		}
		if (count($checks) > self::CHECKED_USERS) {
			$more = count($checks) - self::CHECKED_USERS;
			$checks = array_slice($checks, 0, self::CHECKED_USERS);
			$checks[] = ConnectionCheck::passed(ConnectionCheck::OWNERS, 'And %s more lines of who also gets which documents, which a run treats in the same way.', [(string)$more]);
		}
		if ($unknown > 0) {
			$checks[] = new ConnectionCheck(ConnectionCheck::OWNERS, ConnectionCheck::WARNING, 'Paperless did not say who may view documents: %s', [(string)$unknown], 'A run gives them to their owners alone, and their copies stay where they are; check that no proxy changes the answers of Paperless.');
		}

		return $checks;
	}

	/**
	 * The line of one user of Paperless, with the number of its documents.
	 *
	 * @param Route $route
	 */
	private static function routeCheck(array $route, string $targetUser, string $documents): ConnectionCheck {
		return match ($route['reason']) {
			Owners::NAME, Owners::TABLE => ConnectionCheck::passed(ConnectionCheck::OWNERS, '%1$s → %2$s, documents: %3$s', [$route['name'], (string)$route['user'], $documents]),
			Owners::NONE => ConnectionCheck::passed(ConnectionCheck::OWNERS, '%1$s → %2$s, the target user, as Nextcloud has no user with this ID; documents: %3$s', [$route['name'], $targetUser, $documents]),
			Owners::OUTSIDE_GROUP => ConnectionCheck::passed(ConnectionCheck::OWNERS, '%1$s → %2$s, the target user, as %3$s is no member of the group; documents: %4$s', [$route['name'], $targetUser, $route['detail'], $documents]),
			Owners::UNKNOWN => new ConnectionCheck(ConnectionCheck::OWNERS, ConnectionCheck::WARNING, '%1$s → %2$s, the target user, as Nextcloud has no user %3$s; documents: %4$s', [$route['name'], $targetUser, $route['detail'], $documents], 'Correct the line of the user in "Paperless users with another Nextcloud ID".', setting: 'user_map'),
			default => new ConnectionCheck(ConnectionCheck::OWNERS, ConnectionCheck::WARNING, '%1$s → nobody, as other users of Paperless have the user of Nextcloud %2$s as well, so their documents stay where their copies are; documents: %3$s', [$route['name'], $route['detail'], $documents], 'Give each user of Paperless a user of Nextcloud of its own in "Paperless users with another Nextcloud ID".', setting: 'user_map'),
		};
	}

	/** Whether Nextcloud has a user with this ID, such as one whose archive has copies of an earlier run. */
	public function exists(string $user): bool {
		return $this->userManager->userExists($user);
	}

	/**
	 * The lines of the table "Paperless users with another Nextcloud ID": a user of Paperless, an
	 * equals sign and the ID of a user of Nextcloud, such as jdoe = john.doe, with the number of
	 * the line. Neither ID can hold an equals sign, and empty lines don't count. A line that is no
	 * such mapping is refused when $strict, as a save checks the table, and left out otherwise.
	 *
	 * @return list<array{line: int, paperless: string, nextcloud: string}>
	 * @throws InvalidSettingException for a line that is no mapping, when $strict
	 */
	public static function parse(string $map, bool $strict = true): array {
		$lines = [];
		foreach (preg_split('/\R/u', $map) ?: [] as $index => $text) {
			$text = trim($text);
			if ($text === '') {
				continue;
			}
			$parts = array_map('trim', explode('=', $text));
			if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
				if ($strict) {
					throw new InvalidSettingException('"Paperless users with another Nextcloud ID", line %s: write the user of Paperless, an equals sign and the user ID of Nextcloud, such as jdoe = john.doe.', [(string)($index + 1)], 'user_map');
				}
				continue;
			}
			$lines[] = ['line' => $index + 1, 'paperless' => $parts[0], 'nextcloud' => $parts[1]];
		}

		return $lines;
	}

	/**
	 * @param Owners::* $reason
	 * @return Route
	 */
	private static function route(int $id, string $name, ?string $user, string $reason, string $detail = ''): array {
		return ['id' => $id, 'name' => $name, 'user' => $user, 'reason' => $reason, 'detail' => $detail];
	}
}
