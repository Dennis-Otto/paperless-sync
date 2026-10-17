<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Nextcloud's app configuration, system configuration and credentials store, kept in the
 * properties of the test.
 */
trait InMemoryConfiguration {
	/** @var array<string, bool|int|string> */
	private array $settings = [];
	/** @var array<string, int> the values of config.php */
	private array $systemSettings = [];
	/** @var array<string, true> the keys stored as lazy, which Nextcloud reads only when asked for lazy keys */
	private array $lazyKeys = [];
	/** @var array<string, true> the keys read as lazy, which makes Nextcloud load every lazy value */
	private array $lazyReads = [];
	/** What the credentials store holds, as it gives it back: decoded from JSON. */
	private mixed $credential = null;
	/** How often the credentials store was read, for code that must not read the token. */
	private int $credentialReads = 0;
	/** The default language of Nextcloud, which the names of the folders of a new installation follow. */
	private string $defaultLanguage = 'en';
	/** The forced language of Nextcloud, false when none is set, as config.php holds it. */
	private bool|string $forcedLanguage = false;

	private function configService(?PathTemplateService $paths = null, ?ICredentialsManager $credentials = null): ConfigService {
		return new ConfigService($this->appConfig(), $credentials ?? $this->credentialsManager(), $paths ?? new PathTemplateService(), $this->l10nFactory(), $this->systemConfig());
	}

	/** The status of the runs; a run holds its lock when $locking says so, and never without it. */
	private function statusService(ITimeFactory $clock, ?ILockingProvider $locking = null): StatusService {
		return new StatusService($this->appConfig(), $clock, $locking ?? $this->createStub(ILockingProvider::class), $this->systemConfig());
	}

	/**
	 * Settings that pass validation, for tests that need a configured app.
	 *
	 * @return array<string, bool|int|string>
	 */
	private static function validSettings(): array {
		return [
			'paperless_url' => 'https://paperless.example.test',
			'target_user' => 'paperless',
			'inbox_enabled' => true,
			'export_enabled' => true,
		];
	}

	/**
	 * Nextcloud's app configuration: a lazy key comes back only to a lazy read, as Nextcloud loads
	 * lazy keys only when asked for one, and a lazy read gets every key.
	 *
	 * @return IAppConfig&MockObject
	 */
	private function appConfig(): IAppConfig {
		$mock = $this->createMock(IAppConfig::class);
		$read = function (string $key, bool|int|string $default, bool $lazy): bool|int|string {
			if ($lazy) {
				$this->lazyReads[$key] = true;
			}

			return isset($this->lazyKeys[$key]) && !$lazy ? $default : ($this->settings[$key] ?? $default);
		};
		$write = function (string $key, bool|int|string $value, bool $lazy): bool {
			$this->settings[$key] = $value;
			if ($lazy) {
				$this->lazyKeys[$key] = true;
			} else {
				unset($this->lazyKeys[$key]);
			}

			return true;
		};
		$mock->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '', bool $lazy = false): string => (string)$read($key, $default, $lazy));
		$mock->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => (bool)$read($key, $default, $lazy));
		$mock->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0, bool $lazy = false): int => (int)$read($key, $default, $lazy));
		$mock->method('setValueString')->willReturnCallback(fn (string $app, string $key, string $value, bool $lazy = false): bool => $write($key, $value, $lazy));
		$mock->method('setValueBool')->willReturnCallback(fn (string $app, string $key, bool $value, bool $lazy = false): bool => $write($key, $value, $lazy));
		$mock->method('setValueInt')->willReturnCallback(fn (string $app, string $key, int $value, bool $lazy = false): bool => $write($key, $value, $lazy));
		$mock->method('updateLazy')->willReturnCallback(function (string $app, string $key, bool $lazy): bool {
			if (!array_key_exists($key, $this->settings) || isset($this->lazyKeys[$key]) === $lazy) {
				return false;
			}
			if ($lazy) {
				$this->lazyKeys[$key] = true;
			} else {
				unset($this->lazyKeys[$key]);
			}

			return true;
		});
		// Whether a key is stored, as a lazy one or not, or as either for null.
		$mock->method('hasKey')->willReturnCallback(fn (string $app, string $key, ?bool $lazy = false): bool => array_key_exists($key, $this->settings)
			&& ($lazy === null || isset($this->lazyKeys[$key]) === $lazy));
		$mock->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->settings[$key]);
		});

		return $mock;
	}

	/**
	 * Nextcloud's translations of the app, with $defaultLanguage as the language of the instance:
	 * German from the catalog l10n/de.json, so that a test also finds a text that the catalog
	 * lacks, and English, the language of the texts in the code; the app has no other catalog.
	 *
	 * @return IFactory&MockObject
	 */
	private function l10nFactory(): IFactory {
		$catalog = json_decode((string)file_get_contents(__DIR__ . '/../../l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		/** @var array<string, string> $german */
		$german = $catalog['translations'];
		$factory = $this->createMock(IFactory::class);
		// Like Nextcloud, findGenericLanguage() of the app gives English for a language without a
		// catalog of the app, even when its generic language has one, which the app must not rely on.
		$factory->method('findGenericLanguage')->willReturnCallback(fn (?string $app = null): string => $app === null || in_array($this->defaultLanguage, ['en', 'de'], true) ? $this->defaultLanguage : 'en');
		$factory->method('languageExists')->willReturnCallback(static fn (?string $app, string $language): bool => in_array($language, ['en', 'de'], true));
		$factory->method('get')->willReturnCallback(function (string $app, ?string $language = null) use ($german): IL10N {
			// Nextcloud would take the language of whoever reads instead of one without a catalog.
			self::assertContains($language, ['en', 'de'], 'The app has no catalog for this language.');
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static function (string $text, array|string $parameters = []) use ($language, $german): string {
				if ($language === 'de') {
					self::assertArrayHasKey($text, $german, 'l10n/de.json has no translation of this text.');
					$text = $german[$text];
				}

				return vsprintf($text, (array)$parameters);
			});

			return $l10n;
		});

		return $factory;
	}

	/**
	 * Nextcloud's system configuration, config.php, with $forcedLanguage as force_language,
	 * $defaultLanguage as default_language, which is missing when empty, and the numbers of
	 * $systemSettings, such as filelocking.ttl.
	 *
	 * @return IConfig&MockObject
	 */
	private function systemConfig(): IConfig {
		$config = $this->createMock(IConfig::class);
		// Nextcloud casts the value to a string, so true reads as '1' and false as ''.
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => match ($key) {
			'force_language' => (string)$this->forcedLanguage,
			'default_language' => $this->defaultLanguage === '' ? $default : $this->defaultLanguage,
			default => $default,
		});
		$config->method('getSystemValueInt')->willReturnCallback(fn (string $key, int $default = 0): int => $this->systemSettings[$key] ?? $default);

		return $config;
	}

	/** @return ICredentialsManager&MockObject */
	private function credentialsManager(): ICredentialsManager {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturnCallback(function (): mixed {
			++$this->credentialReads;

			return $this->credential;
		});
		$credentials->method('store')->willReturnCallback(function (string $userId, string $identifier, mixed $value): void {
			/** @psalm-suppress MixedAssignment */
			$this->credential = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		});
		$credentials->method('delete')->willReturnCallback(function (): int {
			$this->credential = null;
			return 1;
		});

		return $credentials;
	}
}
