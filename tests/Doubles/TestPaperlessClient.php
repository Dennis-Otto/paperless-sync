<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCA\PaperlessSync\Service\PaperlessClientInterface;

/**
 * Paperless-ngx in memory: documents, trash, metadata, uploads and their tasks.
 */
final class TestPaperlessClient implements PaperlessClientInterface {
	/** @var list<array<string, mixed>> */
	public array $documents = [];
	/** @var list<array<string, mixed>> */
	public array $trash = [];
	/** @var array<string, string> */
	public array $correspondents = [];
	/** @var array<string, string> */
	public array $documentTypes = [];
	/** @var array<int, string> */
	public array $contents = [];
	/** @var array<string, string> */
	public array $uploads = [];
	/** @var array<string, array{status: string, message: string}> */
	public array $tasks = [];
	public int $downloads = 0;
	public int $checksumRequests = 0;
	/** @var array{names: array<string, string>, inbox: list<string>} */
	public array $tagInfo = ['names' => [], 'inbox' => []];
	public ?\Throwable $downloadException = null;
	public ?\Throwable $uploadException = null;
	public ?\Throwable $taskException = null;

	public function testConnection(string $url, string $token): void {
	}

	public function documents(): array {
		return $this->documents;
	}

	public function trash(): array {
		return $this->trash;
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

	public function downloadDocument(int $documentId, bool $original, $sink): void {
		if ($this->downloadException !== null) {
			throw $this->downloadException;
		}
		++$this->downloads;
		fwrite($sink, $this->contents[$documentId] ?? '');
		rewind($sink);
	}

	public function documentChecksum(int $documentId, bool $original): string {
		++$this->checksumRequests;
		return hash('sha256', $this->contents[$documentId] ?? '');
	}

	public function uploadDocument($source, string $filename): string {
		if ($this->uploadException !== null) {
			throw $this->uploadException;
		}
		$content = stream_get_contents($source);
		$this->uploads[$filename] = is_string($content) ? $content : '';
		return 'task-' . count($this->uploads);
	}

	public function taskStatus(string $taskId): array {
		if ($this->taskException !== null) {
			throw $this->taskException;
		}
		return $this->tasks[$taskId] ?? ['status' => 'PENDING', 'message' => ''];
	}
}
