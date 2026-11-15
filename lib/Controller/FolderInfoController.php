<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\FolderInfo;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The note above the list of Files in a folder of the app, for every user of Files: which folders
 * have one for the user who asks, never for another, and which kinds of it they hid.
 */
final class FolderInfoController extends Controller {
	/** The clicks of a user in a minute at most, on the buttons that hide a note and bring it back. */
	public const RATE_LIMIT = 30;
	public const RATE_PERIOD = 60;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private FolderInfo $folderInfo,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/** The folders of the app that the user sees, with what the note needs to say about them. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/folder-info')]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->folderInfo->of($user->getUID()));
	}

	/** Hides a kind of the note for the user, or with $dismissed false brings it back. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::RATE_LIMIT, period: self::RATE_PERIOD)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/folder-info/dismissed')]
	public function dismiss(string $kind = '', bool $dismissed = true): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if (!in_array($kind, FolderInfo::KINDS, true)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$this->folderInfo->dismiss($user->getUID(), $kind, $dismissed);

		return new JSONResponse(['kind' => $kind, 'dismissed' => $dismissed]);
	}
}
