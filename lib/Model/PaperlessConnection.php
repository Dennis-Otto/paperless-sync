<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * The connection to one Paperless: its URL, the API token for it, and whether its address may be
 * one of the local network ("Paperless runs in my local network"). A run takes it once, at its
 * start, and keeps it to its end (PaperlessClientFactoryInterface::connect()), so that settings
 * saved during the run reach the next run, not the rest of this one.
 *
 * The token is no public property: Nextcloud's log writes the public properties of every object
 * in the trace of an exception, and var_dump() and print_r() show it hidden.
 */
final class PaperlessConnection {
	public function __construct(
		public readonly string $url,
		#[\SensitiveParameter]
		private readonly string $token,
		public readonly bool $localNetwork,
	) {
	}

	/** The connection that $config names, its URL and its local network, with $token. */
	public static function of(SyncConfig $config, #[\SensitiveParameter] string $token): self {
		return new self($config->paperlessUrl, $token, $config->paperlessLocalNetwork);
	}

	public function token(): string {
		return $this->token;
	}

	/** @return array{url: string, token: string, localNetwork: bool} */
	public function __debugInfo(): array {
		return ['url' => $this->url, 'token' => $this->token === '' ? '' : '[redacted]', 'localNetwork' => $this->localNetwork];
	}
}
