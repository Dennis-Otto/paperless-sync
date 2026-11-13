<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\AppInfo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The icons of the app in img/. Nextcloud shows the icon of an app in its lists, the sections
 * of the settings, the list of apps, the activity and its filter and the notifications, as one
 * dark shape beside its own icons, and turns it white on the colour of the active entry and in
 * its dark theme: that is img/app-dark.svg. The colours of the logo, img/app.svg, stay on the
 * page of the settings, in the README and on the website. The operation of Flow is the other
 * way round: Nextcloud draws it on a coloured circle and turns it dark itself, so img/flow.svg
 * is white.
 */
final class IconsTest extends TestCase {
	private const IMG = __DIR__ . '/../../../img';
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The colours of the fills and strokes of an icon, outside its masks or inside them.
	 *
	 * @return list<string>
	 */
	private static function colors(string $icon, bool $inMasks): array {
		$document = new DOMDocument();
		self::assertTrue($document->load(self::IMG . "/{$icon}"), "img/{$icon} is no XML.");
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');
		$where = $inMasks ? 'ancestor::svg:mask' : 'not(ancestor-or-self::svg:mask)';
		$colors = [];
		foreach ($xpath->query("//svg:*[{$where}]") ?: [] as $element) {
			self::assertInstanceOf(DOMElement::class, $element);
			self::assertFalse($element->hasAttribute('style'), "img/{$icon} sets a colour by style, which this test can't see; use fill and stroke.");
			foreach (['fill', 'stroke'] as $paint) {
				$color = strtolower($element->getAttribute($paint));
				if ($color !== '' && $color !== 'none' && !str_starts_with($color, 'url(')) {
					$colors[] = $color;
				}
			}
		}

		return array_values(array_unique($colors));
	}

	/** The relative luminance of a colour #rgb or #rrggbb of WCAG, from 0 for black to 1 for white. */
	private static function luminance(string $color): float {
		self::assertMatchesRegularExpression('/^#([0-9a-f]{3}){1,2}$/', $color, "{$color} is no colour #rgb or #rrggbb.");
		$hex = strlen($color) === 4 ? $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3] : substr($color, 1);
		$channels = array_map(static function (string $pair): float {
			$value = hexdec($pair) / 255;
			return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
		}, str_split($hex, 2));

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	public function testTheIconOfTheListsIsOneDarkShape(): void {
		$colors = self::colors('app-dark.svg', false);

		self::assertCount(1, $colors, 'img/app-dark.svg paints in more than one colour: ' . implode(', ', $colors));
		self::assertLessThan(0.05, self::luminance($colors[0]), "img/app-dark.svg is {$colors[0]}, which is not dark enough for the icons of Nextcloud on a light background.");
		self::assertSame([], array_values(array_diff(self::colors('app-dark.svg', true), ['#fff', '#ffffff', '#000', '#000000'])), 'A mask of img/app-dark.svg uses a colour other than black and white.');
	}

	public function testTheLogoKeepsItsColours(): void {
		self::assertGreaterThan(1, count(array_diff(self::colors('app.svg', false), ['#fff', '#ffffff'])), 'img/app.svg is the coloured logo of the README, the website and the page of the settings.');
		self::assertNotSame(file_get_contents(self::IMG . '/app.svg'), file_get_contents(self::IMG . '/app-dark.svg'));
	}

	public function testTheIconOfFlowIsWhite(): void {
		self::assertSame(['#fff'], self::colors('flow.svg', false), 'Nextcloud draws the icon of the operation of Flow on a coloured circle and turns it dark itself.');
	}

	/** Every icon that the app hands Nextcloud from its code is the one-coloured one. */
	public function testTheCodeHandsNextcloudNoColouredIcon(): void {
		$coloured = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB, FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			self::assertInstanceOf(SplFileInfo::class, $file);
			if ($file->getExtension() === 'php' && preg_match("/imagePath\\([^)]*'app\\.svg'\\)/", (string)file_get_contents($file->getPathname())) === 1) {
				$coloured[] = substr($file->getPathname(), strlen(self::LIB) + 1);
			}
		}

		self::assertSame([], $coloured, 'These files hand Nextcloud the coloured logo, img/app.svg, where it expects img/app-dark.svg.');
	}
}
