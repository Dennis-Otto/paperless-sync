<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\SettingsController;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class SettingsControllerTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	/** What the checklist says when Paperless refuses the token, with what to do, in German. */
	private const TOKEN_REFUSED = 'Paperless hat das API-Token abgelehnt (HTTP 401). Kopiere das Token noch einmal aus Paperless, unter „Mein Profil“ im Menü seines Kontos, und prüfe, ob das Konto aktiv ist.';
	/** How to save the settings of a schedule that fails, in German. */
	private const TURN_OFF_THE_SCHEDULE = 'Um die Einstellungen ohne den Test zu speichern, schalte die geplante Synchronisation aus.';

	/** @var PaperlessClientInterface&MockObject */
	private PaperlessClientInterface $paperless;
	/** @var NextcloudStorageInterface&MockObject */
	private NextcloudStorageInterface $storage;
	private SettingsController $controller;
	/** @var list<ConnectionCheck> the lines of Paperless of the checklist, as the client answers them */
	private array $checks = [];
	/** @var list<array{string, string, bool}> the URL, the token and whether the local network was allowed, of every checklist */
	private array $preflights = [];
	/** A failure of the checklist of Paperless itself, which its contract leaves out. */
	private ?\Throwable $preflightFailure = null;

	protected function setUp(): void {
		$this->checks = [
			ConnectionCheck::passed(ConnectionCheck::PAPERLESS, 'Paperless-ngx %s answers at this URL.', ['2.20.15']),
			ConnectionCheck::passed(ConnectionCheck::DOCUMENTS, 'Visible to the account: %s', ['12']),
		];
		$this->paperless = $this->createMock(PaperlessClientInterface::class);
		$this->paperless->expects(self::never())->method('testConnection');
		$this->paperless->method('preflight')->willReturnCallback(function (SyncConfig $config, string $token): array {
			$this->preflights[] = [$config->paperlessUrl, $token, $config->paperlessLocalNetwork];
			if ($this->preflightFailure !== null) {
				throw $this->preflightFailure;
			}

			return $this->checks;
		});
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
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Dokumente/Paperless');

		$response = $this->controller->save(self::validSettings() + ['batch_size' => 20], 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['https://paperless.example.test', 'secret-token', false]], $this->preflights);
		$answer = self::answer($response);
		self::assertSame(20, $answer['batchSize']);
		self::assertTrue($answer['tokenConfigured']);
		self::assertTrue($answer['connectionTested']);
		self::assertSame([
			['id' => 'paperless', 'label' => 'Paperless', 'status' => 'passed', 'message' => 'Paperless-ngx 2.20.15 antwortet unter dieser Adresse.', 'hint' => '', 'setting' => ''],
			['id' => 'documents', 'label' => 'Dokumente', 'status' => 'passed', 'message' => 'Für das Konto sichtbar: 12', 'hint' => '', 'setting' => ''],
			['id' => 'folder', 'label' => 'Nextcloud-Ordner', 'status' => 'passed', 'message' => 'paperless darf Dateien in Dokumente/Paperless anlegen.', 'hint' => '', 'setting' => ''],
		], $answer['checks'], 'The checklist ends with the folder of the target user.');
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
		self::assertSame($stored + ['connectionTested' => true], array_diff_key($answer, ['checks' => true]));
		self::assertCount(3, $answer['checks']);
		self::assertSame('https://paperless.example.test', $answer['paperlessUrl']);
		self::assertSame('Private, Draft', $answer['excludedTags']);
		self::assertSame('Unknown', $answer['untitled']);
		self::assertStringNotContainsString('secret-token', json_encode($answer, JSON_THROW_ON_ERROR));
	}

	public function testInvalidSettingsAreRejectedBeforeAnyConnection(): void {
		$response = $this->controller->save(['paperless_url' => 'ftp://paperless.example.test'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'The Paperless URL must use HTTP or HTTPS.'], $response->getData());
		self::assertSame([], $this->preflights);
		self::assertSame([], $this->settings);
	}

	/** The schedule will run with the settings, so they are tested with the stored token first. */
	public function testSavingAScheduleTestsTheStoredToken(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Dokumente/Paperless');

		$response = $this->controller->save(self::validSettings() + ['enabled' => true, 'batch_size' => 30]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['https://paperless.example.test', 'secret-token', false]], $this->preflights);
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
		$this->storage->expects(self::never())->method('test');

		$response = $this->controller->save(self::validSettings() + ['batch_size' => 30]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $this->preflights);
		$answer = self::answer($response);
		self::assertFalse($answer['connectionTested']);
		self::assertSame([], $answer['checks']);
		self::assertSame(30, $this->settings['batch_size']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/** @return array<string, array{string, string}> what fails while the schedule runs, Paperless or the folder of the target user, and what the checklist says of it */
	public static function failuresOfTheSchedule(): array {
		return [
			'Paperless is down' => [
				'paperless',
				'Paperless war nicht erreichbar: Unavailable. Prüfe die Paperless-Adresse und ob der Nextcloud-Server sie über sein Netzwerk, seine Firewall und seinen Proxy erreicht.',
			],
			'the target user is disabled' => [
				'folder',
				'Nextcloud kann die Dateien dort nicht speichern: Unavailable. Prüfe die ID des Zielbenutzers und ob der Basisordner ein Ordner ist, in dem dieser Benutzer Dateien anlegen darf.',
			],
		];
	}

	/**
	 * While Paperless is down, its token revoked or the target user disabled, every scheduled run
	 * fails. Turning the schedule off must still save, without the test that would fail too.
	 */
	#[DataProvider('failuresOfTheSchedule')]
	public function testAScheduleThatFailsCanBeTurnedOff(string $failure, string $message): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$this->failWith($failure);

		$response = $this->controller->save(self::validSettings() + ['enabled' => false]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$answer = self::answer($response);
		self::assertFalse($answer['enabled']);
		self::assertFalse($answer['connectionTested']);
		self::assertTrue($answer['tokenConfigured']);
		self::assertSame([], $this->preflights);
		self::assertFalse($this->settings['sync_enabled']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * A schedule that would fail is not saved, and the answer says what failed and the way to save
	 * anyway, the setting of the schedule, which the page moves the focus to.
	 */
	#[DataProvider('failuresOfTheSchedule')]
	public function testAScheduleThatWouldFailSaysHowToSaveWithoutIt(string $failure, string $message): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$stored = $this->settings;
		$this->failWith($failure);

		$response = $this->controller->save(self::validSettings() + ['enabled' => true, 'batch_size' => 30]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$answer = self::answer($response);
		self::assertSame($message . ' ' . self::TURN_OFF_THE_SCHEDULE, $answer['message']);
		self::assertSame('enabled', $answer['setting']);
		self::assertContains($failure, array_column(array_filter($answer['checks'], static fn (array $line): bool => $line['status'] === 'failed'), 'id'));
		self::assertSame($stored, $this->settings);
	}

	/**
	 * The checklist of Paperless reports its failures as lines. Should it fail on its own, against
	 * its contract, the answer still names the way out of a schedule that fails.
	 */
	public function testAnUnexpectedFailureOfTheChecklistSaysHowToSaveWithoutTheSchedule(): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$this->preflightFailure = new RuntimeException('Unavailable.');

		$response = $this->controller->save(self::validSettings() + ['enabled' => true]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'Could not validate the Paperless and Nextcloud configuration: Unavailable. ' . self::TURN_OFF_THE_SCHEDULE,
			'setting' => 'enabled',
		], $response->getData());
	}

	/**
	 * The hint starts a sentence of its own, also after a message without a full stop at its end,
	 * such as one of Guzzle while Paperless is down, which ends with the URL.
	 */
	public function testTheHintOfTheScheduleIsASentenceOfItsOwn(): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$this->preflightFailure = new RuntimeException(
			'cURL error 7: Failed to connect to paperless.example.test port 443 for https://paperless.example.test/api/documents/ ',
		);

		$response = $this->controller->save(self::validSettings() + ['enabled' => true]);

		self::assertSame(
			'Could not validate the Paperless and Nextcloud configuration: cURL error 7: Failed to connect to paperless.example.test port 443 for https://paperless.example.test/api/documents/. ' . self::TURN_OFF_THE_SCHEDULE,
			$response->getData()['message'],
		);
	}

	/**
	 * A save that fails after the checks passed, such as in the credentials store, is no failure
	 * of the schedule: the answer names no setting, so the page doesn't send the administrator to
	 * the schedule, whose test passed.
	 */
	public function testASaveThatFailsAfterTheTestsDoesNotPointToTheSchedule(): void {
		$this->configService()->save(self::validSettings() + ['enabled' => true], 'secret-token');
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturnCallback(fn (): mixed => $this->credential);
		$credentials->method('store')->willThrowException(new RuntimeException('Unavailable.'));
		$controller = new SettingsController(
			AppConstants::APP_ID,
			$this->createMock(IRequest::class),
			new ConfigService($this->appConfig(), $credentials, new PathTemplateService()),
			$this->paperless,
			$this->storage,
			$this->germanL10n(),
		);
		$this->storage->expects(self::once())->method('test');

		$response = $controller->save(self::validSettings() + ['enabled' => true]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertCount(1, $this->preflights);
		self::assertSame(['message' => 'Could not validate the Paperless and Nextcloud configuration: Unavailable.'], $response->getData());
	}

	/** A token that was entered is tested, also with the schedule off, so a wrong one is never stored. */
	public function testANewTokenIsTestedAlsoWithoutTheSchedule(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');

		$response = $this->controller->save(self::validSettings(), ' new-token ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['https://paperless.example.test', 'new-token', false]], $this->preflights);
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
		$this->storage->expects(self::never())->method('test');

		$response = $this->controller->save(['paperless_url' => 'https://attacker.example.test'] + self::validSettings());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Enter the API token again to connect to another Paperless URL.'], $response->getData());
		self::assertSame([], $this->preflights);
		self::assertSame($stored, $this->settings);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	public function testANewUrlWorksWithTheTokenEnteredAgain(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');

		$response = $this->controller->save(['paperless_url' => 'https://paperless.example.test/paperless/'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['https://paperless.example.test/paperless', 'secret-token', false]], $this->preflights);
		self::assertSame('https://paperless.example.test/paperless', $this->settings['paperless_url']);
	}

	/**
	 * A token that Paperless refuses makes every run fail, so it is not saved. The answer says
	 * what to do, in the language of the administrator, and lists the checklist.
	 */
	public function testSettingsThatPaperlessRejectsAreNotSaved(): void {
		$this->checks = [self::tokenRefused()];

		$response = $this->controller->save(self::validSettings(), 'wrong-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$answer = self::answer($response);
		self::assertSame(self::TOKEN_REFUSED, $answer['message']);
		self::assertArrayNotHasKey('setting', $answer, 'A token entered by hand is no question of the schedule.');
		self::assertSame(['paperless', 'folder'], array_column($answer['checks'], 'id'));
		self::assertSame('failed', $answer['checks'][0]['status']);
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/** A base folder in which the target user can't create files makes every run fail as well. */
	public function testAFolderThatCannotTakeFilesIsNotSaved(): void {
		$this->storage->method('test')->willThrowException(new RuntimeException('The configured Nextcloud base folder is not a writable folder.'));

		$response = $this->controller->save(self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			'Nextcloud kann die Dateien dort nicht speichern: The configured Nextcloud base folder is not a writable folder. Prüfe die ID des Zielbenutzers und ob der Basisordner ein Ordner ist, in dem dieser Benutzer Dateien anlegen darf.',
			self::answer($response)['message'],
		);
		self::assertSame([], $this->settings);
	}

	/**
	 * A permission that only one module needs, such as the one for the tags, is reported with what
	 * to do, and the settings are saved anyway, so that the administrator can fix the permissions
	 * in Paperless afterwards.
	 */
	public function testAFailureOfOneModuleIsSavedWithItsFix(): void {
		$this->checks[] = ConnectionCheck::failed(ConnectionCheck::TAGS, 'The account may not view them (HTTP 403).', [], 'In Paperless, under Users & Groups, give the account the permission View for Tags.');

		$response = $this->controller->save(self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$answer = self::answer($response);
		self::assertTrue($answer['connectionTested']);
		self::assertSame(
			['id' => 'tags', 'label' => 'Tags', 'status' => 'failed', 'message' => 'Das Konto darf sie nicht sehen (HTTP 403).', 'hint' => 'Gib dem Konto in Paperless unter „Benutzer & Gruppen“ die Berechtigung „Anzeigen“ für Tags.', 'setting' => ''],
			$answer['checks'][2],
		);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/** The test of the connection allows the local network as the form asks, before it is saved. */
	public function testTheConnectionIsTestedWithTheLocalNetworkOfTheForm(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');

		$response = $this->controller->save(['paperless_url' => 'http://paperless:8000', 'paperless_local_network' => true] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['http://paperless:8000', 'secret-token', true]], $this->preflights);
		self::assertTrue($this->settings['paperless_local_network']);
	}

	/**
	 * Nextcloud refuses a Paperless in the local network with "Host "paperless" violates local
	 * access rules". The page says instead what to do, in the language of the administrator, and
	 * names the setting, so that it can move the focus there.
	 */
	public function testARefusedLocalAddressSaysWhatToDo(): void {
		$this->checks = [self::localNetworkRefused(new PaperlessLocalNetworkException('paperless'))];

		$response = $this->controller->save(['paperless_url' => 'http://paperless:8000'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$answer = self::answer($response);
		self::assertSame('Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless. Wenn Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.', $answer['message']);
		self::assertSame('paperless_local_network', $answer['setting']);
		self::assertSame('paperless_local_network', $answer['checks'][0]['setting']);
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/**
	 * The way out that a check names comes first, also when only the schedule asked for the test:
	 * turning on the local network makes the schedule work, turning it off doesn't.
	 */
	public function testTheLocalNetworkComesBeforeTheSchedule(): void {
		$this->configService()->save(['paperless_url' => 'http://paperless:8000'] + self::validSettings(), 'secret-token');
		$this->checks = [self::localNetworkRefused(new PaperlessLocalNetworkException('paperless'))];

		$response = $this->controller->save(['paperless_url' => 'http://paperless:8000', 'enabled' => true] + self::validSettings());

		$answer = self::answer($response);
		self::assertSame('paperless_local_network', $answer['setting']);
		self::assertStringNotContainsString(self::TURN_OFF_THE_SCHEDULE, $answer['message']);
	}

	/**
	 * Without the setting, Nextcloud looks the name of Paperless up only in the DNS, so a name of
	 * the hosts file of the server fails with "No DNS record found". The page names the setting too.
	 */
	public function testANameWithoutADnsRecordSaysWhatToDo(): void {
		$this->checks = [self::localNetworkRefused(new PaperlessLocalNetworkException('paperless.home.arpa', true))];

		$response = $this->controller->save(['paperless_url' => 'http://paperless.home.arpa:8000'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$answer = self::answer($response);
		self::assertSame('Nextcloud hat keinen DNS-Eintrag für paperless.home.arpa gefunden. Wenn der Name nur in der hosts-Datei des Servers steht oder Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.', $answer['message']);
		self::assertSame('paperless_local_network', $answer['setting']);
	}

	/**
	 * Test connection runs the checklist of the form without saving it, also with a failure that
	 * a save would refuse, and lists every line with what to do.
	 */
	public function testTheTestListsTheChecklistWithoutSaving(): void {
		$this->checks = [self::tokenRefused()];
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Dokumente/Paperless');

		$response = $this->controller->test(self::validSettings() + ['paperless_local_network' => true], 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['https://paperless.example.test', 'secret-token', true]], $this->preflights);
		$answer = self::answer($response);
		self::assertSame(['checks'], array_keys($answer));
		self::assertSame(['paperless', 'folder'], array_column($answer['checks'], 'id'));
		self::assertSame(self::TOKEN_REFUSED, $answer['checks'][0]['message'] . ' ' . $answer['checks'][0]['hint']);
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/** The test sends the stored token as a save does: only to the URL that it was stored with. */
	public function testTheTestSendsTheStoredTokenOnlyToItsUrl(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');

		$response = $this->controller->test(['paperless_url' => 'https://attacker.example.test'] + self::validSettings());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Enter the API token again to connect to another Paperless URL.'], $response->getData());
		self::assertSame([], $this->preflights);

		$this->controller->test(self::validSettings());

		self::assertSame([['https://paperless.example.test', 'secret-token', false]], $this->preflights);
	}

	/** @return array<string, array{string}> */
	public static function changesOfTheConnection(): array {
		return [
			'save' => ['save'],
			'test' => ['test'],
			'disconnect' => ['reset'],
		];
	}

	/**
	 * Saving and testing decide where the token goes, and disconnecting removes it, so Nextcloud
	 * asks for the password of the administrator first, unless it was confirmed in the last 30
	 * minutes. A session that someone else took over is not enough, also not to make the server
	 * send requests into its network with the test.
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

	/** The line of a token that Paperless refuses, as PaperlessApiService::preflight() makes it. */
	private static function tokenRefused(): ConnectionCheck {
		return ConnectionCheck::failed(
			ConnectionCheck::PAPERLESS,
			'Paperless refused the API token (HTTP 401).',
			[],
			'Copy the token again from Paperless, under My Profile in the menu of its account, and check that the account is active.',
			true,
		);
	}

	/** The line of an address that Nextcloud refuses, as PaperlessApiService::preflight() makes it. */
	private static function localNetworkRefused(PaperlessLocalNetworkException $exception): ConnectionCheck {
		return new ConnectionCheck(
			ConnectionCheck::PAPERLESS,
			ConnectionCheck::FAILED,
			$exception->advice(),
			[$exception->host],
			blocking: true,
			setting: PaperlessLocalNetworkException::SETTING,
		);
	}

	/** Makes the checklist of Paperless, paperless, or the test of the folder in Nextcloud, folder, fail. */
	private function failWith(string $failure): void {
		if ($failure === 'folder') {
			$this->storage->method('test')->willThrowException(new RuntimeException('Unavailable.'));
			return;
		}
		$this->checks = [ConnectionCheck::failed(
			ConnectionCheck::PAPERLESS,
			'Paperless could not be reached: %s',
			['Unavailable.'],
			'Check the Paperless URL, and that the Nextcloud server reaches it through its network, firewall and proxy.',
			true,
		)];
	}
}
