<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\SetupChecks;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackgroundJobModeTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private BackgroundJobMode $check;

	protected function setUp(): void {
		$this->check = new BackgroundJobMode($this->appConfig(), $this->configService(), $this->germanL10n());
		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
	}

	public function testTheCheckBelongsToTheSystem(): void {
		self::assertSame('system', $this->check->getCategory());
		self::assertSame('Hintergrundjob von Paperless Sync', $this->check->getName());
	}

	public function testCronIsWhatScheduledRunsNeed(): void {
		$this->settings['backgroundjobs_mode'] = 'cron';

		$result = $this->check->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
		self::assertSame('Nextcloud führt seine Hintergrundjobs mit Cron aus, wie es die geplanten Läufe von Paperless Sync brauchen.', $result->getDescription());
	}

	/** @return array<string, array{string|null, string}> */
	public static function modes(): array {
		return [
			'AJAX, the default of a new Nextcloud' => [null, 'AJAX'],
			'AJAX' => ['ajax', 'AJAX'],
			'Webcron' => ['webcron', 'Webcron'],
		];
	}

	#[DataProvider('modes')]
	public function testAnotherModeWarnsWhileScheduledRunsAreOn(?string $mode, string $name): void {
		if ($mode !== null) {
			$this->settings['backgroundjobs_mode'] = $mode;
		}

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertStringStartsWith("Nextcloud führt seine Hintergrundjobs mit {$name} aus, das sie nur startet, während jemand Nextcloud benutzt", (string)$result->getDescription());
		self::assertSame(AppConstants::GUIDE_URL . '#being-notified', $result->getLinkToDoc());
	}

	public function testScheduledRunsThatAreOffNeedNoCron(): void {
		$this->configService()->save(['enabled' => false] + self::validSettings(), 'secret-token');

		$result = $this->check->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
		self::assertSame('Die geplanten Läufe von Paperless Sync sind ausgeschaltet.', $result->getDescription());
	}
}
