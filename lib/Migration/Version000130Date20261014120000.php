<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Migration;

use Closure;
use OCP\Migration\IOutput;

/**
 * The names of the folders and the fallbacks for empty metadata follow the default language of
 * Nextcloud from this version on. Every earlier version proposed them in German, whatever the
 * language, so an installation that is in use keeps its German names here: it stores each one
 * that it never saved, such as one set up with occ. Without that, the archive of an English
 * instance would start again in Documents/Paperless/Archive after the update.
 *
 * A migration runs once for each installation, unlike a repair step, which runs with every
 * update: a later update must not give German names to an installation that started with
 * English ones. A new installation runs it too, with nothing set up yet, and keeps nothing.
 */
final class Version000130Date20261014120000 extends KeepNamesStep {
	/** The names that every earlier version proposed. */
	private const GERMAN_NAMES = [
		'base_path' => 'Dokumente/Paperless',
		'archive_folder' => 'Archiv',
		'inbox_folder' => 'Eingang',
		'error_folder' => 'Fehler',
		'deleted_folder' => '_Gelöscht',
		'empty_correspondent' => '_Ohne Korrespondent',
		'empty_document_type' => '_Ohne Dokumenttyp',
		'empty_date' => '_Ohne Datum',
		'untitled' => 'Ohne Titel',
	];

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->inUse()) {
			return;
		}
		$kept = $this->configService->keepNames(self::GERMAN_NAMES);
		if ($kept > 0) {
			$output->info("Kept {$kept} German names of folders of Paperless Sync, which earlier versions proposed in every language.");
		}
	}
}
