<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use JsonException;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use RuntimeException;
use UnexpectedValueException;

final class PaperlessApiService implements PaperlessClientInterface {
	private const PAGE_SIZE = 1000;
	private IClient $client;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	public function testConnection(string $url, string $token): void {
		$this->requestPage($url, $token, '/api/documents/', ['page' => 1, 'page_size' => 1]);
	}

	public function documents(): array {
		return $this->getAll('/api/documents/');
	}

	public function trash(): array {
		return $this->getAll('/api/trash/');
	}

	public function correspondents(): array {
		return $this->namedMap('/api/correspondents/');
	}

	public function documentTypes(): array {
		return $this->namedMap('/api/document_types/');
	}

	public function storagePaths(): array {
		return $this->namedMap('/api/storage_paths/');
	}

	/** @psalm-suppress MixedAssignment */
	public function tags(): array {
		/** @var array<string, string> $names */
		$names = [];
		$inbox = [];
		foreach ($this->getAll('/api/tags/') as $tag) {
			if (!isset($tag['id']) || !is_scalar($tag['id'])) {
				continue;
			}
			$id = (string)$tag['id'];
			$name = $tag['name'] ?? null;
			$names[$id] = is_string($name) ? $name : $id;
			if (($tag['is_inbox_tag'] ?? false) === true) {
				$inbox[] = $id;
			}
		}

		return ['names' => $names, 'inbox' => $inbox];
	}

