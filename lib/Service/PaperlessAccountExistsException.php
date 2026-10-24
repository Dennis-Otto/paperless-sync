<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use RuntimeException;

/**
 * The user that the setup of the account would create exists in Paperless already: the setup
 * changes it only when the administrator asks for that, because it replaces its permissions and
 * its password.
 */
final class PaperlessAccountExistsException extends RuntimeException {
	public function __construct(
		public readonly string $username,
	) {
		parent::__construct("The user {$username} exists in Paperless already.");
	}
}
