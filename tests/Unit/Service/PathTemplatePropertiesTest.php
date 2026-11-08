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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Properties of the paths over random templates and documents: names in any script, with
 * combining marks, emoji, control characters, slashes, dots, the names of Windows devices
 * and the syntax of regular expressions. Every case has its own seed, so that a failure
 * names the command that replays it alone. The checks run 500 cases of fixed seeds;
 * PROPERTY_SEED and PROPERTY_RUNS choose others:
 *
 *     PROPERTY_SEED=20261013 PROPERTY_RUNS=5000 vendor/bin/phpunit --group property
 */
#[Group('property')]
final class PathTemplatePropertiesTest extends TestCase {
	private const VARIABLES = [
		'id', 'doc_pk', 'title', 'correspondent', 'document_type', 'storage_path',
		'created', 'created_year', 'created_month', 'created_month_name_short',
		'added', 'added_year', 'added_year_short', 'added_month_name', 'added_month_name_short',
		'original_filename', 'original_name', 'extension',
	];

	/** Custom fields in both spellings, with spaces, quotes and a default, for the templates that are only validated. */
	private const CUSTOM_FIELD_SPELLINGS = [
		'custom_fields.Invoice number', 'custom_fields. Größe (m²) ', "custom_fields|get_cf_value('Kunde')",
		'custom_fields | get_cf_value( "Nr" , "none" )', 'custom_fields|get_cf_value("Kunde\'s Nr",\'Kein "Wert"\')',
	];

	/**
	 * The variables of the templates that people saved before the variables of the file names of
	 * Paperless came (#385), with two custom fields in the spelling of a dot. Like the generator of
	 * the cases, this list belongs to PathTemplatePathsBefore.json: change neither.
	 */
	private const VARIABLES_BEFORE = [
		'id', 'title', 'correspondent', 'document_type', 'storage_path', 'owner_username', 'tag_list', 'asn', 'page_count',
		'created', 'created_year', 'created_year_short', 'created_month', 'created_month_name', 'created_day',
		'added', 'added_year', 'added_month', 'added_day',
		'original_filename', 'extension', 'custom_fields.Invoice number', 'custom_fields. Priority ',
	];

	/** The paths of the templates of before, by the seeds of their cases. */
	private const PATHS_BEFORE = __DIR__ . '/PathTemplatePathsBefore.json';

	private PathTemplateService $service;

	protected function setUp(): void {
		$this->service = new PathTemplateService();
	}

	public function testEveryPathConsistsOfNamesThatNextcloudAccepts(): void {
		foreach ($this->cases() as $seed => $random) {
			[$config, $document, $correspondents, $documentTypes, $storagePaths] = $this->scenario($random);
			$path = $this->service->render($config, $document, $correspondents, $documentTypes, $storagePaths);

			$names = explode('/', $path);
			$problems = count($names) === count(explode('/', $config->pathTemplate)) ? [] : ['A value added or removed a folder.'];
			$fileName = (string)array_pop($names);
			foreach ($names as $name) {
				$problems = [...$problems, ...$this->problems($name, 200)];
			}
			$problems = [...$problems, ...$this->problems($fileName, 217)];

			$this->assertNoProblems($problems, $seed, ['template' => $config->pathTemplate, 'document' => $document, 'path' => $path]);
		}
	}

	public function testEveryFileNameKeepsTheMarkerAndEndsWithTheExtension(): void {
		foreach ($this->cases() as $seed => $random) {
			[$config, $document, $correspondents, $documentTypes, $storagePaths] = $this->scenario($random);
			$path = $this->service->render($config, $document, $correspondents, $documentTypes, $storagePaths);
			$marker = '[P' . (string)$document['id'] . ']';
			// The template of the marker and the extension alone gives the extension of the document.
			$extension = substr($this->service->render($this->config('[P{{ id }}]{{ extension }}', $config->preferArchive), $document, [], [], []), strlen($marker));

			$problems = [];
			if (preg_match('/^\.[a-z0-9]{1,10}\z/', $extension) !== 1) {
				$problems[] = "The extension {$extension} is not one.";
			}
			if (!str_ends_with($path, $extension)) {
				$problems[] = "The path doesn't end with {$extension}.";
			}
			if (!str_contains(basename($path), $marker)) {
				$problems[] = "The file name lacks {$marker}.";
			}
			$this->assertNoProblems($problems, $seed, ['template' => $config->pathTemplate, 'document' => $document, 'path' => $path]);
		}
	}

