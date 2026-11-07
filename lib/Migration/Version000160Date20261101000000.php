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
 * The earlier versions proposed English names in every language but German, so the same holds
 * for a language whose catalog a later version brings, such as Chinese: an update from an earlier
 * version straight to that one runs this step with the later catalogs.
 *
 * A German installation in use stores the German names that it has. From here on a run stores
 * its names itself, so that the catalog of a further language needs no such step of its own.
 */
final class Version000160Date20261101000000 extends KeepNamesStep {
	/** The one language besides English whose catalog the earlier versions had. */
	private const EARLIER_CATALOG = 'de';

	/** The names that every earlier version proposed in every language but German. */
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
		$kept = $this->configService->defaultLanguage() === self::EARLIER_CATALOG
			? $this->configService->keepDefaultNames()
			: $this->configService->keepNames(self::ENGLISH_NAMES);
		if ($kept > 0) {
			$output->info("Kept {$kept} names of folders of Paperless Sync, so that the languages of this version move no archive.");
		}
	}
}
