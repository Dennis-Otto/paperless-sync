<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use JsonException;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\ITempManager;
use OCP\Security\IRemoteHostValidator;
use RuntimeException;
use UnexpectedValueException;

final class PaperlessApiService implements PaperlessClientInterface {
	private const PAGE_SIZE = 1000;
	/** The answers to an upload that repeat for the same file: invalid, too large, or of a type Paperless doesn't take. */
	private const PERMANENT_UPLOAD_STATUSES = [400, 413, 415, 422];
	private const REASON_LENGTH = 300;
	private IClient $client;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		IClientService $clientService,
		private ITempManager $tempManager,
		private IRemoteHostValidator $remoteHostValidator,
	) {
		$this->client = $clientService->newClient();
	}

	public function testConnection(string $url, #[\SensitiveParameter] string $token, bool $localNetwork): void {
		$this->requestPage($url, $token, $localNetwork, '/api/documents/', ['page' => 1, 'page_size' => 1]);
	}

	public function documents(): PaperlessScan {
		// Ordered by their IDs, a document added during the scan joins the last page instead of
		// shifting the pages that the run has not read yet.
		return $this->scan('/api/documents/', ['ordering' => 'id']);
	}

	public function trash(): PaperlessScan {
		// Paperless orders its trash on its own; the check of the scan finds the pages that shifted.
		return $this->scan('/api/trash/');
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
		foreach ($this->getAll('/api/tags/') as $key => $tag) {
			$id = (string)$key;
			$name = $tag['name'] ?? null;
			$names[$id] = is_string($name) ? $name : $id;
			if (($tag['is_inbox_tag'] ?? false) === true) {
				$inbox[] = $id;
			}
		}

		return ['names' => $names, 'inbox' => $inbox];
	}

	public function downloadDocument(int $documentId, bool $original, callable $consume): DocumentFingerprint {
		$temporaryPath = $this->tempManager->getTemporaryFile();
		if ($temporaryPath === false) {
			throw new RuntimeException('Could not create a temporary Paperless download file.');
		}
		chmod($temporaryPath, 0600);
		try {
			$config = $this->configService->get();
			$response = $this->send(
				'GET',
				$this->url($config, '/api/documents/' . $documentId . '/download/'),
				$this->configService->getToken(),
				$config->paperlessLocalNetwork,
				[
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
			// The temporary file goes on to Nextcloud as it is: no copy of the document, neither in
			// memory nor on disk, whatever its size.
			try {
				$fingerprint = DocumentFingerprint::of($download);
				$consume($download);
			} finally {
				/** @psalm-suppress RedundantCondition Nextcloud may close the stream that it writes. */
				if (is_resource($download)) {
					fclose($download);
				}
			}

			return $fingerprint;
		} finally {
			if (is_file($temporaryPath)) {
				unlink($temporaryPath);
			}
		}
	}

	public function documentChecksum(int $documentId, bool $original): string {
		$config = $this->configService->get();
		$response = $this->send(
			'GET',
			$this->url($config, '/api/documents/' . $documentId . '/metadata/'),
			$this->configService->getToken(),
			$config->paperlessLocalNetwork,
			[
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
		$temporaryPath = $this->tempManager->getTemporaryFile();
		if ($temporaryPath === false) {
			throw new RuntimeException('Could not create a temporary Paperless upload file.');
		}
		chmod($temporaryPath, 0600);
		$upload = fopen($temporaryPath, 'w+b');
		try {
			// The copy on local disk has a size, so that the request has a Content-Length. The stream of
			// a file on object storage has none, and Paperless reads a body without one as "No file was
			// submitted.", so that it refused every file of the inbox.
			$size = is_resource($upload) ? stream_copy_to_stream($source, $upload) : false;
			if (!is_resource($upload) || $size === false) {
				throw new RuntimeException('Could not copy the file for the Paperless upload.');
			}
			if ($size === 0) {
				// Paperless refuses it too: "The submitted file is empty."
				throw new PaperlessUploadException('The file is empty.', true);
			}
			rewind($upload);

			return $this->postDocument($upload, $filename);
		} finally {
			/** @psalm-suppress RedundantCondition Nextcloud's HTTP client may close the stream. */
			if (is_resource($upload)) {
				fclose($upload);
			}
			if (is_file($temporaryPath)) {
				unlink($temporaryPath);
			}
		}
	}

	/** @param resource $upload */
	private function postDocument($upload, string $filename): string {
		$config = $this->configService->get();
		try {
			$response = $this->send(
				'POST',
				$this->url($config, '/api/documents/post_document/'),
				$this->configService->getToken(),
				$config->paperlessLocalNetwork,
				[
					'multipart' => [[
						'name' => 'document',
						'contents' => $upload,
						'filename' => $filename,
					]],
					'connect_timeout' => 10,
					'timeout' => 180,
					// An error comes back as a response, so that the reason in its body can be read.
					'http_errors' => false,
				],
				false,
			);
		} catch (PaperlessRequestException $exception) {
			throw new PaperlessUploadException('Paperless could not be reached for the upload: ' . $exception->getMessage(), false);
		}
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			$reason = $this->errorReason($response->getBody());
			throw new PaperlessUploadException(
				"Paperless answered the upload with HTTP {$status}" . ($reason !== '' ? ": {$reason}" : '.'),
				in_array($status, self::PERMANENT_UPLOAD_STATUSES, true),
				$status,
			);
		}
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
		$config = $this->configService->get();
		$page = $this->requestPage(
			$config->paperlessUrl,
			$this->configService->getToken(),
			$config->paperlessLocalNetwork,
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

	/**
	 * Every entry of a small list of Paperless, such as its tags, by its ID. Without every tag, a
	 * document with an excluded tag or in the inbox would be exported, and without every
	 * correspondent, document type or storage path, documents would move to other paths. So a list
	 * that came back incomplete fails the run, before it changes anything in the archive.
	 *
	 * @return array<array-key, array<string, mixed>>
	 */
	private function getAll(string $path): array {
		$scan = $this->pages($path);
		if (!$scan['complete']) {
			$received = count($scan['results']);
			throw new UnexpectedValueException("Paperless's list {$path} came back incomplete or changed while the run read it ({$received} of {$scan['listed']} received).");
		}

		return $scan['byId'];
	}

	/** @param array<string, string> $query */
	private function scan(string $path, array $query = []): PaperlessScan {
		$scan = $this->pages($path, $query);

		return new PaperlessScan($scan['results'], $scan['complete'], $scan['listed']);
	}

	/**
	 * Every page of a list, its entries in the order of Paperless and by their IDs. The scan is
	 * complete only when every page carries the same count of Paperless, every page before the last
	 * is full, and the run received as many entries with different IDs as Paperless listed: a page
	 * that came back short or empty, or a list that changed while the run read it, may have left
	 * entries out.
	 *
	 * @param array<string, string> $query
	 * @return array{results: list<array<string, mixed>>, byId: array<array-key, array<string, mixed>>, complete: bool, listed: int}
	 */
	private function pages(string $path, array $query = []): array {
		$config = $this->configService->get();
		$token = $this->configService->getToken();
		$results = [];
		$byId = [];
		$complete = true;
		$listed = 0;
		$pages = 1;
		for ($page = 1; $page <= $pages; ++$page) {
			$next = $this->requestPage($config->paperlessUrl, $token, $config->paperlessLocalNetwork, $path, $query + ['page' => $page, 'page_size' => self::PAGE_SIZE]);
			if ($page === 1) {
				$pages = max(1, (int)ceil($next['count'] / self::PAGE_SIZE));
			} elseif ($next['count'] !== $listed) {
				$complete = false;
			}
			$listed = $next['count'];
			if (!$next['paginated'] || ($page < $pages && count($next['results']) !== self::PAGE_SIZE)) {
				$complete = false;
			}
			foreach ($next['results'] as $result) {
				$results[] = $result;
				if (isset($result['id']) && (is_int($result['id']) || is_string($result['id']))) {
					$byId[(string)$result['id']] = $result;
				}
			}
		}

		return [
			'results' => $results,
			'byId' => $byId,
			'complete' => $complete && count($byId) === $listed && count($results) === $listed,
			'listed' => $listed,
		];
	}

	/**
	 * @return array<string, string>
	 * @psalm-suppress MixedAssignment
	 */
	private function namedMap(string $path): array {
		/** @var array<string, string> $map */
		$map = [];
		foreach ($this->getAll($path) as $id => $item) {
			$name = $item['name'] ?? null;
			if (is_string($name)) {
				$map[(string)$id] = $name;
			}
		}

		return $map;
	}

	/**
	 * The page of a list, with the count of Paperless, and whether it was a page of a paginated list
	 * of Paperless: an object with the count and the results.
	 *
	 * @param array<string, int|string> $query
	 * @return array{count: int, results: list<array<string, mixed>>, paginated: bool}
	 * @psalm-suppress MixedAssignment
	 */
	private function requestPage(string $url, #[\SensitiveParameter] string $token, bool $localNetwork, string $path, array $query): array {
		if ($url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}
		$response = $this->send(
			'GET',
			rtrim($url, '/') . '/' . ltrim($path, '/'),
			$token,
			$localNetwork,
			[
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

		$counted = isset($data['count']) && is_numeric($data['count']);

		return [
			'count' => $counted ? (int)$data['count'] : count($results),
			'results' => $results,
			'paginated' => $counted && isset($data['results']) && is_array($data['results']),
		];
	}

	private function url(SyncConfig $config, string $path): string {
		return rtrim($config->paperlessUrl, '/') . '/' . ltrim($path, '/');
	}

	/**
	 * Every request to Paperless, with the token in its header Authorization. When the HTTP client
	 * fails before Paperless answers, as when Paperless can't be reached, only the message of its
	 * exception goes on, in a PaperlessRequestException: the trace of the exception of the client
	 * holds the options of the request with the token, which Nextcloud's log would write, and no
	 * attribute can leave them out of it.
	 *
	 * Nextcloud refuses addresses of the local network, against server-side request forgery. With
	 * $localNetwork, the setting "Paperless runs in my local network", this request alone may go
	 * there: its URL is always the one of Paperless that an administrator entered, and the switch
	 * allow_local_remote_servers, which would lift the protection for every app, stays off.
	 * Without it, a request that follows redirects checks every one of them as Nextcloud does.
	 *
	 * @param 'GET'|'POST' $method
	 * @param array<string, mixed> $options the options of Nextcloud's HTTP client, without the headers
	 */
	private function send(string $method, string $url, #[\SensitiveParameter] string $token, bool $localNetwork, #[\SensitiveParameter] array $options, bool $acceptJson = true): IResponse {
		$options['headers'] = $this->headersForToken($token, $acceptJson);
		if ($localNetwork) {
			$options['nextcloud'] = ['allow_local_address' => true];
		} elseif (isset($options['allow_redirects']) && is_array($options['allow_redirects'])) {
			$options['allow_redirects'] = ['on_redirect' => $this->refuseLocalRedirect(...)] + $options['allow_redirects'];
		}
		try {
			return $method === 'POST' ? $this->client->post($url, $options) : $this->client->get($url, $options);
		} catch (LocalServerException $exception) {
			$message = $this->failureMessage($exception->getMessage(), $token);
			if (!str_starts_with($message, 'No DNS record found')) {
				throw new PaperlessLocalNetworkException(self::refusedHost($message, $url));
			}
			// Nextcloud says with the same exception that it found no address for a name. Without
			// the setting, it looks the name up only in the DNS, so a name of the hosts file of the
			// server needs the setting; with it, the name has no address at all.
			if ($localNetwork) {
				throw new PaperlessRequestException($message);
			}
			$host = preg_match('/^No DNS record found for (\S+)/', $message, $match) === 1 ? $match[1] : (string)parse_url($url, PHP_URL_HOST);
			throw new PaperlessLocalNetworkException($host, true);
		} catch (\Throwable $exception) {
			throw new PaperlessRequestException($this->failureMessage($exception->getMessage(), $token));
		}
	}

	/**
	 * Refuses a redirect to an address of the local network, as Nextcloud's HTTP client does with
	 * an on_redirect of its own, which the allow_redirects of a request replaces. Nextcloud's DNS
	 * pinning checks every redirect as well, but an administrator can turn it off with dns_pinning.
	 *
	 * @psalm-suppress UnusedParam Guzzle passes the request and the response before the target.
	 */
	private function refuseLocalRedirect(mixed $request, mixed $response, \Stringable $target): void {
		$host = (string)parse_url((string)$target, PHP_URL_HOST);
		if (!$this->remoteHostValidator->isValid($host)) {
			throw new LocalServerException('Host "' . $host . '" violates local access rules');
		}
	}

	/**
	 * The host that Nextcloud refused, as its message names it: 'Host "paperless" violates local
	 * access rules', or 'Host "192.168.1.20" (paperless.lan:8000) violates …' after it looked up the
	 * name, which the administrator knows better than the address. Without one, the host of the
	 * request.
	 */
	private static function refusedHost(string $message, string $url): string {
		if (preg_match('/Host "([^"]+)"(?: \((.+):\d+\))?/', $message, $match) === 1) {
			return ($match[2] ?? '') !== '' ? $match[2] : $match[1];
		}

		return (string)parse_url($url, PHP_URL_HOST);
	}

	/**
	 * The message of a failed request without the queries of its URLs, such as ?page=2 or the ID of
	 * a task, which the log doesn't need, and without the token, should a server repeat it.
	 */
	private function failureMessage(string $message, #[\SensitiveParameter] string $token): string {
		$message = (string)preg_replace('~(https?://[^\s?#`\'"<>]*)\?[^\s#`\'"<>]*~i', '$1', $message);

		return str_replace($token, '[redacted]', $message);
	}

	/** @return array<string, string> */
	private function headersForToken(#[\SensitiveParameter] string $token, bool $acceptJson = true): array {
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

	/**
	 * The reason in the body of an error, in one line: the messages of a validation error of
	 * Paperless, such as {"document": ["File type text/plain not supported"]}, or the title of
	 * the page of a proxy in front of it, such as "413 Request Entity Too Large".
	 *
	 * @param resource|string|null $body
	 * @psalm-suppress MixedAssignment
	 */
	private function errorReason($body): string {
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if (!is_string($body)) {
			return '';
		}
		$decoded = json_decode($body, true);
		if (is_array($decoded)) {
			$parts = [];
			foreach ($decoded as $field => $value) {
				$messages = [];
				$values = is_array($value) ? $value : [$value];
				array_walk_recursive($values, static function (mixed $item) use (&$messages): void {
					if (is_scalar($item)) {
						$messages[] = (string)$item;
					}
				});
				$text = implode(' ', $messages);
				$parts[] = is_string($field) && !in_array($field, ['detail', 'non_field_errors'], true) ? "{$field}: {$text}" : $text;
			}
			$reason = implode('; ', $parts);
		} elseif (is_string($decoded)) {
			$reason = $decoded;
		} else {
			$reason = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $match) === 1 ? $match[1] : strip_tags($body);
		}

		return mb_substr(trim((string)preg_replace('/\s+/', ' ', html_entity_decode($reason))), 0, self::REASON_LENGTH);
	}

	private function requireSuccess(int $statusCode, string $operation): void {
		if ($statusCode < 200 || $statusCode >= 300) {
			throw new RuntimeException("Paperless could not {$operation}: HTTP {$statusCode}.");
		}
	}
}
