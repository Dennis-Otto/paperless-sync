<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\Controller\FolderInfoController;
use OCA\PaperlessSync\Service\FolderInfo;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class FolderInfoControllerTest extends TestCase {
	use InMemoryConfiguration;

	private const INBOX = 'Documents/Paperless/Inbox';

	private TestStorage $storage;
	private ?string $user = TestStorage::USER;
	/** @var array<string, array<string, true>> the kinds of the note that each user hid, by the keys of their settings */
	private array $dismissed = [];
	private FolderInfoController $controller;

	protected function setUp(): void {
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'secret-token');
		$this->storage = new TestStorage();
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->user === null) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($this->user);

			return $user;
		});
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueBool')->willReturnCallback(fn (string $user, string $app, string $key): bool => isset($this->dismissed[$user][$key]));
		$userConfig->method('setValueBool')->willReturnCallback(function (string $user, string $app, string $key): bool {
			$this->dismissed[$user][$key] = true;

			return true;
		});
		$userConfig->method('deleteUserConfig')->willReturnCallback(function (string $user, string $app, string $key): void {
			unset($this->dismissed[$user][$key]);
		});
		$this->controller = new FolderInfoController(
			'paperless_sync',
			$this->createStub(IRequest::class),
			new FolderInfo($configService, $this->statusService(new TestClock()), $this->storage, $userConfig, $this->createStub(LoggerInterface::class)),
			$session,
		);
	}

	/** Every user of Files sees the note, so the routes need no administrator and keep their request token. */
	#[DataProvider('actions')]
	public function testTheRouteIsForEveryUserWithItsRequestToken(string $action): void {
		$method = new ReflectionMethod(FolderInfoController::class, $action);

		self::assertCount(1, $method->getAttributes(NoAdminRequired::class));
		self::assertSame([], $method->getAttributes(NoCSRFRequired::class));
	}

	/** @return array<string, array{string}> */
	public static function actions(): array {
		return ['the note' => ['show'], 'hide it' => ['dismiss']];
	}

	public function testHidingANoteHasALimit(): void {
		$limits = (new ReflectionMethod(FolderInfoController::class, 'dismiss'))->getAttributes(UserRateLimit::class);

		self::assertCount(1, $limits);
		self::assertSame(['limit' => FolderInfoController::RATE_LIMIT, 'period' => FolderInfoController::RATE_PERIOD], $limits[0]->getArguments());
	}

	public function testTheAnswerIsForTheUserOfTheSession(): void {
		$this->storage->writeText(TestStorage::USER, self::INBOX . '/scan.pdf', '%PDF');

		$own = $this->controller->show();
		self::assertSame(Http::STATUS_OK, $own->getStatus());
		self::assertSame([['kind' => FolderInfo::INBOX, 'id' => $this->storage->findFileId(TestStorage::USER, self::INBOX), 'path' => self::INBOX]], $own->getData()['folders']);

		$this->user = 'alice';
		self::assertSame([], $this->controller->show()->getData()['folders'], 'Another user never gets the folders of the target user.');
	}

	public function testNoUserGetsNothing(): void {
		$this->user = null;

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->show()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->dismiss(FolderInfo::INBOX)->getStatus());
		self::assertSame([], $this->dismissed);
	}

	public function testAUserHidesAKindOfTheNoteAndBringsItBack(): void {
		$this->storage->writeText(TestStorage::USER, self::INBOX . '/scan.pdf', '%PDF');

		$hidden = $this->controller->dismiss(FolderInfo::INBOX);
		self::assertSame(Http::STATUS_OK, $hidden->getStatus());
		self::assertSame(['kind' => FolderInfo::INBOX, 'dismissed' => true], $hidden->getData());
		self::assertSame([FolderInfo::INBOX], $this->controller->show()->getData()['dismissed']);

		$back = $this->controller->dismiss(FolderInfo::INBOX, false);
		self::assertSame(['kind' => FolderInfo::INBOX, 'dismissed' => false], $back->getData());
		self::assertSame([], $this->controller->show()->getData()['dismissed']);
	}

	/** The kind becomes the name of a setting, so only the kinds of the app come through. */
	#[DataProvider('unknownKinds')]
	public function testAnUnknownKindIsRefused(string $kind): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->dismiss($kind)->getStatus());
		self::assertSame([], $this->dismissed);
	}

	/** @return array<string, array{string}> */
	public static function unknownKinds(): array {
		return ['none' => [''], 'another' => ['deleted'], 'another case' => ['Archive'], 'a key of another setting' => ['archive_x']];
	}
}
