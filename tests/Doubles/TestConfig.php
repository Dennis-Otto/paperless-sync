<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Model\SyncConfig;

/** Settings of a complete configuration, as the tests of the parts of a run need them, without the configuration of Nextcloud. */
final class TestConfig {
	/** The archive folder of the settings, below the home of the target user. */
	public const ARCHIVE = 'Dokumente/Paperless/Archiv';
	/** The inbox folder of the settings. */
	public const INBOX = 'Dokumente/Paperless/Eingang';
	/** The error folder of the settings. */
	public const ERRORS = 'Dokumente/Paperless/Fehler';

	private const DEFAULTS = [
		'paperlessUrl' => 'https://paperless.example.test',
		'tokenConfigured' => true,
		'enabled' => true,
		'targetUser' => TestStorage::USER,
		'basePath' => 'Dokumente/Paperless',
		'archiveFolder' => 'Archiv',
		'inboxFolder' => 'Eingang',
		'errorFolder' => 'Fehler',
		'deletedFolder' => '_Gelöscht',
		'pathTemplate' => '{{ title }}',
		'exportEnabled' => true,
		'inboxEnabled' => true,
		'preferArchive' => true,
		'skipInbox' => true,
		'excludedTags' => '',
		'syncIntervalMinutes' => 15,
		'batchSize' => 100,
		'trashMode' => 'move',
		'permanentDelete' => false,
		'allowDirectDelete' => false,
		'missingGraceRuns' => 3,
		'pruneEmptyFolders' => true,
		'deleteInboxAfterSuccess' => true,
		'recursiveInbox' => true,
		'conflictMode' => 'replace',
		'emptyCorrespondent' => '_Ohne Korrespondent',
		'emptyDocumentType' => '_Ohne Dokumenttyp',
		'emptyDate' => '_Ohne Datum',
		'untitled' => 'Ohne Titel',
	];

	/** @param array<string, bool|int|string> $settings the settings that differ from the defaults, by the names of the properties of SyncConfig */
	public static function of(array $settings = []): SyncConfig {
		/** @psalm-suppress InvalidArgument the keys are the names of the parameters */
		return new SyncConfig(...array_merge(self::DEFAULTS, $settings));
	}
}
