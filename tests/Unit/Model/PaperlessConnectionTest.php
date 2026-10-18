<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use PHPUnit\Framework\TestCase;

final class PaperlessConnectionTest extends TestCase {
	use InMemoryConfiguration;

	public function testTheConnectionOfSettingsHasTheirUrlAndLocalNetwork(): void {
		$config = $this->configService()->validate(['paperless_url' => 'http://paperless:8000', 'paperless_local_network' => true] + self::validSettings(), 'secret-token');

		$connection = PaperlessConnection::of($config, 'secret-token');

		self::assertSame(['http://paperless:8000', 'secret-token', true], [$connection->url, $connection->token(), $connection->localNetwork]);
	}

	/**
	 * Nextcloud's log writes the public properties of every object in the trace of an exception,
	 * as get_object_vars() outside the class sees them, and a debugger dump shows __debugInfo():
	 * neither shows the token.
	 */
	public function testTheTokenIsInNoPublicPropertyAndNoDump(): void {
		$connection = new PaperlessConnection('https://paperless.example.test', 'secret-token', false);

		self::assertSame(['url' => 'https://paperless.example.test', 'localNetwork' => false], get_object_vars($connection));
		self::assertSame('{"url":"https:\/\/paperless.example.test","localNetwork":false}', json_encode($connection));
		self::assertStringNotContainsString('secret-token', print_r($connection, true));
		self::assertStringContainsString('[redacted]', print_r($connection, true));
		self::assertSame(['url' => '', 'token' => '', 'localNetwork' => false], (new PaperlessConnection('', '', false))->__debugInfo(), 'A dump tells a missing token from a hidden one.');
	}
}
