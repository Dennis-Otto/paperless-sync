<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCP\IL10N;

/**
 * Nextcloud refused to connect to Paperless, because its address is one of the local network,
 * such as http://paperless:8000 or 192.168.1.20, and the setting "Paperless runs in my local
 * network" is off. Its message says what to do, in English for the log, and translated() in the
 * language of the administrator.
 */
final class PaperlessLocalNetworkException extends PaperlessRequestException {
	/** The advice, with the host for %s; translated() looks it up in the translations of the app. */
	private const ADVICE = 'Nextcloud blocks connections to local addresses such as %s. If Paperless runs in your network, turn on "Paperless runs in my local network".';

	/** The setting that allows the connection, which the settings page moves the focus to. */
	public const SETTING = 'paperless_local_network';

	/** @param string $host the address that Nextcloud refused, as the administrator knows it */
	public function __construct(
		public readonly string $host,
	) {
		parent::__construct(sprintf(self::ADVICE, $host));
	}

	public function translated(IL10N $l10n): string {
		return $l10n->t(self::ADVICE, [$this->host]);
	}
}
