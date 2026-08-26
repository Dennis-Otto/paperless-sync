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

	public function getToken(): string {
		/** @psalm-suppress MixedAssignment */
		$token = $this->credentialsManager->retrieve('', self::TOKEN_IDENTIFIER);

		return is_string($token) ? $token : '';
	}

	public function resolveToken(string $candidate): string {
		$token = trim($candidate);
		if ($token === '') {
			$token = $this->getToken();
		}
		if ($token === '') {
			throw new InvalidArgumentException('A Paperless API token is required.');
		}

		return $token;
	}

	/**
	 * Validate settings without changing persistent configuration.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function validate(array $settings, string $tokenCandidate = ''): SyncConfig {
		$values = $this->readValues();
		foreach (array_keys(self::DEFAULTS) as $key) {
			if (array_key_exists($key, $settings)) {
				/** @psalm-suppress MixedAssignment */
				$values[$key] = $settings[$key];
			}
		}
		$token = $this->resolveToken($tokenCandidate);
		/** @var array<string, mixed> $normalized */
		$normalized = $this->normalize($values);

		return $this->fromValues($normalized, $token !== '');
	}

	/** @param array<string, mixed> $settings */
	public function save(array $settings, string $tokenCandidate = ''): SyncConfig {
		$config = $this->validate($settings, $tokenCandidate);
		$token = $this->resolveToken($tokenCandidate);
		$values = $config->jsonSerialize();

		$mapping = [
			'paperless_url' => 'paperlessUrl', 'enabled' => 'enabled', 'target_user' => 'targetUser',
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
		$this->credentialsManager->store('', self::TOKEN_IDENTIFIER, $token);

		return $this->get();
	}

	public function reset(): SyncConfig {
		foreach (array_keys(self::DEFAULTS) as $key) {
			$this->appConfig->deleteKey(AppConstants::APP_ID, $this->storageKey($key));
		}
		$this->credentialsManager->delete('', self::TOKEN_IDENTIFIER);

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
			(string)$values['untitled'],
		);
	}
}
