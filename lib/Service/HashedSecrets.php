<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use Exception;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The secrets that open a route of the app without a session of Nextcloud: the secret of the
 * webhook, which Paperless sends, and the token of the health check, which a monitor sends, each in
 * the header Authorization as "Bearer <secret>".
 *
 * A secret is random, 48 letters and digits, and Nextcloud's credentials store, which encrypts it,
 * keeps only its SHA-256 and when it was created: the settings page shows a secret once, when it is
 * created, and no one can read it afterwards, neither from the page nor from the database. A new
 * secret replaces the one before at once.
 */
final class HashedSecrets {
	/** The secret of the webhook of Paperless. */
	public const WEBHOOK = AppConstants::APP_ID . '.webhook-secret';
	/** The token of the health check of a monitor. */
	public const HEALTH = AppConstants::APP_ID . '.health-token';
	/** The length of a secret: 48 letters and digits, about 285 bits. */
	public const LENGTH = 48;
	/** What the log calls each secret that it can't read. */
	private const NAMES = [
		self::WEBHOOK => 'secret of its webhook',
		self::HEALTH => 'token of its health check',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ICredentialsManager $credentialsManager,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A new secret in place of the one before, which stops working at once. It is returned here
	 * alone, for the settings page to show once.
	 */
	public function create(string $identifier): string {
		$secret = $this->random->generate(self::LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->credentialsManager->store('', $identifier, [
			'hash' => hash('sha256', $secret),
			'created' => $this->timeFactory->getTime(),
		]);

		return $secret;
	}

	/** Deletes the secret: no call opens its route until a new one is created. */
	public function remove(string $identifier): void {
		$this->credentialsManager->delete('', $identifier);
	}

	/**
	 * Whether the header Authorization of a call carries the secret, as "Bearer <secret>". The
	 * comparison takes the same time for every wrong secret, so that its time tells nothing.
	 */
	public function verify(string $identifier, #[\SensitiveParameter] string $authorization): bool {
		$stored = $this->stored($identifier);
		if ($stored === null || preg_match('/^Bearer\s+(\S+)\s*$/i', $authorization, $match) !== 1) {
			return false;
		}

		return hash_equals($stored['hash'], hash('sha256', $match[1]));
	}

	/** When the secret was created, 0 for a store that doesn't say, and null without a secret. */
	public function created(string $identifier): ?int {
		return $this->stored($identifier)['created'] ?? null;
	}

	/**
	 * The hash of the secret and when it was created, null without one. A secret that the store can't
	 * decrypt, as after a restore or a move of Nextcloud with another secret in config.php, counts as
	 * none: the settings page offers to create one, and a call with it is refused.
	 *
	 * @return array{hash: string, created: int}|null
	 */
	private function stored(string $identifier): ?array {
		try {
			/** @psalm-suppress MixedAssignment */
			$stored = $this->credentialsManager->retrieve('', $identifier);
		} catch (DatabaseException $exception) {
			// A failure of the database is no secret that can't be decrypted: a new one wouldn't help.
			throw $exception;
		} catch (Exception $exception) {
			$this->logger->warning('Paperless Sync could not read the stored {secret}, as after a restore or a move of Nextcloud with another secret ({error}). Create a new one in its settings.', [
				'app' => AppConstants::APP_ID,
				'secret' => self::NAMES[$identifier] ?? $identifier,
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);

			return null;
		}
		if (!is_array($stored)) {
			return null;
		}
		$hash = $stored['hash'] ?? null;
		if (!is_string($hash) || strlen($hash) !== 64) {
			return null;
		}

		return ['hash' => $hash, 'created' => isset($stored['created']) && is_int($stored['created']) ? $stored['created'] : 0];
	}
}
