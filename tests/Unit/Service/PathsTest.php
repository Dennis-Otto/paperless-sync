<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase {
	public function testJoinsThePartsWithoutEmptyOnesAndOuterSlashes(): void {
		self::assertSame('Dokumente/Paperless/Archiv', Paths::join('/Dokumente/', '', 'Paperless/Archiv/'));
		self::assertSame('', Paths::join('', '/'));
	}

	public function testAPathBelowARootIsRelativeToIt(): void {
		self::assertSame('Energie/2026/a.pdf', Paths::relativeTo('Archiv/Energie/2026/a.pdf', 'Archiv/'));
		self::assertSame('a.pdf', Paths::relativeTo('Elsewhere/a.pdf', 'Archiv'), 'A path outside the root keeps its name.');
		self::assertSame('a.pdf', Paths::relativeTo('Archiv2/a.pdf', 'Archiv'), 'A folder that starts with the name of the root is outside it.');
	}

	/** @return array<string, array{string, string}> */
	public static function namesWithMarkers(): array {
		return [
			'before the extension' => ['Scan [P999].pdf', 'Scan.pdf'],
			'at the start' => ['[P999] Scan.pdf', 'Scan.pdf'],
			'in the middle' => ['Scan [P999] final.pdf', 'Scan final.pdf'],
			'without spaces' => ['Scan[P999]final.pdf', 'Scanfinal.pdf'],
			'after the extension' => ['Scan.pdf [P999]', 'Scan.pdf'],
			'several' => ['[P1] Scan [P2][P3] final [P4].pdf', 'Scan final.pdf'],
			'in a name with umlauts' => ['Rechnung für März [P999].pdf', 'Rechnung für März.pdf'],
			'leading zeros, which the removal takes too, to stay on the safe side' => ['Scan [P007].pdf', 'Scan.pdf'],
			'the ID zero' => ['Scan [P0].pdf', 'Scan.pdf'],
			'more digits than an ID has' => ['Scan [P123456789012345678901234567890].pdf', 'Scan.pdf'],
			'one that the removal of another one would form' => ['Scan [P[P1]2].pdf', 'Scan.pdf'],
			'one that the removal of two others would form' => ['Scan [P[P[P1]2]3].pdf', 'Scan.pdf'],
			'one that the removal of another one and its space would form' => ['Scan [P4 [P5]].pdf', 'Scan.pdf'],
			'a dot left at the end' => ['Scan [P999].', 'Scan'],
			'nothing but a marker' => ['[P999]', 'Untitled'],
			'nothing but a marker and an extension' => ['[P999].pdf', 'Untitled.pdf'],
			'a dot left over, the current folder' => ['.[P999]', 'Untitled'],
			'two dots left over, the parent folder' => ['..[P999]', 'Untitled'],
			'dots on both sides' => ['.[P999].', 'Untitled'],
			'spaces left over' => [' [P999] ', 'Untitled'],
			'a name that becomes a hidden file' => ['.Scan [P999]', 'Untitled.Scan'],
		];
	}

	/** A marker is whatever Paperless Unified Search reads as one, and the name that is left is one that Nextcloud accepts. */
	#[DataProvider('namesWithMarkers')]
	public function testANameLeavesOutItsMarkers(string $name, string $expected): void {
		self::assertSame($expected, Paths::withoutMarkers($name, 'Untitled'));
	}

	/** @return array<string, array{string}> */
	public static function namesWithoutMarkers(): array {
		return [
			'a plain name' => ['Scan.pdf'],
			'a hidden file' => ['.Scan'],
			'a dot at the end' => ['Scan.'],
			'spaces around it' => [' Scan .pdf '],
			'lower case' => ['Scan [p999].pdf'],
			'no digits' => ['Scan [P].pdf'],
			'no brackets' => ['Scan P999.pdf'],
			'other brackets' => ['Scan (P999).pdf'],
			'one bracket' => ['Scan [P999.pdf'],
			'a space inside' => ['Scan [P 999].pdf'],
		];
	}

	#[DataProvider('namesWithoutMarkers')]
	public function testANameWithoutAMarkerStaysAsItIs(string $name): void {
		self::assertSame($name, Paths::withoutMarkers($name, 'Untitled'));
	}
}
