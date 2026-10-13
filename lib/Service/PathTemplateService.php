<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\SyncConfig;

final class PathTemplateService {
	public const DEFAULT_TEMPLATE = '{{ correspondent }}/{{ document_type }}/{{ created_year }}/{{ created }} - {{ title }} [P{{ id }}]{{ extension }}';

	/** The longest folder name in bytes; Nextcloud accepts 250. */
	private const MAX_FOLDER_BYTES = 200;

	/**
	 * The longest file name in bytes: Nextcloud accepts 250, and the temporary name of a
	 * write, ".{name}.paperless-{16 hex digits}.part" in NextcloudStorageService, is 33 longer.
	 */
	private const MAX_FILENAME_BYTES = 217;

	private const PLACEHOLDER = '/{{\s*([a-z_]+)\s*}}/';

	/** The names of devices, which Windows doesn't allow as the name of a file or folder, with any extension. */
	private const WINDOWS_RESERVED = [
		'CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$',
		'COM0', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'COM¹', 'COM²', 'COM³',
		'LPT0', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9', 'LPT¹', 'LPT²', 'LPT³',
	];

	private const VARIABLES = [
		'id', 'title', 'correspondent', 'document_type', 'storage_path',
		'created', 'created_year', 'created_month', 'added', 'added_year',
		'original_filename', 'extension',
	];

	/** The values that are shortened when a name would be too long; the dates, the ID and the extension stay whole. */
	private const FREE_TEXT = ['title', 'correspondent', 'document_type', 'storage_path', 'original_filename'];

	public function validateTemplate(string $template): string {
		$normalized = trim(str_replace('\\', '/', $template), " /\t\n\r\0\x0B");
		if ($normalized === '') {
			throw new InvalidArgumentException('The archive path template must not be empty.');
		}
		if (preg_match('/{{\s*id\s*}}/', $normalized) !== 1) {
			throw new InvalidArgumentException('The archive path template must contain {{ id }}.');
		}

		preg_match_all(self::PLACEHOLDER, $normalized, $matches);
		foreach ($matches[1] as $variable) {
			if (!in_array($variable, self::VARIABLES, true)) {
				throw new InvalidArgumentException('Unknown archive path variable: {{ ' . $variable . ' }}.');
			}
		}
		if (preg_match('/{{|}}/', preg_replace(self::PLACEHOLDER, '', $normalized) ?? '') === 1) {
			throw new InvalidArgumentException('The archive path template contains an invalid placeholder.');
		}

		// One spelling of every placeholder, such as {{ id }} for {{id}}.
		return preg_replace(self::PLACEHOLDER, '{{ $1 }}', $normalized) ?? $normalized;
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
	 */
	public function render(
		SyncConfig $config,
		array $document,
		array $correspondents,
		array $documentTypes,
		array $storagePaths,
	): string {
		/** @psalm-suppress MixedAssignment */
		$id = isset($document['id']) ? (string)$document['id'] : '';
		if ($id === '' || preg_match('/^\d+$/', $id) !== 1) {
			throw new InvalidArgumentException('Paperless returned a document without a numeric ID.');
		}

		$created = $this->datePart($document['created'] ?? null);
		$added = $this->datePart($document['added'] ?? null);
		/** @psalm-suppress MixedAssignment */
		$originalValue = $document['original_file_name'] ?? null;
		$originalFilename = is_string($originalValue) ? $originalValue : '';
		$extension = $this->extension($config, $document, $originalFilename);
		$correspondentId = isset($document['correspondent']) ? (string)$document['correspondent'] : '';
		$documentTypeId = isset($document['document_type']) ? (string)$document['document_type'] : '';
		$storagePathId = isset($document['storage_path']) ? (string)$document['storage_path'] : '';

		/** @var array<string, string> $values */
		$values = [
			'id' => $id,
			'title' => $this->cleanComponent(is_string($document['title'] ?? null) ? (string)$document['title'] : '', $config->untitled),
			'correspondent' => $this->cleanComponent($correspondents[$correspondentId] ?? '', $config->emptyCorrespondent),
			'document_type' => $this->cleanComponent($documentTypes[$documentTypeId] ?? '', $config->emptyDocumentType),
			'storage_path' => $this->cleanComponent($storagePaths[$storagePathId] ?? '', 'Storage'),
			'created' => $created !== '' ? $created : $config->emptyDate,
			'created_year' => $created !== '' ? substr($created, 0, 4) : $config->emptyDate,
			'created_month' => $created !== '' ? substr($created, 5, 2) : $config->emptyDate,
			'added' => $added !== '' ? $added : $config->emptyDate,
			'added_year' => $added !== '' ? substr($added, 0, 4) : $config->emptyDate,
			'original_filename' => $this->cleanComponent(pathinfo($originalFilename, PATHINFO_FILENAME), $config->untitled),
			'extension' => $extension,
		];

		// Each part of the template becomes one name of the path, so a value can't add a folder.
		$templates = explode('/', $config->pathTemplate);
		$fileTemplate = array_pop($templates);
		$components = [];
		foreach ($templates as $template) {
			$components[] = $this->fit($template, $values, 'Folder', self::MAX_FOLDER_BYTES, '');
		}
		// The file name ends with the extension and keeps the marker [P123], which ties the file to its
		// document and which Paperless Unified Search reads; a template without them gets them added.
		$stem = preg_replace('/{{\s*extension\s*}}\z/', '', $fileTemplate) ?? $fileTemplate;
		if (preg_match('/\[P{{\s*id\s*}}\]/', $stem) !== 1) {
			$stem .= ' [P{{ id }}]';
		}
		$components[] = $this->fit($stem, $values, 'Document', self::MAX_FILENAME_BYTES - strlen($extension), "[P{$id}]") . $extension;

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
	 */
	private function fit(string $template, array $values, string $fallback, int $maxBytes, string $marker): string {
		preg_match_all(self::PLACEHOLDER, $template, $matches);
		$lengths = [];
		foreach ($matches[1] as $name) {
			if (in_array($name, self::FREE_TEXT, true)) {
				$lengths[] = strlen($values[$name]);
			}
		}
		$budget = $maxBytes - strlen($this->substitute($template, array_fill_keys(self::FREE_TEXT, '') + $values));
		do {
			$share = $this->fairShare($lengths, $budget);
			$shortened = $values;
			foreach (self::FREE_TEXT as $name) {
				$shortened[$name] = mb_strcut($values[$name], 0, $share, 'UTF-8');
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
		return preg_replace_callback(self::PLACEHOLDER, static fn (array $match): string => $values[$match[1]] ?? $match[0], $template) ?? $template;
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
		if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
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
