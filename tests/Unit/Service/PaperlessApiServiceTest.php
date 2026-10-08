<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use Closure;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\PaperlessApiService;
use OCA\PaperlessSync\Service\PaperlessUploadException;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\UnsizedStream;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * The Paperless-ngx client against recorded HTTP exchanges.
 */
final class PaperlessApiServiceTest extends TestCase {
	use InMemoryConfiguration;

	private const URL = 'https://paperless.example.test';

	/** @var list<array{method: string, url: string, options: array<string, mixed>}> */
	private array $requests = [];
	/** @var list<array{int, mixed}|Closure> the status and body of each response, or a closure that returns them */
	private array $responses = [];
	/** @var ITempManager&MockObject */
	private ITempManager $tempManager;
	/** @var list<string> */
	private array $temporaryFiles = [];
	private PaperlessApiService $service;

	protected function setUp(): void {
		$this->token = 'secret-token';
		$configService = $this->configService();
		$configService->save(self::validSettings());
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $url, array $options): IResponse => $this->respond('GET', $url, $options));
		$client->method('post')->willReturnCallback(fn (string $url, array $options): IResponse => $this->respond('POST', $url, $options));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'paperless-sync-test-');
			self::assertIsString($path);
			$this->temporaryFiles[] = $path;

			return $path;
		});
		$this->service = new PaperlessApiService($configService, $clientService, $this->tempManager);
	}

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	public function testConnectionTestRequestsOneDocumentWithTheGivenCredentials(): void {
		$this->responses[] = [200, ['count' => 0, 'results' => []]];

		$this->service->testConnection('https://other.example.test/', 'other-token');

		self::assertSame('https://other.example.test/api/documents/', $this->requests[0]['url']);
		self::assertSame(['page' => 1, 'page_size' => 1], $this->requests[0]['options']['query']);
		self::assertSame([
			'Authorization' => 'Token other-token',
			'User-Agent' => AppConstants::USER_AGENT,
			'Accept' => 'application/json',
		], $this->requests[0]['options']['headers']);
		self::assertSame(60, $this->requests[0]['options']['timeout']);
	}

	public function testConnectionTestNeedsAUrlAndAToken(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless is not configured.');
		$this->service->testConnection(self::URL, '');
	}

	public function testDocumentsAreReadFromEveryPage(): void {
		$this->responses[] = [200, ['count' => 2001, 'results' => [['id' => 1]]]];
		$this->responses[] = [200, ['count' => 2001, 'results' => [['id' => 2]]]];
		$this->responses[] = [200, ['count' => 2001, 'results' => [['id' => 3]]]];

		self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $this->service->documents());
		self::assertSame([1, 2, 3], array_map(static fn (array $request): mixed => $request['options']['query']['page'], $this->requests));
		self::assertSame(self::URL . '/api/documents/', $this->requests[2]['url']);
		self::assertSame('Token secret-token', $this->requests[0]['options']['headers']['Authorization']);
	}

	public function testTrashIsReadFromItsEndpoint(): void {
		$this->responses[] = [200, ['results' => [['id' => 4, 'deleted_at' => '2026-08-27T08:00:00+02:00']]]];

		self::assertSame([['id' => 4, 'deleted_at' => '2026-08-27T08:00:00+02:00']], $this->service->trash());
		self::assertSame(self::URL . '/api/trash/', $this->requests[0]['url']);
	}

	public function testResultsWithoutAnEnvelopeAreAccepted(): void {
		$this->responses[] = [200, [['id' => 1, 0 => 'dropped'], 'not an object', ['id' => 2]]];

		self::assertSame([['id' => 1], ['id' => 2]], $this->service->documents());
	}

	public function testAnObjectWithoutResultsHasNoDocuments(): void {
		$this->responses[] = [200, ['detail' => 'Nothing here']];

		self::assertSame([], $this->service->documents());
	}

	public function testNamesOfCorrespondentsDocumentTypesAndStoragePaths(): void {
		$entries = ['results' => [['id' => 4, 'name' => 'Energie GmbH'], ['id' => 5], ['name' => 'without an ID'], ['id' => [6], 'name' => 'list as ID']]];
		$this->responses = [[200, $entries], [200, $entries], [200, $entries]];

		self::assertSame(['4' => 'Energie GmbH'], $this->service->correspondents());
		self::assertSame(['4' => 'Energie GmbH'], $this->service->documentTypes());
		self::assertSame(['4' => 'Energie GmbH'], $this->service->storagePaths());
		self::assertSame(
			[self::URL . '/api/correspondents/', self::URL . '/api/document_types/', self::URL . '/api/storage_paths/'],
			array_column($this->requests, 'url'),
		);
	}

	public function testTagsWithTheirNamesAndInboxFlags(): void {
		$this->responses[] = [200, ['results' => [
			['id' => 9, 'name' => 'Inbox', 'is_inbox_tag' => true],
			['id' => 10, 'name' => 'Steuer', 'is_inbox_tag' => false],
			['id' => 11, 'is_inbox_tag' => 'yes'],
			['name' => 'without an ID'],
			['id' => null, 'name' => 'null ID'],
		]]];

		self::assertSame([
			'names' => ['9' => 'Inbox', '10' => 'Steuer', '11' => '11'],
			'inbox' => ['9'],
		], $this->service->tags());
	}

	public function testDownloadCopiesTheDocumentIntoTheSinkAndRemovesTheTemporaryFile(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-original');
			return [200, null];
		};
		$sink = $this->sink();

		$this->service->downloadDocument(123, true, $sink);

		self::assertSame('%PDF-original', stream_get_contents($sink));
		$request = $this->requests[0];
		self::assertSame(self::URL . '/api/documents/123/download/', $request['url']);
		self::assertSame(['original' => 'true'], $request['options']['query']);
		self::assertSame(180, $request['options']['timeout']);
		self::assertSame(['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https']], $request['options']['allow_redirects']);
		self::assertFileDoesNotExist($request['options']['sink']);
	}

	public function testDownloadOfTheArchiveVersion(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-archive');
			return [200, null];
		};
		$sink = $this->sink();

		$this->service->downloadDocument(123, false, $sink);

		self::assertSame(['original' => 'false'], $this->requests[0]['options']['query']);
		self::assertSame('%PDF-archive', stream_get_contents($sink));
	}

	public function testDownloadRequiresASink(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('A writable download stream is required.');
		$this->service->downloadDocument(123, true, 'not a stream');
	}

	public function testDownloadNeedsATemporaryFile(): void {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn(false);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->createMock(IClient::class));
		$service = new PaperlessApiService($this->configService(), $clientService, $tempManager);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a temporary Paperless download file.');
		$service->downloadDocument(123, true, $this->sink());
	}

	public function testFailedDownloadRemovesTheTemporaryFile(): void {
		$this->responses[] = [404, '{"detail": "Not found."}'];

		try {
			$this->service->downloadDocument(123, true, $this->sink());
			self::fail('A failed download must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless could not download document: HTTP 404.', $exception->getMessage());
		}
		self::assertFileDoesNotExist($this->requests[0]['options']['sink']);
	}

	public function testDownloadThatVanishedBeforeItWasReadIsReported(): void {
		$this->responses[] = function (array $options): array {
			unlink($options['sink']);
			return [200, null];
		};
		$warnings = [];
		set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		}, E_WARNING);

		try {
			$this->service->downloadDocument(123, true, $this->sink());
			self::fail('A missing download must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('Could not open the temporary Paperless download.', $exception->getMessage());
		} finally {
			restore_error_handler();
		}
		self::assertCount(1, $warnings);
		self::assertStringContainsString('Failed to open stream', $warnings[0]);
	}

	public function testDownloadIntoAReadOnlySinkIsReported(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-original');
			return [200, null];
		};
		$sink = fopen('php://memory', 'rb');
		self::assertIsResource($sink);

		try {
			$this->service->downloadDocument(123, true, $sink);
			self::fail('A download that cannot be copied must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('Could not copy the Paperless download.', $exception->getMessage());
		}
		self::assertFileDoesNotExist($this->requests[0]['options']['sink']);
	}

	public function testChecksumOfTheOriginalAndTheArchiveVersion(): void {
		$metadata = ['original_checksum' => 'aaa', 'archive_checksum' => 'bbb'];
		$this->responses = [[200, $metadata], [200, $metadata]];

		self::assertSame('aaa', $this->service->documentChecksum(123, true));
		self::assertSame('bbb', $this->service->documentChecksum(123, false));
		self::assertSame(self::URL . '/api/documents/123/metadata/', $this->requests[0]['url']);
	}

	/** @return array<string, array{mixed, string}> */
	public static function invalidMetadata(): array {
		return [
			'text instead of an object' => ['"metadata"', 'Paperless returned invalid document metadata.'],
			'missing checksum' => [['archive_checksum' => 'bbb'], 'Paperless did not return original_checksum.'],
			'empty checksum' => [['original_checksum' => ''], 'Paperless did not return original_checksum.'],
		];
	}

	#[DataProvider('invalidMetadata')]
	public function testInvalidMetadataIsRejected(mixed $body, string $message): void {
		$this->responses[] = [200, $body];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);
		$this->service->documentChecksum(123, true);
	}

	public function testUploadSendsTheFileAndReturnsTheTask(): void {
		$sent = [];
		$this->responses[] = function (array $options) use (&$sent): array {
			$sent = $this->uploadedFile($options);
			return [200, '"4f1e0f9a-task"'];
		};

		self::assertSame('4f1e0f9a-task', $this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf'));

		$request = $this->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame(self::URL . '/api/documents/post_document/', $request['url']);
		self::assertSame(['name' => 'document', 'filename' => 'police.pdf', 'content' => '%PDF-inbox', 'size' => 10], $sent);
		self::assertArrayNotHasKey('Accept', $request['options']['headers']);
		self::assertSame(180, $request['options']['timeout']);
		self::assertFalse($request['options']['http_errors'], 'Errors must come back as responses, with the reason in their body.');
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'), 'The copy of the file must be removed.');
	}

	public function testFileWithoutASizeIsSentWithOne(): void {
		$sent = [];
		$this->responses[] = function (array $options) use (&$sent): array {
			$sent = $this->uploadedFile($options);
			return [200, '"task"'];
		};
		$source = UnsizedStream::open('%PDF-on-object-storage');
		self::assertFalse(fstat($source), 'A file on object storage has no size.');

		$this->service->uploadDocument($source, 'police.pdf');

		self::assertSame('%PDF-on-object-storage', $sent['content']);
		self::assertSame(22, $sent['size'], 'Without a size the request has no Content-Length, and Paperless sees no file.');
	}

	public function testEmptyFileIsRefusedWithoutARequest(): void {
		try {
			$this->service->uploadDocument($this->source(''), 'empty.pdf');
			self::fail('An empty file must be refused.');
		} catch (PaperlessUploadException $exception) {
			self::assertSame('The file is empty.', $exception->getMessage());
			self::assertTrue($exception->permanent);
		}
		self::assertSame([], $this->requests);
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'));
	}

	public function testUploadNeedsATemporaryFile(): void {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn(false);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->createMock(IClient::class));
		$service = new PaperlessApiService($this->configService(), $clientService, $tempManager);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a temporary Paperless upload file.');
		$service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
	}

	public function testUploadThatCannotBeCopiedIsReported(): void {
		$directory = sys_get_temp_dir() . '/paperless-sync-test-' . bin2hex(random_bytes(4));
		mkdir($directory);
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn($directory);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->createMock(IClient::class));
		$service = new PaperlessApiService($this->configService(), $clientService, $tempManager);
		$warnings = [];
		set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		}, E_WARNING);

		try {
			$service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
			self::fail('A file that cannot be copied must be reported.');
		} catch (RuntimeException $exception) {
			self::assertNotInstanceOf(PaperlessUploadException::class, $exception);
			self::assertSame('Could not copy the file for the Paperless upload.', $exception->getMessage());
		} finally {
			restore_error_handler();
			rmdir($directory);
		}
		self::assertCount(1, $warnings);
	}

	/** @return array<string, array{mixed, string}> */
	public static function uploadTasks(): array {
		return [
			'task ID' => [['task_id' => 'abc', 'id' => 'other'], 'abc'],
			'numeric ID' => [['id' => 17], '17'],
			'data field' => [['task_id' => ['nested'], 'data' => 'def'], 'def'],
		];
	}

	#[DataProvider('uploadTasks')]
	public function testUploadAcceptsTheTaskInAnObject(mixed $body, string $task): void {
		$this->responses[] = [200, $body];

		self::assertSame($task, $this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf'));
	}

	public function testUploadWithoutATaskIsRejected(): void {
		$this->responses[] = [200, ['detail' => 'Accepted']];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless returned an invalid upload task response.');
		$this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
	}

	public function testUploadRequiresASource(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('A readable upload stream is required.');
		$this->service->uploadDocument('not a stream', 'police.pdf');
	}

	/** @return array<string, array{int, mixed, string, bool}> */
	public static function refusedUploads(): array {
		$long = str_repeat('x', 400);
		return [
			'file without Content-Length' => [400, ['document' => ['No file was submitted.']], 'Paperless answered the upload with HTTP 400: document: No file was submitted.', true],
			'unsupported type' => [400, ['document' => ['File type application/octet-stream not supported']], 'Paperless answered the upload with HTTP 400: document: File type application/octet-stream not supported', true],
			'several fields' => [400, ['document' => ['a', ['b' => 'c']], 'title' => 'd', 'code' => [5, null]], 'Paperless answered the upload with HTTP 400: document: a c; title: d; code: 5', true],
			'errors of the whole form' => [400, ['non_field_errors' => ['Invalid data.']], 'Paperless answered the upload with HTTP 400: Invalid data.', true],
			'too large for the proxy' => [413, "<html>\n<head><title>413 Request Entity Too Large</title></head>\n<body><center><h1>413</h1></center><hr><center>cloudflare</center></body>\n</html>", 'Paperless answered the upload with HTTP 413: 413 Request Entity Too Large', true],
			'unsupported media type' => [415, ['detail' => 'Unsupported media type.'], 'Paperless answered the upload with HTTP 415: Unsupported media type.', true],
			'unprocessable' => [422, '"Cannot process"', 'Paperless answered the upload with HTTP 422: Cannot process', true],
			'long reason' => [400, ['detail' => $long], 'Paperless answered the upload with HTTP 400: ' . str_repeat('x', 300), true],
			'invalid token' => [401, ['detail' => 'Invalid token.'], 'Paperless answered the upload with HTTP 401: Invalid token.', false],
			'missing permission' => [403, ['detail' => 'You do not have permission to perform this action.'], 'Paperless answered the upload with HTTP 403: You do not have permission to perform this action.', false],
			'too many requests' => [429, ['Request was throttled.'], 'Paperless answered the upload with HTTP 429: Request was throttled.', false],
			'server error' => [500, '<h1>Server Error (500)</h1>', 'Paperless answered the upload with HTTP 500: Server Error (500)', false],
			'bad gateway' => [502, "<html><body>Bad &amp; broken\n gateway</body></html>", 'Paperless answered the upload with HTTP 502: Bad & broken gateway', false],
			'unavailable without a body' => [503, '', 'Paperless answered the upload with HTTP 503.', false],
			'no body' => [504, null, 'Paperless answered the upload with HTTP 504.', false],
			'redirect' => [302, null, 'Paperless answered the upload with HTTP 302.', false],
		];
	}

	#[DataProvider('refusedUploads')]
	public function testRefusedUploadNamesTheReasonOfPaperless(int $status, mixed $body, string $message, bool $permanent): void {
		$this->responses[] = [$status, $body];

		try {
			$this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
			self::fail('A refused upload must be reported.');
		} catch (PaperlessUploadException $exception) {
			self::assertSame($message, $exception->getMessage());
			self::assertSame($permanent, $exception->permanent, 'Only a refusal of the file itself repeats as long as the file stays the same.');
			self::assertSame($status, $exception->statusCode);
		}
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'));
	}

	public function testReasonMayComeAsAStream(): void {
		$body = fopen('php://temp', 'w+b');
		self::assertIsResource($body);
		fwrite($body, '{"document": ["The submitted file is empty."]}');
		rewind($body);
		$this->responses[] = [400, $body];

		$this->expectException(PaperlessUploadException::class);
		$this->expectExceptionMessage('Paperless answered the upload with HTTP 400: document: The submitted file is empty.');
		$this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
	}

	public function testUnreachablePaperlessMayPass(): void {
		$timeout = new RuntimeException('cURL error 28: Operation timed out after 180001 milliseconds');
		$this->responses[] = static function () use ($timeout): array {
			throw $timeout;
		};

		try {
			$this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
			self::fail('An unreachable Paperless must be reported.');
		} catch (PaperlessUploadException $exception) {
			self::assertSame('Paperless could not be reached for the upload: cURL error 28: Operation timed out after 180001 milliseconds', $exception->getMessage());
			self::assertFalse($exception->permanent);
			self::assertSame(0, $exception->statusCode);
			self::assertSame($timeout, $exception->getPrevious());
		}
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'));
	}

	public function testTaskStatusAndItsMessage(): void {
		$this->responses[] = [200, ['results' => [['status' => 'failure', 'result' => 'Unsupported mime type', 'status_str' => 'ignored']]]];

		self::assertSame(['status' => 'FAILURE', 'message' => 'Unsupported mime type'], $this->service->taskStatus('task-1'));
		self::assertSame(['task_id' => 'task-1', 'page_size' => 10], $this->requests[0]['options']['query']);
		self::assertSame(self::URL . '/api/tasks/', $this->requests[0]['url']);
	}

	public function testTaskStateWithoutAMessage(): void {
		$this->responses[] = [200, [['state' => 'started', 'error' => '', 'message' => ['not text']]]];

		self::assertSame(['status' => 'STARTED', 'message' => ''], $this->service->taskStatus('task-1'));
	}

	public function testTaskWithoutAStatusIsPending(): void {
		$this->responses[] = [200, [['task_id' => 'task-1']]];

		self::assertSame(['status' => 'PENDING', 'message' => ''], $this->service->taskStatus('task-1'));
	}

	public function testTaskThatIsNotVisibleYetIsPending(): void {
		$this->responses[] = [200, ['count' => 0, 'results' => []]];

		self::assertSame(['status' => 'PENDING', 'message' => 'Task is not visible yet.'], $this->service->taskStatus('task-1'));
	}

	/** @return array<string, array{int, mixed, class-string<\Throwable>, string}> */
	public static function invalidResponses(): array {
		return [
			'server error' => [500, ['detail' => 'Server error'], RuntimeException::class, 'Paperless could not query /api/documents/: HTTP 500.'],
			'redirect' => [302, null, RuntimeException::class, 'Paperless could not query /api/documents/: HTTP 302.'],
			'empty body' => [200, '', UnexpectedValueException::class, 'Paperless returned an empty response body.'],
			'no body' => [200, null, UnexpectedValueException::class, 'Paperless returned an empty response body.'],
			'invalid JSON' => [200, '{"results": [', UnexpectedValueException::class, 'Paperless returned invalid JSON.'],
			'number' => [200, '42', UnexpectedValueException::class, 'Paperless returned an unsupported JSON value.'],
			'text' => [200, '"text"', UnexpectedValueException::class, 'Paperless returned an invalid JSON object.'],
		];
	}

	/** @param class-string<\Throwable> $exception */
	#[DataProvider('invalidResponses')]
	public function testInvalidResponsesAreRejected(int $status, mixed $body, string $exception, string $message): void {
		$this->responses[] = [$status, $body];

		$this->expectException($exception);
		$this->expectExceptionMessage($message);
		$this->service->documents();
	}

	public function testResponseBodyMayBeAStream(): void {
		$body = fopen('php://temp', 'w+b');
		self::assertIsResource($body);
		fwrite($body, '{"count": 1, "results": [{"id": 1}]}');
		rewind($body);
		$this->responses[] = [200, $body];

		self::assertSame([['id' => 1]], $this->service->documents());
	}

	public function testUnconfiguredAppDoesNotCallPaperless(): void {
		$this->settings = [];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless is not configured.');
		$this->service->documents();
	}

	/** @param array<string, mixed> $options */
	private function respond(string $method, string $url, array $options): IResponse {
		$this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
		$next = array_shift($this->responses);
		self::assertNotNull($next, "Unexpected request {$method} {$url}");
		[$status, $body] = $next instanceof Closure ? $next($options) : $next;
		if (is_array($body)) {
			$body = json_encode($body, JSON_THROW_ON_ERROR);
		}
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	/** @return resource */
	private function sink() {
		$sink = fopen('php://temp', 'w+b');
		self::assertIsResource($sink);

		return $sink;
	}

	/** @return resource a file of the inbox */
	private function source(string $content) {
		$source = $this->sink();
		fwrite($source, $content);
		rewind($source);

		return $source;
	}

	/**
	 * The file in the request, read while the request is made, with the size that its stream has.
	 *
	 * @param array<string, mixed> $options
	 * @return array{name: mixed, filename: mixed, content: string|false, size: mixed}
	 */
	private function uploadedFile(array $options): array {
		$part = $options['multipart'][0];
		self::assertIsResource($part['contents']);
		$stat = fstat($part['contents']);

		return [
			'name' => $part['name'],
			'filename' => $part['filename'],
			'content' => stream_get_contents($part['contents']),
			'size' => is_array($stat) ? $stat['size'] : null,
		];
	}
}
