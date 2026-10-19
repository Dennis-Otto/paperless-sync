<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Listener;

use OC;
use OCA\PaperlessSync\Listener\LoadAdditionalScriptsListener;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FileActions;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class LoadAdditionalScriptsListenerTest extends TestCase {
	use InMemoryConfiguration;

	private ConfigService $configService;
	private ?string $user = 'alice';
	/** @var array<string, mixed> the initial states that the listener provided, by their keys */
	private array $states = [];
	private LoadAdditionalScriptsListener $listener;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(['send_group' => 'scanners'] + self::validSettings(), 'secret-token');
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $userId, string $groupId): bool => $userId === 'alice' && $groupId === 'scanners');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->user === null) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($this->user);

			return $user;
		});
		$initialState = $this->createStub(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(function (string $key, mixed $data): void {
			$this->states[$key] = $data;
		});
		$this->listener = new LoadAdditionalScriptsListener(
			new FileActions($this->configService, $groups, new TestStorage(), new TestStateRepository()),
			$session,
			$initialState,
		);
		$factory = $this->createStub(IFactory::class);
		$factory->method('findLanguage')->willReturn('de');
		OC::$server = new TestServer([IFactory::class => $factory]);
		self::initScripts([]);
	}

	protected function tearDown(): void {
		self::initScripts([]);
		OC::$server = null;
	}

	/** The event of Files, by its name, which Application registers the listener for. */
	public function testTheListenerIsForTheEventOfFilesThatLoadsTheScriptsOfApps(): void {
		self::assertSame('OCA\Files\Event\LoadAdditionalScriptsEvent', LoadAdditionalScriptsListener::EVENT);
	}

	/** The translations come first, as the script translates its actions when it registers them. */
	public function testAMemberOfTheGroupGetsTheScriptWithBothActions(): void {
		$this->listener->handle(new Event());

		self::assertSame(['files' => ['send' => true, 'paperlessUrl' => 'https://paperless.example.test']], $this->states);
		self::assertSame(['paperless_sync/l10n/de', 'paperless_sync/js/files'], self::initScripts());
	}

	public function testEveryoneElseGetsTheScriptToOpenDocuments(): void {
		$this->user = 'bob';

		$this->listener->handle(new Event());

		self::assertSame(['files' => ['send' => false, 'paperlessUrl' => 'https://paperless.example.test']], $this->states);
		self::assertContains('paperless_sync/js/files', self::initScripts());
	}

	/** Before the app knows Paperless, Files has nothing to offer. */
	public function testAnAppWithoutPaperlessAddsNothing(): void {
		$this->user = 'bob';
		$this->settings['paperless_url'] = '';

		$this->listener->handle(new Event());

		self::assertSame([], $this->states);
		self::assertSame([], self::initScripts());
	}

	/** A page without a user, such as a public share, gets nothing. */
	public function testAPageWithoutAUserGetsNothing(): void {
		$this->user = null;

		$this->listener->handle(new Event());

		self::assertSame([], $this->states);
		self::assertSame([], self::initScripts());
	}

	/**
	 * The init scripts of the page, which Util keeps for the whole request; the tests empty them.
	 *
	 * @param list<string>|null $scripts
	 * @return list<string>
	 */
	private static function initScripts(?array $scripts = null): array {
		$property = new ReflectionProperty(Util::class, 'scriptsInit');
		if ($scripts !== null) {
			$property->setValue(null, $scripts);
		}
		/** @var list<string> */
		return $property->getValue();
	}
}
