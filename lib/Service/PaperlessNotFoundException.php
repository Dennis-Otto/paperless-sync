<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

/**
 * Paperless answered a request with 404 Not Found: a document that it no longer has, or whose
 * file it doesn't have, such as an original that was missing at the migration of its checksums,
 * or a wrong address. KnownContent takes it as an answer: an original that Paperless can't
 * serve confirms nothing, so the file goes up and Paperless decides, instead of waiting for good.
 */
final class PaperlessNotFoundException extends PaperlessRequestException {
}
