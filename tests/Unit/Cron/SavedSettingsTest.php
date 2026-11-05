<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Cron;

use OCA\PaperlessSync\Cron\SavedSettings;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * What a background job of the app forgets as it starts, so that a worker that keeps running reads
 * the settings saved since its last job (#323).
 */
final class SavedSettingsTest extends TestCase {
	/**
	 * The settings of the app, and those of the users, such as the time zone that names the folders
	 * of a year; without reading them again at once, so that the next value that the job asks for
	 * comes from the database.
	 */
	public function testReloadForgetsTheSettingsOfTheAppAndOfTheUsers(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())->method('clearCache')->with(false);
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->expects(self::once())->method('clearCacheAll');

		(new SavedSettings($appConfig, $userConfig))->reload();
	}
}
