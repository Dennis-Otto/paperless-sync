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
	private string $token = '';

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

	/** @return IAppConfig&MockObject */
	private function appConfig(): IAppConfig {
		$mock = $this->createMock(IAppConfig::class);
		$mock->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '', bool $lazy = false): string => isset($this->lazyKeys[$key]) && !$lazy ? $default : (string)($this->settings[$key] ?? $default));
		$mock->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->settings[$key] ?? $default));
		$mock->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->settings[$key] ?? $default));
		$mock->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy = false): bool {
			$this->settings[$key] = $value;
			if ($lazy) {
				$this->lazyKeys[$key] = true;
			} else {
				unset($this->lazyKeys[$key]);
			}
			return true;
		});
		$mock->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$mock->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->settings[$key] = $value;
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
		$credentials->method('retrieve')->willReturnCallback(fn (): ?string => $this->token !== '' ? $this->token : null);
		$credentials->method('store')->willReturnCallback(function (string $userId, string $identifier, mixed $value): void {
			$this->token = is_string($value) ? $value : '';
		});
		$credentials->method('delete')->willReturnCallback(function (): int {
			$this->token = '';
			return 1;
		});

		return $credentials;
	}
}
