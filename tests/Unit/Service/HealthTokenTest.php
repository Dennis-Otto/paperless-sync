<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\HealthToken;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\InMemoryWebhook;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthTokenTest extends TestCase {
	use InMemoryConfiguration;
	use InMemoryWebhook;

	private TestClock $clock;
	private HealthToken $token;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->token = $this->healthToken($this->clock);
	}

	public function testWithoutATokenTheHealthCheckIsOffAndRefusesEveryCall(): void {
		self::assertSame(['configured' => false, 'created' => 0, 'url' => self::HEALTH_URL], $this->token->state());
		self::assertFalse($this->token->verify('Bearer ' . self::SECRET));
		self::assertFalse($this->token->verify(''));
	}

	/** The store keeps the SHA-256 of the token alone, beside the secret of the webhook, so that no one reads it afterwards. */
	public function testTheTokenIsShownOnceAndStoredAsItsHashAlone(): void {
		$token = $this->token->create();

		self::assertSame(self::SECRET, $token);
		self::assertSame(['paperless_sync.health-token' => ['hash' => hash('sha256', self::SECRET), 'created' => TestClock::START]], $this->webhookCredentials);
		self::assertStringNotContainsString(self::SECRET, json_encode([$this->webhookCredentials, $this->settings, $this->token->state()], JSON_THROW_ON_ERROR));
		self::assertSame(['configured' => true, 'created' => TestClock::START, 'url' => self::HEALTH_URL], $this->token->state());
	}

	/** @return array<string, array{string, bool}> the header Authorization of a call, and whether it opens the health check */
	public static function authorizations(): array {
		return [
			'the token' => ['Bearer ' . self::SECRET, true],
			'the scheme in any case, and spaces around' => ['bearer  ' . self::SECRET . ' ', true],
			'another token' => ['Bearer ' . self::OTHER_SECRET, false],
			'the token cut short' => ['Bearer ' . substr(self::SECRET, 0, -1), false],
			'the token alone' => [self::SECRET, false],
			'basic authentication' => ['Basic ' . base64_encode('admin:' . self::SECRET), false],
			'nothing' => ['', false],
		];
	}

	#[DataProvider('authorizations')]
	public function testOnlyTheTokenAsBearerOpensTheHealthCheck(string $authorization, bool $opens): void {
		$this->token->create();

		self::assertSame($opens, $this->token->verify($authorization));
	}

	/** A new token replaces the one before at once. */
	public function testANewTokenReplacesTheOneBefore(): void {
		$this->token->create();
		$this->clock->advance(60);

		self::assertSame(self::OTHER_SECRET, $this->token->create());
		self::assertFalse($this->token->verify('Bearer ' . self::SECRET));
		self::assertTrue($this->token->verify('Bearer ' . self::OTHER_SECRET));
		self::assertSame(TestClock::START + 60, $this->token->state()['created']);
	}

	/** Revoking the token leaves the secret of the webhook as it is. */
	public function testRevokingTheTokenLeavesTheWebhookAlone(): void {
		$webhook = $this->webhookService($this->clock);
		$webhook->create();
		$this->token->create();

		$this->token->remove();

		self::assertSame(['paperless_sync.webhook-secret'], array_keys($this->webhookCredentials));
		self::assertFalse($this->token->verify('Bearer ' . self::OTHER_SECRET));
		self::assertFalse($this->token->state()['configured']);
		self::assertTrue($webhook->verify('Bearer ' . self::SECRET));
	}

	/**
	 * After a restore or a move of Nextcloud with another secret in config.php, the credentials store
	 * can't decrypt the token: the health check counts as off, and the log names the token.
	 */
	public function testATokenThatCanNotBeDecryptedIsNone(): void {
		$this->token->create();
		$this->webhookSecretUnreadable = true;

		self::assertSame(['configured' => false, 'created' => 0, 'url' => self::HEALTH_URL], $this->token->state());
		self::assertFalse($this->token->verify('Bearer ' . self::SECRET));
		self::assertContains('Paperless Sync could not read the stored token of its health check, as after a restore or a move of Nextcloud with another secret (HMAC does not match.). Create a new one in its settings.', $this->loggedWarnings);

		$token = $this->token->create();

		self::assertTrue($this->token->verify('Bearer ' . $token));
	}
}
