<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Lock\LockedException;

final class SyncController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $syncService,
		private StatusService $statusService,
	) {
		parent::__construct($appName, $request);
	}

	public function run(bool $dryRun = true): JSONResponse {
		try {
			return new JSONResponse($this->syncService->run($dryRun));
		} catch (LockedException) {
			return new JSONResponse(['message' => 'Another synchronization run is already active.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function status(): JSONResponse {
		return new JSONResponse($this->statusService->get());
	}
}
