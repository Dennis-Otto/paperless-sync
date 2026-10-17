<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;

/**
 * The webhook of the app with Nextcloud's credentials store in $webhookCredentials, by the
 * identifier of each credential, and a random generator that gives the secrets of $secrets one
 * after another. It keeps its last call in the app configuration of InMemoryConfiguration.
 */
trait InMemoryWebhook {
	/** @var array<string, mixed> what the credentials store holds, by its identifier, as it gives it back: decoded from JSON */
	private array $webhookCredentials = [];
	/** @var list<string> the secrets that the next creations get */
	private array $secrets = [self::SECRET, self::OTHER_SECRET];

	/** The secrets of the tests, 48 letters as the app makes them, but plainly no real ones. */
	private const SECRET = 'SecretSecretSecretSecretSecretSecretSecretSecret';
	private const OTHER_SECRET = 'NewSecretNewSecretNewSecretNewSecretNewSecretNew';
	/** The address of the webhook, as Nextcloud links to its route. */
	private const WEBHOOK_URL = 'https://cloud.example.test/index.php/apps/paperless_sync/api/v1/webhook';

	private function webhookService(ITimeFactory $clock): WebhookService {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturnCallback(fn (string $userId, string $identifier): mixed => $this->webhookCredentials[$identifier] ?? null);
		$credentials->method('store')->willReturnCallback(function (string $userId, string $identifier, mixed $value): void {
			/** @psalm-suppress MixedAssignment */
			$this->webhookCredentials[$identifier] = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		});
		$credentials->method('delete')->willReturnCallback(function (string $userId, string $identifier): int {
			$deleted = isset($this->webhookCredentials[$identifier]) ? 1 : 0;
			unset($this->webhookCredentials[$identifier]);

			return $deleted;
		});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(function (int $length, string $characters): string {
			self::assertSame([WebhookService::SECRET_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC], [$length, $characters]);
			$secret = array_shift($this->secrets);
			self::assertIsString($secret, 'The test creates more secrets than it has.');

			return $secret;
		});
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route): string => $route === WebhookService::ROUTE ? self::WEBHOOK_URL : "https://cloud.example.test/{$route}");

		return new WebhookService($credentials, $random, $clock, $this->appConfig(), $urlGenerator);
	}
}
