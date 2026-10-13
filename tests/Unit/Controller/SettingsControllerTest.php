<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\SettingsController;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class SettingsControllerTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	/** @var PaperlessClientInterface&MockObject */
	private PaperlessClientInterface $paperless;
	/** @var NextcloudStorageInterface&MockObject */
	private NextcloudStorageInterface $storage;
	private SettingsController $controller;

	protected function setUp(): void {
		$this->paperless = $this->createMock(PaperlessClientInterface::class);
		$this->storage = $this->createMock(NextcloudStorageInterface::class);
		$this->controller = new SettingsController(
			AppConstants::APP_ID,
			$this->createMock(IRequest::class),
			$this->configService(),
			$this->paperless,
			$this->storage,
			$this->germanL10n(),
		);
	}

	public function testSavesSettingsThatWorkWithPaperlessAndNextcloud(): void {
		$this->paperless->expects(self::once())->method('testConnection')->with('https://paperless.example.test', 'secret-token', false);
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Dokumente/Paperless');

		$response = $this->controller->save(self::validSettings() + ['batch_size' => 20], 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$config = $response->getData();
		self::assertInstanceOf(SyncConfig::class, $config);
		self::assertSame(20, $config->batchSize);
		self::assertTrue($config->tokenConfigured);
		self::assertSame(20, $this->settings['batch_size']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	public function testInvalidSettingsAreRejectedBeforeAnyConnection(): void {
		$this->paperless->expects(self::never())->method('testConnection');

		$response = $this->controller->save(['paperless_url' => 'ftp://paperless.example.test'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'The Paperless URL must use HTTP or HTTPS.'], $response->getData());
		self::assertSame([], $this->settings);
	}

	public function testSavingTheSameUrlWithoutATokenTestsTheStoredOne(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('testConnection')->with('https://paperless.example.test', 'secret-token', false);

		$response = $this->controller->save(self::validSettings() + ['batch_size' => 30]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(30, $this->settings['batch_size']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * Whoever can change the settings, with the session of an administrator for example, must not
	 * receive the stored token by pointing the URL at a server of their own.
	 */
	public function testStoredTokenNeverGoesToANewUrl(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$stored = $this->settings;
		$this->paperless->expects(self::never())->method('testConnection');
		$this->storage->expects(self::never())->method('test');

		$response = $this->controller->save(['paperless_url' => 'https://attacker.example.test'] + self::validSettings());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Enter the API token again to connect to another Paperless URL.'], $response->getData());
		self::assertSame($stored, $this->settings);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	public function testANewUrlWorksWithTheTokenEnteredAgain(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('testConnection')->with('https://paperless.example.test/paperless', 'secret-token', false);

		$response = $this->controller->save(['paperless_url' => 'https://paperless.example.test/paperless/'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('https://paperless.example.test/paperless', $this->settings['paperless_url']);
	}

	public function testSettingsThatPaperlessRejectsAreNotSaved(): void {
		$this->paperless->method('testConnection')->willThrowException(new RuntimeException('Paperless could not query /api/documents/: HTTP 401.'));

		$response = $this->controller->save(self::validSettings(), 'wrong-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['message' => 'Could not validate the Paperless and Nextcloud configuration: Paperless could not query /api/documents/: HTTP 401.'],
			$response->getData(),
		);
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/** The test of the connection allows the local network as the form asks, before it is saved. */
	public function testTheConnectionIsTestedWithTheLocalNetworkOfTheForm(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('testConnection')->with('http://paperless:8000', 'secret-token', true);

		$response = $this->controller->save(['paperless_url' => 'http://paperless:8000', 'paperless_local_network' => true] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($this->settings['paperless_local_network']);
	}

	/**
	 * Nextcloud refuses a Paperless in the local network with "Host "paperless" violates local
	 * access rules". The page says instead what to do, in the language of the administrator, and
	 * names the setting, so that it can move the focus there.
	 */
	public function testARefusedLocalAddressSaysWhatToDo(): void {
		$this->paperless->method('testConnection')->willThrowException(new PaperlessLocalNetworkException('paperless'));
		$this->storage->expects(self::never())->method('test');

		$response = $this->controller->save(['paperless_url' => 'http://paperless:8000'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless. Wenn Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein.',
			'setting' => 'paperless_local_network',
		], $response->getData());
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/** @return array<string, array{string}> */
	public static function changesOfTheConnection(): array {
		return [
			'save' => ['save'],
			'disconnect' => ['reset'],
		];
	}

	/**
	 * Saving decides where the token goes, and disconnecting removes it, so Nextcloud asks for the
	 * password of the administrator first, unless it was confirmed in the last 30 minutes. A
	 * session that someone else took over is not enough.
	 */
	#[DataProvider('changesOfTheConnection')]
	public function testChangesOfTheConnectionNeedThePassword(string $method): void {
		self::assertCount(1, (new ReflectionMethod(SettingsController::class, $method))->getAttributes(PasswordConfirmationRequired::class));
	}

	public function testResetReturnsTheDefaults(): void {
		$this->controller->save(self::validSettings(), 'secret-token');

		$response = $this->controller->reset();

		$config = $response->getData();
		self::assertInstanceOf(SyncConfig::class, $config);
		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
		self::assertSame([], $this->settings);
	}
}
