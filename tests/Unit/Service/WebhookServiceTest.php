<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\WebhookService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\InMemoryWebhook;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase {
	use InMemoryConfiguration;
	use InMemoryWebhook;

	private TestClock $clock;
	private WebhookService $webhook;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->webhook = $this->webhookService($this->clock);
	}

	public function testWithoutASecretTheWebhookIsOffAndRefusesEveryCall(): void {
		self::assertSame(['configured' => false, 'created' => 0, 'url' => self::WEBHOOK_URL, 'lastCall' => null], $this->webhook->state());
		self::assertFalse($this->webhook->verify('Bearer ' . self::SECRET));
		self::assertFalse($this->webhook->verify(''));
	}

	/** The store keeps the SHA-256 of the secret alone, so that no one reads the secret afterwards. */
	public function testTheSecretIsShownOnceAndStoredAsItsHashAlone(): void {
		$secret = $this->webhook->create();

		self::assertSame(self::SECRET, $secret);
		self::assertSame(['paperless_sync.webhook-secret' => ['hash' => hash('sha256', self::SECRET), 'created' => TestClock::START]], $this->webhookCredentials);
		self::assertStringNotContainsString(self::SECRET, json_encode([$this->webhookCredentials, $this->settings, $this->webhook->state()], JSON_THROW_ON_ERROR));
		self::assertSame(['configured' => true, 'created' => TestClock::START, 'url' => self::WEBHOOK_URL, 'lastCall' => null], $this->webhook->state());
	}

	/** @return array<string, array{string, bool}> the header Authorization of a call, and whether it opens the webhook */
	public static function authorizations(): array {
		return [
			'the secret' => ['Bearer ' . self::SECRET, true],
			'the scheme in any case, and spaces around' => ['bearer  ' . self::SECRET . ' ', true],
			'another secret' => ['Bearer ' . self::OTHER_SECRET, false],
			'the secret cut short' => ['Bearer ' . substr(self::SECRET, 0, -1), false],
			'the secret and more' => ['Bearer ' . self::SECRET . 'x', false],
			'the secret alone' => [self::SECRET, false],
			'the token scheme of Paperless' => ['Token ' . self::SECRET, false],
			'basic authentication' => ['Basic ' . base64_encode('admin:' . self::SECRET), false],
			'the scheme without a secret' => ['Bearer ', false],
			'two words' => ['Bearer ' . self::SECRET . ' ' . self::SECRET, false],
			'nothing' => ['', false],
		];
	}

	#[DataProvider('authorizations')]
	public function testOnlyTheSecretAsBearerOpensTheWebhook(string $authorization, bool $opens): void {
		$this->webhook->create();

		self::assertSame($opens, $this->webhook->verify($authorization));
	}

	/** A new secret replaces the one before at once, and the last call, which belonged to it, is gone. */
	public function testANewSecretReplacesTheOneBefore(): void {
		$this->webhook->create();
		$this->webhook->called(123, 'moved');
		$this->clock->advance(60);

		self::assertSame(self::OTHER_SECRET, $this->webhook->create());

		self::assertFalse($this->webhook->verify('Bearer ' . self::SECRET));
		self::assertTrue($this->webhook->verify('Bearer ' . self::OTHER_SECRET));
		self::assertSame(TestClock::START + 60, $this->webhook->state()['created']);
		self::assertNull($this->webhook->state()['lastCall']);
	}

	public function testTurningTheWebhookOffDeletesItsSecretAndItsLastCall(): void {
		$this->webhook->create();
		$this->webhook->called(123, 'moved');

		$this->webhook->remove();

		self::assertSame([], $this->webhookCredentials);
		self::assertArrayNotHasKey('webhook_last_call', $this->settings);
		self::assertFalse($this->webhook->verify('Bearer ' . self::SECRET));
		self::assertFalse($this->webhook->state()['configured']);
	}

	/** The settings page shows what came of the last call, and reads it as a lazy value. */
	public function testTheLastCallIsKeptForTheSettingsPage(): void {
		$this->webhook->create();
		$this->clock->advance(30);

		$this->webhook->called(123, 'failed', str_repeat('ä', 1200));

		self::assertSame(['at' => TestClock::START + 30, 'document' => 123, 'result' => 'failed', 'message' => str_repeat('ä', 1000)], $this->webhook->state()['lastCall']);
		self::assertArrayHasKey('webhook_last_call', $this->lazyKeys);
	}

	/** @return array<string, array{mixed}> what the store or the configuration may hold besides what the app wrote */
	public static function damagedValues(): array {
		return [
			'no object' => ['"secret"'],
			'a time as text' => ['{"at":"now","document":123,"result":"moved","message":""}'],
			'no document' => ['{"at":1,"result":"moved","message":""}'],
			'a result as a number' => ['{"at":1,"document":123,"result":1,"message":""}'],
			'no message' => ['{"at":1,"document":123,"result":"moved"}'],
		];
	}

	#[DataProvider('damagedValues')]
	public function testADamagedLastCallIsNone(string $json): void {
		$this->webhook->create();
		$this->settings['webhook_last_call'] = $json;

		self::assertNull($this->webhook->state()['lastCall']);
	}

	public function testADamagedSecretOpensNothing(): void {
		$this->webhookCredentials['paperless_sync.webhook-secret'] = ['hash' => 'short', 'created' => TestClock::START];
		self::assertFalse($this->webhook->verify('Bearer short'));
		self::assertFalse($this->webhook->state()['configured']);

		$this->webhookCredentials['paperless_sync.webhook-secret'] = 'plain text';
		self::assertFalse($this->webhook->state()['configured']);

		// A store without the time of its creation still opens the webhook.
		$this->webhookCredentials['paperless_sync.webhook-secret'] = ['hash' => hash('sha256', self::SECRET)];
		self::assertTrue($this->webhook->verify('Bearer ' . self::SECRET));
		self::assertSame(0, $this->webhook->state()['created']);
	}

	/** @return array<string, array{array<string, int>, string}> the counts of the report of a run of one document, and its result */
	public static function reports(): array {
		return [
			'an error' => [['errors' => 1, 'unchanged' => 1], 'failed'],
			'an export' => [['exported' => 1], 'exported'],
			'a move' => [['moved' => 1], 'moved'],
			'an excluded copy removed' => [['removedExcluded' => 1, 'skipped' => 1], 'removed'],
			'the copy of another user now removed' => [['removedReassigned' => 1], 'reassigned'],
			'a copy whose tags changed' => [['tagged' => 1, 'unchanged' => 1], 'tagged'],
			'a current copy' => [['unchanged' => 1], 'unchanged'],
			'an excluded document' => [['skipped' => 1], 'excluded'],
			'no document' => [[], 'missing'],
		];
	}

	/** @param array<string, int> $counts */
	#[DataProvider('reports')]
	public function testTheResultOfARunNamesWhatItDid(array $counts, string $result): void {
		$report = new SyncReport(false);
		foreach ($counts as $count => $value) {
			$report->{$count} = $value;
		}

		self::assertSame($result, WebhookService::result($report));
	}
}
