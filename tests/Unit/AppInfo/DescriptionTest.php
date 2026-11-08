<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The descriptions of the App Store in appinfo/info.xml: the English one, without lang, and one
 * in each language of l10n/, which scripts/check-l10n.mjs asks for. Each translation has the
 * sections of the English one, in the same order, with as many bullets in each, so that a new
 * feature can't reach the App Store in one language only.
 */
final class DescriptionTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	private static function infoXml(): string {
		$xml = file_get_contents(self::ROOT . '/appinfo/info.xml');
		self::assertIsString($xml);

		return $xml;
	}

	/**
	 * The bullets of a description by its sections, in their order: how many lines of a list,
	 * "- ", come before the first heading "### ", and then after each heading until the next.
	 *
	 * @return list<int>
	 */
	private static function bullets(string $description): array {
		$sections = [0];
		foreach (explode("\n", $description) as $line) {
			if (str_starts_with($line, '### ')) {
				$sections[] = 0;
			} elseif (str_starts_with($line, '- ')) {
				$sections[count($sections) - 1]++;
			}
		}

		return $sections;
	}

	/**
	 * The bullets of each description by its sections, by the lang of the description, '' for
	 * the English one.
	 *
	 * @return array<string, list<int>>
	 */
	private static function descriptions(string $xml): array {
		$info = simplexml_load_string($xml);
		self::assertInstanceOf(SimpleXMLElement::class, $info);
		$descriptions = [];
		foreach ($info->description as $description) {
			$descriptions[(string)$description['lang']] = self::bullets((string)$description);
		}

		return $descriptions;
	}

	/**
	 * What each translation has otherwise than the English description, by its lang.
	 *
	 * @return array<string, string>
	 */
	private static function differences(string $xml): array {
		$descriptions = self::descriptions($xml);
		self::assertArrayHasKey('', $descriptions, 'appinfo/info.xml has its English <description>, without lang.');
		$english = $descriptions[''];
		unset($descriptions['']);
		$differences = [];
		foreach ($descriptions as $language => $bullets) {
			if ($bullets !== $english) {
				$differences[$language] = sprintf(
					'%d sections with %s bullets, where the English one has %d with %s',
					count($bullets) - 1,
					implode(', ', $bullets),
					count($english) - 1,
					implode(', ', $english),
				);
			}
		}

		return $differences;
	}

	/** Every translation lists the features of the English description, section by section. */
	public function testEveryLanguageHasTheSectionsAndBulletsOfTheEnglishDescription(): void {
		self::assertSame(
			[],
			self::differences(self::infoXml()),
			'A <description lang="…"> of appinfo/info.xml differs from the English one, the counts '
			. 'start with the bullets before the first heading: translate each new or changed bullet '
			. 'into every language, at the same place.',
		);
	}

	/** The English description has the lists that the translations are held to, and more than one. */
	public function testTheEnglishDescriptionListsItsFeaturesInSections(): void {
		$descriptions = self::descriptions(self::infoXml());
		$withBullets = array_filter($descriptions[''], static fn (int $bullets): bool => $bullets > 0);

		self::assertGreaterThanOrEqual(5, count($descriptions), 'English and a description for each language of l10n/.');
		self::assertGreaterThanOrEqual(3, count($withBullets));
	}

	/** A translation with one bullet less than the English description differs from it. */
	public function testATranslationWithOneBulletLessDiffers(): void {
		$xml = self::infoXml();
		$german = strpos($xml, '<description lang="de">');
		self::assertIsInt($german);
		$bullet = strpos($xml, "\n- ", $german);
		self::assertIsInt($bullet);
		$end = strpos($xml, "\n", $bullet + 1);
		self::assertIsInt($end);

		self::assertSame(
			['de'],
			array_keys(self::differences(substr($xml, 0, $bullet) . substr($xml, $end))),
		);
	}

	/** A bullet that moves to another section is a difference as well, with the same number in all. */
	public function testABulletInAnotherSectionDiffers(): void {
		$xml = <<<'XML'
			<info>
				<description><![CDATA[
			### Archive

			- one
			- two

			### Inbox

			- three
			]]></description>
				<description lang="de"><![CDATA[
			### Archiv

			- eins

			### Eingang

			- zwei
			- drei
			]]></description>
				<description lang="fr"><![CDATA[
			### Archive

			- un
			- deux

			### Boîte de réception

			- trois
			]]></description>
			</info>
			XML;

		self::assertSame(
			['de' => '2 sections with 0, 1, 2 bullets, where the English one has 2 with 0, 2, 1'],
			self::differences($xml),
		);
	}
}
