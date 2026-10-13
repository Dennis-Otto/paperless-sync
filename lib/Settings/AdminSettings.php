<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Settings;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private StatusService $statusService,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$config = $this->configService->get();
		$status = $this->statusService->overview($config);
		// The page shows the status, its error and its report from the start, as after a refresh.
		$this->initialState->provideInitialState('status', $status);

		return new TemplateResponse(AppConstants::APP_ID, 'settings', [
			'config' => $config,
			'status' => $status,
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.save'),
			'resetUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.reset'),
			'runUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.sync.run'),
			'statusUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.sync.status'),
		]);
	}

	public function getSection(): string {
		return AppConstants::APP_ID;
	}

	public function getPriority(): int {
		return 55;
	}
}
