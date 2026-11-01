<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * The users and groups of Nextcloud that the settings page offers for the target user and its
 * groups. The provisioning API of Nextcloud answers these questions only for its administrators,
 * and tells a delegated group that a user who exists doesn't, so the page asks here. The answers
 * hold what a picker shows and no more, so no e-mail address or quota (decision 0035).
 */
final class DirectoryController extends Controller {
	/** The most users or groups that a search answers: what the list of a picker shows. */
	public const SEARCH_LIMIT = 10;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The users whose account name, display name or e-mail address holds $search. The limit counts
	 * for each backend of users by itself, so the answer cuts them to it once more.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/settings/users')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function users(string $search = ''): JSONResponse {
		$users = [];
		foreach (array_slice($this->users->searchDisplayName($search, self::SEARCH_LIMIT), 0, self::SEARCH_LIMIT) as $user) {
			$users[] = ['id' => $user->getUID(), 'displayname' => $user->getDisplayName(), 'enabled' => $user->isEnabled()];
		}

		return new JSONResponse(['users' => $users]);
	}

	/** Whether Nextcloud has the user of the field: 404 when not, which the page marks. */
	#[FrontpageRoute(verb: 'GET', url: '/settings/users/{id}')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function user(string $id): JSONResponse {
		$exists = $this->users->userExists($id);

		return new JSONResponse(['exists' => $exists], $exists ? Http::STATUS_OK : Http::STATUS_NOT_FOUND);
	}

	/** The groups whose ID or name holds $search, cut to the limit as for the users. */
	#[FrontpageRoute(verb: 'GET', url: '/settings/groups')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function groups(string $search = ''): JSONResponse {
		$groups = [];
		foreach (array_slice($this->groups->search($search, self::SEARCH_LIMIT), 0, self::SEARCH_LIMIT) as $group) {
			$groups[] = ['id' => $group->getGID(), 'displayname' => $group->getDisplayName()];
		}

		return new JSONResponse(['groups' => $groups]);
	}
}
