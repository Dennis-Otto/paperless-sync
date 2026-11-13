<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCP\IURLGenerator;

/**
 * The token of the health check, through which a monitor, such as Uptime Kuma or the RESTful sensor
 * of Home Assistant, reads the health of the mirror without an account of Nextcloud: it sends the
 * token in the header Authorization as "Bearer <token>", and the token opens that route alone.
 *
 * The token is one of HashedSecrets: random, kept as its SHA-256 alone, shown once when it is
 * created, and replaced at once by a new one.
 *
 * @psalm-type State = array{configured: bool, created: int, url: string}
 */
final class HealthToken {
	/** The route that a monitor calls. */
	public const ROUTE = AppConstants::APP_ID . '.health.check';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private HashedSecrets $secrets,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * A new token in place of the one before, which stops working at once. It is returned here
	 * alone, for the settings page to show once.
	 */
	public function create(): string {
		return $this->secrets->create(HashedSecrets::HEALTH);
	}

	/** Revokes the token: the health check refuses every call until a new token is created. */
	public function remove(): void {
		$this->secrets->remove(HashedSecrets::HEALTH);
	}

	/** Whether the header Authorization of a call carries the token, as "Bearer <token>". */
	public function verify(#[\SensitiveParameter] string $authorization): bool {
		return $this->secrets->verify(HashedSecrets::HEALTH, $authorization);
	}

	/**
	 * The health check as the settings page shows it: whether it has a token and since when, and the
	 * address that a monitor calls. Never the token.
	 *
	 * @return State
	 */
	public function state(): array {
		$created = $this->secrets->created(HashedSecrets::HEALTH);

		return [
			'configured' => $created !== null,
			'created' => $created ?? 0,
			'url' => $this->urlGenerator->linkToRouteAbsolute(self::ROUTE),
		];
	}
}
