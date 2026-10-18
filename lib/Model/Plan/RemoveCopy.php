<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

use OCA\PaperlessSync\Model\SyncReport;

/**
 * The removal of the copy of a document with an excluded tag, or the deletion of the copy of a
 * document that Paperless deleted; the state of the document is forgotten with it.
 */
final class RemoveCopy implements ExportAction {
	/** @param SyncReport::TYPE_REMOVE_EXCLUDED|SyncReport::TYPE_DELETE $type */
	public function __construct(
		public readonly DocumentContext $context,
		public readonly string $path,
		public readonly string $type,
	) {
	}
}
