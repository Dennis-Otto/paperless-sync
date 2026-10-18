<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\Paths;
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
}
