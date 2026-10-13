<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use RuntimeException;

/**
 * A request to Paperless failed before it had an answer, because Paperless could not be reached
 * for example. It carries only the message of the failure and none of the exception of the HTTP
 * client, because the trace of that one holds the options of the request with the API token, and
 * Nextcloud's log would write them. When Nextcloud refused the address of Paperless as one of the
 * local network, it is a PaperlessLocalNetworkException.
 */
class PaperlessRequestException extends RuntimeException {
}
