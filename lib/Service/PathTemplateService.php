<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IConfig;

/**
 * The path of a document in the archive, from the path template and the metadata of Paperless.
 *
 * @psalm-type CustomField = array{name: string, type: string, options: array<string, string>}
 */
final class PathTemplateService {
	public const DEFAULT_TEMPLATE = '{{ correspondent }}/{{ document_type }}/{{ created_year }}/{{ created }} - {{ title }} [P{{ id }}]{{ extension }}';

	/** The variables of the template, in the order of the guide; custom_fields.<name> comes with them. */
	public const VARIABLES = [
		'id', 'title', 'correspondent', 'document_type', 'storage_path', 'owner_username', 'tag_list', 'asn', 'page_count',
		'created', 'created_year', 'created_year_short', 'created_month', 'created_month_name', 'created_day',
		'added', 'added_year', 'added_month', 'added_day',
		'original_filename', 'extension',
	];

	/** The longest folder name in bytes; Nextcloud accepts 250. */
	private const MAX_FOLDER_BYTES = 200;

	/**
	 * The longest file name in bytes: Nextcloud accepts 250, and the temporary name of a
	 * write, ".{name}.paperless-{16 hex digits}.part" in NextcloudStorageService, is 33 longer.
	 */
	private const MAX_FILENAME_BYTES = 217;

	/**
	 * A placeholder: {{ name }}, or {{ custom_fields.Name }} with the name of a custom field of
	 * Paperless, which may hold any character but the braces and the slash, which separates the
	 * names of the path.
	 */
	private const PLACEHOLDER = '/{{\s*([a-z_]+)(?:\.([^{}\/]*?))?\s*}}/';

	/** The variable of the custom fields, which names its field after a dot. */
	private const CUSTOM_FIELDS = 'custom_fields';

	/** The names of devices, which Windows doesn't allow as the name of a file or folder, with any extension. */
	private const WINDOWS_RESERVED = [
		'CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$',
		'COM0', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'COM¹', 'COM²', 'COM³',
		'LPT0', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9', 'LPT¹', 'LPT²', 'LPT³',
	];

	/**
	 * The values that are shortened when a name would be too long, with those of the custom fields;
	 * the dates, the numbers, the ID and the extension stay whole.
	 */
	private const FREE_TEXT = ['title', 'correspondent', 'document_type', 'storage_path', 'original_filename', 'owner_username', 'tag_list'];

	/** The names of the months without the extension intl of PHP, which knows them in every language. */
	private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

	/** @var array<int, string> the names of the months in the language of Nextcloud, by their numbers */
	private array $monthNames = [];

	/**
	 * $config gives the language of Nextcloud, in which {{ created_month_name }} names the month;
	 * without it, the names are English.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private ?IConfig $config = null,
	) {
	}

	public function validateTemplate(string $template): string {
		$normalized = trim(str_replace('\\', '/', $template), " /\t\n\r\0\x0B");
		if ($normalized === '') {
			throw new InvalidArgumentException('The archive path template must not be empty.');
		}
		if (preg_match('/{{\s*id\s*}}/', $normalized) !== 1) {
			throw new InvalidArgumentException('The archive path template must contain {{ id }}.');
		}

		preg_match_all(self::PLACEHOLDER, $normalized, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$field = $match[2] ?? null;
			if ($match[1] === self::CUSTOM_FIELDS && trim((string)$field) === '') {
				throw new InvalidArgumentException('Name the custom field in the archive path template, such as {{ custom_fields.Invoice number }}.');
			}
			if (!($field === null ? in_array($match[1], self::VARIABLES, true) : $match[1] === self::CUSTOM_FIELDS)) {
				throw new InvalidArgumentException('Unknown archive path variable: {{ ' . $match[1] . ($field === null ? '' : '.' . $field) . ' }}.');
			}
		}
		if (preg_match('/{{|}}/', preg_replace(self::PLACEHOLDER, '', $normalized) ?? '') === 1) {
			throw new InvalidArgumentException('The archive path template contains an invalid placeholder.');
		}

		// One spelling of every placeholder, such as {{ id }} for {{id}}.
		return preg_replace_callback(self::PLACEHOLDER, static fn (array $match): string => '{{ ' . self::key($match) . ' }}', $normalized) ?? $normalized;
	}

	/** Whether the template names the owner of a document, for which Paperless lists its users. */
	public function usesOwner(string $template): bool {
		return in_array('owner_username', $this->keys($template), true);
	}

