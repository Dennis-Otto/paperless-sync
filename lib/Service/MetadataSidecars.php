<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;

/**
 * The metadata of Paperless next to the archive, for one run: a sidecar for each copy in the
 * folder .paperless of the archive, such as .paperless/P123.json, with what Paperless knows of its
 * document, and the index .paperless/index.csv, with a row for each sidecar, the path of its copy
 * and the state of its document. With them the archive describes itself, also without Paperless.
 *
 * The index is the memory of the sidecars as well: it keeps the SHA-256 of each sidecar, so a run
 * reads one file, the index, and writes a sidecar only when its content changes, such as after a
 * change of its document in Paperless, or of the name of its correspondent. A sidecar goes when its
 * document leaves the state of the export: when a run deletes its copy, under the rules of deletion
 * safety, or removes the copy of an excluded document. A copy in the deleted folder keeps its
 * sidecar, and its row names its path there and the state of its document.
 *
 * A run writes at most as many sidecars as its batch size, as it makes at most as many changes
 * in a module: the first run after the setting is on, in an archive of many documents, leaves the
 * rest to the next runs, which the SHA-256 of each row brings to them on its own.
 *
 * @psalm-import-type CustomField from PathTemplateService
 * @psalm-type Metadata = array{version: int, id: int, title: string, correspondent: string|null, document_type: string|null, storage_path: string|null, tags: list<string>, created: string|null, added: string|null, modified: string|null, archive_serial_number: int|null, original_file_name: string|null, mime_type: string|null, page_count: int|null, notes: list<array{note: string, created: string|null, user: string|null}>, custom_fields: list<array{name: string, type: string, value: mixed}>, file: array{version: string, sha256: string|null}, url: string}
 */
