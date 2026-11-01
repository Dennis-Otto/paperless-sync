<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * Who may view a document of Paperless by its own permissions, what its interface calls "Shared
 * with me": the users and the groups that the field permissions of the document names under view.
 * Paperless sends that field only for a request with full_perms=true, in the same shape in 2.x and
 * 3.x, and gives whoever may change a document the permission to view it too, so the list of view
 * names everyone. The owner of the document and the superusers need no such permission, and
 * Paperless doesn't name them for it.
 */
final class DocumentPermissions {
	/**
	 * @param list<int> $users the IDs of the users of Paperless with the permission
	 * @param list<int> $groups the IDs of the groups of Paperless with the permission
	 */
	public function __construct(
		public readonly array $users,
		public readonly array $groups,
	) {
	}

	/**
	 * The permissions of a document, or null when its answer doesn't say who may view it, as without
	 * the field, or with anything else than the lists of IDs: the run then can't tell who gets a
	 * copy, or who no longer may have one.
	 *
	 * @param array<string, mixed> $document
	 * @psalm-suppress MixedAssignment
	 */
	public static function of(array $document): ?self {
		$permissions = $document['permissions'] ?? null;
		$view = is_array($permissions) ? $permissions['view'] ?? null : null;
		if (!is_array($view)) {
			return null;
		}
		$users = self::ids($view['users'] ?? null);
		$groups = self::ids($view['groups'] ?? null);

		return $users !== null && $groups !== null ? new self($users, $groups) : null;
	}

	/**
	 * The IDs of a list, each a number or a string of digits, as for the owner of a document; null
	 * for anything else.
	 *
	 * @return list<int>|null
	 * @psalm-suppress MixedAssignment
	 */
	private static function ids(mixed $values): ?array {
		if (!is_array($values)) {
			return null;
		}
		$ids = [];
		foreach ($values as $value) {
			if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
				return null;
			}
			$ids[] = (int)$value;
		}

		return $ids;
	}
}
