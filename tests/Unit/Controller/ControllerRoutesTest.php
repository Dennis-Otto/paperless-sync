<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\Controller\DirectoryController;
use OCA\PaperlessSync\Controller\FileActionController;
use OCA\PaperlessSync\Controller\FolderInfoController;
use OCA\PaperlessSync\Controller\SettingsController;
use OCA\PaperlessSync\Controller\StatusApiController;
use OCA\PaperlessSync\Controller\SyncController;
use OCA\PaperlessSync\Controller\WebhookController;
use OCA\PaperlessSync\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
			'settings account' => [SettingsController::class, 'account', 'POST', '/settings/account'],
			'users to pick' => [DirectoryController::class, 'users', 'GET', '/settings/users'],
			'user of the field' => [DirectoryController::class, 'user', 'GET', '/settings/users/{id}'],
			'groups to pick' => [DirectoryController::class, 'groups', 'GET', '/settings/groups'],
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

	/**
	 * Every route of the settings page, found by its attribute, so that a new route can't be
	 * forgotten: without the attribute of the delegated settings, the page would break for the
	 * delegated group alone.
	 *
	 * @return array<string, array{ReflectionMethod}>
	 */
	public static function routesOfTheSettingsPage(): array {
		$routes = [];
		foreach ([SettingsController::class, SyncController::class, WebhookController::class, DirectoryController::class] as $controller) {
			foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				// The webhook itself is for a workflow of Paperless, not for the page.
				if ($method->getAttributes(FrontpageRoute::class) !== [] && $method->getAttributes(PublicPage::class) === []) {
					$routes["{$controller}::{$method->getName()}"] = [$method];
				}
			}
		}

		return $routes;
	}

	public function testTheRoutesOfTheSettingsPageAreAllFound(): void {
		self::assertCount(14, self::routesOfTheSettingsPage());
	}

	/**
	 * Administrators and the group that they delegate the settings to, as Nextcloud checks it by this
	 * attribute, and no one else: each of the others would let more people in, or skip the CSRF check.
	 */
	#[DataProvider('routesOfTheSettingsPage')]
	public function testEveryRouteOfTheSettingsPageIsForAdministratorsAndTheirDelegates(ReflectionMethod $method): void {
		$delegated = $method->getAttributes(AuthorizedAdminSetting::class);

		self::assertCount(1, $delegated);
		self::assertSame(AdminSettings::class, $delegated[0]->newInstance()->getSettings());
		foreach ([NoAdminRequired::class, SubAdminRequired::class, NoCSRFRequired::class] as $attribute) {
			self::assertSame([], $method->getAttributes($attribute), $attribute);
		}
	}

	/** A delegate confirms their password for the same actions as an administrator. */
	public function testWhatChangesTheConnectionAsksForThePasswordAlsoOfADelegate(): void {
		$confirmed = [];
		foreach (self::routesOfTheSettingsPage() as $name => [$method]) {
			if ($method->getAttributes(PasswordConfirmationRequired::class) !== []) {
				$confirmed[] = $name;
			}
		}

		self::assertSame([
			SettingsController::class . '::save',
			SettingsController::class . '::test',
			SettingsController::class . '::reset',
			SettingsController::class . '::account',
			WebhookController::class . '::create',
			WebhookController::class . '::remove',
		], $confirmed);
	}

	/** The status for monitors takes an app password of an administrator, and no delegation. */
	public function testTheStatusApiStaysWithTheAdministrators(): void {
		$method = new ReflectionMethod(StatusApiController::class, 'status');

		self::assertSame([], $method->getAttributes(AuthorizedAdminSetting::class));
		self::assertSame([], $method->getAttributes(NoAdminRequired::class));
	}

	public function testNoRoutesComeFromTheLegacyRoutesFile(): void {
		self::assertFileDoesNotExist(__DIR__ . '/../../../appinfo/routes.php');
	}
}
