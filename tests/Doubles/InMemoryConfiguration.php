<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\OwnerMapping;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SystemTagService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
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
	/** @var list<string> the IDs of the groups of Nextcloud */
	private array $groupsOfNextcloud = ['admin', 'scanners'];
	/** @var array<string, list<string>> the IDs of the members of the groups of Nextcloud, by the IDs of the groups */
	private array $membersOfGroups = [];
	/** @var list<string> the IDs of the users of Nextcloud, as Nextcloud spells them */
	private array $usersOfNextcloud = ['paperless', 'admin'];

	private function configService(?PathTemplateService $paths = null, ?ICredentialsManager $credentials = null): ConfigService {
		return new ConfigService($this->appConfig(), $credentials ?? $this->credentialsManager(), $paths ?? new PathTemplateService(), $this->l10nFactory(), $this->systemConfig(), $this->groupsOfNextcloud(), $this->usersOfNextcloud());
	}

	/**
	 * The users of Nextcloud of $usersOfNextcloud, which Nextcloud finds by their IDs whatever their
	 * case, and gives with the ID as it spells it.
	 */
	private function usersOfNextcloud(): IUserManager {
		$find = function (string $userId): ?IUser {
			foreach ($this->usersOfNextcloud as $known) {
				if (mb_strtolower($known) === mb_strtolower($userId)) {
					$user = $this->createStub(IUser::class);
					$user->method('getUID')->willReturn($known);

					return $user;
				}
			}

			return null;
		};
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback($find);
		$users->method('userExists')->willReturnCallback(static fn (string $userId): bool => $find($userId) !== null);

		return $users;
	}

	/** Which users of Nextcloud get the documents of which users of Paperless, from $usersOfNextcloud and $membersOfGroups. */
	private function ownerMapping(): OwnerMapping {
		return new OwnerMapping($this->usersOfNextcloud(), $this->groupsOfNextcloud());
	}

	/** The collaborative tags of Nextcloud, which the runs give the copies with the tags of Paperless as tags of Nextcloud. */
	private ?TestSystemTags $nextcloudTags = null;

	/** The tags of Nextcloud of the runs, in $nextcloudTags, with the tags of the app in the config of the app. */
	private function systemTagService(NextcloudStorageInterface $storage): SystemTagService {
		$this->nextcloudTags ??= new TestSystemTags();

		return new SystemTagService($this->nextcloudTags, $this->nextcloudTags, $this->appConfig(), $storage);
	}

	/** The groups of Nextcloud, which the setting of the group that may send files names, by the IDs of $groupsOfNextcloud. */
	private function groupsOfNextcloud(): IGroupManager {
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(fn (string $groupId): bool => in_array($groupId, $this->groupsOfNextcloud, true));
		$groups->method('isInGroup')->willReturnCallback(fn (string $userId, string $groupId): bool => in_array($userId, $this->membersOfGroups[$groupId] ?? [], true));

		return $groups;
	}

	/** The status of the runs; a run holds its lock when $locking says so, and never without it. */
	private function statusService(ITimeFactory $clock, ?ILockingProvider $locking = null): StatusService {
		return new StatusService($this->appConfig(), $clock, $locking ?? $this->createStub(ILockingProvider::class));
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
		$mock = $this->createStub(IAppConfig::class);
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
	 * each language from its catalog in l10n/, so that a test also finds a text that a catalog
	 * lacks, and English, the language of the texts in the code.
	 *
	 * @return IFactory&MockObject
	 */
	private function l10nFactory(): IFactory {
		/** @var array<string, array<string, string>> $catalogs */
		$catalogs = [];
		foreach (glob(__DIR__ . '/../../l10n/*.json') ?: [] as $file) {
			$catalogs[basename($file, '.json')] = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['translations'];
		}
		$languages = ['en', ...array_keys($catalogs)];
		$factory = $this->createStub(IFactory::class);
		// Like Nextcloud, findGenericLanguage() of the app gives English for a language without a
		// catalog of the app, even when its generic language has one, which the app must not rely on.
		$factory->method('findGenericLanguage')->willReturnCallback(fn (?string $app = null): string => $app === null || in_array($this->defaultLanguage, $languages, true) ? $this->defaultLanguage : 'en');
		$factory->method('languageExists')->willReturnCallback(static fn (?string $app, string $language): bool => in_array($language, $languages, true));
		$factory->method('get')->willReturnCallback(function (string $app, ?string $language = null) use ($catalogs, $languages): IL10N {
			// Nextcloud would take the language of whoever reads instead of one without a catalog.
			self::assertContains($language, $languages, 'The app has no catalog for this language.');
			$l10n = $this->createStub(IL10N::class);
			$l10n->method('t')->willReturnCallback(static function (string $text, array|string $parameters = []) use ($language, $catalogs): string {
				if ($language !== 'en') {
					self::assertArrayHasKey($text, $catalogs[$language], "l10n/{$language}.json has no translation of this text.");
					$text = $catalogs[$language][$text];
				}

				return vsprintf($text, (array)$parameters);
			});

			return $l10n;
		});

		return $factory;
	}

	/**
	 * Nextcloud's system configuration, config.php, with $forcedLanguage as force_language
	 * and $defaultLanguage as default_language, which is missing when empty.
	 *
	 * @return IConfig&MockObject
	 */
	private function systemConfig(): IConfig {
		$config = $this->createStub(IConfig::class);
		// Nextcloud casts the value to a string, so true reads as '1' and false as ''.
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => match ($key) {
			'force_language' => (string)$this->forcedLanguage,
			'default_language' => $this->defaultLanguage === '' ? $default : $this->defaultLanguage,
			default => $default,
		});

		return $config;
	}

	/** @return ICredentialsManager&MockObject */
	private function credentialsManager(): ICredentialsManager {
		$credentials = $this->createStub(ICredentialsManager::class);
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
