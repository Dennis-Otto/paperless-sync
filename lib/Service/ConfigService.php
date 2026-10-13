<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;

final class ConfigService {
	private const TOKEN_IDENTIFIER = AppConstants::APP_ID . '.api-token';

	private const DEFAULTS = [
		'paperless_url' => '',
		'paperless_local_network' => false,
		'enabled' => false,
		'target_user' => '',
		'base_path' => 'Dokumente/Paperless',
		'archive_folder' => 'Archiv',
		'inbox_folder' => 'Eingang',
		'error_folder' => 'Fehler',
		'deleted_folder' => '_Gelöscht',
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
		'empty_correspondent' => '_Ohne Korrespondent',
		'empty_document_type' => '_Ohne Dokumenttyp',
		'empty_date' => '_Ohne Datum',
		'untitled' => 'Ohne Titel',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $appConfig,
		private ICredentialsManager $credentialsManager,
		private PathTemplateService $pathTemplate,
	) {
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
			throw new InvalidArgumentException('A Paperless API token is required.');
		}
		// The whole URL, not only its host: one host may serve several apps behind a proxy.
		if (rtrim(trim($url), '/') !== $stored['url']) {
			throw new InvalidArgumentException('Enter the API token again to connect to another Paperless URL.');
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
		foreach (array_keys(self::DEFAULTS) as $key) {
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
				$this->appConfig->setValueBool(AppConstants::APP_ID, $storageKey, $value);
			} elseif (is_int($value)) {
				$this->appConfig->setValueInt(AppConstants::APP_ID, $storageKey, $value);
			} else {
				$this->appConfig->setValueString(AppConstants::APP_ID, $storageKey, $value);
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
			$this->appConfig->setValueBool(AppConstants::APP_ID, $this->storageKey($key), false);
		}

		return $this->get();
	}

	public function normalizeUrl(string $url): string {
		$normalized = rtrim(trim($url), '/');
		if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_URL) === false) {
			throw new InvalidArgumentException('Enter a valid Paperless URL.');
		}
		$parts = parse_url($normalized);
		$scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new InvalidArgumentException('The Paperless URL must use HTTP or HTTPS.');
		}
		if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))) {
			throw new InvalidArgumentException('The Paperless URL must not contain credentials, a query, or a fragment.');
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

	private function configuredUrl(): string {
		return rtrim(trim($this->appConfig->getValueString(AppConstants::APP_ID, 'paperless_url', '')), '/');
	}

	/** @return array<string, mixed> */
	private function readValues(): array {
		$values = [];
		foreach (self::DEFAULTS as $key => $default) {
			$storageKey = $this->storageKey($key);
			if (is_bool($default)) {
				$values[$key] = $this->appConfig->getValueBool(AppConstants::APP_ID, $storageKey, $default);
			} elseif (is_int($default)) {
				$values[$key] = $this->appConfig->getValueInt(AppConstants::APP_ID, $storageKey, $default);
			} else {
				$values[$key] = $this->appConfig->getValueString(AppConstants::APP_ID, $storageKey, $default);
			}
		}

		return $values;
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function normalize(array $values): array {
		$values['paperless_url'] = $this->normalizeUrl((string)$values['paperless_url']);
		// It lifts a protection of Nextcloud, so only a clear yes turns it on: true, as the settings
		// page sends it, or 1, "1", "true", "on" or "yes", as a form sends it; "false" too is a no.
		$values['paperless_local_network'] = filter_var($values['paperless_local_network'], FILTER_VALIDATE_BOOLEAN);
		$values['target_user'] = trim((string)$values['target_user']);
		if ($values['target_user'] === '') {
			throw new InvalidArgumentException('A Nextcloud target user is required.');
		}
		$values['base_path'] = $this->pathTemplate->normalizeRelativePath((string)$values['base_path']);
		foreach (['archive_folder', 'inbox_folder', 'error_folder', 'deleted_folder'] as $key) {
			$values[$key] = $this->pathTemplate->normalizeFolderName((string)($values[$key] ?? ''));
		}
		if (count(array_unique([$values['archive_folder'], $values['inbox_folder'], $values['error_folder']])) !== 3) {
			throw new InvalidArgumentException('Archive, inbox, and error folders must have different names.');
		}
		$values['path_template'] = $this->pathTemplate->validateTemplate((string)$values['path_template']);
		$values['excluded_tags'] = implode(', ', array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string)$values['excluded_tags']) ?: [])))));
		$values['sync_interval_minutes'] = $this->range((int)$values['sync_interval_minutes'], 1, 1440, 'Synchronization interval');
		$values['batch_size'] = $this->range((int)$values['batch_size'], 1, 1000, 'Batch size');
		$values['missing_grace_runs'] = $this->range((int)$values['missing_grace_runs'], 1, 30, 'Missing-document confirmation runs');
		if (!in_array($values['trash_mode'], ['keep', 'move'], true)) {
			throw new InvalidArgumentException('Invalid Paperless trash policy.');
		}
		if (!in_array($values['conflict_mode'], ['replace', 'skip'], true)) {
			throw new InvalidArgumentException('Invalid file conflict policy.');
		}
		foreach (['empty_correspondent', 'empty_document_type', 'empty_date', 'untitled'] as $key) {
			$values[$key] = $this->pathTemplate->cleanComponent($values[$key] ?? '', 'Unknown');
		}

		return $values;
	}

	private function range(int $value, int $minimum, int $maximum, string $label): int {
		if ($value < $minimum || $value > $maximum) {
			throw new InvalidArgumentException("{$label} must be between {$minimum} and {$maximum}.");
		}

		return $value;
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
