<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Activity;

use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\Activity\Filter;
use OCA\PaperlessSync\Activity\Setting;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** The line of the app in the activity settings and its entry in the navigation of the Activity app. */
final class SettingAndFilterTest extends TestCase {
	use GermanTranslations;

	public function testSettingShowsTheEntriesAndLeavesMailAndNotificationsToTheUser(): void {
		$setting = new Setting($this->germanL10n());

		self::assertSame(ActivityPublisher::TYPE, $setting->getIdentifier(), 'The setting governs the entries of the app.');
		self::assertSame('<strong>Paperless Sync</strong> hat das Dokumentenarchiv geändert oder eine Datei importiert', $setting->getName());
		self::assertSame('files', $setting->getGroupIdentifier());
		self::assertSame('Dateien', $setting->getGroupName());
		self::assertSame(70, $setting->getPriority());
		self::assertTrue($setting->isDefaultEnabledStream());
		self::assertTrue($setting->canChangeMail());
		self::assertFalse($setting->isDefaultEnabledMail(), 'No mail until the user asks for it.');
		self::assertTrue($setting->canChangeNotification());
		self::assertFalse($setting->isDefaultEnabledNotification());
	}

	public function testFilterShowsTheEntriesOfTheAppAlone(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.test' . $url);
		$filter = new Filter($this->germanL10n(), $urls);

		self::assertSame('paperless_sync', $filter->getIdentifier());
		self::assertSame('Paperless Sync', $filter->getName());
		self::assertSame(70, $filter->getPriority());
		self::assertSame('https://cloud.example.test/apps/paperless_sync/img/app-dark.svg', $filter->getIcon());
		self::assertSame([ActivityPublisher::TYPE], $filter->filterTypes(['file_created', ActivityPublisher::TYPE, 'shared']));
		self::assertSame([], $filter->filterTypes(['file_created']));
		self::assertSame(['paperless_sync'], $filter->allowedApps());
	}
}
