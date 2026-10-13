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
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

final class SettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessClientInterface $paperless,
		private NextcloudStorageInterface $storage,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Saving decides where the token goes, so Nextcloud asks for the password of the administrator
	 * first, unless it was confirmed in the last 30 minutes; a session that someone else took over
	 * is not enough. The stored token is tested only with the URL that it was stored with, and an
	 * address of the local network only when the settings allow it.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function save(array $settings = [], #[\SensitiveParameter] string $token = ''): JSONResponse {
		try {
			$config = $this->configService->validate($settings, $token);
			$effectiveToken = $this->configService->resolveToken($token, $config->paperlessUrl);
			$this->paperless->testConnection($config->paperlessUrl, $effectiveToken, $config->paperlessLocalNetwork);
			$this->storage->test($config->targetUser, $config->basePath);

			return new JSONResponse($this->configService->save($settings, $token));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (PaperlessLocalNetworkException $exception) {
			// What to do, and the setting that does it, which the page moves the focus to.
			return new JSONResponse(
				['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $exception) {
			return new JSONResponse(
				['message' => 'Could not validate the Paperless and Nextcloud configuration: ' . $exception->getMessage()],
				Http::STATUS_BAD_REQUEST,
			);
		}
	}

	/** Disconnecting deletes the token, also only with the password confirmed. */
	#[FrontpageRoute(verb: 'DELETE', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function reset(): JSONResponse {
		return new JSONResponse($this->configService->reset());
	}
}
