<?php

declare(strict_types=1);

namespace OCA\PaperlessSync\Tests\Unit\AppInfo;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The screenshots of the App Store in appinfo/info.xml: each with its small thumbnail, which
 * tests/e2e/screenshots.mjs writes next to it and the list of the apps shows, both at the tag of
 * the version, which every release writes into both addresses.
 */
final class ScreenshotsTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';
	private const ADDRESS = 'https://raw.githubusercontent.com/Dennis-Otto/paperless-sync/v%s/screenshots/';
	/** The box that a thumbnail fits into: THUMBNAIL of tests/e2e/thumbnail.mjs. */
	private const THUMBNAIL_WIDTH = 640;
	private const THUMBNAIL_HEIGHT = 400;

	/** @return list<array{url: string, thumbnail: string}> */
	private static function screenshots(string $xml): array {
		$info = simplexml_load_string($xml);
		self::assertInstanceOf(SimpleXMLElement::class, $info);
		$screenshots = [];
		foreach ($info->screenshot as $screenshot) {
			$screenshots[] = ['url' => (string)$screenshot, 'thumbnail' => (string)$screenshot['small-thumbnail']];
		}

		return $screenshots;
	}

	private static function infoXml(): string {
		$xml = file_get_contents(self::ROOT . '/appinfo/info.xml');
		self::assertIsString($xml);

		return $xml;
	}

	/**
	 * What release-please makes of the file with its two updaters of release-please-config.json.
	 * The XML updater, for <version>, runs first and writes the whole file out again, which
	 * joins the lines of a start tag into one, as DOMDocument does here. Then the generic
	 * updater, in each line marked x-release-please-version, and in each line between
	 * x-release-please-start-version and x-release-please-end, writes the version in place of
	 * the first one of the line.
	 */
	private static function released(string $xml, string $version): string {
		$document = new DOMDocument();
		self::assertTrue($document->loadXML($xml));
		$xml = $document->saveXML($document->documentElement);
		self::assertIsString($xml);
		$block = false;
		$lines = explode("\n", $xml);
		foreach ($lines as $index => $line) {
			if ($block || str_contains($line, 'x-release-please-version')) {
				$lines[$index] = (string)preg_replace('/\d+\.\d+\.\d+(-[\w.]+)?(\+[-\w.]+)?/', $version, $line, 1);
			}
			if (str_contains($line, 'x-release-please-start-version')) {
				$block = true;
			} elseif (str_contains($line, 'x-release-please-end')) {
				$block = false;
			}
		}

		return implode("\n", $lines);
	}

	/** Each screenshot has its thumbnail, both at the tag of the version of the app info. */
	public function testEveryScreenshotHasASmallThumbnailOfTheSameRelease(): void {
		$xml = self::infoXml();
		$info = simplexml_load_string($xml);
		self::assertInstanceOf(SimpleXMLElement::class, $info);
		$address = sprintf(self::ADDRESS, (string)$info->version);
		$screenshots = self::screenshots($xml);

		self::assertCount(3, $screenshots);
		foreach ($screenshots as ['url' => $url, 'thumbnail' => $thumbnail]) {
			self::assertStringStartsWith($address, $url);
			self::assertStringEndsWith('.png', $url);
			self::assertSame(substr($url, 0, -strlen('.png')) . '-small.png', $thumbnail);
		}
	}

	/** The files of the addresses are in screenshots/, each thumbnail fitted into its box. */
	public function testEveryThumbnailIsTheScreenshotShrunkIntoItsBox(): void {
		foreach (self::screenshots(self::infoXml()) as ['url' => $url, 'thumbnail' => $thumbnail]) {
			$files = [self::ROOT . '/screenshots/' . basename($url), self::ROOT . '/screenshots/' . basename($thumbnail)];
			self::assertFileExists($files[0]);
			self::assertFileExists($files[1], 'Its thumbnail comes from bash tests/e2e/screenshots.sh.');
			$screenshot = getimagesize($files[0]);
			$small = getimagesize($files[1]);
			self::assertIsArray($screenshot, $url);
			self::assertIsArray($small, $thumbnail);
			[$width, $height] = $screenshot;
			[$smallWidth, $smallHeight] = $small;
			$scale = min(self::THUMBNAIL_WIDTH / $width, self::THUMBNAIL_HEIGHT / $height);

			self::assertLessThan(1, $scale, "$url fits into the box of its thumbnail and needs none.");
			self::assertSame(
				[(int)round($width * $scale), (int)round($height * $scale)],
				[$smallWidth, $smallHeight],
				"$thumbnail is no picture of {$width} × {$height} pixels shrunk into "
				. self::THUMBNAIL_WIDTH . ' × ' . self::THUMBNAIL_HEIGHT . '; run bash tests/e2e/screenshots.sh.',
			);
		}
	}

	/** release-please writes the version of a release into both addresses of every screenshot. */
	public function testAReleaseWritesItsVersionIntoBothAddresses(): void {
		$xml = self::infoXml();
		$address = sprintf(self::ADDRESS, '98.76.54');
		$screenshots = self::screenshots(self::released($xml, '98.76.54'));

		self::assertSame(count(self::screenshots($xml)), count($screenshots));
		foreach ($screenshots as ['url' => $url, 'thumbnail' => $thumbnail]) {
			self::assertStringStartsWith($address, $url);
			self::assertStringStartsWith($address, $thumbnail);
		}
	}

	/**
	 * Like release-please, the updater of these tests writes only the first version of a line, so
	 * that both addresses of a screenshot on one line would leave the second at the old tag.
	 */
	public function testTheUpdaterOfTheTestWritesOnlyTheFirstVersionOfALine(): void {
		$line = "<info><screenshot small-thumbnail=\"a/v1.0.0/b\">a/v1.0.0/c</screenshot><!-- x-release-please-version -->\n<x>1.0.0</x></info>";

		self::assertSame(
			"<info><screenshot small-thumbnail=\"a/v2.0.0/b\">a/v1.0.0/c</screenshot><!-- x-release-please-version -->\n<x>1.0.0</x></info>",
			self::released($line, '2.0.0'),
		);
	}

	/**
	 * Like the XML updater of release-please, the updater of these tests joins the lines of a
	 * start tag, so that a thumbnail on a line of its own inside the tag would keep the old tag
	 * in the address of its screenshot.
	 */
	public function testTheUpdaterOfTheTestJoinsTheLinesOfAStartTag(): void {
		$xml = "<info>\n<!-- x-release-please-start-version -->\n<screenshot\n small-thumbnail=\"a/v1.0.0/b\"\n>a/v1.0.0/c</screenshot>\n<!-- x-release-please-end -->\n</info>";

		self::assertSame(
			"<info>\n<!-- x-release-please-start-version -->\n<screenshot small-thumbnail=\"a/v2.0.0/b\">a/v1.0.0/c</screenshot>\n<!-- x-release-please-end -->\n</info>",
			self::released($xml, '2.0.0'),
		);
	}
}
