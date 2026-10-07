<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\SettingsController;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsControllerTest extends TestCase {
	use InMemoryConfiguration;

	/** @var PaperlessClientInterface&MockObject */
	private PaperlessClientInterface $paperless;
	/** @var NextcloudStorageInterface&MockObject */
	private NextcloudStorageInterface $storage;
	private SettingsController $controller;

	protected function setUp(): void {
		$this->paperless = $this->createMock(PaperlessClientInterface::class);
		$this->storage = $this->createMock(NextcloudStorageInterface::class);
		$this->controller = new SettingsController(
			AppConstants::APP_ID,
			$this->createMock(IRequest::class),
			$this->configService(),
			$this->paperless,
			$this->storage,
		);
	}

	public function testSavesSettingsThatWorkWithPaperlessAndNextcloud(): void {
		$this->paperless->expects(self::once())->method('testConnection')->with('https://paperless.example.test', 'secret-token');
		$this->storage->expects(self::once())->method('test')->with('paperless', 'Dokumente/Paperless');

		$response = $this->controller->save(self::validSettings() + ['batch_size' => 20], 'secret-token');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$config = $response->getData();
		self::assertInstanceOf(SyncConfig::class, $config);
		self::assertSame(20, $config->batchSize);
		self::assertTrue($config->tokenConfigured);
		self::assertSame(20, $this->settings['batch_size']);
		self::assertSame('secret-token', $this->token);
	}

	public function testInvalidSettingsAreRejectedBeforeAnyConnection(): void {
		$this->paperless->expects(self::never())->method('testConnection');

		$response = $this->controller->save(['paperless_url' => 'ftp://paperless.example.test'] + self::validSettings(), 'secret-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'The Paperless URL must use HTTP or HTTPS.'], $response->getData());
		self::assertSame([], $this->settings);
	}

	public function testSettingsThatPaperlessRejectsAreNotSaved(): void {
		$this->paperless->method('testConnection')->willThrowException(new RuntimeException('Paperless could not query /api/documents/: HTTP 401.'));

		$response = $this->controller->save(self::validSettings(), 'wrong-token');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['message' => 'Could not validate the Paperless and Nextcloud configuration: Paperless could not query /api/documents/: HTTP 401.'],
			$response->getData(),
		);
		self::assertSame([], $this->settings);
		self::assertSame('', $this->token);
	}

	public function testResetReturnsTheDefaults(): void {
		$this->controller->save(self::validSettings(), 'secret-token');

		$response = $this->controller->reset();

		$config = $response->getData();
		self::assertInstanceOf(SyncConfig::class, $config);
		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
		self::assertSame([], $this->settings);
	}
}
