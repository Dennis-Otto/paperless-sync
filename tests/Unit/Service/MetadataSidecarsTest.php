<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ExportStates;
use OCA\PaperlessSync\Service\MetadataSidecars;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\Files\NotEnoughSpaceException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MetadataSidecarsTest extends TestCase {
	use InMemoryConfiguration;

	private const ARCHIVE = 'Documents/Paperless/Archive';
	private const SIDECAR = self::ARCHIVE . '/.paperless/P123.json';
	private const INDEX = self::ARCHIVE . '/.paperless/index.csv';
	private const PATH = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
	private const SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
	private const MD5 = '098f6bcd4621d373cade4e832627b4f6';
	private const HEADER = "\u{FEFF}id,title,correspondent,document_type,tags,created,added,modified,archive_serial_number,path,state,sha256,url,metadata_sha256\r\n";

	private TestStorage $storage;
	private TestStateRepository $repository;
	private SyncConfig $config;
	private SyncReport $report;

	protected function setUp(): void {
		$this->storage = new TestStorage();
		$this->repository = new TestStateRepository();
		$this->config = $this->configService()->save(['write_metadata' => true, 'paperless_url' => 'https://paperless.example.test/'] + self::validSettings(), 'secret-token');
		$this->report = new SyncReport(false);
	}

	/**
	 * The sidecar holds what Paperless knows of the document, in names instead of IDs, and the
	 * index a row for the copy, so that the archive describes itself without Paperless.
	 */
	public function testACopyGetsItsSidecarAndItsRowInTheIndex(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active']]);
		$sidecars = $this->sidecars();

		$sidecars->describe(self::document(), 123, self::PATH, self::SHA256 . ' ' . self::MD5, false);
		$sidecars->finish($this->states());

		$sidecar = $this->storage->files[self::SIDECAR];
		self::assertStringEndsWith("}\n", $sidecar);
		self::assertSame([
			'version' => 1,
			'id' => 123,
			'title' => 'Strom August',
			'correspondent' => 'Energie GmbH',
			'document_type' => 'Rechnung',
			'storage_path' => 'Haushalt',
			'tags' => ['99', 'bezahlt', 'Strom'],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'archive_serial_number' => 42,
			'original_file_name' => 'scan.pdf',
			'mime_type' => 'application/pdf',
			'page_count' => 2,
			'notes' => [
				['note' => 'Bezahlt am 1. September.', 'created' => '2026-09-01T09:00:00+02:00', 'user' => 'dennis'],
				['note' => 'From a Paperless that names its users by their IDs', 'created' => null, 'user' => null],
			],
			'custom_fields' => [
				['name' => 'Rechnungsnummer', 'type' => 'string', 'value' => 'INV-2026-08'],
				['name' => 'Zahlart', 'type' => 'select', 'value' => 'Überweisung'],
				['name' => 'Zahlart', 'type' => 'select', 'value' => 'opt-gone'],
				['name' => 'Betrag', 'type' => 'monetary', 'value' => 12.0],
				['name' => '9', 'type' => '', 'value' => [124, 125]],
			],
			'file' => ['version' => 'archive', 'sha256' => self::SHA256],
			'url' => 'https://paperless.example.test/documents/123/details',
		], json_decode($sidecar, true, flags: JSON_THROW_ON_ERROR));
		self::assertStringContainsString('"value": 12.0', $sidecar, 'A number keeps its type.');
		self::assertStringContainsString('"title": "Strom August"', $sidecar, 'The sidecar is for people to read as well.');
		self::assertSame(self::HEADER . '123,Strom August,Energie GmbH,Rechnung,"99, bezahlt, Strom",2026-08-26,2026-08-26T10:00:00+02:00,2026-08-26T10:00:00+02:00,42,'
			. 'Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf,active,' . self::SHA256 . ',https://paperless.example.test/documents/123/details,' . hash('sha256', $sidecar) . "\r\n", $this->storage->files[self::INDEX]);
		self::assertSame(0, $this->report->errors);
	}

	/** A document with only some of its metadata, such as from an older Paperless, has the rest empty. */
	public function testMetadataThatADocumentLacksStaysEmpty(): void {
		$this->exports([7 => ['path' => self::ARCHIVE . '/a.pdf', 'state' => 'active']]);
		$sidecars = $this->sidecars();

		$sidecars->describe(['id' => 7, 'correspondent' => 5, 'tags' => 'none', 'archive_serial_number' => '42', 'notes' => 'none', 'custom_fields' => 'none'], 7, self::ARCHIVE . '/a.pdf', 'pending', true);
		$sidecars->finish($this->states());

		$sidecar = json_decode($this->storage->files[self::ARCHIVE . '/.paperless/P7.json'], true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(['', null, null, null, [], null, null, [], []], [
			$sidecar['title'], $sidecar['correspondent'], $sidecar['document_type'], $sidecar['created'], $sidecar['tags'],
			$sidecar['archive_serial_number'], $sidecar['page_count'], $sidecar['notes'], $sidecar['custom_fields'],
		], 'A correspondent that the account of the app may not see has no name.');
		self::assertSame(['version' => 'original', 'sha256' => null], $sidecar['file'], 'A fingerprint without a SHA-256 names none.');
		self::assertStringContainsString("\r\n7,,,,,,,,,a.pdf,active,,https://paperless.example.test/documents/7/details,", $this->storage->files[self::INDEX]);
	}

	/**
	 * The index keeps the SHA-256 of each sidecar, so a run reads one file and writes a sidecar
	 * only when its content changes: after a change of the document, and after one of a name
	 * that it holds, such as that of its correspondent, which leaves the document as it was.
	 */
	public function testASidecarIsWrittenOnlyWhenItsContentChanges(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active']]);
		$first = $this->sidecars();
		$first->describe(self::document(), 123, self::PATH, self::SHA256, false);
		$first->finish($this->states());
		$this->storage->writes = [];
		$this->storage->reads = [];

		$unchanged = $this->sidecars();
		$unchanged->describe(self::document(), 123, self::PATH, self::SHA256, false);
		$unchanged->finish($this->states());
		self::assertSame([], $this->storage->writes, 'Nothing changed, so nothing is written.');
		self::assertSame([self::INDEX], $this->storage->reads, 'The run reads the index alone.');

		$changed = $this->sidecars();
		$changed->describe(['modified' => '2026-08-27T08:00:00+02:00', 'title' => 'Strom August korrigiert'] + self::document(), 123, self::PATH, self::SHA256, false);
		$changed->finish($this->states());
		self::assertSame([[self::SIDECAR, 'updated'], [self::INDEX, 'updated']], $this->storage->writes);
		self::assertStringContainsString('"title": "Strom August korrigiert"', $this->storage->files[self::SIDECAR]);

		$this->storage->writes = [];
		$renamed = $this->sidecars(['4' => 'Energie AG']);
		$renamed->describe(['modified' => '2026-08-27T08:00:00+02:00', 'title' => 'Strom August korrigiert'] + self::document(), 123, self::PATH, self::SHA256, false);
		$renamed->finish($this->states());
		self::assertSame([[self::SIDECAR, 'updated'], [self::INDEX, 'updated']], $this->storage->writes, 'A new name of the correspondent changes the sidecar.');
		self::assertStringContainsString('"correspondent": "Energie AG"', $this->storage->files[self::SIDECAR]);
		self::assertStringContainsString(',Strom August korrigiert,Energie AG,', $this->storage->files[self::INDEX]);
	}

	/**
	 * A copy in the deleted folder keeps its sidecar, and its row follows it there, with the state
	 * of its document; a copy that the run deleted, under the rules of deletion safety, takes its
	 * sidecar and its row along.
	 */
	public function testTheRowsFollowTheCopiesAndASidecarGoesWithItsCopy(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active'], 124 => ['path' => self::ARCHIVE . '/b.pdf', 'state' => 'active']]);
		$sidecars = $this->sidecars();
		$sidecars->describe(self::document(), 123, self::PATH, self::SHA256, false);
		$sidecars->describe(['id' => 124, 'title' => 'Jahresabrechnung'] + self::document(), 124, self::ARCHIVE . '/b.pdf', self::SHA256, false);
		$sidecars->finish($this->states());
		$sidecar = $this->storage->files[self::SIDECAR];

		$this->repository->exports[123] = ['document_id' => 123, 'path' => self::ARCHIVE . '/_Deleted/2026-08-27/a.pdf', 'state' => 'trash'];
		unset($this->repository->exports[124]);
		$this->storage->writes = [];
		$this->sidecars()->finish($this->states());

		self::assertSame($sidecar, $this->storage->files[self::SIDECAR], 'The sidecar of a copy in the deleted folder stays as it was.');
		self::assertArrayNotHasKey(self::ARCHIVE . '/.paperless/P124.json', $this->storage->files);
		self::assertSame([[self::INDEX, 'updated']], $this->storage->writes);
		$rows = explode("\r\n", $this->storage->files[self::INDEX]);
		self::assertCount(3, $rows, 'The header, the row of P123 and the end of the last line.');
		self::assertStringStartsWith('123,Strom August,', $rows[1]);
		self::assertStringContainsString(',_Deleted/2026-08-27/a.pdf,trash,', $rows[1]);

		unset($this->repository->exports[123]);
		$this->sidecars()->finish($this->states());
		self::assertSame([self::INDEX => self::HEADER], $this->storage->files, 'An index that had rows stays, without them.');
	}

	/** A row keeps its path when the state of its document has none, and a path outside the archive whole. */
	public function testARowKeepsWhatTheStateDoesNotKnow(): void {
		$this->exports([123 => ['path' => '', 'state' => null]]);
		$sidecars = $this->sidecars();

		$sidecars->describe(self::document(), 123, 'Elsewhere/a.pdf', self::SHA256, false);
		$sidecars->finish($this->states());

		self::assertStringContainsString(',Elsewhere/a.pdf,active,', $this->storage->files[self::INDEX]);
	}

	/** An archive without metadata gets no folder for it: the run writes nothing. */
	public function testAnArchiveWithoutSidecarsGetsNoIndex(): void {
		$this->sidecars()->finish($this->states());

		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->storage->writes);
	}

	/**
	 * A spreadsheet runs a cell that starts with =, +, - or @ as a formula, such as a title in
	 * Paperless that someone wrote to run one: in the index it starts with an apostrophe, which
	 * makes it text, and the next run reads it back as it was, so the index stays as it is.
	 */
	public function testAFormulaInTheMetadataStaysTextInTheIndex(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active']]);
		$sidecars = $this->sidecars();
		$title = '=HYPERLINK("https://evil.example","Open")';

		$sidecars->describe(['title' => $title, 'tags' => []] + self::document(), 123, self::PATH, self::SHA256, false);
		$sidecars->finish($this->states());

		self::assertStringContainsString('123,"\'=HYPERLINK(""https://evil.example"",""Open"")",Energie GmbH,', $this->storage->files[self::INDEX]);
		self::assertStringContainsString('"title": "=HYPERLINK(\"https://evil.example\",\"Open\")"', $this->storage->files[self::SIDECAR], 'The sidecar keeps the title as it is.');
		$this->storage->writes = [];
		$this->sidecars()->finish($this->states());
		self::assertSame([], $this->storage->writes, 'The apostrophe is read back, not doubled.');
	}

	/**
	 * An index of another version serves by the names of its columns, with a byte order mark,
	 * values in quotes and lines in a value; the rows that make no sense are left out, and the
	 * next index has the columns of this version.
	 */
	public function testAnIndexOfAnotherVersionServesByTheNamesOfItsColumns(): void {
		$this->storage->files[self::INDEX] = "\u{FEFF}title,id,path,state\r\n"
			. "\"Strom, August\",123,Old/a.pdf,active\r\n"
			. "\r\n"
			. "broken,row\r\n"
			. "No ID,0,b.pdf,active\r\n"
			. "\"'+49 Hotline\nzweite Zeile\",124,c.pdf,trash\n";
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active'], 124 => ['path' => self::ARCHIVE . '/c.pdf', 'state' => 'trash']]);

		$this->sidecars()->finish($this->states());

		self::assertSame(self::HEADER
			. "123,\"Strom, August\",,,,,,,,Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf,active,,,\r\n"
			. "124,\"'+49 Hotline\nzweite Zeile\",,,,,,,,c.pdf,trash,,,\r\n", $this->storage->files[self::INDEX]);

		$this->storage->writes = [];
		$sidecars = $this->sidecars();
		$sidecars->describe(self::document(), 123, self::PATH, self::SHA256, false);
		self::assertSame([[self::SIDECAR, 'created']], $this->storage->writes, 'A row without the SHA-256 of its sidecar has its sidecar written.');
	}

	/**
	 * Nextcloud refuses the writes of a run against a full quota, and those of the metadata as
	 * well: the first one is an error of its document, and the run writes no further metadata,
	 * which the next run catches up.
	 */
	public function testAFailedWriteStopsTheMetadataOfTheRun(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active']]);
		$this->storage->writeTextException = new NotEnoughSpaceException('Insufficient space in Documents');
		$sidecars = $this->sidecars();

		$sidecars->describe(self::document(), 123, self::PATH, self::SHA256, false);
		$sidecars->describe(['id' => 124] + self::document(), 124, self::PATH, self::SHA256, false);
		$sidecars->finish($this->states());

		self::assertSame(['Metadata P123: Insufficient space in Documents'], $this->report->errorMessages);
		self::assertSame(['writeText'], array_values(array_filter($this->storage->methods(), static fn (string $method): bool => $method !== 'exists')));
		self::assertSame(['type' => 'error', 'document' => 123, 'path' => self::SIDECAR, 'detail' => 'Metadata P123: Insufficient space in Documents'], $this->report->entries[0]);
	}

	/** A sidecar that Nextcloud doesn't delete stays in the index, for the next run to delete. */
	public function testAFailedDeleteKeepsTheRowForTheNextRun(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active']]);
		$sidecars = $this->sidecars();
		$sidecars->describe(self::document(), 123, self::PATH, self::SHA256, false);
		$sidecars->finish($this->states());
		$index = $this->storage->files[self::INDEX];
		unset($this->repository->exports[123]);
		$this->storage->deleteException = new RuntimeException('');

		$this->sidecars()->finish($this->states());

		self::assertSame(['Metadata P123: RuntimeException'], $this->report->errorMessages, 'An error without a message names its class.');
		self::assertSame($index, $this->storage->files[self::INDEX]);
	}

	/** An index that Nextcloud doesn't write is an error of the run, and the next run writes it. */
	public function testAFailedWriteOfTheIndexIsAnErrorOfTheRun(): void {
		$this->exports([123 => ['path' => self::PATH, 'state' => 'active']]);
		$sidecars = $this->sidecars();
		$sidecars->describe(self::document(), 123, self::PATH, self::SHA256, false);
		$sidecars->finish($this->states());
		$this->repository->exports[123]['state'] = 'trash';
		$this->storage->writeTextException = new RuntimeException('The storage is read-only.');

		$this->sidecars()->finish($this->states());

		self::assertSame(['Metadata index: The storage is read-only.'], $this->report->errorMessages);
		self::assertSame(['type' => 'error', 'path' => self::INDEX, 'detail' => 'Metadata index: The storage is read-only.'], $this->report->entries[0]);
	}

	/** @param array<string, string>|null $correspondents */
	private function sidecars(?array $correspondents = null): MetadataSidecars {
		return new MetadataSidecars(
			$this->storage,
			$this->config,
			$this->report,
			self::ARCHIVE,
			$correspondents ?? ['4' => 'Energie GmbH'],
			['7' => 'Rechnung'],
			['2' => 'Haushalt'],
			['3' => 'bezahlt', '12' => 'Strom'],
			[
				'1' => ['name' => 'Rechnungsnummer', 'type' => 'string', 'options' => []],
				'2' => ['name' => 'Zahlart', 'type' => 'select', 'options' => ['opt-a' => 'Lastschrift', 'opt-b' => 'Überweisung']],
				'3' => ['name' => 'Betrag', 'type' => 'monetary', 'options' => []],
			],
		);
	}

	/** @param array<int, array<string, int|string|null>> $exports */
	private function exports(array $exports): void {
		foreach ($exports as $documentId => $values) {
			$this->repository->exports[$documentId] = ['document_id' => $documentId] + $values;
		}
	}

	private function states(): ExportStates {
		return new ExportStates($this->repository, TestStateRepository::OWNER);
	}

	/** @return array<string, mixed> a document as the scan of a run reads it, with its notes */
	private static function document(): array {
		return [
			'id' => 123,
			'title' => 'Strom August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => '2',
			'tags' => [12, '3', 99, null],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
			'owner' => 3,
			'archive_serial_number' => 42,
			'page_count' => 2,
			'notes' => [
				['id' => 1, 'note' => 'Bezahlt am 1. September.', 'created' => '2026-09-01T09:00:00+02:00', 'user' => ['id' => 3, 'username' => 'dennis']],
				['id' => 2, 'note' => 'From a Paperless that names its users by their IDs', 'user' => 3],
				['id' => 3, 'note' => null],
				'no note',
			],
			'custom_fields' => [
				['field' => 1, 'value' => 'INV-2026-08'],
				['field' => 2, 'value' => 'opt-b'],
				['field' => '2', 'value' => 'opt-gone'],
				['field' => 3, 'value' => 12.0],
				['field' => 9, 'value' => [124, 125]],
				['value' => 'without a field'],
				'no field',
			],
		];
	}
}
