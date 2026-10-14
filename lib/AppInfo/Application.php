<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\AppInfo;

use OCA\PaperlessSync\Notification\Notifier;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\NextcloudStorageService;
use OCA\PaperlessSync\Service\PaperlessApiService;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\SyncStateRepository;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
	public const APP_ID = AppConstants::APP_ID;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(PaperlessClientInterface::class, PaperlessApiService::class);
		$context->registerServiceAlias(NextcloudStorageInterface::class, NextcloudStorageService::class);
		$context->registerServiceAlias(SyncStateRepositoryInterface::class, SyncStateRepository::class);
		// The notifications of a failing mirror and of failed imports, and the checks of
		// Administration → Overview.
		$context->registerNotifierService(Notifier::class);
		$context->registerSetupCheck(SyncHealth::class);
		$context->registerSetupCheck(BackgroundJobMode::class);
	}

	public function boot(IBootContext $context): void {
	}
}
