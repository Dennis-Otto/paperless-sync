<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\FileVersions;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Paperless-ngx 3.x keeps versions of a document and serves the newest, while the fields of the
 * document describe its first file: the copy takes the file of the newest version, which a run
 * reads once from the metadata of the document.
 */
final class FileVersionsTest extends TestCase {
	private TestPaperlessClient $paperless;
	private FileVersions $versions;

	protected function setUp(): void {
		$this->paperless = new TestPaperlessClient();
		$this->versions = new FileVersions($this->paperless);
	}

	/** @return array<string, array{mixed, int|null}> the field versions of a document, and the ID of its newest version of its own */
	public static function versionLists(): array {
		return [
			'none, as on Paperless-ngx 2.x' => [null, null],
			'no list' => [['id' => 7, 'is_root' => false], null],
			'an empty list' => [[], null],
			'the first file as the newest' => [[['id' => 1, 'is_root' => true]], null],
			'a newest version that names no first file' => [[['id' => 7]], null],
			'a newest version without a valid ID' => [[['id' => '7', 'is_root' => false], ['id' => 1, 'is_root' => true]], null],
			'a newest version with the ID 0' => [[['id' => 0, 'is_root' => false], ['id' => 1, 'is_root' => true]], null],
			'a newest version' => [[['id' => 7, 'is_root' => false], ['id' => 6, 'is_root' => false], ['id' => 1, 'is_root' => true]], 7],
		];
	}

	#[DataProvider('versionLists')]
	public function testTheNewestVersionIsTheFirstOfTheListUnlessItIsTheFirstFile(mixed $versions, ?int $expected): void {
		$document = ['id' => 1] + ($versions !== null ? ['versions' => $versions] : []);

		self::assertSame($expected, FileVersions::newest($document));
	}

	public function testADocumentWhoseNewestFileIsItsFirstStaysAsItIsWithoutARequest(): void {
		$document = self::document([['id' => 1, 'is_root' => true]]);

		self::assertSame($document, $this->versions->document(1, $document));
		self::assertSame([], $this->paperless->fileRequests);
	}

	public function testADocumentTakesTheFileOfItsNewestVersion(): void {
		$this->paperless->newestFiles[1] = ['name' => 'notes.txt', 'type' => 'text/plain', 'archive' => false];

		$document = $this->versions->document(1, self::document(self::newest(7)));

		self::assertSame(['notes.txt', null, 'text/plain'], [$document['original_file_name'], $document['archived_file_name'], $document['mime_type']]);
		self::assertSame('Letter', $document['title']);
	}

	public function testANewestVersionWithAnArchivedVersionNamesIt(): void {
		$this->paperless->newestFiles[1] = ['name' => 'photo.png', 'type' => 'image/png', 'archive' => true];

		$document = $this->versions->document(1, self::document(self::newest(7)));

		self::assertSame(['photo.png', 'archive.pdf', 'image/png'], [$document['original_file_name'], $document['archived_file_name'], $document['mime_type']]);
	}

	/** One request for each newest version in a run, also when several archives take the document, and a new one for a newer version. */
	public function testTheRunReadsTheFileOfANewestVersionOnce(): void {
		$this->paperless->newestFiles[1] = ['name' => 'notes.txt', 'type' => 'text/plain', 'archive' => false];

		$this->versions->document(1, self::document(self::newest(7)));
		$this->versions->document(1, self::document(self::newest(7)));
		$this->versions->checksum(1, self::document(self::newest(7)), true);
		$this->versions->document(1, self::document(self::newest(8)));

		self::assertSame([1, 1], $this->paperless->fileRequests);
	}

	public function testTheChecksumComesFromTheMetadataOfTheNewestVersion(): void {
		$this->paperless->newestFiles[1] = ['name' => 'photo.png', 'type' => 'image/png', 'archive' => true];
		$this->paperless->contents[1] = '%PDF-archive';
		$this->paperless->originalContents[1] = 'PNG-photo';

		self::assertSame(hash('sha256', 'PNG-photo'), $this->versions->checksum(1, self::document(self::newest(7)), true));
		self::assertSame(hash('sha256', '%PDF-archive'), $this->versions->checksum(1, self::document(self::newest(7)), false));
		self::assertSame(0, $this->paperless->checksumRequests);
	}

	public function testADocumentWithoutVersionsAsksPaperlessForItsChecksum(): void {
		$this->paperless->contents[1] = '%PDF-archive';

		self::assertSame(hash('sha256', '%PDF-archive'), $this->versions->checksum(1, self::document(null), false));
		self::assertSame(1, $this->paperless->checksumRequests);
		self::assertSame([], $this->paperless->fileRequests);
	}

	/** Without an archived version, the metadata names no checksum of one, and Paperless says so. */
	public function testTheArchivedVersionThatTheNewestVersionLacksHasNoChecksum(): void {
		$this->paperless->newestFiles[1] = ['name' => 'digital.pdf', 'type' => 'application/pdf', 'archive' => false];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless did not return archive_checksum.');
		$this->versions->checksum(1, self::document(self::newest(7)), false);
	}

	/** @return list<array{id: int, is_root: bool}> the versions of the document 1, with $version as its newest */
	private static function newest(int $version): array {
		return [['id' => $version, 'is_root' => false], ['id' => 1, 'is_root' => true]];
	}

	/**
	 * The document 1, a scan with an archived version, as its fields describe it.
	 *
	 * @param list<array{id: int, is_root: bool}>|null $versions
	 * @return array<string, mixed>
	 */
	private static function document(?array $versions): array {
		return [
			'id' => 1,
			'title' => 'Letter',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'Letter.pdf',
			'mime_type' => 'application/pdf',
		] + ($versions !== null ? ['versions' => $versions] : []);
	}
}
