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
 * The app speaks French and Spanish from this version on, so an instance in one of them proposes
 * French or Spanish names of its folders. An installation that is in use there keeps the English
 * names of the earlier versions: it stores each one that it never saved, such as one set up with
 * occ. Without that, the archive of a French instance would start again in Archives.
 *
 * Every other installation in use stores the names that it has too. From here on a run does that
 * itself, so that the catalog of a further language moves no archive and needs no such step.
 */
final class Version000160Date20261101000000 extends KeepNamesStep {
	/** The languages whose catalogs this version brings. */
	private const NEW_CATALOGS = ['es', 'fr'];

	/** The names that every earlier version proposed in these languages. */
	private const ENGLISH_NAMES = [
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

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->inUse()) {
			return;
		}
		$kept = in_array($this->configService->defaultLanguage(), self::NEW_CATALOGS, true)
			? $this->configService->keepNames(self::ENGLISH_NAMES)
			: $this->configService->keepDefaultNames();
		if ($kept > 0) {
			$output->info("Kept {$kept} names of folders of Paperless Sync, so that the languages of this version move no archive.");
		}
	}
}
