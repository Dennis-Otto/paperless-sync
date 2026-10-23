<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\AppInfo;

use OCA\PaperlessSync\Listener\FlowListener;
use OCA\PaperlessSync\Listener\LoadAdditionalScriptsListener;
use OCA\PaperlessSync\Notification\Notifier;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\NextcloudStorageService;
use OCA\PaperlessSync\Service\PaperlessClientFactory;
use OCA\PaperlessSync\Service\PaperlessClientFactoryInterface;
use OCA\PaperlessSync\Service\SyncStateRepository;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\Service\UserFilesystem;
use OCA\PaperlessSync\Service\UserFilesystemInterface;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\WorkflowEngine\Events\LoadSettingsScriptsEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

final class Application extends App implements IBootstrap {
	public const APP_ID = AppConstants::APP_ID;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// A client of Paperless belongs to one connection, which the factory takes for each run.
		$context->registerServiceAlias(PaperlessClientFactoryInterface::class, PaperlessClientFactory::class);
		$context->registerServiceAlias(NextcloudStorageInterface::class, NextcloudStorageService::class);
		$context->registerServiceAlias(SyncStateRepositoryInterface::class, SyncStateRepository::class);
		$context->registerServiceAlias(UserFilesystemInterface::class, UserFilesystem::class);
		// The notifications of a failing mirror and of failed imports, and the checks of
		// Administration → Overview.
		$context->registerNotifierService(Notifier::class);
		$context->registerSetupCheck(SyncHealth::class);
		$context->registerSetupCheck(BackgroundJobMode::class);
		// "Send to Paperless" and "Open in Paperless" in the menu of Files.
		$context->registerEventListener(LoadAdditionalScriptsListener::EVENT, LoadAdditionalScriptsListener::class);
		// "Send to Paperless" in Flow, for rules of the administrators.
		$context->registerEventListener(RegisterOperationsEvent::class, FlowListener::class);
		$context->registerEventListener(LoadSettingsScriptsEvent::class, FlowListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
