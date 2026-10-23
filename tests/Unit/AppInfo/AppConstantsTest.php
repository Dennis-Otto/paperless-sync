<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\AppInfo;

use OCA\PaperlessSync\AppInfo\AppConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppConstantsTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function languages(): array {
		return [
			'German' => ['de', 'https://dennis-otto.github.io/paperless-sync/de/guide.html#being-notified'],
			'formal German' => ['de_DE', 'https://dennis-otto.github.io/paperless-sync/de/guide.html#being-notified'],
			'English' => ['en', 'https://dennis-otto.github.io/paperless-sync/guide.html#being-notified'],
			'British English' => ['en_GB', 'https://dennis-otto.github.io/paperless-sync/guide.html#being-notified'],
			'a language whose code only starts with the letters de' => ['dev', 'https://dennis-otto.github.io/paperless-sync/guide.html#being-notified'],
			'French, which the website has no translation of' => ['fr', 'https://dennis-otto.github.io/paperless-sync/guide.html#being-notified'],
			'no language' => ['', 'https://dennis-otto.github.io/paperless-sync/guide.html#being-notified'],
		];
	}

	/** A part of the guide opens in German for a reader of German, otherwise in English. */
	#[DataProvider('languages')]
	public function testTheGuideOpensInTheLanguageOfTheReader(string $language, string $expected): void {
		self::assertSame($expected, AppConstants::guide($language, 'being-notified'));
	}
}