	/** @psalm-suppress DocblockTypeContradiction */
	public function downloadDocument(int $documentId, bool $original, $sink): void {
		if (!is_resource($sink)) {
			throw new UnexpectedValueException('A writable download stream is required.');
		}
		$temporaryPath = tempnam(sys_get_temp_dir(), 'paperless-sync-');
		if ($temporaryPath === false) {
			throw new RuntimeException('Could not create a temporary Paperless download file.');
		}
		chmod($temporaryPath, 0600);
		try {
			$response = $this->client->get(
				$this->url('/api/documents/' . $documentId . '/download/'),
				[
					'headers' => $this->headers(),
					'query' => ['original' => $original ? 'true' : 'false'],
					'allow_redirects' => ['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https']],
					'connect_timeout' => 10,
					'timeout' => 180,
					'sink' => $temporaryPath,
				],
			);
			$this->requireSuccess($response->getStatusCode(), 'download document');
			$download = fopen($temporaryPath, 'rb');
			if (!is_resource($download)) {
				throw new RuntimeException('Could not open the temporary Paperless download.');
			}
			try {
				if (stream_copy_to_stream($download, $sink) === false) {
					throw new RuntimeException('Could not copy the Paperless download.');
				}
			} finally {
				fclose($download);
			}
			rewind($sink);
		} finally {
			if (is_file($temporaryPath)) {
				unlink($temporaryPath);
			}
		}
	}

	public function documentChecksum(int $documentId, bool $original): string {
		$response = $this->client->get(
			$this->url('/api/documents/' . $documentId . '/metadata/'),
			[
				'headers' => $this->headers(),
				'connect_timeout' => 10,
				'timeout' => 60,
			],
		);
		$this->requireSuccess($response->getStatusCode(), 'query document metadata');
		$data = $this->decodeBody($response->getBody());
		if (!is_array($data)) {
			throw new UnexpectedValueException('Paperless returned invalid document metadata.');
		}
		$key = $original ? 'original_checksum' : 'archive_checksum';
		$checksum = $data[$key] ?? null;
		if (!is_string($checksum) || $checksum === '') {
			throw new UnexpectedValueException("Paperless did not return {$key}.");
		}

		return $checksum;
	}

	/** @psalm-suppress DocblockTypeContradiction */
	public function uploadDocument($source, string $filename): string {
		if (!is_resource($source)) {
			throw new UnexpectedValueException('A readable upload stream is required.');
		}
		$response = $this->client->post(
			$this->url('/api/documents/post_document/'),
			[
				'headers' => $this->headers(false),
				'multipart' => [[
					'name' => 'document',
					'contents' => $source,
					'filename' => $filename,
				]],
				'connect_timeout' => 10,
				'timeout' => 180,
			],
		);
		$this->requireSuccess($response->getStatusCode(), 'upload document');
		$data = $this->decodeBody($response->getBody());
		if (is_string($data) && $data !== '') {
			return $data;
		}
		if (is_array($data)) {
			foreach (['task_id', 'id', 'data'] as $key) {
				if (isset($data[$key]) && is_scalar($data[$key])) {
					return (string)$data[$key];
				}
			}
		}

		throw new UnexpectedValueException('Paperless returned an invalid upload task response.');
	}

	public function taskStatus(string $taskId): array {
		$page = $this->requestPage(
			$this->configService->get()->paperlessUrl,
			$this->configService->getToken(),
			'/api/tasks/',
			['task_id' => $taskId, 'page_size' => 10],
		);
		$task = $page['results'][0] ?? null;
		if (!is_array($task)) {
			return ['status' => 'PENDING', 'message' => 'Task is not visible yet.'];
		}
		$status = strtoupper((string)($task['status'] ?? $task['state'] ?? 'PENDING'));
		$message = '';
		foreach (['error', 'message', 'result', 'status_str'] as $key) {
			if (isset($task[$key]) && is_scalar($task[$key]) && (string)$task[$key] !== '') {
				$message = (string)$task[$key];
				break;
			}
		}

		return ['status' => $status, 'message' => $message];
	}

	/** @return list<array<string, mixed>> */
	private function getAll(string $path): array {
		$url = $this->configService->get()->paperlessUrl;
		$token = $this->configService->getToken();
		$first = $this->requestPage($url, $token, $path, ['page' => 1, 'page_size' => self::PAGE_SIZE]);
		$results = $first['results'];
		$pages = max(1, (int)ceil($first['count'] / self::PAGE_SIZE));
		for ($page = 2; $page <= $pages; ++$page) {
			$next = $this->requestPage($url, $token, $path, ['page' => $page, 'page_size' => self::PAGE_SIZE]);
			array_push($results, ...$next['results']);
		}

		return $results;
	}

	/**
	 * @return array<string, string>
	 * @psalm-suppress MixedAssignment
	 */
	private function namedMap(string $path): array {
		/** @var array<string, string> $map */
		$map = [];
		foreach ($this->getAll($path) as $item) {
			$id = $item['id'] ?? null;
			$name = $item['name'] ?? null;
			if (is_scalar($id) && is_string($name)) {
				$map[(string)$id] = $name;
			}
		}

		return $map;
	}

	/**
	 * @param array<string, int|string> $query
	 * @return array{count: int, results: list<array<string, mixed>>}
	 * @psalm-suppress MixedAssignment
	 */
	private function requestPage(string $url, string $token, string $path, array $query): array {
		if ($url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}
		$response = $this->client->get(
			rtrim($url, '/') . '/' . ltrim($path, '/'),
			[
				'headers' => $this->headersForToken($token),
				'query' => $query,
				'connect_timeout' => 10,
				'timeout' => 60,
			],
		);
		$this->requireSuccess($response->getStatusCode(), 'query ' . $path);
		$data = $this->decodeBody($response->getBody());
		if (!is_array($data)) {
			throw new UnexpectedValueException('Paperless returned an invalid JSON object.');
		}
		$rawResults = isset($data['results']) && is_array($data['results']) ? $data['results'] : (array_is_list($data) ? $data : []);
		/** @var list<array<string, mixed>> $results */
		$results = [];
		foreach ($rawResults as $result) {
			if (is_array($result)) {
				$normalized = [];
				foreach ($result as $key => $value) {
					if (is_string($key)) {
						$normalized[$key] = $value;
					}
				}
				$results[] = $normalized;
			}
		}

		return [
			'count' => isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : count($results),
			'results' => $results,
		];
	}

	private function url(string $path): string {
		return rtrim($this->configService->get()->paperlessUrl, '/') . '/' . ltrim($path, '/');
	}

	/** @return array<string, string> */
	private function headers(bool $acceptJson = true): array {
		return $this->headersForToken($this->configService->getToken(), $acceptJson);
	}

	/** @return array<string, string> */
	private function headersForToken(string $token, bool $acceptJson = true): array {
		$headers = [
			'Authorization' => 'Token ' . $token,
			'User-Agent' => AppConstants::USER_AGENT,
		];
		if ($acceptJson) {
			$headers['Accept'] = 'application/json';
		}

		return $headers;
	}

	/** @param resource|string|null $body @return array<array-key, mixed>|string */
	private function decodeBody($body): array|string {
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if (!is_string($body) || $body === '') {
			throw new UnexpectedValueException('Paperless returned an empty response body.');
		}
		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($decoded) && !is_string($decoded)) {
				throw new UnexpectedValueException('Paperless returned an unsupported JSON value.');
			}
			return $decoded;
		} catch (JsonException $exception) {
			throw new UnexpectedValueException('Paperless returned invalid JSON.', 0, $exception);
		}
	}

	private function requireSuccess(int $statusCode, string $operation): void {
		if ($statusCode < 200 || $statusCode >= 300) {
			throw new RuntimeException("Paperless could not {$operation}: HTTP {$statusCode}.");
		}
	}
}
