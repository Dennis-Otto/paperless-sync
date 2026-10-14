<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The health of the mirror for a monitor, such as Uptime Kuma or the RESTful sensor of Home
 * Assistant, at /ocs/v2.php/apps/paperless_sync/api/v1/status. Only administrators may read it,
 * as every route of the app that doesn't say otherwise.
 */
final class StatusApiController extends OCSController {
	/** The states of health in which a monitor should raise an alarm. */
	private const UNHEALTHY = ['failed', 'errors', 'stale'];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private StatusService $statusService,
		private ConfigService $configService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The health of the mirror, as the setup check of Administration → Overview names it, the last
	 * run, also a dry-run: its state, when it started and ended, its counts and the Paperless that it
	 * reached, and of the runs that are no dry-runs: when one last succeeded, how many in a row did
	 * not since, and the error of the last of them.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{healthy: bool, health: string, enabled: bool, state: string, lastStarted: int, lastEnded: int, lastSuccess: int, nextRun: int|null, error: string, failedRuns: int, failingSince: int, summary: array<string, mixed>, paperlessVersion: string, paperlessApiVersion: string}, array{}>
	 */
	#[ApiRoute(verb: 'GET', url: '/api/v1/status')]
	public function status(): DataResponse {
		$config = $this->configService->get();
		$status = $this->statusService->overview($config);
		$health = $this->statusService->health($config);
		$failures = $this->statusService->failures();

		return new DataResponse([
			'healthy' => !in_array($health, self::UNHEALTHY, true),
			'health' => $health,
			'enabled' => $config->enabled,
			'state' => $status['state'],
			'lastStarted' => $status['lastStarted'],
			'lastEnded' => $status['lastEnded'],
			'lastSuccess' => $status['lastSuccess'],
			'nextRun' => $status['nextRun'],
			'error' => $failures['error'],
			'failedRuns' => $failures['runs'],
			'failingSince' => $failures['since'],
			'summary' => $status['summary'],
			'paperlessVersion' => $status['paperlessVersion'],
			'paperlessApiVersion' => $status['paperlessApiVersion'],
		]);
	}
}
