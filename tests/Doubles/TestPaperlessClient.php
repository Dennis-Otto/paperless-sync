<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\PaperlessClientInterface;

/**
 * Paperless-ngx in memory: documents, trash, metadata, uploads and their tasks.
 */
final class TestPaperlessClient implements PaperlessClientInterface {
	/** @var list<array<string, mixed>> */
	public array $documents = [];
	/** @var list<array<string, mixed>> */
	public array $trash = [];
	/** The number of documents that Paperless lists, when the run receives fewer: an incomplete scan. */
	public ?int $documentsListed = null;
	/** The number of documents that Paperless lists in its trash, when the run receives fewer. */
	public ?int $trashListed = null;
	/** @var array<string, string> */
	public array $correspondents = [];
	/** @var array<string, string> */
	public array $documentTypes = [];
	/** @var array<int, string> the archived version of each document, which Paperless serves by default */
	public array $contents = [];
	/** @var array<int, string> the original of each document whose original differs from $contents */
	public array $originalContents = [];
	/** @var array<string, string> */
	public array $uploads = [];
	/** @var array<string, array{status: string, message: string, documentId?: int|null, listed?: bool}> the tasks that Paperless lists, by default pending */
	public array $tasks = [];
	/** @var array<string, int> the ID of a document by a checksum of its original, SHA-256 or MD5 */
	public array $originals = [];
	/** @var list<string> the SHA-256 of every content that a run looked for */
	public array $searches = [];
	public ?\Throwable $findException = null;
	/** @var array{version: string, apiVersion: string} the Paperless that every test of the connection reaches */
	public array $server = ['version' => '2.20.15', 'apiVersion' => '9'];
	public int $downloads = 0;
	public int $checksumRequests = 0;
	/** Paperless-ngx up to 2.x, whose checksums are MD5; 3.x reports SHA-256. */
	public bool $md5Checksums = false;
	/** @var list<array{int, bool}> the ID of every downloaded document and whether the run asked for its original */
	public array $downloadRequests = [];
	/** @var list<array{string, string, bool}> the URL, the token and whether a local address was allowed, of every test of the connection */
	public array $connections = [];
	/** @var array{names: array<string, string>, inbox: list<string>} */
	public array $tagInfo = ['names' => [], 'inbox' => []];
	/** The failure of every test of the connection, such as an address that Nextcloud refuses. */
	public ?\Throwable $connectionException = null;
	public ?\Throwable $downloadException = null;
	public ?\Throwable $uploadException = null;
	/** @var array<string, \Throwable> the failure of the upload of a file, by its name, ahead of $uploadException */
	public array $uploadFailures = [];
	/** @var list<string> the names of the files of every upload, also of the failed ones */
	public array $uploadAttempts = [];
	public ?\Throwable $taskException = null;

	public function testConnection(string $url, #[\SensitiveParameter] string $token, bool $localNetwork): array {
		$this->connections[] = [$url, $token, $localNetwork];
		if ($this->connectionException !== null) {
			throw $this->connectionException;
		}

		return $this->server;
	}

	public function preflight(SyncConfig $config, #[\SensitiveParameter] string $token): array {
		return [ConnectionCheck::passed(ConnectionCheck::PAPERLESS, 'Paperless answers at this URL.')];
	}

	public function documents(): PaperlessScan {
		return self::scan($this->documents, $this->documentsListed);
	}

	public function trash(): PaperlessScan {
		return self::scan($this->trash, $this->trashListed);
	}

	public function correspondents(): array {
		return $this->correspondents;
	}

	public function documentTypes(): array {
		return $this->documentTypes;
	}

	public function storagePaths(): array {
		return [];
	}

	public function tags(): array {
		return $this->tagInfo;
	}

	public function downloadDocument(int $documentId, bool $original, callable $consume): DocumentFingerprint {
		$this->downloadRequests[] = [$documentId, $original];
		if ($this->downloadException !== null) {
			throw $this->downloadException;
		}
		++$this->downloads;
		$content = $this->content($documentId, $original);
		// Beyond 2 MB, php://temp keeps its content in a file, as the download of the app does.
		$download = fopen('php://temp', 'w+b');
		if (!is_resource($download)) {
			throw new \RuntimeException('Could not open the test download.');
		}
		fwrite($download, $content);
		rewind($download);
		try {
			$consume($download);
		} finally {
			/** @psalm-suppress RedundantCondition $consume may close the stream. */
			if (is_resource($download)) {
				fclose($download);
			}
		}

		return new DocumentFingerprint(hash('sha256', $content), hash('md5', $content));
	}

	public function documentChecksum(int $documentId, bool $original): string {
		++$this->checksumRequests;
		return hash($this->md5Checksums ? 'md5' : 'sha256', $this->content($documentId, $original));
	}

	public function findDocument(DocumentFingerprint $content): ?int {
		$this->searches[] = $content->sha256;
		if ($this->findException !== null) {
			throw $this->findException;
		}
		return $this->originals[$content->sha256] ?? $this->originals[$content->md5] ?? null;
	}

	public function uploadDocument($source, string $filename): string {
		$this->uploadAttempts[] = $filename;
		$failure = $this->uploadFailures[$filename] ?? $this->uploadException;
		if ($failure !== null) {
			throw $failure;
		}
		$content = stream_get_contents($source);
		$this->uploads[$filename] = is_string($content) ? $content : '';
		return 'task-' . count($this->uploads);
	}

	public function taskStatus(string $taskId): array {
		if ($this->taskException !== null) {
			throw $this->taskException;
		}
		return ($this->tasks[$taskId] ?? ['status' => 'PENDING', 'message' => '']) + ['documentId' => null, 'listed' => true];
	}

	/** The original of a document, when it has one of its own, or else its archived version. */
	private function content(int $documentId, bool $original): string {
		if ($original && isset($this->originalContents[$documentId])) {
			return $this->originalContents[$documentId];
		}

		return $this->contents[$documentId] ?? '';
	}

	/** @param list<array<string, mixed>> $documents */
	private static function scan(array $documents, ?int $listed): PaperlessScan {
		return new PaperlessScan($documents, $listed === null, $listed ?? count($documents));
	}
}
