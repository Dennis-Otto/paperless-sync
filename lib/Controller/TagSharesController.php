<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\TagShares;
use OCA\PaperlessSync\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The shares of the target user that show a user with tags more than their tags, under "Tags that
 * a user may see" of the settings page, for the saved settings: the list, and their removal, which
 * only a click of an administrator asks for, with the password confirmed, as it can't be undone.
 */
final class TagSharesController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private TagShares $tagShares,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/** The shares, as TagShares::find() names them. */
	#[FrontpageRoute(verb: 'GET', url: '/settings/user-tags/shares')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function shares(): JSONResponse {
		try {
			return new JSONResponse(['shares' => $this->tagShares->find($this->configService->get())]);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $this->l10n->t('Nextcloud did not list the shares of %1$s: %2$s', [$this->configService->get()->targetUser, $exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Removes the shares of $ids that the page listed and that still show a user with tags more
	 * than their tags, and answers how many it removed and the shares that are left.
	 *
	 * @param array<array-key, mixed> $ids the IDs of the shares, as the page listed them
	 */
	#[FrontpageRoute(verb: 'DELETE', url: '/settings/user-tags/shares')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function remove(array $ids = []): JSONResponse {
		$config = $this->configService->get();
		try {
			$removed = $this->tagShares->remove($config, array_values(array_filter($ids, 'is_string')));
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $this->l10n->t('Nextcloud did not remove the shares: %s', [$exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['removed' => $removed, 'shares' => $this->tagShares->find($config)]);
	}
}
