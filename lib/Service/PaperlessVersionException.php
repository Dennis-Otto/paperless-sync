<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\RunError;

/**
 * Paperless refused the version of its API that every request of the app asks for, with
 * 406 Not Acceptable: a Paperless-ngx before 2.16, which doesn't know version 9 yet, or a future
 * one that no longer serves it. Every other request would fail the same way, so the message says
 * which Paperless the app needs, in the language of the reader of the status of a run.
 */
final class PaperlessVersionException extends PaperlessRequestException {
	public function __construct() {
		$error = new RunError(
			'Paperless does not serve version %1$s of its API, which Paperless Sync uses (HTTP 406 Not Acceptable). Paperless Sync needs Paperless-ngx %2$s or newer; with a newer Paperless-ngx, update Paperless Sync.',
			[PaperlessApiService::API_VERSION, PaperlessApiService::MINIMUM_VERSION],
		);
		parent::__construct($error->message(), $error);
	}
}
