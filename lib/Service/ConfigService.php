<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\Security\ICredentialsManager;

/**
 * The settings of the app, in the app configuration of Nextcloud, and the API token, in its
 * credentials store. Every setting is lazy: Nextcloud loads it when the app asks for it, not with
 * every request of every user.
 */
final class ConfigService {
	/**
	 * The shortest interval of the scheduled synchronization: Nextcloud starts the background job
	 * that runs it every five minutes at most.
	 */
	public const MINIMUM_INTERVAL_MINUTES = 5;
	/** The longest interval of the scheduled synchronization, a day. */
	public const MAXIMUM_INTERVAL_MINUTES = 1440;

	private const TOKEN_IDENTIFIER = AppConstants::APP_ID . '.api-token';

	private const DEFAULTS = [
		'paperless_url' => '',
		'paperless_local_network' => false,
		'enabled' => false,
		'target_user' => '',
		'path_template' => PathTemplateService::DEFAULT_TEMPLATE,
		'export_enabled' => true,
		'inbox_enabled' => true,
		'prefer_archive' => true,
		'skip_inbox' => true,
		'excluded_tags' => '',
		'sync_interval_minutes' => 5,
		'batch_size' => 100,
		'trash_mode' => 'move',
		'permanent_delete' => false,
		'allow_direct_delete' => false,
		'missing_grace_runs' => 3,
		'prune_empty_folders' => true,
		'delete_inbox_after_success' => true,
		'recursive_inbox' => true,
		'conflict_mode' => 'replace',
	];

	/**
	 * The names of the folders and the fallbacks for empty metadata that a new installation
	 * proposes, in English. They follow the default language of Nextcloud through the catalog of the
	 * app, so that a German instance proposes Dokumente/Paperless, Archiv and Eingang, and an
	 * English one Documents/Paperless, Archive and Inbox. Once saved, a name stays as it is.
	 */
	private const NAMES = [
		'base_path' => 'Documents/Paperless',
		'archive_folder' => 'Archive',
		'inbox_folder' => 'Inbox',
		'error_folder' => 'Failed imports',
		'deleted_folder' => '_Deleted',
		'empty_correspondent' => '_No correspondent',
		'empty_document_type' => '_No document type',
		'empty_date' => '_No date',
		'untitled' => 'Untitled',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $appConfig,
		private ICredentialsManager $credentialsManager,
		private PathTemplateService $pathTemplate,
		private IFactory $l10nFactory,
		private IConfig $config,
	) {
	}

	/**
	 * Makes lazy the settings that an earlier version stored to be loaded with every request of
	 * Nextcloud, and returns how many it changed.
	 */
	public function storeLazily(): int {
		$changed = 0;
		foreach (self::keys() as $key) {
			if ($this->appConfig->updateLazy(AppConstants::APP_ID, $this->storageKey($key), true)) {
				++$changed;
			}
		}

		return $changed;
	}

	public function get(): SyncConfig {
		return $this->fromValues($this->readValues(), $this->getToken() !== '');
	}

	/**
	 * The stored token, but only while the configured URL is the one that it was saved with. The
	 * URL may change without the settings page and its check in resolveToken(): through occ, or
	 * through the provisioning API of Nextcloud, which may set any value of the app configuration.
	 * The token is then held back until it is entered again, instead of going to the new URL with
	 * the next run.
	 */
	public function getToken(): string {
		$stored = $this->storedToken();

		return $stored !== null && $stored['url'] === $this->configuredUrl() ? $stored['token'] : '';
	}

	/**
	 * The token for a connection to $url: the one that was entered, or else the stored one, but
	 * only for the URL that it was stored with. Otherwise, whoever may change the settings, with
	 * the session of an administrator for example, could point the URL at a server of their own and
	 * receive the stored token with the test of the connection, without ever knowing it.
	 */
	public function resolveToken(#[\SensitiveParameter] string $tokenCandidate, string $url): string {
		$token = trim($tokenCandidate);
		if ($token !== '') {
			return $token;
		}
		$stored = $this->storedToken();
		if ($stored === null) {
			throw new InvalidSettingException('A Paperless API token is required.');
		}
		// The whole URL, not only its host: one host may serve several apps behind a proxy.
		if (rtrim(trim($url), '/') !== $stored['url']) {
			throw new InvalidSettingException('Enter the API token again to connect to another Paperless URL.');
		}

		return $stored['token'];
	}

