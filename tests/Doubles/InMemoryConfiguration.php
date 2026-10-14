<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Nextcloud's app configuration and credentials store, kept in the properties of the test.
 */
trait InMemoryConfiguration {
	/** @var array<string, bool|int|string> */
	private array $settings = [];
	/** @var array<string, true> the keys stored as lazy, which Nextcloud reads only when asked for lazy keys */
	private array $lazyKeys = [];
	/** @var array<string, true> the keys read as lazy, which makes Nextcloud load every lazy value */
	private array $lazyReads = [];
	/** What the credentials store holds, as it gives it back: decoded from JSON. */
	private mixed $credential = null;
	/** How often the credentials store was read, for code that must not read the token. */
	private int $credentialReads = 0;

	private function configService(?PathTemplateService $paths = null): ConfigService {
		return new ConfigService($this->appConfig(), $this->credentialsManager(), $paths ?? new PathTemplateService());
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
		$mock->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->settings[$key]);
		});

		return $mock;
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