	public function testDocumentsWithTheSameMetadataGetDifferentPaths(): void {
		foreach ($this->cases() as $seed => $random) {
			[$config, $document, $correspondents, $documentTypes, $storagePaths] = $this->scenario($random);
			$other = ['id' => $random->getInt(1, PHP_INT_MAX)] + $document;
			$path = $this->service->render($config, $document, $correspondents, $documentTypes, $storagePaths);
			$otherPath = $this->service->render($config, $other, $correspondents, $documentTypes, $storagePaths);

			$problems = $path === $otherPath && (string)$other['id'] !== (string)$document['id'] ? ["Another document has the path {$path}."] : [];
			$this->assertNoProblems($problems, $seed, ['template' => $config->pathTemplate, 'document' => $document, 'other ID' => $other['id']]);
		}
	}

	/**
	 * A template that people saved before keeps every path byte for byte, so that a new variable
	 * or spelling moves no file of an archive. PathTemplatePathsBefore.json holds the SHA-256 of
	 * the saved template and the path of 500 random templates of the variables of before, with
	 * documents that have a value for each or none, as the code before #385 rendered them. A
	 * change that moves paths on purpose writes the file anew, says so in its changelog and
	 * moves the files:
	 *
	 *     PATHS_BEFORE=write vendor/bin/phpunit --filter testTemplatesOfBeforeKeepTheirPaths
	 */
	#[RequiresPhpExtension('intl')]
	public function testTemplatesOfBeforeKeepTheirPaths(): void {
		if (getenv('PATHS_BEFORE') === 'write') {
			$hashes = [];
			for ($seed = 1; $seed <= 500; ++$seed) {
				$hashes[(string)$seed] = $this->pathOfBefore($seed)['hash'];
			}
			file_put_contents(self::PATHS_BEFORE, json_encode($hashes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
		}
		/** @var array<string, string> $before */
		$before = json_decode((string)file_get_contents(self::PATHS_BEFORE), true, 2, JSON_THROW_ON_ERROR);
		self::assertCount(500, $before);
		foreach ($before as $seed => $hash) {
			$case = $this->pathOfBefore((int)$seed);
			$problems = $case['hash'] === $hash ? [] : ["The saved template or the path of the case of seed {$seed} differs from the one of before."];
			unset($case['hash']);
			$this->assertNoProblems($problems, (int)$seed, $case, 'vendor/bin/phpunit --filter testTemplatesOfBeforeKeepTheirPaths');
		}
	}

	public function testCleaningAgainChangesNothing(): void {
		foreach ($this->cases() as $seed => $random) {
			$value = $this->text($random, 12);
			$maxBytes = $random->getInt(12, 300);
			$cleaned = $this->service->cleanComponent($value, 'Fallback', $maxBytes);

			$problems = $this->problems($cleaned, $maxBytes);
			if ($this->service->cleanComponent($cleaned, 'Fallback', $maxBytes) !== $cleaned) {
				$problems[] = 'Cleaning the name again changes it.';
			}
			$this->assertNoProblems($problems, $seed, ['value' => $value, 'maximum' => $maxBytes, 'cleaned' => $cleaned]);
		}
	}

	public function testAValidatedTemplateIsValidAndStaysTheSame(): void {
		$accepted = 0;
		foreach ($this->cases() as $seed => $random) {
			$template = $this->template($random, true, [...self::VARIABLES, ...self::CUSTOM_FIELD_SPELLINGS]);
			try {
				$validated = $this->service->validateTemplate($template);
			} catch (InvalidArgumentException) {
				continue;
			}
			++$accepted;

			$problems = [];
			if ($this->service->validateTemplate($validated) !== $validated) {
				$problems[] = 'Validating the template again changes it.';
			}
			if (preg_match('/{{(?! [a-z_]+ }}| custom_fields\.[^{}\/]+ }}| custom_fields\|get_cf_value\(([\'"])[^{}\/]*?\1(?:, ([\'"])[^{}\/]*?\2)?\) }})/', $validated) === 1) {
				$problems[] = "A placeholder of {$validated} has another spelling than {{ name }}, {{ custom_fields.Name }} or {{ custom_fields|get_cf_value(\"Name\", \"default\") }}.";
			}
			$this->assertNoProblems($problems, $seed, ['template' => $template]);
		}
		self::assertGreaterThan(0, $accepted, 'No random template was valid; the generator needs a look.');
	}

	/** @return list<string> */
	private function problems(string $name, int $maxBytes): array {
		$problems = [];
		if (!mb_check_encoding($name, 'UTF-8')) {
			return ['A name is not UTF-8.'];
		}
		if (in_array($name, ['', '.', '..'], true)) {
			$problems[] = "The name '{$name}' is not one.";
		}
		if (strlen($name) > $maxBytes) {
			$problems[] = 'The name ' . $name . ' has ' . strlen($name) . " bytes, more than {$maxBytes}.";
		}
		if (preg_match('/[\p{Cc}<>:"\/\\\\|?*]/u', $name) === 1) {
			$problems[] = "The name {$name} has a character that a system rejects.";
		}
		if (preg_match('/^[ .]|[ .]$/', $name) === 1) {
			$problems[] = "The name {$name} starts or ends with a space or a dot.";
		}
		if (preg_match('/^(CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|COM[0-9¹²³]|LPT[0-9¹²³]) *(\.|$)/iu', $name) === 1) {
			$problems[] = "The name {$name} is a device of Windows.";
		}

		return $problems;
	}

	/**
	 * One assertion for each case, whose message tells how to replay it.
	 *
	 * @param list<string> $problems
	 * @param array<string, mixed> $case
	 * @param ?string $replay the command that replays the case, when PROPERTY_SEED doesn't choose it
	 */
	private function assertNoProblems(array $problems, int $seed, array $case, ?string $replay = null): void {
		if ($problems === []) {
			$this->addToAssertionCount(1);
			return;
		}
		$json = json_encode($case, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		$replay ??= "PROPERTY_SEED={$seed} PROPERTY_RUNS=1 vendor/bin/phpunit --group property";
		self::fail(implode("\n", $problems) . "\nReplay: {$replay}\nCase: {$json}");
	}

	/**
	 * A random generator for each case, by its seed.
	 *
	 * @return \Generator<int, Randomizer>
	 */
	private function cases(): \Generator {
		$first = self::setting('PROPERTY_SEED', 1);
		$runs = self::setting('PROPERTY_RUNS', 500);
		for ($seed = $first; $seed < $first + $runs; ++$seed) {
			yield $seed => new Randomizer(new Mt19937($seed));
		}
	}

	private static function setting(string $name, int $default): int {
		$value = getenv($name);
		if ($value === false || $value === '') {
			return $default;
		}
		if (preg_match('/^\d{1,9}\z/', $value) !== 1) {
			self::fail("{$name} must be a whole number of at most nine digits, not {$value}.");
		}

		return (int)$value;
	}

	/**
	 * The path of a case of the templates of before, with its saved template, its document and
	 * the SHA-256 of both.
	 *
	 * @return array{template: string, document: array<string, mixed>, path: string, hash: string}
	 */
	private function pathOfBefore(int $seed): array {
		$random = new Randomizer(new Mt19937($seed));
		[$config, $document, $correspondents, $documentTypes, $storagePaths] = $this->scenario($random, self::VARIABLES_BEFORE);
		$document += [
			'owner' => $this->pick($random, [3, '3', null, 8]),
			'tags' => $this->pick($random, [[], [10, 12], [['id' => 12], 99], null]),
			'archive_serial_number' => $this->pick($random, [1042, '17', null, '2 pages']),
			'page_count' => $this->pick($random, [2, '0', null]),
			'custom_fields' => [
				['field' => 1, 'value' => $this->pick($random, [null, $this->text($random, 6), 42, true, [12, 13]])],
				['field' => 2, 'value' => $this->pick($random, ['aB3', 'gone', null])],
			],
		];
		$tags = ['10' => $this->text($random, 6), '12' => $this->text($random, 6)];
		$users = ['3' => $this->text($random, 6)];
		$customFields = [
			'1' => ['name' => 'Invoice number', 'type' => 'string', 'options' => []],
			'2' => ['name' => 'Priority', 'type' => 'select', 'options' => ['aB3' => $this->text($random, 4)]],
		];
		$path = $this->service->render($config, $document, $correspondents, $documentTypes, $storagePaths, $tags, $users, $customFields);

		return ['template' => $config->pathTemplate, 'document' => $document, 'path' => $path, 'hash' => hash('sha256', $config->pathTemplate . "\0" . $path)];
	}

	/**
	 * @param list<string> $variables
	 * @return array{SyncConfig, array<string, mixed>, array<string, string>, array<string, string>, array<string, string>}
	 */
	private function scenario(Randomizer $random, array $variables = self::VARIABLES): array {
		$template = $this->service->validateTemplate($this->template($random, false, $variables));
		$id = $random->getInt(1, PHP_INT_MAX);
		$document = [
			'id' => $random->getInt(0, 1) === 1 ? $id : (string)$id,
			'title' => $random->getInt(0, 9) === 0 ? null : $this->text($random, 20),
			'correspondent' => $this->pick($random, [1, '1', null, 99]),
			'document_type' => $this->pick($random, [2, null]),
			'storage_path' => $this->pick($random, [3, null]),
			'created' => $this->pick($random, ['2026-08-26', '2026-08-26T09:00:00+02:00', '0001-01-01', 'yesterday', null, 20260826]),
			'added' => $this->pick($random, ['2026-09-01', '1999-12-31T23:59:59Z', '', null]),
			'original_file_name' => $random->getInt(0, 9) === 0 ? null : $this->text($random, 8) . $this->pick($random, ['.pdf', '.PDF', '.jpeg', '.ti-f', '.', '', ".pdf\n", ".txt\t", '.' . $this->text($random, 2)]),
			'archived_file_name' => $this->pick($random, [null, '', 'archive.pdf', $this->text($random, 4) . '.PDF', "archive.pdf\n"]),
			'mime_type' => $this->pick($random, ['application/pdf', 'image/jpeg', 'IMAGE/PNG', 'image/tiff', 'text/plain', null]),
		];
		$fallback = fn (): string => $this->service->cleanComponent($this->text($random, 6), 'Unknown');

		return [
			$this->config($template, $random->getInt(0, 1) === 1, $fallback(), $fallback(), $fallback(), $fallback()),
			$document,
			['1' => $this->text($random, 20), '99' => ''],
			['2' => $this->text($random, 20)],
			['3' => $this->text($random, 20)],
		];
	}

	/**
	 * A template of one to six parts with variables in every spelling, {{ id }} among them unless $loose.
	 *
	 * @param list<string> $variables
	 */
	private function template(Randomizer $random, bool $loose, array $variables = self::VARIABLES): string {
		$parts = [];
		for ($count = $random->getInt(1, 6); $count > 0; --$count) {
			$part = '';
			for ($pieces = $random->getInt(0, 4); $pieces > 0; --$pieces) {
				$part .= $random->getInt(0, 1) === 1 ? $this->placeholder($random, $this->pick($random, $variables)) : $this->literal($random, $loose);
			}
			$parts[] = $part;
		}
		if (!$loose || $random->getInt(0, 3) > 0) {
			$id = $this->placeholder($random, 'id');
			$parts[$random->getInt(0, count($parts) - 1)] .= $this->pick($random, [" [P{$id}]", "[P{$id}]", $id, " {$id}"]);
		}

		return implode($this->pick($random, ['/', '\\']), $parts);
	}

	private function placeholder(Randomizer $random, string $variable): string {
		$space = fn (): string => $this->pick($random, ['', ' ', ' ', '  ', "\t"]);

		return '{{' . $space() . $variable . $space() . '}}';
	}

	private function literal(Randomizer $random, bool $loose): string {
		$text = $this->text($random, 3);

		return $loose ? $text : str_replace(['{', '}'], ['(', ')'], $text);
	}

	private function text(Randomizer $random, int $maxPieces): string {
		$text = '';
		for ($pieces = $random->getInt(0, $maxPieces); $pieces > 0; --$pieces) {
			$text .= $this->piece($random);
		}

		return $text;
	}

	private function piece(Randomizer $random): string {
		return match ($random->getInt(0, 12)) {
			0, 1 => $this->pick($random, ['Rechnung', 'Invoice 2026', 'a', 'Stadtwerke GmbH', '-', ' - ', '_', '(1)', '[P7]', '%s', "l'été"]),
			2 => mb_chr($random->getInt(0x400, 0x4FF), 'UTF-8'),
			3 => mb_chr($random->getInt(0x4E00, 0x9FFF), 'UTF-8'),
			4 => $this->pick($random, ['😀', '👍🏽', '👨‍👩‍👧', '🇩🇪', '❤️']),
			5 => $this->pick($random, ['e', 'a', 'क', 'ᄀ']) . mb_chr($random->getInt(0x300, 0x36F), 'UTF-8') . $this->pick($random, ['', "\u{93F}", "\u{1161}"]),
			6 => mb_chr($this->pick($random, [$random->getInt(0x0, 0x1F), 0x7F, $random->getInt(0x80, 0x9F)]), 'UTF-8'),
			7 => $this->pick($random, ['/', '\\', '.', '..', '...', ' ', '   ', "\u{A0}", "\u{3000}", "\u{2028}", "\t", "\n"]),
			8 => $this->pick($random, ['<', '>', ':', '"', '|', '?', '*']),
			9 => $this->pick($random, ['CON', 'prn', 'Aux', 'nul.txt', 'COM1', 'lpt9', 'COM0', 'LPT¹', 'CONIN$', 'conout$', 'con .txt']),
			10 => $this->pick($random, ['$0', '$1', '${1}', '$10', '\\1', '\\\\', '{{ title }}', '{{id}}', '{{', '}}', '{{ owner }}']),
			11 => str_repeat($this->pick($random, ['x', 'Ж', '株', '😀', ' ', '.']), $random->getInt(40, 160)),
			default => $this->pick($random, ["\xff", "\xc3", "\xe2\x82", "\xf0\x9f\x98"]),
		};
	}

	/**
	 * @template T
	 * @param list<T> $choices
	 * @return T
	 */
	private function pick(Randomizer $random, array $choices): mixed {
		return $choices[$random->getInt(0, count($choices) - 1)];
	}

	private function config(string $template, bool $preferArchive, string $emptyCorrespondent = '_Ohne Korrespondent', string $emptyDocumentType = '_Ohne Dokumenttyp', string $emptyDate = '_Ohne Datum', string $untitled = 'Ohne Titel'): SyncConfig {
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
			$emptyCorrespondent,
			$emptyDocumentType,
			$emptyDate,
			$untitled,
		);
	}
}
