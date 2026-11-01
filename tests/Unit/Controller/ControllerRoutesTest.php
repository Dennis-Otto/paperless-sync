<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\Controller\FileActionController;
use OCA\PaperlessSync\Controller\FolderInfoController;
use OCA\PaperlessSync\Controller\SettingsController;
use OCA\PaperlessSync\Controller\StatusApiController;
use OCA\PaperlessSync\Controller\SyncController;
use OCA\PaperlessSync\Controller\WebhookController;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nextcloud loads routes from appinfo/routes.php only for apps that are already loaded,
 * so a settings page rendered before the app was loaded got empty request URLs.
 * Attribute routes are loaded for every enabled app.
 */
final class ControllerRoutesTest extends TestCase {
	/**
	 * @return array<string, array{class-string, string, string, string}>
	 */
	public static function routes(): array {
		return [
			'settings save' => [SettingsController::class, 'save', 'POST', '/settings'],
			'settings reset' => [SettingsController::class, 'reset', 'DELETE', '/settings'],
			'settings test' => [SettingsController::class, 'test', 'POST', '/settings/test'],
			'settings preview' => [SettingsController::class, 'preview', 'POST', '/settings/preview'],
			'settings tags of Paperless' => [SettingsController::class, 'tags', 'GET', '/settings/paperless/tags'],
			'sync run' => [SyncController::class, 'run', 'POST', '/sync/run'],
			'sync status' => [SyncController::class, 'status', 'GET', '/sync/status'],
			'webhook' => [WebhookController::class, 'receive', 'POST', '/api/v1/webhook'],
			'webhook state' => [WebhookController::class, 'state', 'GET', '/settings/webhook'],
			'webhook secret' => [WebhookController::class, 'create', 'POST', '/settings/webhook'],
			'webhook off' => [WebhookController::class, 'remove', 'DELETE', '/settings/webhook'],
			'send to Paperless from Files' => [FileActionController::class, 'send', 'POST', '/api/v1/send'],
			'the note in Files' => [FolderInfoController::class, 'show', 'GET', '/api/v1/folder-info'],
			'hide the note in Files' => [FolderInfoController::class, 'dismiss', 'POST', '/api/v1/folder-info/dismissed'],
		];
	}

	/**
	 * @param class-string $controller
	 */
	#[DataProvider('routes')]
	public function testEveryActionDeclaresItsRoute(string $controller, string $method, string $verb, string $url): void {
		$attributes = (new ReflectionMethod($controller, $method))->getAttributes(FrontpageRoute::class);

		self::assertCount(1, $attributes);
		$route = $attributes[0]->newInstance();
		self::assertSame($verb, $route->getVerb());
		self::assertSame($url, $route->getUrl());
	}

	/** The status of the OCS API has its route there alone, which StatusApiControllerTest checks. */
	public function testTheStatusApiHasNoRouteOfThePages(): void {
		self::assertSame([], (new ReflectionMethod(StatusApiController::class, 'status'))->getAttributes(FrontpageRoute::class));
	}

	public function testNoRoutesComeFromTheLegacyRoutesFile(): void {
		self::assertFileDoesNotExist(__DIR__ . '/../../../appinfo/routes.php');
	}
}
