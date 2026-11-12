<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\TranslatableError;
use RuntimeException;

/**
 * A request to Paperless failed before it had an answer, because Paperless could not be reached
 * for example, or Paperless refused it, with 401 for a token that it no longer takes. It carries
 * only the message of the failure and none of the exception of the HTTP client, because the trace
 * of that one holds the options of the request with the API token, and Nextcloud's log would write
 * them. When Nextcloud refused the address of Paperless as one of the local network, it is a
 * PaperlessLocalNetworkException.
 *
 * Its message is that of the HTTP client, which the log and the connection checklist show. The
 * status, the report and the notifications of a run show runError(): what happened and what to do,
 * in the words of the app and in the language of the reader, with that message as its detail, for
 * a failure that the app knows, and the message as it came for any other (#447).
 */
class PaperlessRequestException extends RuntimeException implements TranslatableError {
	/**
	 * @param string $message the message of the failure, without the token and the queries of its URLs
	 * @param ?RunError $error what happened and what to do, in the words of the app, or null for a failure that it can't explain
	 */
	public function __construct(
		string $message,
		private readonly ?RunError $error = null,
	) {
		parent::__construct($message);
	}

	public function runError(): RunError {
		if ($this->error !== null) {
			return $this->error;
		}
		$message = trim($this->getMessage());

		return RunError::plain($message !== '' ? $message : static::class);
	}
}
