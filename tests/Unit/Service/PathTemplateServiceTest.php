<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\PathTemplateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTemplateServiceTest extends TestCase {
	private PathTemplateService $service;

	protected function setUp(): void {
		$this->service = new PathTemplateService();
	}

	public function testRendersStableStructuredPath(): void {
		$document = [
			'id' => 123,
			'title' => 'Rechnung: Strom / August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'created' => '2026-08-26',
			'added' => '2026-08-27T12:30:00+02:00',
			'original_file_name' => 'scan.PDF',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];

		self::assertSame(
			'Energie GmbH/Rechnung/2026/2026-08-26 - Rechnung_ Strom _ August [P123].pdf',
			$this->service->render($this->config(), $document, ['4' => 'Energie GmbH'], ['7' => 'Rechnung'], []),
		);
	}

	public function testUsesConfiguredFallbacksAndWindowsSafeNames(): void {
		$document = [
			'id' => 5,
			'title' => 'CON',
			'created' => null,
			'original_file_name' => 'document',
			'mime_type' => 'application/pdf',
		];

		self::assertSame(
			'_Ohne Korrespondent/_Ohne Dokumenttyp/_Ohne Datum/_Ohne Datum - _CON [P5].pdf',
			$this->service->render($this->config(), $document, [], [], []),
		);
	}

	public function testRejectsTraversalAndUnknownVariables(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->normalizeRelativePath('Dokumente/../Geheim');
	}

	public function testRequiresStableDocumentMarkerVariable(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('{{ id }}');
		$this->service->validateTemplate('{{ title }}.pdf');
	}

	/** @return array<string, array{string, string}> */
	public static function invalidTemplates(): array {
		return [
			'empty' => [' / ', 'The archive path template must not be empty.'],
			'unknown variable' => ['{{ id }}/{{ owner }}', 'Unknown archive path variable: {owner}.'],
			'uppercase variable' => ['{{ id }}/{{ Title }}', 'The archive path template contains an invalid placeholder.'],
			'unbalanced braces' => ['{{ id }}}}', 'The archive path template contains an invalid placeholder.'],
		];
	}

	#[DataProvider('invalidTemplates')]
	public function testRejectsInvalidTemplates(string $template, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->validateTemplate($template);
	}

	public function testTemplateIsNormalizedToForwardSlashes(): void {
		self::assertSame('{{ created_year }}/{{ title }} [P{{ id }}]', $this->service->validateTemplate(' \\{{ created_year }}\\{{ title }} [P{{ id }}]/ '));
	}

	public function testRelativePathsAreNormalized(): void {
		self::assertSame('Dokumente/Paperless', $this->service->normalizeRelativePath(' \\Dokumente\\Paperless/ '));
		self::assertSame('', $this->service->normalizeRelativePath(' / ', true));
	}

	public function testRelativePathMustNotBeEmptyUnlessAllowed(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A relative folder path is required.');
		$this->service->normalizeRelativePath(' / ');
	}

	public function testRelativePathMustNotContainEmptyComponents(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->normalizeRelativePath('Dokumente//Paperless');
	}

	public function testFolderNameIsOneCleanComponent(): void {
		self::assertSame('Archiv_ 2026', $this->service->normalizeFolderName(' Archiv: 2026 '));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Folder names must contain exactly one path component.');
		$this->service->normalizeFolderName('Archiv/2026');
	}

	public function testRendersEveryTemplateVariable(): void {
		$template = '{{ storage_path }}/{{ created_month }}/{{ added_year }}-{{ added }}/{{ original_filename }} [P{{ id }}]{{ extension }}';
		$document = [
			'id' => '42',
			'storage_path' => 3,
			'created' => '2026-08-26T09:00:00+02:00',
			'added' => '2026-09-01',
			'original_file_name' => 'Scan 0815.tiff',
			'archived_file_name' => '',
			'mime_type' => 'image/tiff',
		];

		self::assertSame(
			'Steuern/08/2026-2026-09-01/Scan 0815 [P42].tiff',
			$this->service->render($this->config($template), $document, [], [], ['3' => 'Steuern']),
		);
	}

	public function testRendersUnreadableDatesWithTheirFallback(): void {
		$template = '{{ created_month }}/{{ added_year }}/{{ added }} [P{{ id }}]';

		self::assertSame(
			'_Ohne Datum/_Ohne Datum/_Ohne Datum [P7]',
			$this->service->render($this->config($template), ['id' => 7, 'created' => 'yesterday', 'added' => 20260826], [], [], []),
		);
	}

	/** @return array<string, array{array<string, mixed>, bool, string}> */
	public static function extensions(): array {
		return [
			'archive version' => [['archived_file_name' => 'archive.PDF', 'original_file_name' => 'scan.jpeg'], true, '.pdf'],
			'original when preferred' => [['archived_file_name' => 'archive.pdf', 'original_file_name' => 'scan.jpeg'], false, '.jpeg'],
			'JPEG by type' => [['original_file_name' => 'scan', 'mime_type' => 'image/jpeg'], true, '.jpg'],
			'PNG by type' => [['original_file_name' => 'scan.', 'mime_type' => 'IMAGE/PNG'], true, '.png'],
			'TIFF by type' => [['original_file_name' => 'scan.ti-f', 'mime_type' => 'image/tiff'], true, '.tiff'],
			'unknown type' => [['original_file_name' => 'scan', 'mime_type' => 'text/plain'], true, '.bin'],
			'no type' => [['original_file_name' => 42], true, '.bin'],
		];
	}

	/** @param array<string, mixed> $files */
	#[DataProvider('extensions')]
	public function testChoosesTheFileExtension(array $files, bool $preferArchive, string $extension): void {
		$rendered = $this->service->render($this->config('P{{ id }}{{ extension }}', $preferArchive), ['id' => 1] + $files, [], [], []);

		self::assertSame('P1' . $extension, $rendered);
	}

	public function testRejectsDocumentsWithoutANumericId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Paperless returned a document without a numeric ID.');
		$this->service->render($this->config(), ['id' => 'P1'], [], [], []);
	}

	public function testCleansComponents(): void {
		self::assertSame('Fallback', $this->service->cleanComponent(' .. ', 'Fallback'));
		self::assertSame('_com1.txt', $this->service->cleanComponent('com1.txt', 'Fallback'));
		self::assertSame('a b', $this->service->cleanComponent('a     b', 'Fallback'));
		self::assertSame('a_b_c', $this->service->cleanComponent("a\tb\nc", 'Fallback'));
		self::assertSame('abc', $this->service->cleanComponent('abc. def', 'Fallback', 4));
		self::assertSame('Fallback', $this->service->cleanComponent('. . .', 'Fallback'));
	}

	private function config(string $template = PathTemplateService::DEFAULT_TEMPLATE, bool $preferArchive = true): SyncConfig {
		return new SyncConfig(
			'https://paperless.example.test',
			true,
			false,
			'paperless',
			'Dokumente/Paperless',
			'Archiv',
			'Eingang',
			'Fehler',
			'_Gelöscht',
			$template,
			true,
			true,
			$preferArchive,
			true,
			'',
			5,
			100,
			'move',
			false,
			false,
			3,
			true,
			true,
			true,
			'replace',
			'_Ohne Korrespondent',
			'_Ohne Dokumenttyp',
			'_Ohne Datum',
			'Ohne Titel',
		);
	}
}
