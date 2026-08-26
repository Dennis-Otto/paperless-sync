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
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private StatusService $statusService,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(AppConstants::APP_ID, 'settings', [
			'config' => $this->configService->get(),
			'status' => $this->statusService->get(),
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
