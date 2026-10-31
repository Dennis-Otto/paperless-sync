<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\InboxFileTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only videos, programs and archives stay aside; a document, however odd its name, goes to Paperless,
 * which judges it by its content.
 */
final class InboxFileTypesTest extends TestCase {
	/** @return array<string, array{string, string|null}> */
	public static function names(): array {
		return [
			'a video' => ['holiday.mp4', InboxFileTypes::VIDEO],
			'a video of a phone' => ['IMG_0815.MOV', InboxFileTypes::VIDEO],
			'a video of a browser' => ['talk.webm', InboxFileTypes::VIDEO],
			'a program of Windows' => ['setup.exe', InboxFileTypes::PROGRAM],
			'an installer of Windows' => ['setup.msi', InboxFileTypes::PROGRAM],
			'an installer of macOS' => ['setup.dmg', InboxFileTypes::PROGRAM],
			'a package of Linux' => ['setup.deb', InboxFileTypes::PROGRAM],
			'an app of Android' => ['app.apk', InboxFileTypes::PROGRAM],
			'a script' => ['install.PS1', InboxFileTypes::PROGRAM],
			'a zip file' => ['backup.zip', InboxFileTypes::ARCHIVE],
			'an archive with two extensions' => ['backup.tar.gz', InboxFileTypes::ARCHIVE],
			'an archive that names a document' => ['invoice.pdf.7z', InboxFileTypes::ARCHIVE],
			'an image of a disc' => ['disc.iso', InboxFileTypes::ARCHIVE],
			'a PDF' => ['scan.pdf', null],
			'a PDF in capitals' => ['SCAN.PDF', null],
			'a PDF whose name holds such an extension' => ['holiday.mp4.pdf', null],
			'a photo' => ['receipt.jpeg', null],
			'a scan as TIFF' => ['page.tif', null],
			'a text' => ['notes.txt', null],
			'a mail' => ['offer.eml', null],
			'a document of Word, which is a zip file inside' => ['letter.docx', null],
			'a spreadsheet of LibreOffice, which is a zip file inside' => ['costs.ods', null],
			'a type that only Paperless can judge' => ['notes.bin', null],
			'a name that ends in a domain' => ['Rechnung amazon.com', null],
			'a name without an extension' => ['scan', null],
			'a name that is such an extension' => ['zip', null],
			'a name without a dot that ends like such an extension' => ['azip', null],
			'a name that ends in a dot' => ['scan.', null],
		];
	}

	#[DataProvider('names')]
	public function testSetsAsideVideosProgramsAndArchivesAlone(string $name, ?string $kind): void {
		self::assertSame($kind, InboxFileTypes::setAsideKind($name));
	}
}
