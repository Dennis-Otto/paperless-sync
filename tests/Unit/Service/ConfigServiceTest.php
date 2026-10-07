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

	public function testSavingWithoutATokenKeepsTheStoredOne(): void {
		$this->service->save(self::validSettings(), 'secret-token');

		$config = $this->service->save(['batch_size' => 50]);

		self::assertSame(50, $config->batchSize);
		self::assertSame('secret-token', $this->service->getToken());
		self::assertSame('secret-token', $this->service->resolveToken(' '));
		self::assertSame('other-token', $this->service->resolveToken(' other-token '));
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
