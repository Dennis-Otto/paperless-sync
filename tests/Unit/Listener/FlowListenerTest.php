<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Listener;

use OC;
use OCA\PaperlessSync\Listener\FlowListener;
use OCA\PaperlessSync\Operation\SendToPaperless;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCP\EventDispatcher\Event;
use OCP\L10N\IFactory;
use OCP\Util;
use OCP\WorkflowEngine\Events\LoadSettingsScriptsEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class FlowListenerTest extends TestCase {
	private SendToPaperless $operation;
	private FlowListener $listener;

	protected function setUp(): void {
		$this->operation = (new ReflectionClass(SendToPaperless::class))->newInstanceWithoutConstructor();
		$this->listener = new FlowListener($this->operation);
		$factory = $this->createStub(IFactory::class);
		$factory->method('findLanguage')->willReturn('de');
		OC::$server = new TestServer([IFactory::class => $factory]);
		self::scripts([]);
	}

	protected function tearDown(): void {
		self::scripts([]);
		OC::$server = null;
	}

	public function testFlowGetsTheOperationOfTheApp(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::once())->method('registerOperation')->with(self::identicalTo($this->operation));

		$this->listener->handle(new RegisterOperationsEvent($manager));

		self::assertSame([], self::scripts());
	}

	/** The form of a rule registers itself with Flow, so it loads after the script of Flow, with the translations of the app. */
	public function testTheSettingsOfFlowLoadTheFormOfARule(): void {
		// Nextcloud's own record of the scripts that one of an app loads after, which OCP lacks.
		$dependencies = new class() {
			/** @var list<string> */
			public array $after = [];

			public function addDep(string $appId): void {
				$this->after[] = $appId;
			}
		};
		(new ReflectionProperty(Util::class, 'scriptDeps'))->setValue(null, ['paperless_sync' => $dependencies]);

		$this->listener->handle(new LoadSettingsScriptsEvent());

		self::assertSame(['paperless_sync' => ['paperless_sync/l10n/de', 'paperless_sync/js/flow']], self::scripts());
		self::assertSame(['workflowengine'], $dependencies->after);
	}

	public function testAnotherEventChangesNothing(): void {
		$this->listener->handle(new Event());

		self::assertSame([], self::scripts());
	}

	/**
	 * The scripts of the page by their apps, which Util keeps for the whole request; the tests empty them.
	 *
	 * @param array<string, list<string>>|null $scripts
	 * @return array<string, list<string>>
	 */
	private static function scripts(?array $scripts = null): array {
		$property = new ReflectionProperty(Util::class, 'scripts');
		if ($scripts !== null) {
			$property->setValue(null, $scripts);
			(new ReflectionProperty(Util::class, 'scriptDeps'))->setValue(null, []);
		}
		/** @var array<string, list<string>> */
		return $property->getValue();
	}
}
