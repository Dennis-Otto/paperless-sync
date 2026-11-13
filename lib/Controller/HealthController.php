<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\HealthToken;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/**
 * The health check, through which a monitor, such as Uptime Kuma or the RESTful sensor of Home
 * Assistant, reads the health of the mirror with a token of its own, and that token, which
 * administrators create on the settings page.
 *
 * The status API of StatusApiController takes the app password of an administrator, which opens
 * every file and the administration of the whole Nextcloud; a monitor needs far less. The health
 * check opens only with its token, in the header Authorization as "Bearer <token>", and answers
 * every call without it with 401, which Nextcloud counts against the address of the caller and
 * delays, until it refuses the address for a while, as for the webhook. It answers with the health
 * alone: no error of a run, which may name paths and documents, no counts and no user of Nextcloud.
 * docs/security.md has the whole threat model.
 */
final class HealthController extends Controller {
	/** The action under which Nextcloud counts and delays the calls with a wrong token. */
	public const THROTTLE_ACTION = 'paperless_sync_health';
	/** The most calls that Nextcloud accepts from one address in RATE_PERIOD seconds. */
	public const RATE_LIMIT = 60;
	public const RATE_PERIOD = 60;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private HealthToken $token,
		private StatusService $statusService,
		private ConfigService $configService,
		private IThrottler $throttler,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The health of the mirror, as the status API names it: whether a monitor should stay quiet, the
	 * health and the state of the last run, when a run that was no dry-run last succeeded, how many
	 * in a row did not since and when the first of them ended. It answers 200 whatever the health, so
	 * that a monitor tells a mirror that fails from a health check that it can't reach.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	#[AnonRateLimit(limit: self::RATE_LIMIT, period: self::RATE_PERIOD)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/health')]
	public function check(): JSONResponse {
		if (!$this->token->verify($this->request->getHeader('Authorization'))) {
			$response = new JSONResponse(['message' => 'The token of the health check is missing or wrong. Send it as the header Authorization: Bearer <token>.'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);

			return $response;
		}
		// Only a caller with the token ends the delay of its address, such as a monitor after an
		// administrator fixed a wrong token in its configuration.
		$this->throttler->resetDelay($this->request->getRemoteAddress(), self::THROTTLE_ACTION, ['action' => self::THROTTLE_ACTION]);
		$config = $this->configService->get();
		$health = $this->statusService->health($config);
		$failures = $this->statusService->failures();

		return new JSONResponse([
			'healthy' => StatusService::healthy($health),
			'health' => $health,
			'state' => $this->statusService->overview($config)['state'],
			'lastSuccess' => $this->statusService->lastSuccess(),
			'failedRuns' => $failures['runs'],
			'failingSince' => $failures['since'],
		]);
	}

	/** The health check as the settings page shows it, without the token. */
	#[FrontpageRoute(verb: 'GET', url: '/settings/health-token')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function state(): JSONResponse {
		return new JSONResponse($this->token->state());
	}

	/**
	 * Creates the token, or a new one in place of the one before, which stops working at once, and
	 * answers with it: the one time that anyone sees it.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/health-token')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function create(): JSONResponse {
		$token = $this->token->create();

		return new JSONResponse(['token' => $token] + $this->token->state());
	}

	/** Revokes the token: the health check refuses every call until a new token is created. */
	#[FrontpageRoute(verb: 'DELETE', url: '/settings/health-token')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function remove(): JSONResponse {
		$this->token->remove();

		return new JSONResponse($this->token->state());
	}
}
