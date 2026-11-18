<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\RunError;

/**
 * Paperless refused the host name under which Nextcloud reaches it (#532). Django answers a host
 * name that PAPERLESS_ALLOWED_HOSTS leaves out with HTTP 400 and its plain page "Bad Request
 * (400)", before Paperless sees the request, so the answer has no header X-Version and no JSON.
 * Paperless-ngx 3.5 and newer allow by default only the host name of PAPERLESS_URL and localhost
 * once PAPERLESS_URL is set, so that the usual Docker setup, with http://paperless:8000 as the URL
 * of Paperless and the public address in PAPERLESS_URL, loses the connection with the update.
 *
 * Every request to Paperless gets the same answer, also every upload, so no file counts as one
 * that Paperless refused for good. The words name no host name: the status, the report and the
 * notifications, which people share when they ask for help, leave it to the settings page and to
 * the log of Paperless, which names the host name that it refused.
 */
final class PaperlessHostException extends PaperlessRequestException {
	/** The setting that the error names as a way out. */
	public const SETTING = 'paperless_url';

	public function __construct() {
		$error = new RunError('Paperless does not accept the host name of its URL (HTTP 400). Add it to PAPERLESS_ALLOWED_HOSTS of Paperless and restart it, or use the host name of PAPERLESS_URL. The log of Paperless names it.', [], self::SETTING);
		parent::__construct($error->message(), $error);
	}

	/** The line of the connection checklist, which blocks a save: no request of a run would reach Paperless. */
	public function check(): ConnectionCheck {
		return new ConnectionCheck(
			ConnectionCheck::PAPERLESS,
			ConnectionCheck::FAILED,
			'Paperless does not accept the host name of its URL (HTTP 400).',
			[],
			'Add the host name of the Paperless URL to PAPERLESS_ALLOWED_HOSTS of Paperless and restart Paperless, or enter the URL with the host name of PAPERLESS_URL when the Nextcloud server reaches Paperless under it. The log of Paperless names the host name that it refused.',
			[],
			true,
			self::SETTING,
		);
	}
}
