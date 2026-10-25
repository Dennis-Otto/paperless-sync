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
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\AccountPermissions;
use OCA\PaperlessSync\Service\AccountSetup;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\ConnectionChecklist;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientFactoryInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class SettingsControllerTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	/** What the checklist says when Paperless refuses the token, with what to do, in German. */
	private const TOKEN_REFUSED = 'Paperless hat das API-Token abgelehnt (HTTP 401). Kopiere das Token noch einmal aus Paperless, unter „Mein Profil“ im Menü seines Kontos, und prüfe, ob das Konto aktiv ist.';
	/** How to save the settings of a schedule that fails, in German. */
	private const TURN_OFF_THE_SCHEDULE = 'Um die Einstellungen ohne den Test zu speichern, schalte die geplante Synchronisation aus.';
	/** The random password of every account that the setup creates or updates. */
	private const PASSWORD = 'random-password-of-the-setup';
	/** The permissions of the export and the inbox of self::validSettings(), with "Send to Paperless" of its default group. */
	private const PERMISSIONS = ['view_document', 'add_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_paperlesstask', 'view_uisettings'];
	/** The same, as the settings of Paperless show them, in German. */
	private const GERMAN_PERMISSIONS = [
		'„Anzeigen“ für Dokumente', '„Hinzufügen“ für Dokumente', '„Anzeigen“ für Korrespondenten', '„Anzeigen“ für Dokumenttypen',
		'„Anzeigen“ für Speicherpfade', '„Anzeigen“ für Tags', '„Anzeigen“ für Paperless-Aufgaben', '„Anzeigen“ für UI-Einstellungen',
	];

	/** @var PaperlessClientInterface&MockObject */
	private PaperlessClientInterface $paperless;
	/** @var PaperlessClientFactoryInterface&MockObject the factory that makes $paperless the client of every connection */
	private PaperlessClientFactoryInterface $clients;
	/** @var list<PaperlessConnection> every connection that the controller made a client for, in their order */
	private array $connects = [];
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
		$this->paperless->method('preflight')->willReturnCallback(function (SyncConfig $config): array {
			$connection = $this->connects[array_key_last($this->connects)];
			self::assertSame([$config->paperlessUrl, $config->paperlessLocalNetwork], [$connection->url, $connection->localNetwork], 'The checklist checks the connection of the settings that it checks.');
			$this->preflights[] = [$connection->url, $connection->token(), $connection->localNetwork];
			if ($this->preflightFailure !== null) {
				throw $this->preflightFailure;
			}

			return $this->checks;
		});
		$this->clients = $this->createStub(PaperlessClientFactoryInterface::class);
		$this->clients->method('connect')->willReturnCallback(function (PaperlessConnection $connection): PaperlessClientInterface {
			$this->connects[] = $connection;

			return $this->paperless;
		});
		$this->storage = $this->createMock(NextcloudStorageInterface::class);
		$this->controller = $this->controller($this->configService());
	}

	public function testSavesSettingsThatWorkWithPaperlessAndNextcloud(): void {
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Documents/Paperless');

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
			['id' => 'folder', 'label' => 'Nextcloud-Ordner', 'status' => 'passed', 'message' => 'paperless darf Dateien in Documents/Paperless anlegen.', 'hint' => '', 'setting' => ''],
		], $answer['checks'], 'The checklist ends with the folder of the target user.');
		self::assertSame(20, $this->settings['batch_size']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
		self::assertTrue($this->connectionPassed(), 'A checklist without a failed line completes the connection of Getting started.');
	}

	/**
	 * The page shows what the server stored, so the answer has every value as it was stored,
	 * normalized, and never the token.
	 */
	public function testTheAnswerIsTheStoredConfiguration(): void {
		$response = $this->controller->save([
			'paperless_url' => 'https://paperless.example.test/',
			'excluded_tags' => 'Private, Draft, Private',
			'included_tags' => 'Nextcloud, nextcloud',
			'untitled' => ' ',
		] + self::validSettings(), 'secret-token');

		$answer = self::answer($response);
		$stored = $this->configService()->get()->jsonSerialize();
		self::assertSame($stored + ['connectionTested' => true], array_diff_key($answer, ['checks' => true]));
		self::assertCount(3, $answer['checks']);
		self::assertSame('https://paperless.example.test', $answer['paperlessUrl']);
		self::assertSame('Private, Draft', $answer['excludedTags']);
		self::assertSame('Nextcloud', $answer['includedTags']);
		self::assertSame('Unknown', $answer['untitled']);
		self::assertStringNotContainsString('secret-token', json_encode($answer, JSON_THROW_ON_ERROR));
	}

	public function testInvalidSettingsAreRejectedBeforeAnyConnection(): void {
		$response = $this->controller->save(['paperless_url' => 'ftp://paperless.example.test'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Die Paperless-Adresse muss HTTP oder HTTPS verwenden.', 'setting' => 'paperless_url'], $response->getData());
		self::assertSame([], $this->preflights);
		self::assertSame([], $this->settings);
	}

	/** The schedule will run with the settings, so they are tested with the stored token first. */
	public function testSavingAScheduleTestsTheStoredToken(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Documents/Paperless');

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
			'message' => 'Die Konfiguration von Paperless und Nextcloud konnte nicht geprüft werden: Unavailable. ' . self::TURN_OFF_THE_SCHEDULE,
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
			'Die Konfiguration von Paperless und Nextcloud konnte nicht geprüft werden: cURL error 7: Failed to connect to paperless.example.test port 443 for https://paperless.example.test/api/documents/. ' . self::TURN_OFF_THE_SCHEDULE,
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
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturnCallback(fn (): mixed => $this->credential);
		$credentials->method('store')->willThrowException(new RuntimeException('Unavailable.'));
		$controller = $this->controller($this->configService(credentials: $credentials));
		$this->storage->expects(self::once())->method('test');

		$response = $controller->save(self::validSettings() + ['enabled' => true]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertCount(1, $this->preflights);
		self::assertSame(['message' => 'Die Konfiguration von Paperless und Nextcloud konnte nicht geprüft werden: Unavailable.'], $response->getData());
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
		self::assertSame(['message' => 'Gib das API-Token erneut ein, um dich mit einer anderen Paperless-Adresse zu verbinden.'], $response->getData());
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

	/**
	 * A base folder in which the target user can't create files makes every run fail as well. The
	 * line says why in the language of the administrator, not half in English.
	 */
	public function testAFolderThatCannotTakeFilesIsNotSaved(): void {
		$this->storage->method('test')->willThrowException(new InvalidSettingException('The configured Nextcloud base folder is not a writable folder.', [], 'base_path'));

		$response = $this->controller->save(self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			'Nextcloud kann die Dateien dort nicht speichern: Der eingestellte Nextcloud-Basisordner ist kein beschreibbarer Ordner. Prüfe die ID des Zielbenutzers und ob der Basisordner ein Ordner ist, in dem dieser Benutzer Dateien anlegen darf.',
			self::answer($response)['message'],
		);
		self::assertSame([], $this->settings);
	}

	/** A target user that Nextcloud doesn't know is named in German in the line of the folder. */
	public function testTheChecklistNamesAnUnknownTargetUserInGerman(): void {
		$this->storage->method('test')->willThrowException(new InvalidSettingException('Nextcloud user %s does not exist.', ['ghost'], 'target_user'));

		$answer = self::answer($this->controller->test(self::validSettings(), 'secret-token'));

		self::assertSame('Nextcloud kann die Dateien dort nicht speichern: Den Nextcloud-Benutzer ghost gibt es nicht.', array_column($answer['checks'], 'message', 'id')['folder']);
	}

	/**
	 * Another invalid argument, such as from a document of Paperless without a numeric ID in the
	 * preview, keeps its message, which no translation knows.
	 */
	public function testAnUntranslatedInvalidArgumentKeepsItsMessage(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->method('newestDocuments')->willThrowException(new \InvalidArgumentException('Paperless returned a document without a numeric ID.'));

		$response = $this->controller->preview(['path_template' => PathTemplateService::DEFAULT_TEMPLATE]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Paperless returned a document without a numeric ID.'], self::answer($response));
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
		self::assertFalse($this->connectionPassed(), 'A failed line leaves the connection of Getting started next.');
	}

	/**
	 * A test with the saved token tells Getting started whether the connection passes now, such as
	 * after the permissions were fixed in Paperless; a test with a token entered, which is not
	 * saved, tells it nothing.
	 */
	public function testATestWithTheSavedTokenMarksTheConnection(): void {
		$this->checks[] = ConnectionCheck::failed(ConnectionCheck::TAGS, 'The account may not view them (HTTP 403).', [], 'Give the account the permission.');
		$this->controller->save(self::validSettings(), 'secret-token');
		self::assertFalse($this->connectionPassed());

		$this->checks = [];
		$this->controller->test(self::validSettings(), 'another-token');
		self::assertFalse($this->connectionPassed(), 'A token that is not saved.');

		$this->controller->test(self::validSettings());
		self::assertTrue($this->connectionPassed(), 'The saved token passes now.');

		$this->checks[] = ConnectionCheck::warning(ConnectionCheck::TAGS, 'A note.', [], 'Read it.');
		$this->controller->test(self::validSettings());
		self::assertTrue($this->connectionPassed(), 'A note is no failure.');
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
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Documents/Paperless');

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

	/**
	 * In per-user ownership, the checklist shows who gets the documents of whom, in German, once
	 * Paperless answered; a blocking failure of Paperless leaves those lines out.
	 */
	public function testTheChecklistOfPerUserOwnershipShowsWhoGetsWhichDocuments(): void {
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$this->paperless->method('users')->willReturn(['1' => 'alice']);
		$this->paperless->method('ownedDocuments')->willReturnCallback(static fn (?int $owner): int => $owner === null ? 2 : 5);

		$answer = self::answer($this->controller->test(self::validSettings() + ['ownership' => 'per-user'], 'secret-token'));

		self::assertSame(['paperless', 'documents', 'owners', 'owners', 'folder'], array_column($answer['checks'], 'id'));
		self::assertSame(['Benutzer von Paperless', 'alice → alice, Dokumente: 5'], [$answer['checks'][2]['label'], $answer['checks'][2]['message']]);
		self::assertSame('Ohne Eigentümer → paperless, der Zielbenutzer, Dokumente: 2', $answer['checks'][3]['message']);

		$this->checks = [self::tokenRefused()];
		$answer = self::answer($this->controller->test(self::validSettings() + ['ownership' => 'per-user'], 'secret-token'));

		self::assertSame(['paperless', 'folder'], array_column($answer['checks'], 'id'));
	}

	/** The test sends the stored token as a save does: only to the URL that it was stored with. */
	public function testTheTestSendsTheStoredTokenOnlyToItsUrl(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');

		$response = $this->controller->test(['paperless_url' => 'https://attacker.example.test'] + self::validSettings());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Gib das API-Token erneut ein, um dich mit einer anderen Paperless-Adresse zu verbinden.'], $response->getData());
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
			'the setup of the account' => ['account'],
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
	 * The setup of the account: the token of the superuser creates a user with the permissions of
	 * the modules of the form, the settings are saved with the token of that user, and the
	 * checklist runs with it. The token of the superuser is neither stored nor answered.
	 */
	public function testCreatesTheAccountOfTheAppAndSavesTheSettingsWithItsToken(): void {
		$this->paperless->method('user')->with('nextcloud-sync')->willReturn(null);
		$this->paperless->expects(self::once())->method('createUser')->with('nextcloud-sync', self::PASSWORD, self::PERMISSIONS);
		$this->paperless->expects(self::never())->method('updateUser');
		$this->paperless->method('userToken')->with('nextcloud-sync', self::PASSWORD)->willReturn('token-of-the-app');
		$this->storage->expects(self::exactly(2))->method('test')->with('paperless', 'Documents/Paperless');

		$response = $this->controller->account(self::validSettings() + ['batch_size' => 20], ' superuser-token ', '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['superuser-token', 'token-of-the-app'], array_map(static fn (PaperlessConnection $connection): string => $connection->token(), $this->connects));
		self::assertSame([['https://paperless.example.test', 'token-of-the-app', false]], $this->preflights, 'The checklist checks the new account.');
		$answer = self::answer($response);
		self::assertSame(['username' => 'nextcloud-sync', 'created' => true, 'permissions' => self::GERMAN_PERMISSIONS], $answer['account']);
		self::assertTrue($answer['connectionTested']);
		self::assertSame(['paperless', 'documents', 'folder'], array_column($answer['checks'], 'id'));
		self::assertSame(20, $answer['batchSize']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'token-of-the-app'], $this->credential);
		self::assertTrue($this->connectionPassed(), 'The setup of the account completes the connection of Getting started.');
		self::assertStringNotContainsString('superuser-token', $response->render());
		self::assertStringNotContainsString('superuser-token', json_encode($this->settings, JSON_THROW_ON_ERROR));
	}

	/** A user that exists already changes only when the administrator agrees, after the page asked. */
	public function testAnExistingUserChangesOnlyWithAnUpdate(): void {
		$this->paperless->method('user')->willReturn(['id' => 7, 'superuser' => false]);
		$this->paperless->expects(self::never())->method('createUser');
		$this->paperless->expects(self::once())->method('updateUser')->with(7, self::PASSWORD, self::PERMISSIONS);
		$this->paperless->expects(self::once())->method('userToken')->willReturn('token-of-the-app');

		$asked = $this->controller->account(self::validSettings(), 'superuser-token', 'nextcloud-sync');

		self::assertSame(Http::STATUS_CONFLICT, $asked->getStatus());
		self::assertSame([
			'message' => 'Den Benutzer nextcloud-sync gibt es in Paperless schon. Aktualisieren gibt ihm genau diese Berechtigungen: ' . implode(', ', self::GERMAN_PERMISSIONS) . '. Außerdem bekommt er ein neues zufälliges Passwort, das niemand braucht, weil sich die App mit seinem Token anmeldet. Er ist dann kein Admin mehr, und die Berechtigungen seiner Gruppen bleiben.',
			'exists' => true,
		], $asked->getData());
		self::assertNull($this->credential);

		$updated = self::answer($this->controller->account(self::validSettings(), 'superuser-token', 'nextcloud-sync', true));

		self::assertSame(['username' => 'nextcloud-sync', 'created' => false, 'permissions' => self::GERMAN_PERMISSIONS], $updated['account']);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'token-of-the-app'], $this->credential);
	}

	/** @return array<string, array{array<string, mixed>, string, string, string}> the settings, the token, the message and the setting that it names */
	public static function refusedSetups(): array {
		return [
			'without the token of a superuser' => [[], ' ', 'Gib das API-Token eines Superusers von Paperless ein.', 'account_token'],
			'with an invalid URL' => [['paperless_url' => 'ftp://paperless.example.test'], 'superuser-token', 'Die Paperless-Adresse muss HTTP oder HTTPS verwenden.', 'paperless_url'],
		];
	}

	/** Settings that can't be saved reach no Paperless, and change nothing. */
	#[DataProvider('refusedSetups')]
	public function testSettingsThatCantBeSavedReachNoPaperless(array $settings, string $token, string $message, string $setting): void {
		$response = $this->controller->account($settings + self::validSettings(), $token);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message, 'setting' => $setting], $response->getData());
		self::assertSame([], $this->connects);
		self::assertSame([], $this->settings);
	}

	/** Paperless gets no account for a folder in which the target user can't create files. */
	public function testAFolderThatCannotTakeFilesCreatesNoAccount(): void {
		$this->storage->method('test')->willThrowException(new InvalidSettingException('Nextcloud user %s does not exist.', ['ghost'], 'target_user'));

		$response = $this->controller->account(self::validSettings(), 'superuser-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['folder'], array_column(self::answer($response)['checks'], 'id'));
		self::assertSame([], $this->connects);
	}

	/** @return array<string, array{\Throwable, int, array<string, string>}> the failure of Paperless and the answer */
	public static function failedSetups(): array {
		return [
			'a token of no superuser' => [
				new InvalidSettingException('Paperless does not let the account of this token manage users (HTTP 403). Use the token of a superuser of Paperless.', [], 'account_token'),
				Http::STATUS_BAD_REQUEST,
				['message' => 'Paperless lässt das Konto dieses Tokens keine Benutzer verwalten (HTTP 403). Verwende das Token eines Superusers von Paperless.', 'setting' => 'account_token'],
			],
			'an address of the local network' => [
				new PaperlessLocalNetworkException('paperless'),
				Http::STATUS_BAD_GATEWAY,
				['message' => (new PaperlessLocalNetworkException('paperless'))->getMessage(), 'setting' => 'paperless_local_network'],
			],
			'a Paperless that fails' => [
				new RuntimeException('Paperless did not list its users: HTTP 500.'),
				Http::STATUS_BAD_GATEWAY,
				['message' => 'Paperless hat das Konto nicht eingerichtet: Paperless did not list its users: HTTP 500.'],
			],
		];
	}

	/** What Paperless refused reaches the page, with the field that fixes it, and nothing is saved. */
	#[DataProvider('failedSetups')]
	public function testAFailedSetupSaysWhatToDo(\Throwable $failure, int $status, array $answer): void {
		$this->paperless->method('user')->willThrowException($failure);
		if ($failure instanceof PaperlessLocalNetworkException) {
			$answer['message'] = $failure->translated($this->germanL10n());
		}

		$response = $this->controller->account(self::validSettings(), 'superuser-token');

		self::assertSame($status, $response->getStatus());
		self::assertSame($answer, $response->getData());
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/**
	 * A blocking failure of the checklist after the setup refuses the settings, as on save, and
	 * says that the account is ready, so that a second setup updates it.
	 */
	public function testABlockingCheckAfterTheSetupKeepsTheSettingsUnsaved(): void {
		$this->paperless->method('user')->willReturn(null);
		$this->paperless->method('userToken')->willReturn('token-of-the-app');
		$this->checks = [self::tokenRefused()];

		$response = $this->controller->account(self::validSettings(), 'superuser-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(self::TOKEN_REFUSED . ' Das Konto nextcloud-sync ist in Paperless bereit: Sobald das behoben ist, richte es noch einmal ein, was es aktualisiert.', self::answer($response)['message']);
		self::assertNull($this->credential);
	}

	/** A checklist that fails on its own is reported as on save, and nothing is saved. */
	public function testAChecklistThatFailsAfterTheSetupIsReported(): void {
		$this->paperless->method('user')->willReturn(null);
		$this->paperless->method('userToken')->willReturn('token-of-the-app');
		$this->preflightFailure = new RuntimeException('Broken.');

		$response = $this->controller->account(self::validSettings(), 'superuser-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Die Konfiguration von Paperless und Nextcloud konnte nicht geprüft werden: Broken.'], $response->getData());
		self::assertNull($this->credential);
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
		self::assertFalse($this->connectionPassed(), 'Disconnect forgets the checklist of Getting started.');
		self::assertSame('', $this->settings['status_connection_url']);
	}

	/**
	 * The page offers the tags of Paperless for the excluded tags, as a run reads them with the
	 * stored URL and token, once each and in the order of their letters. A run compares the names
	 * in small letters, so Private and PRIVATE are one tag; a name with a comma or a line break,
	 * which separate the names of the setting, can't be one of them.
	 */
	public function testTagsAreTheNamesOfPaperlessThatTheSettingCanHold(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('tags')->willReturn([
			'names' => [
				'9' => 'Inbox',
				'10' => 'Private',
				'11' => 'draft',
				'12' => 'Tax, 2026',
				'13' => "Two\nlines",
				'14' => ' ',
				'15' => 'PRIVATE',
				'16' => 'Tag 10',
				'17' => ' Tag 9 ',
				'18' => '2026',
			],
			'inbox' => ['9'],
		]);

		$response = $this->controller->tags();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['tags' => ['2026', 'draft', 'Inbox', 'Private', 'Tag 9', 'Tag 10']], self::answer($response));
		self::assertEquals([new PaperlessConnection('https://paperless.example.test', 'secret-token', false)], $this->connects, 'The tags come from the saved connection.');
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function connectionsWithoutTheirToken(): array {
		return [
			'nothing saved' => [[]],
			// The URL changed past the settings page, through occ: the token stays with its own URL.
			'another URL' => [['paperless_url' => 'https://other.example.test']],
		];
	}

	/**
	 * Without a saved connection there are no tags to read; the page takes names as they are typed.
	 *
	 * @param array<string, mixed> $changes
	 */
	#[DataProvider('connectionsWithoutTheirToken')]
	public function testTagsNeedTheSavedConnection(array $changes): void {
		if ($changes !== []) {
			$this->configService()->save(self::validSettings(), 'secret-token');
			$this->settings = $changes + $this->settings;
		}
		$this->paperless->expects(self::never())->method('tags');

		$response = $this->controller->tags();

		self::assertSame([], $this->connects);
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Speichere zuerst die Verbindung zu Paperless; dann erscheinen seine Tags hier.'], $response->getData());
	}

	public function testTagsSayWhyPaperlessDidNotListThem(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->method('tags')->willThrowException(new RuntimeException('Paperless request failed with HTTP 403.'));

		$response = $this->controller->tags();

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertSame(['message' => 'Paperless hat seine Tags nicht aufgelistet: Paperless request failed with HTTP 403.'], $response->getData());
	}

	/** A Paperless in the local network that the settings don't allow: the page names the setting. */
	public function testTagsOfARefusedLocalAddressSayWhatToDo(): void {
		$this->configService()->save(['paperless_url' => 'http://paperless:8000'] + self::validSettings(), 'secret-token');
		$this->paperless->method('tags')->willThrowException(new PaperlessLocalNetworkException('paperless'));

		$response = $this->controller->tags();

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		/** @var array{message: string, setting: string} $data */
		$data = $response->getData();
		self::assertSame('paperless_local_network', $data['setting']);
		self::assertStringStartsWith('Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless.', $data['message']);
	}

	/** @return array<string, array{string}> */
	public static function actions(): array {
		return [
			'save' => ['save'],
			'test connection' => ['test'],
			'preview of the path template' => ['preview'],
			'disconnect' => ['reset'],
			'tags of Paperless' => ['tags'],
		];
	}

	/**
	 * Every action of the controller is for administrators alone, the list of tags too, which is
	 * read with the token of the app. Nextcloud lets others in only with one of these attributes.
	 */
	#[DataProvider('actions')]
	public function testOnlyAdministratorsReachTheSettings(string $method): void {
		$attributes = array_map(
			static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
			(new ReflectionMethod(SettingsController::class, $method))->getAttributes(),
		);

		self::assertSame([], array_values(array_intersect($attributes, [
			NoAdminRequired::class, SubAdminRequired::class, AuthorizedAdminSetting::class, PublicPage::class, NoCSRFRequired::class,
		])));
	}

	/**
	 * Editing the template shows where the newest documents of Paperless would go, before a save:
	 * with the template and the fallback names of the page, and the saved connection.
	 */
	public function testThePreviewShowsThePathsOfTheNewestDocumentsWithTheTemplateOfThePage(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->expects(self::once())->method('newestDocuments')->with(SettingsController::PREVIEW_DOCUMENTS)->willReturn([
			['id' => 12, 'title' => 'Strom', 'correspondent' => 4, 'created' => '2026-03-02', 'original_file_name' => 'scan.pdf', 'tags' => [1]],
			['id' => 11, 'title' => '', 'created' => '2026-02-01', 'original_file_name' => 'scan.pdf'],
		]);
		$this->paperless->method('correspondents')->willReturn(['4' => 'Energie GmbH']);
		$this->paperless->method('tags')->willReturn(['names' => ['1' => 'Inbox'], 'inbox' => ['1']]);
		// The preview reads with the saved connection, whatever the page says about it.
		$this->paperless->expects(self::never())->method('testConnection');

		$response = $this->controller->preview([
			'path_template' => '{{correspondent}}/{{ created_month_name }}/{{ title }} [P{{ id }}]',
			'untitled' => 'Ohne Namen',
			'skip_inbox' => false,
			'paperless_url' => 'https://other.example.test',
			'target_user' => 'someone-else',
		]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'template' => '{{ correspondent }}/{{ created_month_name }}/{{ title }} [P{{ id }}]',
			'connected' => true,
			'paths' => [
				['id' => 12, 'title' => 'Strom', 'path' => 'Energie GmbH/March/Strom [P12].pdf', 'skipped' => false],
				['id' => 11, 'title' => '', 'path' => '_No correspondent/February/Ohne Namen [P11].pdf', 'skipped' => false],
			],
		], self::answer($response));
		self::assertSame(PathTemplateService::DEFAULT_TEMPLATE, $this->configService()->get()->pathTemplate, 'The preview saves nothing.');
		self::assertSame('paperless', $this->configService()->get()->targetUser);
		self::assertEquals([new PaperlessConnection('https://paperless.example.test', 'secret-token', false)], $this->connects, 'The preview reads with the saved connection, not with the URL of the page.');
	}

	/**
	 * The preview takes the tags to mirror of the page, and a name that Paperless has no tag of
	 * says why at the field, in the language of the administrator, as a run would fail with it.
	 */
	public function testThePreviewSaysWhichTagToMirrorPaperlessDoesNotHave(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->method('newestDocuments')->willReturn([['id' => 12, 'title' => 'Strom', 'created' => '2026-03-02', 'original_file_name' => 'scan.pdf', 'tags' => [5]]]);
		$this->paperless->method('tags')->willReturn(['names' => ['5' => 'Nextcloud'], 'inbox' => []]);

		$mirrored = $this->controller->preview(['path_template' => '{{ title }} [P{{ id }}]', 'included_tags' => 'nextcloud']);
		$missing = $this->controller->preview(['path_template' => '{{ title }} [P{{ id }}]', 'included_tags' => 'Nextcloud, Nextclod']);

		self::assertSame([['id' => 12, 'title' => 'Strom', 'path' => 'Strom [P12].pdf', 'skipped' => false]], self::answer($mirrored)['paths']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $missing->getStatus());
		self::assertSame([
			'message' => 'Paperless hat keinen Tag namens "Nextclod", den „Nur Dokumente mit diesen Tags spiegeln“ nennt; ein Lauf würde die Kopien der Dokumente entfernen, die bleiben sollen.',
			'setting' => 'included_tags',
		], self::answer($missing));
		self::assertSame('', $this->configService()->get()->includedTags, 'The preview saves nothing.');
	}

	public function testWithoutAConnectionThePreviewChecksTheTemplateAlone(): void {
		$this->paperless->expects(self::never())->method('newestDocuments');

		$response = $this->controller->preview(['path_template' => '{{title}} [P{{id}}]']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['template' => '{{ title }} [P{{ id }}]', 'connected' => false, 'paths' => []], self::answer($response));
		self::assertSame(['template' => PathTemplateService::DEFAULT_TEMPLATE, 'connected' => false, 'paths' => []], self::answer($this->controller->preview()), 'Without a template on the page, the preview shows the saved one.');
	}

	/** @return array<string, array{mixed, string}> a template and what the page says about it, in German */
	public static function invalidPreviewTemplates(): array {
		return [
			'an unknown variable' => ['{{ titel }} [P{{ id }}]', 'Unbekannte Variable in der Archiv-Pfadvorlage: {{ titel }}.'],
			'no id' => ['{{ title }}', 'Die Archiv-Pfadvorlage muss {{ id }} enthalten.'],
			'no text' => [['{{ id }}'], 'Die Archiv-Pfadvorlage darf nicht leer sein.'],
		];
	}

	#[DataProvider('invalidPreviewTemplates')]
	public function testThePreviewOfAnInvalidTemplateSaysWhyAsASaveWould(mixed $template, string $message): void {
		$response = $this->controller->preview(['path_template' => $template]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message, 'setting' => 'path_template'], self::answer($response));
	}

	public function testThePreviewSaysWhyPaperlessCouldNotBeRead(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->method('newestDocuments')->willThrowException(new RuntimeException('Connection refused.'));

		$response = $this->controller->preview(['path_template' => PathTemplateService::DEFAULT_TEMPLATE]);

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertSame(['message' => 'Paperless konnte für die Vorschau nicht gelesen werden: Connection refused.'], self::answer($response));
	}

	public function testThePreviewSaysHowToReachAPaperlessInTheLocalNetwork(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->paperless->method('newestDocuments')->willThrowException(new PaperlessLocalNetworkException('paperless'));

		$response = $this->controller->preview();

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertStringContainsString('„Paperless läuft in meinem lokalen Netzwerk“', (string)self::answer($response)['message']);
	}

	/** The preview reads Paperless with the saved connection and saves nothing, so it needs no password. */
	public function testThePreviewNeedsNoPassword(): void {
		self::assertCount(0, (new ReflectionMethod(SettingsController::class, 'preview'))->getAttributes(PasswordConfirmationRequired::class));
	}

	private function controller(ConfigService $configService): SettingsController {
		$paths = new PathTemplateService();
		$clock = new TestClock();
		$status = $this->statusService($clock);
		$sync = new SyncService(
			$configService,
			$this->clients,
			$this->storage,
			new TestStateRepository(),
			$paths,
			$status,
			$this->createStub(ILockingProvider::class),
			$this->createStub(LoggerInterface::class),
			$clock,
			$this->activityPublisher($this->storage, $this->createStub(LoggerInterface::class)),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($status, $this->storage, $clock, $this->createStub(LoggerInterface::class)),
			$this->systemTagService($this->storage),
			$this->ownerMapping(),
		);

		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(self::PASSWORD);

		return new SettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			$this->clients,
			new ConnectionChecklist($this->clients, $this->storage, $this->ownerMapping(), $this->germanL10n()),
			$this->germanL10n(),
			$sync,
			$paths,
			new AccountPermissions($paths),
			new AccountSetup($this->clients, $random),
			$status,
		);
	}

	/** Whether Getting started counts the connection to the URL of validSettings() as done. */
	private function connectionPassed(): bool {
		return $this->statusService(new TestClock())->connectionPassed('https://paperless.example.test');
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
