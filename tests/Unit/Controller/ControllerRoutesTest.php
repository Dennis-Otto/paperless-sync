<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\Controller\SettingsController;
use OCA\PaperlessSync\Controller\SyncController;
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
			'sync run' => [SyncController::class, 'run', 'POST', '/sync/run'],
			'sync status' => [SyncController::class, 'status', 'GET', '/sync/status'],
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

	public function testNoRoutesComeFromTheLegacyRoutesFile(): void {
		self::assertFileDoesNotExist(__DIR__ . '/../../../appinfo/routes.php');
	}
}
