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
 * Paperless, or a proxy in front of it, answered a request with a redirect, such as from http://
 * to https:// or from an old name to a new one. The app follows none: Nextcloud's HTTP client
 * would drop the token on the way to another address, so that Paperless refused it as an unknown
 * token, and it would turn an upload into a GET without its file (#458). The URL of Paperless in
 * the settings has to be the address that answers, which the message names when the redirect
 * leads to the same path of the API under another address.
 */
final class PaperlessRedirectException extends PaperlessRequestException {
	/** The setting that the error names as the way out. */
	public const SETTING = 'paperless_url';

	/**
	 * @param int $status the status of the redirect, such as 301 or 308
	 * @param string $location the address that the redirect leads to, without its query
	 * @param string $url the URL of Paperless that the address means, or '' when it leads elsewhere, such as to the sign-in of a proxy
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $location,
		public readonly string $url,
	) {
		$error = $url !== ''
			? new RunError('Paperless, or a proxy in front of it, redirects the requests of Paperless Sync to %1$s (HTTP %2$s). Enter %3$s as the URL of Paperless in the settings of Paperless Sync.', [$location, (string)$status, $url], self::SETTING)
			: new RunError('Paperless, or a proxy in front of it, redirects the requests of Paperless Sync to %1$s (HTTP %2$s). Enter the URL under which Paperless answers without a redirect: a proxy that asks for a sign-in has to let the API of Paperless through.', [$location, (string)$status], self::SETTING);
		parent::__construct($error->message(), $error);
	}

	/** The line of the connection checklist, which blocks a save: no request of a run would reach Paperless. */
	public function check(): ConnectionCheck {
		return new ConnectionCheck(
			ConnectionCheck::PAPERLESS,
			ConnectionCheck::FAILED,
			'Paperless, or a proxy in front of it, redirects to %1$s (HTTP %2$s).',
			[$this->location, (string)$this->status],
			$this->url !== ''
				? 'Enter %s as the URL of Paperless. Paperless Sync follows no redirect: it would send the token to another address, or turn an upload into a request without its file.'
				: 'Enter the URL under which Paperless answers without a redirect: a proxy that asks for a sign-in has to let the API of Paperless through.',
			$this->url !== '' ? [$this->url] : [],
			true,
			self::SETTING,
		);
	}
}
