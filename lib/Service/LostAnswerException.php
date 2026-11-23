<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use RuntimeException;

/**
 * In per-user ownership, an earlier upload of the same content got no answer from Paperless,
 * which may still create its document: the file waits, neither uploaded nor deleted, for as long
 * as the record of that upload holds, UploadRecords::LOST_ANSWER after its last attempt. A second
 * upload in that time could create a second document, or fail as a duplicate of the first, and
 * leave the first with the account of the app.
 */
final class LostAnswerException extends RuntimeException {
	public function __construct() {
		parent::__construct('An earlier upload of this content got no answer from Paperless, which may still create its document, so the file waits up to a day before it goes up again');
	}
}
