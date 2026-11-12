<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncConfigTest extends TestCase {
	/**
	 * The settings as stored, such as by occ, which saves a value without the page: with spaces
	 * and a slash at the end.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function addresses(): array {
		return [
			'the address in the browser' => ['http://paperless:8000', 'https://paperless.example.com', 'https://paperless.example.com'],
			'the address in the browser, as occ stores it' => ['http://paperless:8000', " https://paperless.example.com/ \n", 'https://paperless.example.com'],
			'the Paperless URL without an address in the browser' => ['https://paperless.example.test', '', 'https://paperless.example.test'],
			'the Paperless URL, as occ stores it, for an address in the browser of spaces' => [' https://paperless.example.test/ ', '  ', 'https://paperless.example.test'],
			'none without either' => ['', '', ''],
		];
	}

	/** The links to Paperless take the address in the browser, or else the Paperless URL (#446). */
	#[DataProvider('addresses')]
	public function testTheLinksTakeTheAddressInTheBrowserOrElseThePaperlessUrl(string $paperlessUrl, string $publicUrl, string $link): void {
		$config = TestConfig::of(['paperlessUrl' => $paperlessUrl, 'paperlessPublicUrl' => $publicUrl]);

		self::assertSame($link, $config->linkUrl());
		self::assertSame($paperlessUrl, $config->paperlessUrl, 'The requests keep the Paperless URL.');
	}

	/** A copy for another batch size, path template or user keeps the address in the browser. */
	public function testACopyKeepsTheAddressInTheBrowser(): void {
		$config = TestConfig::of(['paperlessPublicUrl' => 'https://paperless.example.com']);

		foreach ([$config->withBatchSize(1), $config->withPathTemplate('{{ id }}'), $config->withTargetUser('alice')] as $copy) {
			self::assertSame('https://paperless.example.com', $copy->linkUrl());
		}
	}
}