	/**
	 * The names of the custom fields that the template uses, for which Paperless lists its fields.
	 *
	 * @return list<string>
	 */
	public function customFieldNames(string $template): array {
		$names = [];
		foreach ($this->keys($template) as $key) {
			if (str_starts_with($key, self::CUSTOM_FIELDS . '.')) {
				$names[] = substr($key, strlen(self::CUSTOM_FIELDS) + 1);
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * Fails when the template uses a custom field that Paperless doesn't have, such as one with a
	 * typo or one that was renamed: its value would be empty in the path of every document, which
	 * would move all of them.
	 *
	 * @param array<string, CustomField> $customFields the custom fields of Paperless, by their IDs
	 * @throws InvalidArgumentException
	 */
	public function requireCustomFields(string $template, array $customFields): void {
		$missing = array_values(array_diff($this->customFieldNames($template), array_column($customFields, 'name')));
		if ($missing !== []) {
			throw new InvalidArgumentException('Paperless has no custom field named "' . implode('", "', $missing) . '", which the archive path template uses.');
		}
	}

	public function normalizeRelativePath(string $path, bool $allowEmpty = false): string {
		$normalized = trim(str_replace('\\', '/', $path), " /\t\n\r\0\x0B");
		if ($normalized === '') {
			if ($allowEmpty) {
				return '';
			}
			throw new InvalidArgumentException('A relative folder path is required.');
		}
		$parts = explode('/', $normalized);
		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				throw new InvalidArgumentException('Folder paths must be relative and must not contain empty, . or .. components.');
			}
		}

		return implode('/', $parts);
	}

	public function normalizeFolderName(string $name): string {
		$normalized = $this->normalizeRelativePath($name);
		if (str_contains($normalized, '/')) {
			throw new InvalidArgumentException('Folder names must contain exactly one path component.');
		}

		return $this->cleanComponent($normalized, 'Folder');
	}

	/**
	 * @param array<string, mixed> $document
	 * @param array<string, string> $correspondents
	 * @param array<string, string> $documentTypes
	 * @param array<string, string> $storagePaths
	 * @param array<string, string> $tags the names of the tags, by their IDs
	 * @param array<string, string> $users the names of the users, by their IDs, when the template names the owner
	 * @param array<string, CustomField> $customFields the custom fields, by their IDs, when the template uses one
	 * @throws InvalidArgumentException when the document has no numeric ID, or Paperless lacks a custom field of the template
	 */
	public function render(
		SyncConfig $config,
		array $document,
		array $correspondents,
		array $documentTypes,
		array $storagePaths,
		array $tags = [],
		array $users = [],
		array $customFields = [],
	): string {
		/** @psalm-suppress MixedAssignment */
		$id = isset($document['id']) ? (string)$document['id'] : '';
		// \z and not $, which also matches before a final line break: "5\n" is no ID.
		if ($id === '' || preg_match('/^\d+\z/', $id) !== 1) {
			throw new InvalidArgumentException('Paperless returned a document without a numeric ID.');
		}
		$this->requireCustomFields($config->pathTemplate, $customFields);

		$created = $this->datePart($document['created'] ?? null);
		$added = $this->datePart($document['added'] ?? null);
		/** @psalm-suppress MixedAssignment */
		$originalValue = $document['original_file_name'] ?? null;
		$originalFilename = is_string($originalValue) ? $originalValue : '';
		$extension = $this->extension($config, $document, $originalFilename);
		$correspondentId = isset($document['correspondent']) ? (string)$document['correspondent'] : '';
		$documentTypeId = isset($document['document_type']) ? (string)$document['document_type'] : '';
		$storagePathId = isset($document['storage_path']) ? (string)$document['storage_path'] : '';
		$ownerId = isset($document['owner']) ? (string)$document['owner'] : '';

		// A value that a document doesn't have, such as an owner, a tag or a custom field, is empty;
		// a name that only it would fill takes the fallback of its kind, Folder or Document.
		/** @var array<string, string> $values */
		$values = [
			'id' => $id,
			'title' => $this->cleanComponent(is_string($document['title'] ?? null) ? (string)$document['title'] : '', $config->untitled),
			'correspondent' => $this->cleanComponent($correspondents[$correspondentId] ?? '', $config->emptyCorrespondent),
			'document_type' => $this->cleanComponent($documentTypes[$documentTypeId] ?? '', $config->emptyDocumentType),
			'storage_path' => $this->cleanComponent($storagePaths[$storagePathId] ?? '', 'Storage'),
			'owner_username' => $this->cleanComponent($users[$ownerId] ?? '', ''),
			'tag_list' => $this->cleanComponent($this->tagList($document, $tags), ''),
			'asn' => $this->number($document['archive_serial_number'] ?? null),
			'page_count' => $this->number($document['page_count'] ?? null),
			'created' => $created !== '' ? $created : $config->emptyDate,
			'created_year' => $created !== '' ? substr($created, 0, 4) : $config->emptyDate,
			'created_year_short' => $created !== '' ? substr($created, 2, 2) : $config->emptyDate,
			'created_month' => $created !== '' ? substr($created, 5, 2) : $config->emptyDate,
			'created_month_name' => $created !== '' ? $this->monthName((int)substr($created, 5, 2)) : $config->emptyDate,
			'created_day' => $created !== '' ? substr($created, 8, 2) : $config->emptyDate,
			'added' => $added !== '' ? $added : $config->emptyDate,
			'added_year' => $added !== '' ? substr($added, 0, 4) : $config->emptyDate,
			'added_month' => $added !== '' ? substr($added, 5, 2) : $config->emptyDate,
			'added_day' => $added !== '' ? substr($added, 8, 2) : $config->emptyDate,
			'original_filename' => $this->cleanComponent(pathinfo($originalFilename, PATHINFO_FILENAME), $config->untitled),
			'extension' => $extension,
		];
		$freeText = self::FREE_TEXT;
		foreach ($this->customFieldNames($config->pathTemplate) as $name) {
			$key = self::CUSTOM_FIELDS . '.' . $name;
			$values[$key] = $this->cleanComponent($this->customFieldValue($document, $name, $customFields), '');
			$freeText[] = $key;
		}

		// Each part of the template becomes one name of the path, so a value can't add a folder.
		$templates = explode('/', $config->pathTemplate);
		$fileTemplate = array_pop($templates);
		$components = [];
		foreach ($templates as $template) {
			$components[] = $this->fit($template, $values, $freeText, 'Folder', self::MAX_FOLDER_BYTES, '');
		}
		// The file name ends with the extension and keeps the marker [P123], which ties the file to its
		// document and which Paperless Unified Search reads; a template without them gets them added.
		$stem = preg_replace('/{{\s*extension\s*}}\z/', '', $fileTemplate) ?? $fileTemplate;
		if (preg_match('/\[P{{\s*id\s*}}\]/', $stem) !== 1) {
			$stem .= ' [P{{ id }}]';
		}
		$components[] = $this->fit($stem, $values, $freeText, 'Document', self::MAX_FILENAME_BYTES - strlen($extension), "[P{$id}]") . $extension;

		return implode('/', $components);
	}

	public function cleanComponent(string $value, string $fallback, int $maxBytes = self::MAX_FOLDER_BYTES): string {
		$text = mb_scrub($value, 'UTF-8');
		// Normalizer comes with the extension intl, so there is nothing to autoload.
		/**
		 * @psalm-suppress InternalMethod
		 * @psalm-suppress MixedAssignment
		 */
		$normalized = class_exists(\Normalizer::class, false) ? \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;
		$text = is_string($normalized) ? $normalized : $text;
		// Control characters, and the characters that Windows, macOS or Nextcloud don't allow in a name.
		$text = preg_replace('/[\p{Cc}<>:"\/\\\\|?*]/u', '_', $text) ?? $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		// Without dots at the ends, . and .. become empty and take the fallback.
		$text = trim($text, ' .');
		if ($text === '') {
			$text = $fallback;
		}
		$stem = strtoupper(rtrim(explode('.', $text, 2)[0], ' '));
		if (in_array($stem, self::WINDOWS_RESERVED, true)) {
			$text = '_' . $text;
		}
		$text = rtrim(mb_strcut($text, 0, $maxBytes, 'UTF-8'), ' .');

		return $text !== '' ? $text : $fallback;
	}

	/**
	 * Renders one name of the path in at most $maxBytes bytes. It shortens only the free-text
	 * values, the longest first, so that the text of the template, the dates, the ID and the
	 * marker stay whole. Only when they alone are too long does it cut the name itself, and a
	 * file name then keeps its marker at its end.
	 *
	 * @param array<string, string> $values
	 * @param list<string> $freeText the keys of the values that may be shortened
	 */
	private function fit(string $template, array $values, array $freeText, string $fallback, int $maxBytes, string $marker): string {
		$lengths = [];
		foreach ($this->keys($template) as $key) {
			if (in_array($key, $freeText, true)) {
				$lengths[] = strlen($values[$key]);
			}
		}
		$budget = $maxBytes - strlen($this->substitute($template, array_fill_keys($freeText, '') + $values));
		do {
			$share = $this->fairShare($lengths, $budget);
			$shortened = $values;
			foreach ($freeText as $key) {
				$shortened[$key] = mb_strcut($values[$key], 0, $share, 'UTF-8');
			}
			$component = $this->cleanComponent($this->substitute($template, $shortened), $fallback, PHP_INT_MAX);
			// Cleaning can make the name a little longer, such as "_" before a name of Windows.
			$excess = strlen($component) - $maxBytes;
			$budget -= $excess;
		} while ($excess > 0 && $share > 0);

		// A name that fits stays as it is; one whose template text alone is too long is cut.
		$component = $this->cleanComponent($component, $fallback, $maxBytes);
		if ($marker !== '' && !str_contains($component, $marker)) {
			$component = $this->cleanComponent($component, $fallback, $maxBytes - strlen($marker) - 1) . ' ' . $marker;
		}

		return $component;
	}

	/**
	 * The bytes that each value may keep so that all of them fit into $budget bytes together:
	 * the values shorter than their share stay whole, and the longer ones share the rest equally.
	 *
	 * @param list<int> $lengths
	 */
	private function fairShare(array $lengths, int $budget): int {
		sort($lengths);
		$remaining = count($lengths);
		foreach ($lengths as $length) {
			if ($length * $remaining > $budget) {
				return intdiv(max(0, $budget), $remaining);
			}
			$budget -= $length;
			--$remaining;
		}

		return max([0, ...$lengths]);
	}

	/**
	 * Puts the values into the template in one pass and as plain text, so that "$1" or
	 * "{{ title }}" in a title stays as it is.
	 *
	 * @param array<string, string> $values
	 */
	private function substitute(string $template, array $values): string {
		return preg_replace_callback(self::PLACEHOLDER, static fn (array $match): string => $values[self::key($match)] ?? $match[0], $template) ?? $template;
	}

	/**
	 * The keys of the placeholders of a template, in their order: the names of the variables, and
	 * custom_fields.<name> for a custom field.
	 *
	 * @return list<string>
	 */
	private function keys(string $template): array {
		preg_match_all(self::PLACEHOLDER, $template, $matches, PREG_SET_ORDER);

		return array_map(self::key(...), $matches);
	}

	/** @param array<array-key, string> $match a match of PLACEHOLDER */
	private static function key(array $match): string {
		return isset($match[2]) ? self::CUSTOM_FIELDS . '.' . trim($match[2]) : $match[1];
	}

	/**
	 * The name of a month in the language of Nextcloud, the same in every run: the language that
	 * Nextcloud forces or has as its default, never the one of the administrator who starts a run,
	 * which would move the files between the runs of two of them. ICU's standalone form, "LLLL",
	 * names the month on its own, as in "März" or "март".
	 */
	private function monthName(int $month): string {
		if (!isset($this->monthNames[$month])) {
			$english = self::MONTHS[$month - 1] ?? sprintf('%02d', $month);
			// IntlDateFormatter comes with the extension intl, so there is nothing to autoload.
			$formatter = class_exists(\IntlDateFormatter::class, false) && isset(self::MONTHS[$month - 1])
				? new \IntlDateFormatter($this->language(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL')
				: null;
			$name = $formatter?->format(new \DateTimeImmutable(sprintf('2000-%02d-01T12:00:00Z', $month)));
			$this->monthNames[$month] = $this->cleanComponent(is_string($name) ? $name : $english, $english);
		}

		return $this->monthNames[$month];
	}

	/** The language of Nextcloud for everyone: the one that it forces, or else its default one, or else English. */
	private function language(): string {
		/** @psalm-suppress MixedAssignment */
		$forced = $this->config?->getSystemValue('force_language', false);
		if (is_string($forced) && $forced !== '') {
			return $forced;
		}
		$default = (string)$this->config?->getSystemValueString('default_language', '');

		return $default !== '' ? $default : 'en';
	}

	/** A number of Paperless, such as the archive serial number, or nothing when the document has none. */
	private function number(mixed $value): string {
		return is_int($value) || (is_string($value) && preg_match('/^\d+\z/', $value) === 1) ? (string)$value : '';
	}

	/**
	 * The names of the tags of a document, sorted and separated by commas, as Paperless writes
	 * its own {{ tag_list }}.
	 *
	 * @param array<string, mixed> $document
	 * @param array<string, string> $tags
	 * @psalm-suppress MixedAssignment
	 */
	private function tagList(array $document, array $tags): string {
		$names = [];
		$rawTags = $document['tags'] ?? null;
		foreach (is_array($rawTags) ? $rawTags : [] as $tag) {
			$tagId = is_array($tag) ? ($tag['id'] ?? null) : $tag;
			if (is_scalar($tagId) && isset($tags[(string)$tagId])) {
				$names[] = $tags[(string)$tagId];
			}
		}
		sort($names, SORT_STRING);

		return implode(',', $names);
	}

	/**
	 * The value of a custom field of a document as text: the choice of a select field by its label,
	 * the documents of a link field by their IDs, a yes or no as true or false, and nothing when the
	 * document has no value.
	 *
	 * @param array<string, mixed> $document
	 * @param array<string, CustomField> $customFields
	 * @psalm-suppress MixedAssignment
	 */
	private function customFieldValue(array $document, string $name, array $customFields): string {
		$instances = $document['custom_fields'] ?? null;
		foreach (is_array($instances) ? $instances : [] as $instance) {
			$fieldId = is_array($instance) ? ($instance['field'] ?? null) : null;
			$definition = is_scalar($fieldId) ? ($customFields[(string)$fieldId] ?? null) : null;
			if (!is_array($instance) || $definition === null || $definition['name'] !== $name) {
				continue;
			}
			$value = $instance['value'] ?? null;
			if ($definition['type'] === 'select') {
				return is_scalar($value) ? ($definition['options'][(string)$value] ?? '') : '';
			}

			return match (true) {
				is_bool($value) => $value ? 'true' : 'false',
				is_scalar($value) => (string)$value,
				is_array($value) => implode(',', array_map(strval(...), array_filter($value, is_scalar(...)))),
				default => '',
			};
		}

		return '';
	}

	/** @param mixed $value */
	private function datePart(mixed $value): string {
		if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
			return '';
		}

		return $matches[1];
	}

	/** @param array<string, mixed> $document */
	private function extension(SyncConfig $config, array $document, string $originalFilename): string {
		/** @psalm-suppress MixedAssignment */
		$archiveValue = $document['archived_file_name'] ?? null;
		$archiveName = is_string($archiveValue) ? $archiveValue : '';
		$filename = $config->preferArchive && $archiveName !== '' ? $archiveName : $originalFilename;
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		// \z and not $, which also matches before a final line break: the extension goes into the name uncleaned.
		if (preg_match('/^[a-z0-9]{1,10}\z/', $extension) !== 1) {
			/** @psalm-suppress MixedAssignment */
			$mimeValue = $document['mime_type'] ?? null;
			$mime = is_string($mimeValue) ? strtolower($mimeValue) : '';
			$extension = match ($mime) {
				'application/pdf' => 'pdf',
				'image/jpeg' => 'jpg',
				'image/png' => 'png',
				'image/tiff' => 'tiff',
				default => 'bin',
			};
		}

		return '.' . $extension;
	}
}
