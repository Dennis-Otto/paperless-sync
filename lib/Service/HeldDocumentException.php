<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use RuntimeException;

/**
 * In per-user ownership, Paperless has the content of a file as a document of the account of the
 * app while an upload of another user waits for its owner: the document may be that upload's, so
 * it counts for nobody, and the file waits, neither uploaded nor deleted, until the upload ends.
 */
final class HeldDocumentException extends RuntimeException {
	public function __construct(int $document) {
		parent::__construct("Paperless has the content of the file as the document {$document}, which may be that of an upload that waits for its owner, so the file waits until that upload ends");
	}
}
