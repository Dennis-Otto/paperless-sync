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
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
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
			'unknown variable' => ['{{ id }}/{{ owner }}', 'Unknown archive path variable: {{ owner }}.'],
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

	/** @return array<string, array{string, string}> */
	public static function placeholderSpellings(): array {
		return [
			'without spaces' => ['{{title}} [P{{id}}]{{extension}}', '{{ title }} [P{{ id }}]{{ extension }}'],
			'with more spaces' => ['{{  title  }} [P{{  id  }}]', '{{ title }} [P{{ id }}]'],
			'with a tab' => ["{{\tcreated_year }}/[P{{ id\t}}]", '{{ created_year }}/[P{{ id }}]'],
		];
	}

	#[DataProvider('placeholderSpellings')]
	public function testAcceptsEverySpellingOfAPlaceholderAndWritesItAsOne(string $template, string $canonical): void {
		self::assertSame($canonical, $this->service->validateTemplate($template));
		self::assertSame($canonical, $this->service->validateTemplate($canonical));
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
		$template = '{{ created_month }}/{{ added_year }}/{{ added }} [P{{ id }}]{{ extension }}';

		self::assertSame(
			'_Ohne Datum/_Ohne Datum/_Ohne Datum [P7].bin',
			$this->service->render($this->config($template), ['id' => 7, 'created' => 'yesterday', 'added' => 20260826], [], [], []),
		);
	}

	/** @return array<string, array{string, string}> */
	public static function valuesWithDollarsAndBackslashes(): array {
		return [
			'group reference' => ['$1', '$1'],
			'braced group reference' => ['${1}0', '${1}0'],
			'backslash reference' => ['\\1', '_1'],
			'amount' => ['Refund $100 from Shop', 'Refund $100 from Shop'],
			'whole match' => ['Tarif $0 Basis', 'Tarif $0 Basis'],
			'in a name' => ['Stadtwerke $1 GmbH', 'Stadtwerke $1 GmbH'],
		];
	}

	#[DataProvider('valuesWithDollarsAndBackslashes')]
	public function testInsertsValuesAsTheyAre(string $title, string $rendered): void {
		self::assertSame(
			"Energie GmbH/Rechnung/2026/2026-08-26 - {$rendered} [P1].pdf",
			$this->service->render($this->config(), $this->document(['title' => $title]), ['4' => 'Energie GmbH'], ['7' => 'Rechnung'], []),
		);
	}

	public function testLeavesAnUnknownVariableOfAnOlderTemplateAsText(): void {
		self::assertSame('{{ owner }} [P1].pdf', $this->service->render($this->config('{{ owner }} [P{{ id }}]{{ extension }}'), $this->document(), [], [], []));
	}

	public function testReadsOnlyADateAtTheStartOfTheValue(): void {
		self::assertSame(
			'_Ohne Datum [P1].pdf',
			$this->service->render($this->config('{{ created }} [P{{ id }}]{{ extension }}'), $this->document(['created' => 'about 2026-08-26']), [], [], []),
		);
	}

	public function testDoesNotExpandPlaceholdersInValues(): void {
		self::assertSame(
			'{{ title }}/Rechnung/2026/2026-08-26 - {{ correspondent }} [P1].pdf',
			$this->service->render($this->config(), $this->document(['title' => '{{ correspondent }}']), ['4' => '{{ title }}'], ['7' => 'Rechnung'], []),
		);
	}

	/** @return array<string, array{string, string}> */
	public static function fileNamesWithoutMarkerOrExtension(): array {
		return [
			'the ID in a folder' => ['{{ id }}/{{ title }}', '1/Rechnung [P1].pdf'],
			'the ID without the marker' => ['{{ title }} {{ id }}{{ extension }}', 'Rechnung 1 [P1].pdf'],
			'the extension inside' => ['{{ title }}{{ extension }} [P{{ id }}]', 'Rechnung.pdf [P1].pdf'],
			'placeholders without spaces' => ['{{title}} [P{{id}}]{{extension}}', 'Rechnung [P1].pdf'],
		];
	}

	#[DataProvider('fileNamesWithoutMarkerOrExtension')]
	public function testEveryFileNameKeepsTheMarkerAndEndsWithTheExtension(string $template, string $rendered): void {
		self::assertSame($rendered, $this->service->render($this->config($template), $this->document(['title' => 'Rechnung']), [], [], []));
	}

	/** @return array<string, array{string, string}> */
	public static function longTitles(): array {
		// The title in bytes is 195 at most: 217 for the name, less "2026-08-26 - ", " [P1]" and ".pdf".
		return [
			'Cyrillic' => [str_repeat('Ж', 120), str_repeat('Ж', 97)],
			'Chinese' => [str_repeat('株', 120), str_repeat('株', 65)],
			'emoji' => [str_repeat('😀', 120), str_repeat('😀', 48)],
			'Latin' => [str_repeat('Rechnung ', 30), rtrim(substr(str_repeat('Rechnung ', 30), 0, 195))],
		];
	}

	#[DataProvider('longTitles')]
	public function testShortensLongTitlesToTheBytesThatNextcloudAccepts(string $title, string $shortened): void {
		$path = $this->service->render($this->config(), $this->document(['title' => $title]), ['4' => 'Energie GmbH'], ['7' => 'Rechnung'], []);

		self::assertSame("Energie GmbH/Rechnung/2026/2026-08-26 - {$shortened} [P1].pdf", $path);
		self::assertLessThanOrEqual(217, strlen(basename($path)));
	}

	public function testShortensLongFolderNamesToTheBytesThatNextcloudAccepts(): void {
		$path = $this->service->render($this->config(), $this->document(), ['4' => str_repeat('株', 120)], ['7' => str_repeat('Ж', 120)], []);

		self::assertSame(str_repeat('株', 66) . '/' . str_repeat('Ж', 100) . '/2026/2026-08-26 - Rechnung [P1].pdf', $path);
	}

	public function testLongValuesKeepTheMarkerAndTheExtensionOfEveryDocument(): void {
		$template = '{{ correspondent }} - {{ title }} - {{ original_filename }} [P{{ id }}]{{ extension }}';
		$document = $this->document(['title' => str_repeat('Rechnung Stadtwerke ', 10), 'original_file_name' => 'Rechnung_' . str_repeat('x', 100) . '.pdf']);
		$correspondents = ['4' => 'Stadtwerke Musterstadt GmbH'];

		$first = $this->service->render($this->config($template), ['id' => 123] + $document, $correspondents, [], []);
		$second = $this->service->render($this->config($template), ['id' => 456] + $document, $correspondents, [], []);

		// The short correspondent stays whole, and the two long values share the rest.
		self::assertSame('Stadtwerke Musterstadt GmbH - ' . substr(str_repeat('Rechnung Stadtwerke ', 5), 0, 86) . ' - Rechnung_' . str_repeat('x', 77) . ' [P123].pdf', $first);
		self::assertSame(str_replace('[P123]', '[P456]', $first), $second);
		self::assertSame(216, strlen($first));
	}

	public function testAShortValueStaysWholeAlsoAfterALongOne(): void {
		$template = '{{ title }} - {{ correspondent }} [P{{ id }}]{{ extension }}';

		self::assertSame(
			substr(str_repeat('Rechnung ', 30), 0, 193) . ' - Energie GmbH [P1].pdf',
			$this->service->render($this->config($template), $this->document(['title' => str_repeat('Rechnung ', 30)]), ['4' => 'Energie GmbH'], [], []),
		);
	}

	/** @return array<string, array{string, string}> */
	public static function templatesWithTooMuchText(): array {
		return [
			// No byte is left for the title, and the marker moves to the end of what is left.
			'the marker after the text' => [
				str_repeat('a', 300) . '/{{ title }} ' . str_repeat('b', 300) . ' [P{{ id }}]{{ extension }}',
				str_repeat('a', 200) . '/' . str_repeat('b', 208) . ' [P1].pdf',
			],
			'the marker before the text' => ['{{ id }}/[P{{ id }}] ' . str_repeat('b', 300) . '{{ extension }}', '1/[P1] ' . str_repeat('b', 208) . '.pdf'],
		];
	}

	#[DataProvider('templatesWithTooMuchText')]
	public function testCutsTheTextOfTheTemplateOnlyWhenItAloneIsTooLong(string $template, string $rendered): void {
		self::assertSame($rendered, $this->service->render($this->config($template), $this->document(['title' => str_repeat('Rechnung ', 20)]), [], [], []));
	}

	public function testShortensFurtherWhenCleaningLengthensTheName(): void {
		// "CON." of the template becomes "_CON.", one byte more than the template has; the title gives way, not "Scan".
		$template = '{{ id }}/CON.{{ created }} {{ title }} [P{{ id }}] Scan{{ extension }}';

		$path = $this->service->render($this->config($template), $this->document(['title' => str_repeat('x', 300)]), [], [], []);

		self::assertSame('1/_CON.2026-08-26 ' . str_repeat('x', 187) . ' [P1] Scan.pdf', $path);
		self::assertSame(217, strlen(basename($path)));
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
		$rendered = $this->service->render($this->config('[P{{ id }}]{{ extension }}', $preferArchive), ['id' => 1] + $files, [], [], []);

		self::assertSame('[P1]' . $extension, $rendered);
	}

	/** @return array<string, array{mixed}> */
	public static function invalidIds(): array {
		return [
			'marker' => ['P1'],
			'digits first' => ['12abc'],
			'empty' => [''],
			'missing' => [null],
		];
	}

	#[DataProvider('invalidIds')]
	public function testRejectsDocumentsWithoutANumericId(mixed $id): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Paperless returned a document without a numeric ID.');
		$this->service->render($this->config(), ['id' => $id], [], [], []);
	}

	public function testCleansComponents(): void {
		self::assertSame('Fallback', $this->service->cleanComponent(' .. ', 'Fallback'));
		self::assertSame('_com1.txt', $this->service->cleanComponent('com1.txt', 'Fallback'));
		self::assertSame('a b', $this->service->cleanComponent('a     b', 'Fallback'));
		self::assertSame('a_b_c', $this->service->cleanComponent("a\tb\nc", 'Fallback'));
		self::assertSame('abc', $this->service->cleanComponent('abc. def', 'Fallback', 4));
		self::assertSame('Fallback', $this->service->cleanComponent('. . .', 'Fallback'));
	}

	public function testCutsComponentsInBytesBetweenCharacters(): void {
		self::assertSame('äö', $this->service->cleanComponent('äöü', 'Fallback', 5));
		self::assertSame('株', $this->service->cleanComponent('株式会社', 'Fallback', 5));
		self::assertSame(str_repeat('Ж', 100), $this->service->cleanComponent(str_repeat('Ж', 120), 'Fallback'));
	}

	#[RequiresPhpExtension('intl')]
	public function testComposesCharactersAsMacOsAndWindowsExpectThem(): void {
		// "e" with a combining acute accent, as macOS writes names, becomes the one character "é".
		self::assertSame("Caf\u{E9}", $this->service->cleanComponent("Cafe\u{301}", 'Fallback'));
	}

	public function testReplacesEveryControlCharacterAndBrokenText(): void {
		self::assertSame('a_b_c', $this->service->cleanComponent("a\u{85}b\u{9f}c", 'Fallback'));
		self::assertSame('a_b', $this->service->cleanComponent("a\xffb", 'Fallback'));
	}

	/** @return array<string, array{string, string}> */
	public static function windowsDeviceNames(): array {
		return [
			'zero port' => ['COM0', '_COM0'],
			'superscript port' => ['lpt¹.pdf', '_lpt¹.pdf'],
			'console input' => ['CONIN$', '_CONIN$'],
			'space before the extension' => ['nul .txt', '_nul .txt'],
			'longer name' => ['CONSOLE', 'CONSOLE'],
		];
	}

	#[DataProvider('windowsDeviceNames')]
	public function testKeepsWindowsDeviceNamesUsable(string $name, string $cleaned): void {
		self::assertSame($cleaned, $this->service->cleanComponent($name, 'Fallback'));
	}

	/** @param array<string, mixed> $overrides @return array<string, mixed> */
	private function document(array $overrides = []): array {
		return $overrides + [
			'id' => 1,
			'title' => 'Rechnung',
			'correspondent' => 4,
			'document_type' => 7,
			'created' => '2026-08-26',
			'original_file_name' => 'scan.pdf',
			'mime_type' => 'application/pdf',
		];
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
