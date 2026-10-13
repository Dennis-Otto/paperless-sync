<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000111Date20261013000000;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class Version000111Date20261013000000Test extends TestCase {
	use InMemoryConfiguration;

	public function testATokenOfAnEarlierVersionIsBoundToTheConfiguredUrl(): void {
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'target_user' => 'paperless'];
		$this->credential = 'secret-token';
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Bound the stored Paperless API token to its Paperless URL.');

		$this->migrate($output);

		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
		self::assertSame('secret-token', $this->configService()->getToken());
		self::assertTrue($this->configService()->get()->tokenConfigured);
	}

	public function testABoundTokenIsKept(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$stored = $this->credential;
		$this->settings['paperless_url'] = 'https://attacker.example.test';
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');

		$this->migrate($output);

		self::assertSame($stored, $this->credential);
		self::assertSame('', $this->configService()->getToken(), 'The token must not follow a URL that was changed elsewhere.');
	}

	public function testNothingIsStoredWithoutAToken(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');

		$this->migrate($output);

		self::assertNull($this->credential);
	}

	private function migrate(IOutput $output): void {
		(new Version000111Date20261013000000($this->configService()))->postSchemaChange($output, fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);
	}
}
