<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Nextcloud's translations of the app for an administrator who reads German, from the catalog
 * l10n/de.json, so that a test also finds a text that the catalog lacks, and those of another
 * language whose plural has a form for a single thing and one for every other count, such as de_DE,
 * the formal German, or nl, Dutch.
 */
trait GermanTranslations {
	/** @return IL10N&MockObject */
	private function germanL10n(): IL10N {
		return $this->catalogL10n('de');
	}

	/** @return IL10N&MockObject */
	private function catalogL10n(string $language): IL10N {
		$file = "l10n/{$language}.json";
		$catalog = json_decode((string)file_get_contents(__DIR__ . "/../../{$file}"), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('nplurals=2; plural=(n != 1);', $catalog['pluralForm'], "{$file} has other plurals than this double picks.");
		/** @var array<string, string> $translations */
		$translations = $catalog['translations'];
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);
		$l10n->method('t')->willReturnCallback(static function (string $text, array|string $parameters = []) use ($translations, $file): string {
			self::assertArrayHasKey($text, $translations, "{$file} has no translation of this text.");

			return vsprintf($translations[$text], (array)$parameters);
		});
		// As Nextcloud: the plural for every count but 1, and the count in place of %n.
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $parameters = []) use ($catalog, $file): string {
			$key = "_{$singular}_::_{$plural}_";
			self::assertArrayHasKey($key, $catalog['translations'], "{$file} has no translation of this text.");
			/** @var array{string, string} $forms */
			$forms = $catalog['translations'][$key];

			return vsprintf(str_replace('%n', (string)$count, $forms[$count === 1 ? 0 : 1]), $parameters);
		});

		return $l10n;
	}
}
