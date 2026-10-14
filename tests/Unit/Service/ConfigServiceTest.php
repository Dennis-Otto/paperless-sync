<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase {
	use InMemoryConfiguration;

	private ConfigService $service;

	protected function setUp(): void {
		$this->service = $this->configService();
	}

	public function testDefaultsApplyUntilSomethingIsSaved(): void {
		$config = $this->service->get();

		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->enabled);
		self::assertSame('Dokumente/Paperless', $config->basePath);
		self::assertSame(['Archiv', 'Eingang', 'Fehler', '_Gelöscht'], [$config->archiveFolder, $config->inboxFolder, $config->errorFolder, $config->deletedFolder]);
		self::assertSame(PathTemplateService::DEFAULT_TEMPLATE, $config->pathTemplate);
		self::assertSame([5, 100, 3], [$config->syncIntervalMinutes, $config->batchSize, $config->missingGraceRuns]);
		self::assertSame(['move', 'replace'], [$config->trashMode, $config->conflictMode]);
		self::assertSame('', $this->service->getToken());
	}

	public function testSavesNormalizedSettingsAndTheToken(): void {
		$config = $this->service->save([
			'paperless_url' => ' https://paperless.example.test/ ',
			'target_user' => ' paperless ',
			'base_path' => '/Dokumente\\Paperless/',
			'archive_folder' => ' Archiv ',
			'excluded_tags' => "Privat, Steuer\nPrivat,,\r\n ",
			'enabled' => true,
			'sync_interval_minutes' => '15',
			'empty_correspondent' => 'Ohne: Korrespondent',
			'not_a_setting' => 'ignored',
		], ' secret-token ');

		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertSame('paperless', $config->targetUser);
		self::assertSame('Dokumente/Paperless', $config->basePath);
		self::assertSame('Archiv', $config->archiveFolder);
		self::assertSame('Privat, Steuer', $config->excludedTags);
		self::assertTrue($config->enabled);
		self::assertSame(15, $config->syncIntervalMinutes);
		self::assertSame('Ohne_ Korrespondent', $config->emptyCorrespondent);
		self::assertTrue($config->tokenConfigured);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
		self::assertSame(15, $this->settings['sync_interval_minutes']);
		self::assertArrayNotHasKey('not_a_setting', $this->settings);
	}

	/** @return array<string, array{string}> the stored URL as an administrator may enter it again */
	public static function sameUrls(): array {
		return [
			'unchanged' => ['https://paperless.example.test'],
			'with a slash and spaces' => [' https://paperless.example.test/ '],
		];
	}

	/** Nextcloud loads the settings when the app asks for them, not with every request of every user. */
	public function testEverySettingIsStoredAndReadLazily(): void {
		$this->service->save(self::validSettings() + ['enabled' => true, 'batch_size' => 20], 'secret-token');
		$this->service->reset();

		self::assertNotSame([], $this->settings);
		self::assertSame([], array_keys(array_diff_key($this->settings, $this->lazyKeys)));
		$this->lazyReads = [];
		$config = $this->service->get();
		self::assertSame(['https://paperless.example.test', 'paperless', 20, false], [$config->paperlessUrl, $config->targetUser, $config->batchSize, $config->enabled]);
		self::assertCount(29, $this->lazyReads, 'Every setting is read as a lazy one.');
		self::assertSame('https://paperless.example.test', $this->service->configuredUrl());
	}

	/** The settings of an earlier version, which Nextcloud loaded with every request, become lazy and keep their values. */
	public function testSettingsOfAnEarlierVersionBecomeLazy(): void {
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'sync_enabled' => true, 'batch_size' => 20, 'enabled' => 'yes', 'installed_version' => '0.2.0'];
		$this->lazyKeys = ['batch_size' => true];

		self::assertSame(2, $this->service->storeLazily());

		$lazy = array_keys($this->lazyKeys);
		sort($lazy);
		self::assertSame(['batch_size', 'paperless_url', 'sync_enabled'], $lazy, 'The keys of Nextcloud for the app, such as enabled, stay as they are.');
		self::assertSame(0, $this->service->storeLazily());
		$config = $this->service->get();
		self::assertSame(['https://paperless.example.test', true, 20], [$config->paperlessUrl, $config->enabled, $config->batchSize]);
	}

	#[DataProvider('sameUrls')]
	public function testSavingTheSameUrlWithoutATokenKeepsTheStoredOne(string $url): void {
		$this->service->save(self::validSettings(), 'secret-token');

		$config = $this->service->save(['paperless_url' => $url, 'batch_size' => 50]);

		self::assertSame(50, $config->batchSize);
		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertSame('secret-token', $this->service->getToken());
		self::assertSame('secret-token', $this->service->resolveToken(' ', 'https://paperless.example.test'));
	}

	public function testATokenThatIsEnteredIsUsedForAnyUrl(): void {
		$this->service->save(self::validSettings(), 'secret-token');

		self::assertSame('other-token', $this->service->resolveToken(' other-token ', 'https://other.example.test'));
		$config = $this->service->save(['paperless_url' => 'https://other.example.test'], 'other-token');

		self::assertSame('https://other.example.test', $config->paperlessUrl);
		self::assertSame(['url' => 'https://other.example.test', 'token' => 'other-token'], $this->credential);
	}

	/** @return array<string, array{string}> URLs that may lead to another server or another app than the stored one */
	public static function otherUrls(): array {
		return [
			'another host' => ['https://attacker.example.test'],
			'another port' => ['https://paperless.example.test:8443'],
			'another scheme' => ['http://paperless.example.test'],
			'another path on the same host' => ['https://paperless.example.test/other-app'],
		];
	}

	/**
	 * The stored token goes only to the URL that it was saved with. Otherwise, whoever can change
	 * the settings could point the URL at a server of their own and receive the token with the test
	 * of the connection, without knowing it.
	 */
	#[DataProvider('otherUrls')]
	public function testANewUrlNeedsTheTokenAgain(string $url): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$stored = $this->settings;
		$calls = [
			'validate' => fn (): mixed => $this->service->validate(['paperless_url' => $url], ' '),
			'save' => fn (): mixed => $this->service->save(['paperless_url' => $url]),
			'resolveToken' => fn (): mixed => $this->service->resolveToken('', $url),
		];

		foreach ($calls as $method => $call) {
			try {
				$call();
				self::fail("{$method}() must refuse a new URL without a token.");
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Enter the API token again to connect to another Paperless URL.', $exception->getMessage());
			}
		}
		self::assertSame($stored, $this->settings);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * The provisioning API of Nextcloud and occ may change the URL without the settings page. The
	 * next run must not send the stored token there.
	 */
	public function testAUrlChangedElsewhereGetsNoToken(): void {
		$this->service->save(self::validSettings(), 'secret-token');

		$this->settings['paperless_url'] = 'https://attacker.example.test';

		self::assertSame('', $this->service->getToken());
		self::assertFalse($this->service->get()->tokenConfigured);
		try {
			$this->service->validate([]);
			self::fail('The settings page must not take over a URL that was changed elsewhere without the token.');
		} catch (InvalidArgumentException $exception) {
			self::assertSame('Enter the API token again to connect to another Paperless URL.', $exception->getMessage());
		}
		self::assertSame('secret-token', $this->service->resolveToken('', 'https://paperless.example.test'), 'The URL that the token was saved with still gets it.');

		$this->settings['paperless_url'] = 'https://paperless.example.test/';
		self::assertSame('secret-token', $this->service->getToken());
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/** A token that an earlier version stored without its URL waits for the update to bind it to one. */
	public function testATokenWithoutItsUrlIsNotUsed(): void {
		$this->settings['paperless_url'] = 'https://paperless.example.test';
		$this->credential = 'secret-token';

		self::assertSame('', $this->service->getToken());
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');
		$this->service->validate(self::validSettings());
	}

	/** @return array<string, array{mixed}> what else the store of credentials may hold */
	public static function unusableCredentials(): array {
		return [
			'without a URL' => [['token' => 'secret-token']],
			'without a token' => [['url' => 'https://paperless.example.test']],
			'with an empty token' => [['url' => 'https://paperless.example.test', 'token' => '']],
			'with a token that is no text' => [['url' => 'https://paperless.example.test', 'token' => 42]],
		];
	}

	#[DataProvider('unusableCredentials')]
	public function testAnIncompleteCredentialIsNoToken(mixed $credential): void {
		$this->settings['paperless_url'] = 'https://paperless.example.test';
		$this->credential = $credential;

		self::assertSame('', $this->service->getToken());
		self::assertFalse($this->service->bindUnboundToken());
		self::assertSame($credential, $this->credential);
	}

	public function testATokenIsRequired(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');

		$this->service->validate(self::validSettings());
	}

	public function testValidationLeavesTheStoredSettingsAlone(): void {
		$config = $this->service->validate(self::validSettings() + ['batch_size' => 7], 'secret-token');

		self::assertSame(7, $config->batchSize);
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/**
	 * Nextcloud starts the background job every five minutes at most, so a shorter interval can't
	 * be kept: it can't be saved, and one that an earlier version or occ stored acts as five
	 * minutes, as it always did, and is shown as such.
	 */
	public function testTheIntervalIsAtLeastTheTimeBetweenTwoBackgroundJobs(): void {
		self::assertSame(5, ConfigService::MINIMUM_INTERVAL_MINUTES);
		self::assertSame(5, $this->service->save(['sync_interval_minutes' => 5] + self::validSettings(), 'secret-token')->syncIntervalMinutes);
		self::assertSame(1440, $this->service->save(['sync_interval_minutes' => 1440] + self::validSettings())->syncIntervalMinutes);

		$this->settings['sync_interval_minutes'] = 1;
		self::assertSame(5, $this->service->get()->syncIntervalMinutes);

		$config = $this->service->save(['batch_size' => 20] + self::validSettings());
		self::assertSame([5, 20], [$config->syncIntervalMinutes, $config->batchSize], 'Saving other settings keeps the interval that acts.');
		self::assertSame(5, $this->settings['sync_interval_minutes']);
	}

	/**
	 * Disconnecting deletes the token, as its button and its question say, and turns off the
	 * schedule, which would fail without the token. The other settings stay, the deletion policy
	 * of the administrator too, so that connecting again needs the token and not all of them.
	 */
	public function testDisconnectDeletesTheTokenAndTurnsOffTheSchedule(): void {
		$this->service->save(self::validSettings() + ['enabled' => true, 'permanent_delete' => true, 'base_path' => 'Archive/Paperless', 'batch_size' => 20], 'secret-token');
		$stored = $this->settings;

		$config = $this->service->reset();

		self::assertNull($this->credential);
		self::assertSame(array_replace($stored, ['sync_enabled' => false]), $this->settings);
		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->enabled);
		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertSame(['paperless', 'Archive/Paperless', 20], [$config->targetUser, $config->basePath, $config->batchSize]);
		self::assertTrue($config->permanentDelete);
		self::assertSame('', $this->service->getToken());
	}

	/** Disconnecting needs no saved settings, such as right after the installation. */
	public function testDisconnectWithoutSettingsKeepsTheDefaults(): void {
		$config = $this->service->reset();

		self::assertSame(['sync_enabled' => false, 'paperless_local_network' => false], $this->settings);
		self::assertSame($this->configService()->get()->jsonSerialize(), $config->jsonSerialize());
		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
	}

	/**
	 * Allowing the local network lifts a protection of Nextcloud for the requests to Paperless, so
	 * it is off until an administrator turns it on, and off again after disconnecting.
	 */
	public function testTheLocalNetworkIsAllowedOnlyAfterItWasTurnedOn(): void {
		self::assertFalse($this->service->get()->paperlessLocalNetwork);

		$config = $this->service->save(self::validSettings() + ['paperless_local_network' => true], 'secret-token');

		self::assertTrue($config->paperlessLocalNetwork);
		self::assertTrue($this->settings['paperless_local_network']);
		self::assertTrue($config->jsonSerialize()['paperlessLocalNetwork'], 'The settings page shows it.');
		self::assertTrue($this->service->save(self::validSettings())->paperlessLocalNetwork, 'A save that leaves it out keeps it.');

		self::assertFalse($this->service->reset()->paperlessLocalNetwork);
		self::assertFalse($this->settings['paperless_local_network']);
	}

	/** @return array<string, array{mixed, bool}> a value of the setting as a request may send it, and whether it allows the local network */
	public static function valuesOfTheLocalNetwork(): array {
		return [
			'true' => [true, true],
			'false' => [false, false],
			'1 of a form' => ['1', true],
			'0 of a form' => ['0', false],
			'the number 1' => [1, true],
			'"true"' => ['true', true],
			'"on"' => ['on', true],
			'"false"' => ['false', false],
			'empty' => ['', false],
			'anything else' => ['maybe', false],
			'null' => [null, false],
		];
	}

	/** Only a clear yes lifts the protection of Nextcloud; "false", which PHP would take for true, is a no. */
	#[DataProvider('valuesOfTheLocalNetwork')]
	public function testOnlyAClearYesAllowsTheLocalNetwork(mixed $value, bool $allowed): void {
		$config = $this->service->save(self::validSettings() + ['paperless_local_network' => $value], 'secret-token');

		self::assertSame($allowed, $config->paperlessLocalNetwork);
		self::assertSame($allowed, $this->settings['paperless_local_network']);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function invalidSettings(): array {
		return [
			'empty URL' => [['paperless_url' => ' '], 'Enter a valid Paperless URL.'],
			'URL without scheme' => [['paperless_url' => 'paperless.example.test'], 'Enter a valid Paperless URL.'],
			'FTP URL' => [['paperless_url' => 'ftp://paperless.example.test'], 'The Paperless URL must use HTTP or HTTPS.'],
			'URL with credentials' => [['paperless_url' => 'https://user:secret@paperless.example.test'], 'The Paperless URL must not contain credentials, a query, or a fragment.'],
			'URL with a query' => [['paperless_url' => 'https://paperless.example.test/?page=1'], 'The Paperless URL must not contain credentials, a query, or a fragment.'],
			'URL with a fragment' => [['paperless_url' => 'https://paperless.example.test/#top'], 'The Paperless URL must not contain credentials, a query, or a fragment.'],
			'no target user' => [['target_user' => '  '], 'A Nextcloud target user is required.'],
			'base path leaving the user folder' => [['base_path' => 'Dokumente/../..'], 'Folder paths must be relative and must not contain empty, . or .. components.'],
			'nested folder name' => [['archive_folder' => 'Archiv/2026'], 'Folder names must contain exactly one path component.'],
			'shared folder name' => [['error_folder' => 'Eingang'], 'Archive, inbox, and error folders must have different names.'],
			'template without ID' => [['path_template' => '{{ title }}{{ extension }}'], 'The archive path template must contain {{ id }}.'],
			'interval shorter than the background job' => [['sync_interval_minutes' => 4], 'Synchronization interval must be between 5 and 1440.'],
			'interval longer than a day' => [['sync_interval_minutes' => 1441], 'Synchronization interval must be between 5 and 1440.'],
			'batch too large' => [['batch_size' => 1001], 'Batch size must be between 1 and 1000.'],
			'too many confirmation runs' => [['missing_grace_runs' => 31], 'Missing-document confirmation runs must be between 1 and 30.'],
			'unknown trash policy' => [['trash_mode' => 'delete'], 'Invalid Paperless trash policy.'],
			'unknown conflict policy' => [['conflict_mode' => 'rename'], 'Invalid file conflict policy.'],
		];
	}

	/** @param array<string, mixed> $settings */
	#[DataProvider('invalidSettings')]
	public function testRejectsInvalidSettings(array $settings, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		try {
			$this->service->save($settings + self::validSettings(), 'secret-token');
		} finally {
			self::assertSame([], $this->settings, 'Invalid settings must not be stored.');
		}
	}

	public function testUppercaseSchemeIsAccepted(): void {
		self::assertSame('HTTPS://paperless.example.test', $this->service->normalizeUrl('HTTPS://paperless.example.test/'));
	}
}
