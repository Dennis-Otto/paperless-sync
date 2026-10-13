<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Lock\LockedException;

final class SyncController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $syncService,
		private StatusService $statusService,
		private IL10N $l10n,
		private ConfigService $configService,
	) {
		parent::__construct($appName, $request);
	}

	#[FrontpageRoute(verb: 'POST', url: '/sync/run')]
	public function run(bool $dryRun = true): JSONResponse {
		try {
			return new JSONResponse($this->syncService->run($dryRun));
		} catch (LockedException) {
			return new JSONResponse(['message' => 'Another synchronization run is already active.'], Http::STATUS_CONFLICT);
		} catch (PaperlessLocalNetworkException $exception) {
			return new JSONResponse(
				['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[FrontpageRoute(verb: 'GET', url: '/sync/status')]
	public function status(): JSONResponse {
		return new JSONResponse($this->statusService->overview($this->configService->get()));
	}
}
