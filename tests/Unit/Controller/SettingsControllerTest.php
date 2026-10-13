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
use OCP\AppFramework\Http\JSONResponse;
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
		$answer = self::answer($response);
		self::assertSame(20, $answer['batchSize']);
		self::assertTrue($answer['tokenConfigured']);
		self::assertTrue($answer['connectionTested']);
		self::assertSame(20, $this->settings['batch_size']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * The page shows what the server stored, so the answer has every value as it was stored,
	 * normalized, and never the token.
	 */
	public function testTheAnswerIsTheStoredConfiguration(): void {
		$response = $this->controller->save([
			'paperless_url' => 'https://paperless.example.test/',
			'excluded_tags' => 'Private, Draft, Private',
			'untitled' => ' ',
		] + self::validSettings(), 'secret-token');

		$answer = self::answer($response);
		$stored = $this->configService()->get()->jsonSerialize();
		self::assertSame($stored + ['connectionTested' => true], $answer);
		self::assertSame('https://paperless.example.test', $answer['paperlessUrl']);
		self::assertSame('Private, Draft', $answer['excludedTags']);
		self::assertSame('Unknown', $answer['untitled']);
		self::assertStringNotContainsString('secret-token', json_encode($answer, JSON_THROW_ON_ERROR));
	}

	public function testInvalidSettingsAreRejectedBeforeAnyConnection(): void {
		$this->paperless->expects(self::never())->method('testConnection');

		$response = $this->controller->save(['paperless_url' => 'ftp://paperless.example.test'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'The Paperless URL must use HTTP or HTTPS.'], $response->getData());
		self::assertSame([], $this->settings);
	}

	/** The schedule will run with the settings, so they are tested with the stored token first. */
	public function testSavingAScheduleTestsTheStoredToken(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('testConnection')->with('https://paperless.example.test', 'secret-token', false);
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Dokumente/Paperless');

		$response = $this->controller->save(self::validSettings() + ['enabled' => true, 'batch_size' => 30]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue(self::answer($response)['connectionTested']);
		self::assertSame(30, $this->settings['batch_size']);
		self::assertTrue($this->settings['sync_enabled']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * Without the schedule and without a new token, nothing reaches Paperless until a run by
	 * hand, which tests the settings on its own, so saving needs no test.
	 */
	public function testSavingWithoutTheScheduleAndATokenNeedsNoTest(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::never())->method('testConnection');
		$this->storage->expects(self::never())->method('test');

		$response = $this->controller->save(self::validSettings() + ['batch_size' => 30]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse(self::answer($response)['connectionTested']);
		self::assertSame(30, $this->settings['batch_size']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/** @return array<string, array{string}> what fails while the schedule runs: Paperless, or the folder of the target user */
	public static function failuresOfTheSchedule(): array {
		return [
			'Paperless is down' => ['testConnection'],
			'the target user is disabled' => ['test'],
		];
	}

	/**
	 * While Paperless is down, its token revoked or the target user disabled, every scheduled run
	 * fails. Turning the schedule off must still save, without the test that would fail too.
	 */
	#[DataProvider('failuresOfTheSchedule')]
	public function testAScheduleThatFailsCanBeTurnedOff(string $failure): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$this->failWith($failure);

		$response = $this->controller->save(self::validSettings() + ['enabled' => false]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$answer = self::answer($response);
		self::assertFalse($answer['enabled']);
		self::assertFalse($answer['connectionTested']);
		self::assertTrue($answer['tokenConfigured']);
		self::assertFalse($this->settings['sync_enabled']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * A schedule that would fail is not saved, and the answer names the way to save anyway, the
	 * setting of the schedule, which the page moves the focus to.
	 */
	#[DataProvider('failuresOfTheSchedule')]
	public function testAScheduleThatWouldFailSaysHowToSaveWithoutIt(string $failure): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$stored = $this->settings;
		$this->failWith($failure);

		$response = $this->controller->save(self::validSettings() + ['enabled' => true, 'batch_size' => 30]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'Could not validate the Paperless and Nextcloud configuration: Unavailable. To save the settings without the test, turn off scheduled synchronization.',
			'setting' => 'enabled',
		], $response->getData());
		self::assertSame($stored, $this->settings);
	}

	/** A token that was entered is tested, also with the schedule off, so a wrong one is never stored. */
	public function testANewTokenIsTestedAlsoWithoutTheSchedule(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('testConnection')->with('https://paperless.example.test', 'new-token', false);

		$response = $this->controller->save(self::validSettings(), ' new-token ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue(self::answer($response)['connectionTested']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'new-token'], $this->credential);
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
			'message' => 'Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless. Wenn Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.',
			'setting' => 'paperless_local_network',
		], $response->getData());
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/**
	 * Without the setting, Nextcloud looks the name of Paperless up only in the DNS, so a name of
	 * the hosts file of the server fails with "No DNS record found". The page names the setting too.
	 */
	public function testANameWithoutADnsRecordSaysWhatToDo(): void {
		$this->paperless->method('testConnection')->willThrowException(new PaperlessLocalNetworkException('paperless.home.arpa', true));

		$response = $this->controller->save(['paperless_url' => 'http://paperless.home.arpa:8000'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'Nextcloud hat keinen DNS-Eintrag für paperless.home.arpa gefunden. Wenn der Name nur in der hosts-Datei des Servers steht oder Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.',
			'setting' => 'paperless_local_network',
		], $response->getData());
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

	/**
	 * Disconnecting deletes the token, as its button and its question say, and turns off the
	 * schedule; the answer is the configuration that stays, which the page shows.
	 */
	public function testDisconnectAnswersWithTheConfigurationThatStays(): void {
		$this->controller->save(self::validSettings() + ['enabled' => true, 'permanent_delete' => true, 'batch_size' => 20], 'secret-token');

		$response = $this->controller->reset();

		$config = $response->getData();
		self::assertInstanceOf(SyncConfig::class, $config);
		self::assertSame($this->configService()->get()->jsonSerialize(), $config->jsonSerialize());
		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->enabled);
		self::assertTrue($config->permanentDelete);
		self::assertSame(20, $config->batchSize);
		self::assertNull($this->credential);
	}

	/** @return array<string, mixed> the data of an answer, decoded as the page receives it */
	private static function answer(JSONResponse $response): array {
		/** @var array<string, mixed> */
		return json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
	}

	/** Makes the test of Paperless, testConnection, or of the folder in Nextcloud, test, fail. */
	private function failWith(string $failure): void {
		$double = $failure === 'test' ? $this->storage : $this->paperless;
		$double->method($failure)->willThrowException(new RuntimeException('Unavailable.'));
	}
}
