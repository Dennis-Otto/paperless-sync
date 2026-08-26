<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use InvalidArgumentException;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessClientInterface $paperless,
		private NextcloudStorageInterface $storage,
	) {
		parent::__construct($appName, $request);
	}

	/** @param array<string, mixed> $settings */
	public function save(array $settings = [], string $token = ''): JSONResponse {
		try {
			$config = $this->configService->validate($settings, $token);
			$effectiveToken = $this->configService->resolveToken($token);
			$this->paperless->testConnection($config->paperlessUrl, $effectiveToken);
			$this->storage->test($config->targetUser, $config->basePath);

			return new JSONResponse($this->configService->save($settings, $token));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $exception) {
			return new JSONResponse(
				['message' => 'Could not validate the Paperless and Nextcloud configuration: ' . $exception->getMessage()],
				Http::STATUS_BAD_REQUEST,
			);
		}
	}

	public function reset(): JSONResponse {
		return new JSONResponse($this->configService->reset());
	}
}
