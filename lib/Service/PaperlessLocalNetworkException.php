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
 * such as http://paperless:8000 or 192.168.1.20, or because it found no DNS record for its name,
 * and the setting "Paperless runs in my local network" is off. Its message says what to do, in
 * English for the log, and translated() in the language of the administrator.
 */
final class PaperlessLocalNetworkException extends PaperlessRequestException {
	/** The advice, with the host for %s; translated() looks it up in the translations of the app. */
	private const ADVICE = 'Nextcloud blocks connections to local addresses such as %s. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.';
	/**
	 * The advice for a name without a DNS record. Without the setting, Nextcloud looks a name up
	 * only in the DNS, not in the hosts file of the server, such as one of Docker's extra_hosts.
	 */
	private const ADVICE_WITHOUT_DNS_RECORD = 'Nextcloud found no DNS record for %s. If the name is set only in the hosts file of the server, or Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.';

	/** The setting that allows the connection, which the settings page moves the focus to. */
	public const SETTING = 'paperless_local_network';

	/**
	 * @param string $host the address that Nextcloud refused, as the administrator knows it
	 * @param bool $withoutDnsRecord whether Nextcloud found no DNS record for it
	 */
	public function __construct(
		public readonly string $host,
		public readonly bool $withoutDnsRecord = false,
	) {
		parent::__construct(sprintf($this->advice(), $host));
	}

	public function translated(IL10N $l10n): string {
		return $l10n->t($this->advice(), [$this->host]);
	}

	private function advice(): string {
		return $this->withoutDnsRecord ? self::ADVICE_WITHOUT_DNS_RECORD : self::ADVICE;
	}
}
