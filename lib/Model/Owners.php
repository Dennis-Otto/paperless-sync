<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * Whose archive each document of Paperless goes to in per-user ownership, as OwnerMapping decided
 * it at the start of a run: the user of Nextcloud of each user of Paperless that has exactly one,
 * and the target user for the documents without an owner and for those whose owner has none.
 *
 * A document whose owner Paperless didn't list among its users goes to nobody: the run can't tell
 * whose it is, so it holds the document, and its copy stays where it is, rather than handing it to
 * the target user, who may not be allowed to see it. So does a document of a user of Paperless
 * whose user of Nextcloud another user of Paperless has as well: the mapping isn't clear, and the
 * run doesn't guess, until the administrator gives each one a user of Nextcloud of its own.
 *
 * With the documents that Paperless shares, a document also goes to the user of Nextcloud of each
 * user of Paperless who may view it, by itself or through a group. A user of Paperless without a
 * user of Nextcloud gets no copy then: the target user may not be allowed to see the document.
 * Neither does the account of the app for what it may view: it has that permission so that a run
 * can read the documents of others, which says nothing about whom they are meant for.
 *
 * @psalm-type Route = array{id: int, name: string, user: string|null, reason: self::*, detail: string}
 */
final class Owners {
	/** The user of Nextcloud has the ID of the user of Paperless, whatever its case. */
	public const NAME = 'name';
	/** The table "Paperless users with another Nextcloud ID" names the user of Nextcloud. */
	public const TABLE = 'table';
	/** Nextcloud has no user with the ID of the user of Paperless. */
	public const NONE = 'none';
	/** The table names a user of Nextcloud that no longer exists. */
	public const UNKNOWN = 'unknown';
	/** The user of Nextcloud is no member of the group of the setting. */
	public const OUTSIDE_GROUP = 'group';
	/** Another user of Paperless has the same user of Nextcloud, so neither gets it, and the run holds their documents. */
	public const SHARED = 'shared';

	/** @var array<string, int> the user of Paperless of each user of Nextcloud that has one */
	private array $paperlessUsers = [];

	/**
	 * @param string $fallback the target user, who gets the documents without an owner and those whose owner has no user of Nextcloud
	 * @param array<int, Route> $routes every user of Paperless, by its ID, with the user of Nextcloud that gets its documents, or null for the target user or, for Owners::SHARED, for nobody, and why
	 * @param list<string> $unmatched the users of Paperless that the table names but Paperless doesn't have
	 * @param array<int, list<int>> $groups the IDs of the users of each group of Paperless, by its ID, when the run reads who may view a document
	 * @param int|null $account the ID of the account of the app, which is no viewer, when the run reads who may view a document
	 */
	public function __construct(
		public readonly string $fallback,
		private array $routes,
		public readonly array $unmatched = [],
		private array $groups = [],
		private ?int $account = null,
	) {
		foreach ($routes as $id => $route) {
			if ($route['user'] !== null) {
				$this->paperlessUsers[$route['user']] = $id;
			}
		}
	}

	/**
	 * The user of Nextcloud whose archive gets a document with this owner, as Paperless names it in
	 * the field owner of the document: the target user without an owner or for an owner without a
	 * user of Nextcloud, and null for an owner that Paperless didn't list or whose user of Nextcloud
	 * another user of Paperless has as well, whose document the run holds.
	 */
	public function userOf(mixed $owner): ?string {
		if ($owner === null) {
			return $this->fallback;
		}
		$id = is_int($owner) || (is_string($owner) && ctype_digit($owner)) ? (int)$owner : 0;
		if (!isset($this->routes[$id]) || $this->routes[$id]['reason'] === self::SHARED) {
			return null;
		}

		return $this->routes[$id]['user'] ?? $this->fallback;
	}

	/**
	 * The users of Paperless who may view a document with these permissions, by themselves or
	 * through a group, each once, with the user of Nextcloud of each that has one. A user that
	 * Paperless didn't list counts for nobody, and neither does the account of the app. Null for a
	 * group that Paperless didn't list: whom it holds is not for the app to guess.
	 *
	 * @return list<Route>|null
	 */
	public function viewersOf(DocumentPermissions $permissions): ?array {
		$ids = [$permissions->users];
		foreach ($permissions->groups as $group) {
			if (!isset($this->groups[$group])) {
				return null;
			}
			$ids[] = $this->groups[$group];
		}

		return array_values(array_intersect_key($this->routes, array_flip(array_diff(array_merge(...$ids), [$this->account]))));
	}

	/** The ID of the user of Paperless whose documents go to this user of Nextcloud, or null for none. */
	public function paperlessUserOf(string $user): ?int {
		return $this->paperlessUsers[$user] ?? null;
	}

	/**
	 * The users of Nextcloud that get documents: the target user first, then the others in the
	 * order of their IDs.
	 *
	 * @return list<string>
	 */
	public function users(): array {
		$users = array_keys($this->paperlessUsers);
		$users = array_values(array_filter($users, fn (string $user): bool => $user !== $this->fallback));
		sort($users, SORT_STRING);

		return [$this->fallback, ...$users];
	}

	/**
	 * Every user of Paperless, in the order of their names, with the user of Nextcloud that gets
	 * its documents, or null for the target user, why, and what the reason names: the user of
	 * Nextcloud of the table or the other users of Paperless with the same user of Nextcloud.
	 *
	 * @return list<Route>
	 */
	public function routes(): array {
		$routes = array_values($this->routes);
		usort($routes, self::byName(...));

		return $routes;
	}

	/**
	 * @param Route $left
	 * @param Route $right
	 */
	private static function byName(array $left, array $right): int {
		return [mb_strtolower($left['name']), $left['id']] <=> [mb_strtolower($right['name']), $right['id']];
	}
}
