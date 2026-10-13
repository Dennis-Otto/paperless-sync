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
		self::assertSame('secret-token', $this->token);
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
		self::assertSame('other-token', $this->token);
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
		self::assertSame('secret-token', $this->token);
	}

	public function testAStoredTokenWithoutItsUrlIsNotUsed(): void {
		$this->token = 'secret-token';

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Enter the API token again to connect to another Paperless URL.');
		$this->service->validate(self::validSettings());
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
		self::assertSame('', $this->token);
	}

	public function testResetRemovesEverySettingAndTheToken(): void {
		$this->service->save(self::validSettings() + ['enabled' => true], 'secret-token');

		$config = $this->service->reset();

		self::assertSame([], $this->settings);
		self::assertSame('', $this->token);
		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
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
			'interval too short' => [['sync_interval_minutes' => 0], 'Synchronization interval must be between 1 and 1440.'],
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
