<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UnsavedSettingException;
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

	/**
	 * A run, or a dry-run, by default. A dry-run may come with the settings of the page, which it
	 * uses without saving them, so that the page shows what they do before a save; they keep the
	 * saved connection to Paperless and the saved target user (ConfigService::validateForDryRun()).
	 * A run uses the saved settings only.
	 *
	 * @param array<string, mixed>|null $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/sync/run')]
	public function run(bool $dryRun = true, ?array $settings = null): JSONResponse {
		if ($settings !== null && !$dryRun) {
			return new JSONResponse(['message' => $this->l10n->t('Save the settings first: only a dry-run can use settings that are not saved.')], Http::STATUS_BAD_REQUEST);
		}
		try {
			$config = $settings === null ? null : $this->configService->validateForDryRun($settings);
		} catch (UnsavedSettingException $exception) {
			// The setting to save first, which the page moves the focus to.
			return new JSONResponse(['message' => $exception->translated($this->l10n), 'setting' => $exception->setting], Http::STATUS_BAD_REQUEST);
		} catch (InvalidSettingException $exception) {
			$answer = ['message' => $exception->translated($this->l10n)];
			if ($exception->setting !== '') {
				$answer['setting'] = $exception->setting;
			}

			return new JSONResponse($answer, Http::STATUS_BAD_REQUEST);
		}
		try {
			return new JSONResponse($this->syncService->run($dryRun, $config));
		} catch (LockedException) {
			return new JSONResponse(['message' => $this->l10n->t('Another synchronization run is already active.')], Http::STATUS_CONFLICT);
		} catch (PaperlessLocalNetworkException $exception) {
			return new JSONResponse(
				['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		} catch (\Throwable $exception) {
			// A run checks the target user and the base folder again, which may have changed since the save.
			$message = $exception instanceof InvalidSettingException ? $exception->translated($this->l10n) : $exception->getMessage();

			return new JSONResponse(['message' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[FrontpageRoute(verb: 'GET', url: '/sync/status')]
	public function status(): JSONResponse {
		return new JSONResponse($this->statusService->overview($this->configService->get()));
	}
}
