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

	private const WINDOWS_RESERVED = [
		'CON', 'PRN', 'AUX', 'NUL',
		'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
		'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
	];

	private const VARIABLES = [
		'id', 'title', 'correspondent', 'document_type', 'storage_path',
		'created', 'created_year', 'created_month', 'added', 'added_year',
		'original_filename', 'extension',
	];

	public function validateTemplate(string $template): string {
		$normalized = trim(str_replace('\\', '/', $template), " /\t\n\r\0\x0B");
		if ($normalized === '') {
			throw new InvalidArgumentException('The archive path template must not be empty.');
		}
		if (!str_contains($normalized, '{{ id }}')) {
			throw new InvalidArgumentException('The archive path template must contain {{ id }}.');
		}

		preg_match_all('/{{\s*([a-z_]+)\s*}}/', $normalized, $matches);
		foreach ($matches[1] as $variable) {
			if (!in_array($variable, self::VARIABLES, true)) {
				throw new InvalidArgumentException("Unknown archive path variable: {{$variable}}.");
			}
		}
		if (preg_match('/{{|}}/', preg_replace('/{{\s*[a-z_]+\s*}}/', '', $normalized) ?? '') === 1) {
			throw new InvalidArgumentException('The archive path template contains an invalid placeholder.');
		}

		return $normalized;
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

		$rendered = $config->pathTemplate;
		foreach ($values as $name => $value) {
			$rendered = preg_replace('/{{\s*' . preg_quote($name, '/') . '\s*}}/', $value, $rendered) ?? $rendered;
		}

		$renderedComponents = explode('/', $rendered);
		$lastIndex = array_key_last($renderedComponents);
		$components = [];
		foreach ($renderedComponents as $index => $component) {
			$isFilename = $index === $lastIndex;
			$components[] = $this->cleanComponent($component, $isFilename ? 'Document' : 'Folder', $isFilename ? 220 : 120);
		}

		return implode('/', $components);
	}

	public function cleanComponent(string $value, string $fallback, int $maxLength = 120): string {
		/**
		 * @psalm-suppress InternalMethod
		 * @psalm-suppress MixedAssignment
		 */
		$normalized = class_exists(\Normalizer::class) ? \Normalizer::normalize($value, \Normalizer::FORM_C) : $value;
		$text = is_string($normalized) ? $normalized : $value;
		$text = preg_replace('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u', '_', $text) ?? $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		$text = trim($text, " .\t\n\r\0\x0B");
		if ($text === '' || $text === '.' || $text === '..') {
			$text = $fallback;
		}
		$stem = strtoupper(explode('.', $text, 2)[0]);
		if (in_array($stem, self::WINDOWS_RESERVED, true)) {
			$text = '_' . $text;
		}
		$text = mb_substr($text, 0, $maxLength);
		$text = rtrim($text, ' .');

		return $text !== '' ? $text : $fallback;
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