final class MetadataSidecars {
	/** The folder of the metadata in the archive folder; Files hides it, as every name with a dot. */
	public const FOLDER = '.paperless';
	public const INDEX = 'index.csv';
	/** The version of the format of the sidecars, which grows with every change that a reader must know of. */
	public const VERSION = 1;
	/** The columns of the index, in their order. */
	public const COLUMNS = [
		'id', 'title', 'correspondent', 'document_type', 'tags', 'created', 'added', 'modified',
		'archive_serial_number', 'path', 'state', 'sha256', 'url', 'metadata_sha256',
	];
	/** The first characters of a cell that a spreadsheet takes for a formula, after OWASP's advice on CSV injection. */
	private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];
	/** The byte order mark, with which spreadsheets such as Excel read the index as UTF-8. */
	private const BOM = "\u{FEFF}";

	/** @var array<int, array<string, string>> the rows of the index by document ID, each with every column */
	private array $rows = [];
	/** The index as the run found it, empty when there was none. */
	private string $found = '';
	private bool $indexExists = false;
	/** After the archive refused a write, such as against a full quota, the run writes no more sidecars: the next ones would fail as well. */
	private bool $stopped = false;
	/** The sidecars that the run wrote or tried to write, which its batch size limits. */
	private int $writes = 0;

	/**
	 * Reads the index of the archive, when there is one.
	 *
	 * @param array<string, string> $correspondents
	 * @param array<string, string> $documentTypes
	 * @param array<string, string> $storagePaths
	 * @param array<string, string> $tagNames
	 * @param array<string, CustomField> $customFields
	 * @param \Closure(\Throwable): bool $archiveFailed whether a failure is one of the whole archive, such as a full quota, after which the run writes no further sidecar
	 * @throws \Throwable when Nextcloud can't read the index
	 */
	public function __construct(
		private NextcloudStorageInterface $storage,
		private SyncConfig $config,
		private SyncReport $report,
		private string $archiveRoot,
		private array $correspondents,
		private array $documentTypes,
		private array $storagePaths,
		private array $tagNames,
		private array $customFields,
		private \Closure $archiveFailed,
	) {
		$path = $this->indexPath();
		if (!$this->storage->exists($this->config->targetUser, $path)) {
			return;
		}
		$this->indexExists = true;
		$stream = $this->storage->openRead($this->config->targetUser, $path);
		try {
			$this->found = (string)stream_get_contents($stream);
		} finally {
			fclose($stream);
		}
		$this->rows = self::parse($this->found);
	}

	/** The path of the sidecar of a document. */
	public function sidecarPath(int $documentId): string {
		return $this->archiveRoot . '/' . self::FOLDER . '/P' . $documentId . '.json';
	}

	public function indexPath(): string {
		return $this->archiveRoot . '/' . self::FOLDER . '/' . self::INDEX;
	}

	/**
	 * Describes the copy of a document that the run exported, moved or found unchanged at its path:
	 * it writes the sidecar when its content changed, and gives the copy its row in the index. A
	 * sidecar that the run can't write keeps its old row, so that the next run writes it, as does
	 * one beyond the batch size of the run.
	 *
	 * @param array<string, mixed> $document as Paperless lists it
	 * @param string $fingerprint the checksums of the copy, as the state keeps them
	 * @param bool $original whether the copy is the original file of the document, not its archive version
	 */
	public function describe(array $document, int $documentId, string $path, string $fingerprint, bool $original): void {
		if ($this->stopped) {
			return;
		}
		$sha256 = self::sha256Of($fingerprint);
		$metadata = $this->metadata($document, $documentId, $original, $sha256);
		$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
		$hash = hash('sha256', $json);
		if (($this->rows[$documentId]['metadata_sha256'] ?? '') !== $hash) {
			if ($this->writes >= $this->config->batchSize) {
				return;
			}
			// A write takes its place in the batch before it is tried, also one that fails.
			++$this->writes;
			try {
				$this->storage->writeText($this->config->targetUser, $this->sidecarPath($documentId), $json);
			} catch (\Throwable $exception) {
				$this->fail($exception, $documentId, $this->sidecarPath($documentId));

				return;
			}
		}
		$this->rows[$documentId] = [
			'id' => (string)$documentId,
			'title' => $metadata['title'],
			'correspondent' => $metadata['correspondent'] ?? '',
			'document_type' => $metadata['document_type'] ?? '',
			'tags' => implode(', ', $metadata['tags']),
			'created' => $metadata['created'] ?? '',
			'added' => $metadata['added'] ?? '',
			'modified' => $metadata['modified'] ?? '',
			'archive_serial_number' => $metadata['archive_serial_number'] === null ? '' : (string)$metadata['archive_serial_number'],
			'path' => $this->relative($path),
			'state' => 'active',
			'sha256' => $sha256 ?? '',
			'url' => $metadata['url'],
			'metadata_sha256' => $hash,
		];
	}

	/**
	 * Ends the metadata of the run: the sidecar of a document that left the state of the export
	 * goes, the rows of the others follow their copies, such as into the deleted folder, and the
	 * index is written when it changed. A run of one document ends it as well: the state of the
	 * export knows every document. After the archive refused a write, the run still tries the
	 * deletions and the index once, so that the rows of the sidecars that it wrote are kept,
	 * without reporting their failures a second time.
	 */
	public function finish(ExportStates $states): void {
		foreach ($this->rows as $documentId => $row) {
			$entry = $states->find($documentId);
			if ($entry === null) {
				try {
					$this->storage->delete($this->config->targetUser, $this->sidecarPath($documentId));
				} catch (\Throwable $exception) {
					// The row stays, so that the next run deletes the sidecar.
					$this->fail($exception, $documentId, $this->sidecarPath($documentId));
					continue;
				}
				unset($this->rows[$documentId]);
				continue;
			}
			$path = $entry['path'] ?? null;
			$state = $entry['state'] ?? null;
			$row['path'] = is_string($path) && $path !== '' ? $this->relative($path) : $row['path'];
			$row['state'] = is_string($state) ? $state : $row['state'];
			$this->rows[$documentId] = $row;
		}
		$index = $this->index();
		// An archive without a sidecar needs no index, until it had one.
		if ($index === $this->found || ($this->rows === [] && !$this->indexExists)) {
			return;
		}
		try {
			$this->storage->writeText($this->config->targetUser, $this->indexPath(), $index);
		} catch (\Throwable $exception) {
			$this->fail($exception, null, $this->indexPath());
		}
	}

	/**
	 * What Paperless knows of a document, in names instead of the IDs of Paperless.
	 *
	 * @param array<string, mixed> $document
	 * @return Metadata
	 * @psalm-suppress MixedAssignment the values of Paperless are checked before they are used
	 */
	private function metadata(array $document, int $documentId, bool $original, ?string $sha256): array {
		$asn = $document['archive_serial_number'] ?? null;
		$pages = $document['page_count'] ?? null;

		return [
			'version' => self::VERSION,
			'id' => $documentId,
			'title' => self::text($document['title'] ?? null) ?? '',
			'correspondent' => self::name($this->correspondents, $document['correspondent'] ?? null),
			'document_type' => self::name($this->documentTypes, $document['document_type'] ?? null),
			'storage_path' => self::name($this->storagePaths, $document['storage_path'] ?? null),
			'tags' => $this->tags($document['tags'] ?? null),
			'created' => self::text($document['created'] ?? null),
			'added' => self::text($document['added'] ?? null),
			'modified' => self::text($document['modified'] ?? null),
			'archive_serial_number' => is_int($asn) ? $asn : null,
			'original_file_name' => self::text($document['original_file_name'] ?? null),
			'mime_type' => self::text($document['mime_type'] ?? null),
			'page_count' => is_int($pages) ? $pages : null,
			'notes' => self::notes($document['notes'] ?? null),
			'custom_fields' => $this->customFieldValues($document['custom_fields'] ?? null),
			'file' => ['version' => $original ? 'original' : 'archive', 'sha256' => $sha256],
			'url' => rtrim($this->config->paperlessUrl, '/') . '/documents/' . $documentId . '/details',
		];
	}

	/**
	 * The names of the tags of a document, in the order of a person who reads them.
	 *
	 * @return list<string>
	 * @psalm-suppress MixedAssignment the values of Paperless are checked before they are used
	 */
	private function tags(mixed $ids): array {
		$names = [];
		foreach (is_array($ids) ? $ids : [] as $id) {
			if (is_int($id) || is_string($id)) {
				$names[] = $this->tagNames[(string)$id] ?? (string)$id;
			}
		}
		sort($names, SORT_NATURAL | SORT_FLAG_CASE);

		return $names;
	}

	/**
	 * The notes of a document, with the name of the user who wrote each, which Paperless sends since 2.0.
	 *
	 * @return list<array{note: string, created: string|null, user: string|null}>
	 * @psalm-suppress MixedAssignment the values of Paperless are checked before they are used
	 */
	private static function notes(mixed $notes): array {
		$result = [];
		foreach (is_array($notes) ? $notes : [] as $note) {
			$text = is_array($note) ? ($note['note'] ?? null) : null;
			if (!is_array($note) || !is_string($text)) {
				continue;
			}
			$user = $note['user'] ?? null;
			$username = is_array($user) ? ($user['username'] ?? null) : null;
			$result[] = [
				'note' => $text,
				'created' => self::text($note['created'] ?? null),
				'user' => is_string($username) ? $username : null,
			];
		}

		return $result;
	}

	/**
	 * The custom fields of a document by their names, the choice of a select field by its label.
	 *
	 * @return list<array{name: string, type: string, value: mixed}>
	 * @psalm-suppress MixedAssignment the value of a custom field is any JSON, which the sidecar keeps as it is
	 */
	private function customFieldValues(mixed $values): array {
		$result = [];
		foreach (is_array($values) ? $values : [] as $value) {
			$field = is_array($value) ? ($value['field'] ?? null) : null;
			if (!is_array($value) || (!is_int($field) && !is_string($field))) {
				continue;
			}
			$content = $value['value'] ?? null;
			$definition = $this->customFields[(string)$field] ?? ['name' => (string)$field, 'type' => '', 'options' => []];
			if ($definition['type'] === 'select' && (is_int($content) || is_string($content))) {
				$content = $definition['options'][(string)$content] ?? $content;
			}
			$result[] = ['name' => $definition['name'], 'type' => $definition['type'], 'value' => $content];
		}

		return $result;
	}

	/**
	 * The name of a correspondent, a document type or a storage path by its ID; null without one, or
	 * for one that the account of the app may not see.
	 *
	 * @param array<string, string> $names
	 */
	private static function name(array $names, mixed $id): ?string {
		return is_int($id) || is_string($id) ? ($names[(string)$id] ?? null) : null;
	}

	private static function text(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	/** The SHA-256 of a copy from its fingerprint, which holds it first; null for a fingerprint without one. */
	private static function sha256Of(string $fingerprint): ?string {
		$sha256 = explode(' ', $fingerprint)[0];

		return preg_match('/^[0-9a-f]{64}$/', $sha256) === 1 ? $sha256 : null;
	}

	/** The path of a copy below the archive folder, as the index names it. */
	private function relative(string $path): string {
		$prefix = $this->archiveRoot . '/';

		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
	}

	/**
	 * Reports a failed write or deletion as an error of its document, or of the run for the index.
	 * A failure of the whole archive stops the sidecars of the run and is reported once.
	 */
	private function fail(\Throwable $exception, ?int $documentId, string $path): void {
		if ($this->stopped) {
			return;
		}
		$this->stopped = ($this->archiveFailed)($exception);
		$error = RunError::of($exception);
		$this->report->error($documentId === null ? new RunError('Metadata index: %s', [$error]) : new RunError('Metadata P%1$s: %2$s', [(string)$documentId, $error]), $documentId, $path);
	}

	/** The index as CSV after RFC 4180, with the rows by the IDs of their documents. */
	private function index(): string {
		ksort($this->rows);
		$index = self::BOM . self::line(self::COLUMNS);
		foreach ($this->rows as $row) {
			$index .= self::line(array_map(static fn (string $column): string => self::guard($row[$column] ?? ''), self::COLUMNS));
		}

		return $index;
	}

	/** @param list<string> $values */
	private static function line(array $values): string {
		return implode(',', array_map(
			static fn (string $value): string => preg_match('/[",\r\n]/', $value) === 1 ? '"' . str_replace('"', '""', $value) . '"' : $value,
			$values,
		)) . "\r\n";
	}

	/** A value that a spreadsheet would run as a formula starts with an apostrophe, which makes it text. */
	private static function guard(string $value): string {
		return in_array(substr($value, 0, 1), self::FORMULA_STARTS, true) ? "'" . $value : $value;
	}

	private static function unguard(string $value): string {
		return str_starts_with($value, "'") && in_array(substr($value, 1, 1), self::FORMULA_STARTS, true) ? substr($value, 1) : $value;
	}

	/**
	 * The rows of an index by the IDs of their documents, by the names of the columns in its first
	 * line, so that an index of another version still serves: a column that it lacks stays empty,
	 * and its sidecar is written again. A row without a valid ID, or with another number of values
	 * than the first line, is left out.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function parse(string $content): array {
		$file = new \SplTempFileObject();
		$file->fwrite(str_starts_with($content, self::BOM) ? substr($content, strlen(self::BOM)) : $content);
		$file->rewind();
		$file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY);
		$file->setCsvControl(',', '"', '');
		$header = null;
		$rows = [];
		/** @var mixed $line */
		foreach ($file as $line) {
			$values = array_map(static fn (mixed $value): string => is_string($value) ? self::unguard($value) : '', is_array($line) ? array_values($line) : []);
			if ($header === null) {
				$header = $values;
				continue;
			}
			$row = count($values) === count($header) ? array_combine($header, $values) : [];
			$id = $row['id'] ?? '';
			if (preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
				continue;
			}
			$rows[(int)$id] = array_combine(self::COLUMNS, array_map(static fn (string $column): string => $row[$column] ?? '', self::COLUMNS));
		}

		return $rows;
	}
}