	/**
	 * Bind a token that an earlier version stored on its own to the configured URL, the one that
	 * it was saved with, so that it keeps working after the update.
	 *
	 * @return bool whether there was such a token
	 */
	public function bindUnboundToken(): bool {
		/** @psalm-suppress MixedAssignment */
		$token = $this->credentialsManager->retrieve('', self::TOKEN_IDENTIFIER);
		if (!is_string($token) || trim($token) === '') {
			return false;
		}
		$this->storeToken($this->configuredUrl(), trim($token));

		return true;
	}

	/**
	 * Validate settings without changing persistent configuration.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function validate(array $settings, #[\SensitiveParameter] string $tokenCandidate = ''): SyncConfig {
		$values = $this->readValues();
		foreach (self::keys() as $key) {
			if (array_key_exists($key, $settings)) {
				/** @psalm-suppress MixedAssignment */
				$values[$key] = $settings[$key];
			}
		}
		/** @var array<string, mixed> $normalized */
		$normalized = $this->normalize($values);
		$token = $this->resolveToken($tokenCandidate, (string)$normalized['paperless_url']);

		return $this->fromValues($normalized, $token !== '');
	}

	/**
	 * Settings for a dry-run that uses them without saving them, such as those of the settings page
	 * before a save, which validate() checks. Their connection to Paperless and their target user
	 * are the saved ones, which a save tests and confirms with the password of an administrator: a
	 * dry-run doesn't ask for it, so it may neither send the stored token to another address, nor
	 * allow one of the local network, nor read the files of another user.
	 *
	 * @param array<string, mixed> $settings
	 * @throws UnsavedSettingException when one of these settings differs from the saved one
	 * @throws InvalidSettingException when a setting is invalid
	 */
	public function validateForDryRun(array $settings): SyncConfig {
		$saved = $this->readValues();
		$same = [
			'paperless_url' => static fn (mixed $value): string => rtrim(trim(is_scalar($value) ? (string)$value : ''), '/'),
			'paperless_local_network' => static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'target_user' => static fn (mixed $value): string => trim(is_scalar($value) ? (string)$value : ''),
		];
		foreach ($same as $key => $normalize) {
			if (array_key_exists($key, $settings) && $normalize($settings[$key]) !== $normalize($saved[$key])) {
				throw new UnsavedSettingException($key);
			}
		}

		return $this->validate($settings);
	}

	/** @param array<string, mixed> $settings */
	public function save(array $settings, #[\SensitiveParameter] string $tokenCandidate = ''): SyncConfig {
		$config = $this->validate($settings, $tokenCandidate);
		$token = $this->resolveToken($tokenCandidate, $config->paperlessUrl);
		$values = $config->jsonSerialize();

		$mapping = [
			'paperless_url' => 'paperlessUrl', 'paperless_local_network' => 'paperlessLocalNetwork', 'enabled' => 'enabled', 'target_user' => 'targetUser',
			'base_path' => 'basePath', 'archive_folder' => 'archiveFolder', 'inbox_folder' => 'inboxFolder',
			'error_folder' => 'errorFolder', 'deleted_folder' => 'deletedFolder', 'path_template' => 'pathTemplate',
			'export_enabled' => 'exportEnabled', 'inbox_enabled' => 'inboxEnabled', 'prefer_archive' => 'preferArchive',
			'skip_inbox' => 'skipInbox', 'excluded_tags' => 'excludedTags', 'sync_interval_minutes' => 'syncIntervalMinutes',
			'batch_size' => 'batchSize', 'trash_mode' => 'trashMode', 'permanent_delete' => 'permanentDelete',
			'allow_direct_delete' => 'allowDirectDelete', 'missing_grace_runs' => 'missingGraceRuns',
			'prune_empty_folders' => 'pruneEmptyFolders', 'delete_inbox_after_success' => 'deleteInboxAfterSuccess',
			'recursive_inbox' => 'recursiveInbox', 'conflict_mode' => 'conflictMode',
			'empty_correspondent' => 'emptyCorrespondent', 'empty_document_type' => 'emptyDocumentType',
			'empty_date' => 'emptyDate', 'untitled' => 'untitled',
		];

		foreach ($mapping as $key => $property) {
			$value = $values[$property];
			$storageKey = $this->storageKey($key);
			if (is_bool($value)) {
				$this->appConfig->setValueBool(AppConstants::APP_ID, $storageKey, $value, lazy: true);
			} elseif (is_int($value)) {
				$this->appConfig->setValueInt(AppConstants::APP_ID, $storageKey, $value, lazy: true);
			} else {
				$this->appConfig->setValueString(AppConstants::APP_ID, $storageKey, $value, lazy: true);
			}
		}
		$this->storeToken($config->paperlessUrl, $token);

		return $this->get();
	}

	/**
	 * Disconnect Paperless: delete the token and turn off what reaches Paperless without an
	 * administrator watching, the scheduled synchronization, and the access to the local network,
	 * which lifts a protection of Nextcloud. The other settings stay, the deletion policy too: to
	 * connect again, an administrator enters the token, and allows the local network again when
	 * Paperless runs there.
	 */
	public function reset(): SyncConfig {
		$this->credentialsManager->delete('', self::TOKEN_IDENTIFIER);
		foreach (['enabled', 'paperless_local_network'] as $key) {
			$this->appConfig->setValueBool(AppConstants::APP_ID, $this->storageKey($key), false, lazy: true);
		}

		return $this->get();
	}

	public function normalizeUrl(string $url): string {
		$normalized = rtrim(trim($url), '/');
		if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_URL) === false) {
			throw new InvalidSettingException('Enter a valid Paperless URL.', [], 'paperless_url');
		}
		$parts = parse_url($normalized);
		$scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new InvalidSettingException('The Paperless URL must use HTTP or HTTPS.', [], 'paperless_url');
		}
		if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))) {
			throw new InvalidSettingException('The Paperless URL must not contain credentials, a query, or a fragment.', [], 'paperless_url');
		}

		return $normalized;
	}

	/** The token with the URL that it may go to, nothing else, in the store of Nextcloud, which encrypts it. */
	private function storeToken(string $url, #[\SensitiveParameter] string $token): void {
		$this->credentialsManager->store('', self::TOKEN_IDENTIFIER, ['url' => $url, 'token' => $token]);
	}

	/** @return array{url: string, token: string}|null the stored token and the URL that it was saved with */
	private function storedToken(): ?array {
		/** @psalm-suppress MixedAssignment */
		$stored = $this->credentialsManager->retrieve('', self::TOKEN_IDENTIFIER);
		if (!is_array($stored)) {
			return null;
		}
		$url = $stored['url'] ?? null;
		$token = $stored['token'] ?? null;
		if (!is_string($url) || !is_string($token) || $token === '') {
			return null;
		}

		return ['url' => $url, 'token' => $token];
	}

	/**
	 * Keep the names of the folders and the fallbacks of an installation that is in use: store
	 * each of $names whose setting has no stored value, so that the default of a newer version,
	 * in the default language of Nextcloud, doesn't move its archive. Returns how many it stored.
	 *
	 * @param array<string, string> $names by setting, such as base_path
	 */
	public function keepNames(array $names): int {
		$kept = 0;
		foreach (array_intersect_key($names, self::NAMES) as $key => $name) {
			$storageKey = $this->storageKey($key);
			if (!$this->appConfig->hasKey(AppConstants::APP_ID, $storageKey, null)) {
				$this->appConfig->setValueString(AppConstants::APP_ID, $storageKey, $name, lazy: true);
				++$kept;
			}
		}

		return $kept;
	}

	/**
	 * The configured Paperless URL alone, without a trailing slash. Unlike get(), it doesn't read the
	 * token, which a link to Paperless, such as in the activity stream, doesn't need.
	 */
	public function configuredUrl(): string {
		return rtrim(trim($this->appConfig->getValueString(AppConstants::APP_ID, 'paperless_url', '', lazy: true)), '/');
	}

	/**
	 * The configured target user alone. Unlike get(), it doesn't read the token, whose decryption
	 * fails after a restore with another secret, which a check whether the app is set up must survive.
	 */
	public function targetUser(): string {
		return trim($this->appConfig->getValueString(AppConstants::APP_ID, 'target_user', '', lazy: true));
	}

	/** @return array<string, mixed> */
	private function readValues(): array {
		$values = [];
		foreach ($this->defaults() as $key => $default) {
			$storageKey = $this->storageKey($key);
			if (is_bool($default)) {
				$values[$key] = $this->appConfig->getValueBool(AppConstants::APP_ID, $storageKey, $default, lazy: true);
			} elseif (is_int($default)) {
				$values[$key] = $this->appConfig->getValueInt(AppConstants::APP_ID, $storageKey, $default, lazy: true);
			} else {
				$values[$key] = $this->appConfig->getValueString(AppConstants::APP_ID, $storageKey, $default, lazy: true);
			}
		}
		// A shorter interval, which an earlier version or occ stored, always acted as the shortest.
		$values['sync_interval_minutes'] = max(self::MINIMUM_INTERVAL_MINUTES, (int)$values['sync_interval_minutes']);

		return $values;
	}

	/** @return list<string> every setting, by its key */
	private static function keys(): array {
		return array_keys(self::DEFAULTS + self::NAMES);
	}

	/**
	 * The default of every setting, with the names in the default language of Nextcloud: the
	 * language of the instance, not that of whoever reads the settings, so that the settings page
	 * and a scheduled run, which has no reader, agree on them.
	 *
	 * @return array<string, bool|int|string>
	 */
	private function defaults(): array {
		$l10n = $this->l10nFactory->get(AppConstants::APP_ID, $this->defaultLanguage());
		$defaults = self::DEFAULTS;
		foreach (array_keys(self::NAMES) as $key) {
			$defaults[$key] = $l10n->t(self::NAMES[$key]);
		}

		return $defaults;
	}

	/**
	 * The default language of Nextcloud, force_language before default_language as Nextcloud reads
	 * them, or else its generic language, such as de for de_DE, when the app has no catalog for the
	 * regional one, or else English. It reads the system configuration itself: IFactory's
	 * findGenericLanguage() gives English for a language that the app has no catalog for, before
	 * trying the generic one, and follows the forceLanguage parameter of a request. Nextcloud would
	 * fall back to the language of the user who reads, which differs between administrators and is
	 * missing in a scheduled run.
	 */
	private function defaultLanguage(): string {
		$language = $this->config->getSystemValueString('force_language', '');
		// force_language true, read as '1', only keeps users from choosing a language.
		if (in_array($language, ['', '1'], true)) {
			$language = $this->config->getSystemValueString('default_language', 'en');
		}
		foreach ([$language, explode('_', $language)[0]] as $candidate) {
			if ($this->l10nFactory->languageExists(AppConstants::APP_ID, $candidate)) {
				return $candidate;
			}
		}

		return 'en';
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function normalize(array $values): array {
		$values['paperless_url'] = $this->normalizeUrl((string)$values['paperless_url']);
		// It lifts a protection of Nextcloud, so only a clear yes turns it on: true, as the settings
		// page sends it, or 1, "1", "true", "on" or "yes", as a form sends it; "false" too is a no.
		$values['paperless_local_network'] = filter_var($values['paperless_local_network'], FILTER_VALIDATE_BOOLEAN);
		$values['target_user'] = trim((string)$values['target_user']);
		if ($values['target_user'] === '') {
			throw new InvalidSettingException('A Nextcloud target user is required.', [], 'target_user');
		}
		$values['base_path'] = $this->pathTemplate->normalizeRelativePath((string)$values['base_path']);
		foreach (['archive_folder', 'inbox_folder', 'error_folder', 'deleted_folder'] as $key) {
			$values[$key] = $this->pathTemplate->normalizeFolderName((string)($values[$key] ?? ''));
		}
		if (count(array_unique([$values['archive_folder'], $values['inbox_folder'], $values['error_folder']])) !== 3) {
			throw new InvalidSettingException('Archive, inbox, and error folders must have different names.');
		}
		$values['path_template'] = $this->pathTemplate->validateTemplate((string)$values['path_template']);
		$values['excluded_tags'] = implode(', ', array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string)$values['excluded_tags']) ?: [])))));
		// The numbers, by the labels of the settings page, which their messages name.
		$values['sync_interval_minutes'] = self::within((int)$values['sync_interval_minutes'], self::MINIMUM_INTERVAL_MINUTES, self::MAXIMUM_INTERVAL_MINUTES)
			?? throw new InvalidSettingException('"Interval in minutes" must be between %1$s and %2$s.', [(string)self::MINIMUM_INTERVAL_MINUTES, (string)self::MAXIMUM_INTERVAL_MINUTES], 'sync_interval_minutes');
		$values['batch_size'] = self::within((int)$values['batch_size'], 1, 1000)
			?? throw new InvalidSettingException('"Maximum changes per module and run" must be between %1$s and %2$s.', ['1', '1000'], 'batch_size');
		$values['missing_grace_runs'] = self::within((int)$values['missing_grace_runs'], 1, 30)
			?? throw new InvalidSettingException('"Required consecutive missing scans" must be between %1$s and %2$s.', ['1', '30'], 'missing_grace_runs');
		if (!in_array($values['trash_mode'], ['keep', 'move'], true)) {
			throw new InvalidSettingException('Choose one of the options of "Paperless trash behavior".', [], 'trash_mode');
		}
		if (!in_array($values['conflict_mode'], ['replace', 'skip'], true)) {
			throw new InvalidSettingException('Choose one of the options of "File conflict policy".', [], 'conflict_mode');
		}
		foreach (['empty_correspondent', 'empty_document_type', 'empty_date', 'untitled'] as $key) {
			$values[$key] = $this->pathTemplate->cleanComponent($values[$key] ?? '', 'Unknown');
		}

		return $values;
	}

	/** The value when it is from $minimum to $maximum, or else null. */
	private static function within(int $value, int $minimum, int $maximum): ?int {
		return $value >= $minimum && $value <= $maximum ? $value : null;
	}

	private function storageKey(string $key): string {
		return $key === 'enabled' ? 'sync_enabled' : $key;
	}

	/** @param array<string, mixed> $values */
	private function fromValues(array $values, bool $tokenConfigured): SyncConfig {
		return new SyncConfig(
			(string)$values['paperless_url'], $tokenConfigured, (bool)$values['enabled'], (string)$values['target_user'],
			(string)$values['base_path'], (string)$values['archive_folder'], (string)$values['inbox_folder'],
			(string)$values['error_folder'], (string)$values['deleted_folder'], (string)$values['path_template'],
			(bool)$values['export_enabled'], (bool)$values['inbox_enabled'], (bool)$values['prefer_archive'],
			(bool)$values['skip_inbox'], (string)$values['excluded_tags'], (int)$values['sync_interval_minutes'],
			(int)$values['batch_size'], (string)$values['trash_mode'], (bool)$values['permanent_delete'],
			(bool)$values['allow_direct_delete'], (int)$values['missing_grace_runs'], (bool)$values['prune_empty_folders'],
			(bool)$values['delete_inbox_after_success'], (bool)$values['recursive_inbox'], (string)$values['conflict_mode'],
			(string)$values['empty_correspondent'], (string)$values['empty_document_type'], (string)$values['empty_date'],
			(string)$values['untitled'], (bool)$values['paperless_local_network'],
		);
	}
}
