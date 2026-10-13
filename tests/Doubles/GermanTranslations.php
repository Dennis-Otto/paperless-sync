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
 * l10n/de.json, so that a test also finds a text that the catalog lacks.
 */
trait GermanTranslations {
	/** @return IL10N&MockObject */
	private function germanL10n(): IL10N {
		$catalog = json_decode((string)file_get_contents(__DIR__ . '/../../l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		/** @var array<string, string> $translations */
		$translations = $catalog['translations'];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array|string $parameters = []) use ($translations): string {
			self::assertArrayHasKey($text, $translations, 'l10n/de.json has no translation of this text.');

			return vsprintf($translations[$text], (array)$parameters);
		});

		return $l10n;
	}
}
