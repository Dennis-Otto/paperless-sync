<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use Closure;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Service\PaperlessApiService;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PaperlessUploadException;
use OCA\PaperlessSync\Service\PaperlessVersionException;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\UnsizedStream;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\ITempManager;
use OCP\Security\IRemoteHostValidator;
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
	private const VERSION_REQUIRED = 'Paperless does not serve version 9 of its API, which Paperless Sync uses (HTTP 406 Not Acceptable). Paperless Sync needs Paperless-ngx 2.16 or newer; with a newer Paperless-ngx, update Paperless Sync.';

	/** @var list<array{method: string, url: string, options: array<string, mixed>}> */
	private array $requests = [];
	/** @var list<array{int, mixed}|array{int, mixed, array<string, string>}|Closure> the status, the body and the headers of each response, or a closure that returns them */
	private array $responses = [];
	/** @var ITempManager&MockObject */
	private ITempManager $tempManager;
	/** @var list<string> */
	private array $temporaryFiles = [];
	private PaperlessApiService $service;

	protected function setUp(): void {
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'secret-token');
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
		// Nextcloud's check of an address against the local network, for two of its addresses.
		$remoteHostValidator = $this->createMock(IRemoteHostValidator::class);
		$remoteHostValidator->method('isValid')->willReturnCallback(static fn (string $host): bool => !in_array($host, ['paperless', '192.168.1.20'], true));
		$this->service = new PaperlessApiService($configService, $clientService, $this->tempManager, $remoteHostValidator);
	}

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	public function testConnectionTestRequestsOneDocumentWithTheGivenCredentials(): void {
		$this->responses[] = [200, ['count' => 0, 'results' => []], ['X-Version' => '3.3.0', 'X-Api-Version' => '10']];

		$server = $this->service->testConnection('https://other.example.test/', 'other-token', false);

		self::assertSame('https://other.example.test/api/documents/', $this->requests[0]['url']);
		self::assertSame(['page' => 1, 'page_size' => 1], $this->requests[0]['options']['query']);
		self::assertSame([
			'Authorization' => 'Token other-token',
			'User-Agent' => AppConstants::USER_AGENT,
			'Accept' => 'application/json; version=9',
		], $this->requests[0]['options']['headers']);
		self::assertSame(60, $this->requests[0]['options']['timeout']);
		self::assertSame(['version' => '3.3.0', 'apiVersion' => '9'], $server, 'The app speaks API version 9, also with a Paperless that offers 10.');
	}

	/** @return array<string, array{string, string}> the header X-Version of Paperless, and the version that the app keeps of it */
	public static function versionHeaders(): array {
		return [
			'Paperless-ngx 2.20' => ['2.20.15', '2.20.15'],
			'a prerelease' => ['3.4.0-beta.1', '3.4.0-beta.1'],
			'with a v and spaces' => [' v3.3.1 ', '3.3.1'],
			'none, as behind a proxy that drops it' => ['', ''],
			'no version' => ['latest', ''],
			'markup' => ['<b>3.3.0</b>', ''],
			'too long' => ['3.' . str_repeat('1', 40), ''],
		];
	}

	/** Paperless names its version in the header X-Version of every answer to a request with a token. */
	#[DataProvider('versionHeaders')]
	public function testConnectionTestNamesTheVersionOfPaperless(string $header, string $version): void {
		$this->responses[] = [200, ['count' => 0, 'results' => []], ['X-Version' => $header]];

		self::assertSame(['version' => $version, 'apiVersion' => '9'], $this->service->testConnection(self::URL, 'secret-token', false));
	}

	public function testConnectionTestNeedsAUrlAndAToken(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless is not configured.');
		$this->service->testConnection(self::URL, '', false);
	}

	public function testDocumentsAreReadFromEveryPageInTheOrderOfTheirIds(): void {
		$this->responses[] = [200, ['count' => 2001, 'results' => self::entries(1, 1000)]];
		$this->responses[] = [200, ['count' => 2001, 'results' => self::entries(1001, 2000)]];
		$this->responses[] = [200, ['count' => 2001, 'results' => self::entries(2001, 2001)]];

		$scan = $this->service->documents();

		self::assertSame(self::entries(1, 2001), $scan->documents);
		self::assertTrue($scan->complete);
		self::assertSame(2001, $scan->listed);
		self::assertSame([
			['ordering' => 'id', 'page' => 1, 'page_size' => 1000],
			['ordering' => 'id', 'page' => 2, 'page_size' => 1000],
			['ordering' => 'id', 'page' => 3, 'page_size' => 1000],
		], array_map(static fn (array $request): mixed => $request['options']['query'], $this->requests), 'Ordered by ID, a document added during the scan joins the last page.');
		self::assertSame(self::URL . '/api/documents/', $this->requests[2]['url']);
		self::assertSame('Token secret-token', $this->requests[0]['options']['headers']['Authorization']);
	}

	/** @return array<string, array{list<array{int, int, int}>}> the count, the first and the last ID of each page; no IDs when the last is lower */
	public static function incompleteScans(): array {
		return [
			'a short page before the last' => [[[1500, 1, 999], [1500, 1001, 1500]]],
			'an empty page' => [[[1500, 1, 1000], [1500, 1, 0]]],
			'a short last page' => [[[1500, 1, 1000], [1500, 1001, 1499]]],
			'a single short page' => [[[3, 1, 2]]],
			'a document deleted during the scan' => [[[1500, 1, 1000], [1499, 1002, 1500]]],
			'a document added during the scan' => [[[1500, 1, 1000], [1501, 1001, 1501]]],
			'a document read twice' => [[[1500, 1, 1000], [1500, 1000, 1499]]],
		];
	}

	/** @param list<array{int, int, int}> $pages */
	#[DataProvider('incompleteScans')]
	public function testScanThatMayHaveMissedDocumentsIsIncomplete(array $pages): void {
		$documents = [];
		foreach ($pages as [$count, $first, $last]) {
			$results = $last < $first ? [] : self::entries($first, $last);
			$this->responses[] = [200, ['count' => $count, 'results' => $results]];
			array_push($documents, ...$results);
		}

		$scan = $this->service->documents();

		self::assertFalse($scan->complete);
		self::assertSame($documents, $scan->documents, 'The documents that came back are still synchronized.');
		self::assertSame(end($pages)[0], $scan->listed);
		self::assertSame([], $this->responses, 'The pages follow from the count of the first one.');
	}

	public function testDocumentWithoutAnIdLeavesTheScanIncomplete(): void {
		$this->responses[] = [200, ['count' => 2, 'results' => [['id' => 1], ['title' => 'without an ID']]]];

		self::assertFalse($this->service->documents()->complete);
	}

	public function testTrashIsReadFromItsEndpoint(): void {
		$this->responses[] = [200, ['count' => 1, 'results' => [['id' => 4, 'deleted_at' => '2026-08-27T08:00:00+02:00']]]];

		$scan = $this->service->trash();

		self::assertSame([['id' => 4, 'deleted_at' => '2026-08-27T08:00:00+02:00']], $scan->documents);
		self::assertTrue($scan->complete);
		self::assertSame(self::URL . '/api/trash/', $this->requests[0]['url']);
		self::assertSame(['page' => 1, 'page_size' => 1000], $this->requests[0]['options']['query'], 'Paperless orders its trash on its own.');
	}

	public function testScanOfTheTrashIsCheckedToo(): void {
		$this->responses[] = [200, ['count' => 2, 'results' => [['id' => 4]]]];

		self::assertFalse($this->service->trash()->complete);
	}

	public function testResultsWithoutAnEnvelopeAreReadButLeaveTheScanIncomplete(): void {
		$this->responses[] = [200, [['id' => 1, 0 => 'dropped'], 'not an object', ['id' => 2]]];

		$scan = $this->service->documents();

		self::assertSame([['id' => 1], ['id' => 2]], $scan->documents);
		self::assertFalse($scan->complete, 'Without the count of Paperless, the run cannot tell whether it has every document.');
	}

	public function testAnObjectWithoutResultsHasNoDocumentsAndLeavesTheScanIncomplete(): void {
		$this->responses[] = [200, ['detail' => 'Nothing here']];

		$scan = $this->service->documents();

		self::assertSame([], $scan->documents);
		self::assertFalse($scan->complete, 'An answer without a list must not make every document look deleted.');
	}

	public function testEmptyListIsACompleteScan(): void {
		$this->responses[] = [200, ['count' => 0, 'next' => null, 'previous' => null, 'results' => []]];

		$scan = $this->service->documents();

		self::assertSame([], $scan->documents);
		self::assertTrue($scan->complete);
	}

	public function testNamesOfCorrespondentsDocumentTypesAndStoragePaths(): void {
		$entries = ['count' => 3, 'results' => [['id' => 4, 'name' => 'Energie GmbH'], ['id' => 5], ['id' => '6', 'name' => ['not a name']]]];
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
		$this->responses[] = [200, ['count' => 3, 'results' => [
			['id' => 9, 'name' => 'Inbox', 'is_inbox_tag' => true],
			['id' => 10, 'name' => 'Steuer', 'is_inbox_tag' => false],
			['id' => 11, 'is_inbox_tag' => 'yes'],
		]]];

		self::assertSame([
			'names' => ['9' => 'Inbox', '10' => 'Steuer', '11' => '11'],
			'inbox' => ['9'],
		], $this->service->tags());
	}

	/** @return array<string, array{string, string}> */
	public static function smallLists(): array {
		return [
			'tags' => ['tags', '/api/tags/'],
			'correspondents' => ['correspondents', '/api/correspondents/'],
			'document types' => ['documentTypes', '/api/document_types/'],
			'storage paths' => ['storagePaths', '/api/storage_paths/'],
		];
	}

	/**
	 * Without every tag, a document with an excluded tag would be exported; without every
	 * correspondent, document type or storage path, documents would move to other paths.
	 */
	#[DataProvider('smallLists')]
	public function testIncompleteListOfNamesFailsTheRun(string $method, string $path): void {
		$this->responses[] = [200, ['count' => 2, 'results' => [['id' => 9, 'name' => 'Privat']]]];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage("Paperless's list {$path} came back incomplete or changed while the run read it (1 of 2 received).");
		$this->service->{$method}();
	}

	#[DataProvider('smallLists')]
	public function testListOfNamesWithoutTheCountOfPaperlessFailsTheRun(string $method, string $path): void {
		$this->responses[] = [200, [['id' => 9, 'name' => 'Privat']]];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage("Paperless's list {$path} came back incomplete or changed while the run read it (1 of 1 received).");
		$this->service->{$method}();
	}

	public function testDownloadHandsTheTemporaryFileWithItsChecksumsToTheWriteAndRemovesIt(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-original');
			return [200, null];
		};
		$received = [];

		$fingerprint = $this->service->downloadDocument(123, true, static function ($download) use (&$received): void {
			$received = [stream_get_meta_data($download)['uri'], stream_get_contents($download)];
		});

		$request = $this->requests[0];
		self::assertSame([$request['options']['sink'], '%PDF-original'], $received, 'The write reads the temporary file itself, from its start, instead of a copy.');
		self::assertSame(hash('sha256', '%PDF-original'), $fingerprint->sha256);
		self::assertSame(hash('md5', '%PDF-original'), $fingerprint->md5);
		self::assertSame(self::URL . '/api/documents/123/download/', $request['url']);
		self::assertSame(['original' => 'true'], $request['options']['query']);
		self::assertSame(180, $request['options']['timeout']);
		$redirects = $request['options']['allow_redirects'];
		self::assertIsArray($redirects);
		self::assertIsCallable($redirects['on_redirect'], 'Without the setting, every redirect is checked against the local network.');
		unset($redirects['on_redirect']);
		self::assertSame(['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https']], $redirects);
		self::assertFileDoesNotExist($request['options']['sink']);
	}

	/**
	 * Before, the download was copied into a stream of PHP and then read into a string for its
	 * checksum, so that a document larger than the free memory of PHP ended the run with a fatal
	 * error, and every run after it on the same document.
	 */
	public function testDownloadOfALargeDocumentNeverHoldsItInMemory(): void {
		$size = 16 * 1024 * 1024;
		$content = str_repeat('%PDF-large', intdiv($size, 10));
		$this->responses[] = static function (array $options) use ($content): array {
			file_put_contents($options['sink'], $content);
			return [200, null];
		};
		$written = hash_init('sha256');
		memory_reset_peak_usage();
		$before = memory_get_usage();

		$fingerprint = $this->service->downloadDocument(123, true, static function ($download) use ($written): void {
			hash_update_stream($written, $download);
		});

		self::assertLessThan($size / 2, memory_get_peak_usage() - $before, 'The download must be hashed and written in chunks.');
		self::assertSame(hash('sha256', $content), $fingerprint->sha256);
		self::assertSame(hash('md5', $content), $fingerprint->md5);
		self::assertSame(hash('sha256', $content), hash_final($written), 'The write receives the whole document.');
	}

	/** Nextcloud may close the stream that it writes. */
	public function testWriteMayCloseTheDownload(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-original');
			return [200, null];
		};

		$fingerprint = $this->service->downloadDocument(123, true, static function ($download): void {
			fclose($download);
		});

		self::assertSame(hash('md5', '%PDF-original'), $fingerprint->md5);
		self::assertFileDoesNotExist($this->requests[0]['options']['sink']);
	}

	public function testFailedWriteRemovesTheTemporaryFile(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-original');
			return [200, null];
		};
		$opened = null;

		try {
			$this->service->downloadDocument(123, true, static function ($download) use (&$opened): void {
				$opened = $download;
				throw new RuntimeException('Nextcloud refused the write.');
			});
			self::fail('A failed write must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('Nextcloud refused the write.', $exception->getMessage());
		}
		self::assertFalse(is_resource($opened), 'The download is closed.');
		self::assertFileDoesNotExist($this->requests[0]['options']['sink']);
	}

	/**
	 * The redirects of a download replace the check of Nextcloud's HTTP client against the local
	 * network, so the app checks every one itself. Without that, Paperless could redirect a download
	 * into the network of the server whenever an administrator turned off Nextcloud's dns_pinning.
	 */
	public function testDownloadRefusesARedirectIntoTheLocalNetwork(): void {
		$this->responses[] = static function (array $options): array {
			$redirect = $options['allow_redirects']['on_redirect'];
			$redirect(null, null, self::uri('https://files.example.test/123.pdf'));
			$redirect(null, null, self::uri('http://192.168.1.20:8000/123.pdf'));
			self::fail('A redirect into the local network must be refused.');
		};

		try {
			$this->service->downloadDocument(123, true, self::noWrite());
			self::fail('A refused redirect must be reported.');
		} catch (PaperlessLocalNetworkException $exception) {
			self::assertSame('192.168.1.20', $exception->host);
			self::assertFalse($exception->withoutDnsRecord);
		}
	}

	/** With the setting, Nextcloud allows the local network for the redirects of a download too. */
	public function testDownloadInTheLocalNetworkLeavesItsRedirectsToNextcloud(): void {
		$this->settings['paperless_local_network'] = true;
		$this->responses[] = [200, null];

		$this->service->downloadDocument(123, true, self::discard());

		self::assertSame(['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https']], $this->requests[0]['options']['allow_redirects']);
		self::assertSame(['allow_local_address' => true], $this->requests[0]['options']['nextcloud']);
	}

	public function testDownloadOfTheArchiveVersion(): void {
		$this->responses[] = function (array $options): array {
			file_put_contents($options['sink'], '%PDF-archive');
			return [200, null];
		};
		$content = null;

		$fingerprint = $this->service->downloadDocument(123, false, static function ($download) use (&$content): void {
			$content = stream_get_contents($download);
		});

		self::assertSame(['original' => 'false'], $this->requests[0]['options']['query']);
		self::assertSame('%PDF-archive', $content);
		self::assertSame(hash('sha256', '%PDF-archive'), $fingerprint->sha256);
	}

	public function testDownloadNeedsATemporaryFile(): void {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn(false);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->createMock(IClient::class));
		$service = new PaperlessApiService($this->configService(), $clientService, $tempManager, $this->createStub(IRemoteHostValidator::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a temporary Paperless download file.');
		$service->downloadDocument(123, true, self::noWrite());
	}

	public function testFailedDownloadRemovesTheTemporaryFile(): void {
		$this->responses[] = [404, '{"detail": "Not found."}'];

		try {
			$this->service->downloadDocument(123, true, self::noWrite());
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
			$this->service->downloadDocument(123, true, self::noWrite());
			self::fail('A missing download must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('Could not open the temporary Paperless download.', $exception->getMessage());
		} finally {
			restore_error_handler();
		}
		self::assertCount(1, $warnings);
		self::assertStringContainsString('Failed to open stream', $warnings[0]);
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
		self::assertSame('application/json; version=9', $request['options']['headers']['Accept']);
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
		$service = new PaperlessApiService($this->configService(), $clientService, $tempManager, $this->createStub(IRemoteHostValidator::class));

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
		$service = new PaperlessApiService($this->configService(), $clientService, $tempManager, $this->createStub(IRemoteHostValidator::class));
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
			self::assertNull($exception->getPrevious(), 'The trace of the failure of the HTTP client holds the token.');
		}
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'));
	}

	/** @return array<string, array{string}> every operation that sends the token to Paperless */
	public static function requestsWithTheToken(): array {
		return [
			'connection test' => ['testConnection'],
			'documents' => ['documents'],
			'trash' => ['trash'],
			'tags' => ['tags'],
			'download' => ['downloadDocument'],
			'checksum' => ['documentChecksum'],
			'document by its checksums' => ['findDocument'],
			'upload' => ['uploadDocument'],
			'task' => ['taskStatus'],
		];
	}

	/**
	 * Nextcloud's log writes an exception with the arguments of every frame of its trace, and
	 * those of the exceptions before it, as PHP records them unless zend.exception_ignore_args is
	 * on. A run that can't reach Paperless, the most common failure, must not put the token there.
	 */
	#[DataProvider('requestsWithTheToken')]
	public function testFailedRequestKeepsTheTokenOutOfTheLog(string $operation): void {
		$ignoreArgs = ini_set('zend.exception_ignore_args', '0');
		$failure = null;
		$this->responses[] = static function (array $options) use (&$failure): array {
			// The failure of the HTTP client, with the options of the request in its trace.
			$failure = new RuntimeException('cURL error 6: Could not resolve host: paperless.example.test');
			throw $failure;
		};

		try {
			$this->call($operation);
			self::fail('A request that failed must be reported.');
		} catch (\Throwable $exception) {
			self::assertInstanceOf(RuntimeException::class, $failure);
			self::assertStringContainsString('secret-token', self::logged($failure), 'Without the protection, the log would hold the token.');
			self::assertStringNotContainsString('secret-token', self::logged($exception));
			self::assertStringContainsString('Could not resolve host: paperless.example.test', $exception->getMessage());
		} finally {
			ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
		}
	}

	/** @return array<string, array{string, string}> the message of the HTTP client and the one that the app keeps */
	public static function failureMessages(): array {
		return [
			'the query of a URL' => [
				'cURL error 6: Could not resolve host: paperless.example.test (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://paperless.example.test/api/documents/?page=1&page_size=1',
				'cURL error 6: Could not resolve host: paperless.example.test (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://paperless.example.test/api/documents/',
			],
			'a URL in quotes' => [
				"Client error: `GET https://paperless.example.test/api/tasks/?task_id=abc&page_size=10` resulted in a `401 Unauthorized` response:\n{\"detail\":\"Invalid token.\"}",
				"Client error: `GET https://paperless.example.test/api/tasks/` resulted in a `401 Unauthorized` response:\n{\"detail\":\"Invalid token.\"}",
			],
			'a token that the server repeats' => [
				'Server error: GET https://paperless.example.test/api/documents/ rejected "Token secret-token" and secret-token',
				'Server error: GET https://paperless.example.test/api/documents/ rejected "Token [redacted]" and [redacted]',
			],
		];
	}

	#[DataProvider('failureMessages')]
	public function testFailedRequestNamesItsReasonWithoutQueryAndToken(string $failure, string $message): void {
		$this->responses[] = static function () use ($failure): array {
			throw new RuntimeException($failure);
		};

		try {
			$this->service->documents();
			self::fail('A request that failed must be reported.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame($message, $exception->getMessage());
			self::assertNull($exception->getPrevious());
		}
	}

	/** @return array<string, array{string, bool}> every operation that sends the token, with the setting off and on */
	public static function requestsInTheLocalNetwork(): array {
		$cases = [];
		foreach (self::requestsWithTheToken() as $name => [$operation]) {
			$cases[$name . ', setting off'] = [$operation, false];
			$cases[$name . ', setting on'] = [$operation, true];
		}

		return $cases;
	}

	/**
	 * Nextcloud refuses addresses of the local network unless a request allows them. Every request
	 * of the app to Paperless allows them exactly when the setting "Paperless runs in my local
	 * network" is on, so that no administrator has to turn off the protection of Nextcloud for
	 * every app with allow_local_remote_servers.
	 */
	#[DataProvider('requestsInTheLocalNetwork')]
	public function testRequestsAllowTheLocalNetworkOnlyWithTheSetting(string $operation, bool $localNetwork): void {
		$this->settings['paperless_local_network'] = $localNetwork;
		$this->responses[] = static function (): array {
			throw new RuntimeException('cURL error 7: Failed to connect to paperless.example.test');
		};

		try {
			$this->call($operation, $localNetwork);
		} catch (\Throwable) {
			// The options of the request are what counts, not its failure.
		}

		self::assertCount(1, $this->requests);
		$options = $this->requests[0]['options'];
		if ($localNetwork) {
			self::assertSame(['allow_local_address' => true], $options['nextcloud'] ?? null);
		} else {
			self::assertArrayNotHasKey('nextcloud', $options, 'Without the setting, Nextcloud decides as for any other app.');
		}
	}

	/**
	 * Paperless answers in the shape of the API version that a request asks for, and in that of
	 * its default without one, which changed from 9 to 10 with Paperless-ngx 3.0. Every request asks
	 * for 9, which Paperless-ngx 2.16 to 2.20 and 3.x all serve, so that one shape holds for all.
	 */
	#[DataProvider('requestsWithTheToken')]
	public function testEveryRequestAsksForApiVersion9(string $operation): void {
		$this->responses[] = static function (): array {
			throw new RuntimeException('cURL error 7: Failed to connect to paperless.example.test');
		};

		try {
			$this->call($operation);
		} catch (\Throwable) {
			// The headers of the request are what counts, not its failure.
		}

		self::assertCount(1, $this->requests);
		self::assertSame('application/json; version=9', $this->requests[0]['options']['headers']['Accept']);
	}

	/**
	 * Paperless refuses an API version that it doesn't serve with 406 Not Acceptable, which
	 * Nextcloud's HTTP client throws with the status as its code: Paperless-ngx before 2.16, or a
	 * Paperless of the future that no longer serves version 9.
	 */
	#[DataProvider('requestsWithTheToken')]
	public function testPaperlessWithoutApiVersion9IsNamed(string $operation): void {
		$ignoreArgs = ini_set('zend.exception_ignore_args', '0');
		$this->responses[] = static function (): array {
			throw new RuntimeException('Client error: `GET https://paperless.example.test/api/documents/?page=1` resulted in a `406 Not Acceptable` response: {"detail":"Invalid version in \\"Accept\\" header."}', 406);
		};

		try {
			$this->call($operation);
			self::fail('A Paperless without API version 9 must be reported.');
		} catch (\Throwable $exception) {
			self::assertSame(self::VERSION_REQUIRED, $exception->getMessage());
			self::assertNull($exception->getPrevious());
			self::assertStringNotContainsString('secret-token', self::logged($exception));
		} finally {
			ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
		}
	}

	public function testRefusedApiVersionIsAFailureOfTheRequest(): void {
		$this->responses[] = static function (): array {
			throw new RuntimeException('Client error: `GET https://paperless.example.test/api/documents/` resulted in a `406 Not Acceptable` response', 406);
		};

		$this->expectException(PaperlessVersionException::class);
		$this->service->documents();
	}

	/** An error that comes back as an answer, as to an upload, whose body has the reason. */
	public function testUploadToAPaperlessWithoutApiVersion9WaitsLikeAnUnreachableOne(): void {
		$this->responses[] = [406, ['detail' => 'Invalid version in "Accept" header.']];

		try {
			$this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf');
			self::fail('A Paperless without API version 9 must be reported.');
		} catch (PaperlessUploadException $exception) {
			self::assertSame(self::VERSION_REQUIRED, $exception->getMessage());
			self::assertFalse($exception->permanent, 'Another file would fail the same way: the file is not to blame.');
			self::assertSame(406, $exception->statusCode);
		}
	}

	public function testAnswer406OfAListIsNamedToo(): void {
		$this->responses[] = [406, ['detail' => 'Invalid version in "Accept" header.']];

		$this->expectException(PaperlessVersionException::class);
		$this->expectExceptionMessage(self::VERSION_REQUIRED);
		$this->service->documents();
	}

	public function testConnectionTestAllowsTheLocalNetworkAsAskedAndNotAsSaved(): void {
		$this->settings['paperless_local_network'] = true;
		$this->responses[] = [200, ['count' => 0, 'results' => []]];
		$this->responses[] = [200, ['count' => 0, 'results' => []]];

		$this->service->testConnection('http://paperless:8000', 'other-token', false);
		$this->settings['paperless_local_network'] = false;
		$this->service->testConnection('http://paperless:8000', 'other-token', true);

		self::assertArrayNotHasKey('nextcloud', $this->requests[0]['options']);
		self::assertSame(['allow_local_address' => true], $this->requests[1]['options']['nextcloud']);
	}

	/** @return array<string, array{string, string}> the message of Nextcloud's refusal and the host that the advice names */
	public static function refusedLocalAddresses(): array {
		return [
			'a name without a domain' => ['Host "paperless" violates local access rules', 'paperless'],
			'an address of the network' => ['Host "192.168.1.20" violates local access rules', '192.168.1.20'],
			'a name that points into the network' => ['Host "192.168.1.20" (paperless.lan:8000) violates local access rules', 'paperless.lan'],
			'no host in the message' => ['violates local access rules', 'paperless.example.test'],
		];
	}

	/**
	 * Nextcloud refuses an address of the local network with a message that names neither the
	 * reason nor the way out. The app says both, for the host that the administrator entered.
	 */
	#[DataProvider('refusedLocalAddresses')]
	public function testRefusedLocalAddressSaysHowToAllowIt(string $refusal, string $host): void {
		$ignoreArgs = ini_set('zend.exception_ignore_args', '0');
		$this->responses[] = static function () use ($refusal): array {
			throw new LocalServerException($refusal);
		};

		try {
			$this->service->documents();
			self::fail('A refused address must be reported.');
		} catch (PaperlessLocalNetworkException $exception) {
			self::assertSame($host, $exception->host);
			self::assertSame(
				"Nextcloud blocks connections to local addresses such as {$host}. If Paperless runs in your network, turn on \"Paperless runs in my local network\" and save the settings.",
				$exception->getMessage(),
			);
			self::assertInstanceOf(PaperlessRequestException::class, $exception, 'An upload treats it as any other failed request.');
			self::assertNull($exception->getPrevious());
			self::assertStringNotContainsString('secret-token', self::logged($exception));
		} finally {
			ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
		}
	}

	/**
	 * Nextcloud says "No DNS record found" for a name that it can't look up. Without the setting it
	 * asks only the DNS, not the hosts file of the server, such as Docker's extra_hosts, so the app
	 * names the setting as a way out; with the setting on, the name has no address at all.
	 */
	public function testNameWithoutADnsRecordNamesTheSettingWhileItIsOff(): void {
		$this->responses[] = static function (): array {
			throw new LocalServerException('No DNS record found for paperless.home.arpa');
		};

		try {
			$this->service->documents();
			self::fail('A name without an address must be reported.');
		} catch (PaperlessLocalNetworkException $exception) {
			self::assertSame('paperless.home.arpa', $exception->host);
			self::assertTrue($exception->withoutDnsRecord);
			self::assertSame(
				'Nextcloud found no DNS record for paperless.home.arpa. If the name is set only in the hosts file of the server, or Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
				$exception->getMessage(),
			);
		}
	}

	public function testNameWithoutADnsRecordNamesTheHostOfTheUrlWhenNextcloudDoesNot(): void {
		$this->responses[] = static function (): array {
			throw new LocalServerException('No DNS record found');
		};

		try {
			$this->service->documents();
			self::fail('A name without an address must be reported.');
		} catch (PaperlessLocalNetworkException $exception) {
			self::assertSame('paperless.example.test', $exception->host);
		}
	}

	public function testNameWithoutAnAddressIsNoQuestionOfTheLocalNetworkWithTheSettingOn(): void {
		$this->settings['paperless_local_network'] = true;
		$this->responses[] = static function (): array {
			throw new LocalServerException('No DNS record found for paperless.example.test');
		};

		try {
			$this->service->documents();
			self::fail('A name without an address must be reported.');
		} catch (PaperlessRequestException $exception) {
			self::assertNotInstanceOf(PaperlessLocalNetworkException::class, $exception);
			self::assertSame('No DNS record found for paperless.example.test', $exception->getMessage());
		}
	}

	public function testTaskIsAskedForByItsId(): void {
		$this->responses[] = [200, []];

		$this->service->taskStatus('task-1');

		self::assertSame(self::URL . '/api/tasks/', $this->requests[0]['url']);
		self::assertSame(['task_id' => 'task-1', 'page_size' => 10], $this->requests[0]['options']['query']);
	}

	/**
	 * Import tasks as Paperless lists them, for an upload of the app: API version 9 as
	 * Paperless-ngx 2.20 serves it (TasksViewSerializer) and as 3.x serves it (TaskSerializerV9,
	 * which derives its result from result_data), in a list; version 10 of 3.x
	 * (TaskSerializerV10), in pages, with a status in lower case and the result in result_data.
	 *
	 * @return array<string, array{mixed, array{status: string, message: string, documentId: int|null, listed: bool}}>
	 */
	public static function tasks(): array {
		$v9 = static fn (array $task): array => [[
			'id' => 41,
			'task_id' => 'task-1',
			'task_name' => 'consume_file',
			'task_file_name' => 'police.pdf',
			'date_created' => '2026-10-13T10:00:00+02:00',
			'date_done' => '2026-10-13T10:00:30+02:00',
			'type' => 'manual_task',
			'acknowledged' => false,
			'owner' => 3,
			...$task,
		]];
		$v10 = static fn (array $task): array => ['count' => 1, 'next' => null, 'previous' => null, 'results' => [[
			'id' => 41,
			'task_id' => 'task-1',
			'task_type' => 'consume_file',
			'task_type_display' => 'Consume File',
			'trigger_source' => 'api_upload',
			'trigger_source_display' => 'API Upload',
			'date_created' => '2026-10-13T10:00:00+02:00',
			'date_started' => '2026-10-13T10:00:01+02:00',
			'date_done' => '2026-10-13T10:00:30+02:00',
			'duration_seconds' => 29.0,
			'wait_time_seconds' => 1.0,
			'input_data' => ['filename' => 'police.pdf'],
			'acknowledged' => false,
			'owner' => 3,
			...$task,
		]]];

		return [
			'2.20, pending' => [$v9(['status' => 'PENDING', 'result' => null, 'related_document' => null]), ['status' => 'PENDING', 'message' => '', 'documentId' => null, 'listed' => true]],
			'2.20, success' => [
				$v9(['status' => 'SUCCESS', 'result' => 'Success. New document id 456 created', 'related_document' => '456']),
				['status' => 'SUCCESS', 'message' => 'Success. New document id 456 created', 'documentId' => 456, 'listed' => true],
			],
			'2.20, success without related_document' => [
				$v9(['status' => 'SUCCESS', 'result' => 'Success. New document id 457 created']),
				['status' => 'SUCCESS', 'message' => 'Success. New document id 457 created', 'documentId' => 457, 'listed' => true],
			],
			'2.20, failure' => [
				$v9(['status' => 'FAILURE', 'result' => 'police.pdf: Unsupported mime type application/octet-stream', 'related_document' => null]),
				['status' => 'FAILURE', 'message' => 'police.pdf: Unsupported mime type application/octet-stream', 'documentId' => null, 'listed' => true],
			],
			'2.20, duplicate, whose related_document is the original' => [
				$v9(['status' => 'FAILURE', 'result' => 'Not consuming police.pdf: It is a duplicate of Police (#123).', 'related_document' => '123']),
				['status' => 'FAILURE', 'message' => 'Not consuming police.pdf: It is a duplicate of Police (#123).', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 9, success' => [
				$v9(['status' => 'SUCCESS', 'result' => 'Success. New document id 456 created', 'related_document' => 456, 'duplicate_documents' => []]),
				['status' => 'SUCCESS', 'message' => 'Success. New document id 456 created', 'documentId' => 456, 'listed' => true],
			],
			'3.x in version 9, duplicate' => [
				$v9(['status' => 'FAILURE', 'result' => 'Not consuming: It is a duplicate of document #123', 'related_document' => 123, 'duplicate_documents' => [['id' => 123, 'title' => 'Police', 'deleted_at' => null]]]),
				['status' => 'FAILURE', 'message' => 'Not consuming: It is a duplicate of document #123', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 9, revoked' => [
				$v9(['status' => 'REVOKED', 'result' => null, 'related_document' => null, 'duplicate_documents' => []]),
				['status' => 'REVOKED', 'message' => '', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 10, started' => [$v10(['status' => 'started', 'status_display' => 'Started', 'result_data' => null, 'related_document_ids' => []]), ['status' => 'STARTED', 'message' => '', 'documentId' => null, 'listed' => true]],
			'3.x in version 10, success' => [
				$v10(['status' => 'success', 'status_display' => 'Success', 'result_data' => ['document_id' => 456], 'related_document_ids' => [456]]),
				['status' => 'SUCCESS', 'message' => '', 'documentId' => 456, 'listed' => true],
			],
			'3.x in version 10, success with the ID only in related_document_ids' => [
				$v10(['status' => 'success', 'status_display' => 'Success', 'result_data' => [], 'related_document_ids' => [458]]),
				['status' => 'SUCCESS', 'message' => '', 'documentId' => 458, 'listed' => true],
			],
			'3.x in version 10, stopped, such as after a split at a barcode' => [
				$v10(['status' => 'success', 'status_display' => 'Success', 'result_data' => ['reason' => 'Barcode splitting complete!'], 'related_document_ids' => []]),
				['status' => 'SUCCESS', 'message' => 'Barcode splitting complete!', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 10, failure' => [
				$v10(['status' => 'failure', 'status_display' => 'Failure', 'result_data' => [
					'error_type' => 'ConsumerError',
					'error_message' => 'police.pdf: Unsupported mime type application/octet-stream',
					'traceback' => "Traceback (most recent call last):\n  File \"consumer.py\"",
				], 'related_document_ids' => []]),
				['status' => 'FAILURE', 'message' => 'police.pdf: Unsupported mime type application/octet-stream', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 10, failure with its type alone' => [
				$v10(['status' => 'failure', 'status_display' => 'Failure', 'result_data' => ['error_type' => 'WorkerLostError', 'error_message' => ''], 'related_document_ids' => []]),
				['status' => 'FAILURE', 'message' => 'WorkerLostError', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 10, duplicate' => [
				$v10(['status' => 'failure', 'status_display' => 'Failure', 'result_data' => ['duplicate_of' => 123, 'duplicate_in_trash' => false], 'related_document_ids' => [123]]),
				['status' => 'FAILURE', 'message' => 'Not consuming: It is a duplicate of document #123', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 10, duplicate of a document in the trash' => [
				$v10(['status' => 'failure', 'status_display' => 'Failure', 'result_data' => ['duplicate_of' => 123, 'duplicate_in_trash' => true], 'related_document_ids' => [123]]),
				['status' => 'FAILURE', 'message' => 'Not consuming: It is a duplicate of document #123, which is in the trash of Paperless', 'documentId' => null, 'listed' => true],
			],
			'3.x in version 10, revoked' => [
				$v10(['status' => 'revoked', 'status_display' => 'Revoked', 'result_data' => null, 'related_document_ids' => []]),
				['status' => 'REVOKED', 'message' => '', 'documentId' => null, 'listed' => true],
			],
			'a status of the future' => [$v9(['status' => 'Retrying', 'result' => ['not text'], 'related_document' => 'none']), ['status' => 'RETRYING', 'message' => '', 'documentId' => null, 'listed' => true]],
			'no status' => [[['task_id' => 'task-1']], ['status' => 'PENDING', 'message' => '', 'documentId' => null, 'listed' => true]],
			'not listed yet, in version 9' => [[], ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'documentId' => null, 'listed' => false]],
			'not listed yet, in version 10' => [['count' => 0, 'results' => []], ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'documentId' => null, 'listed' => false]],
		];
	}

	/** @param array{status: string, message: string, documentId: int|null, listed: bool} $expected */
	#[DataProvider('tasks')]
	public function testTaskStatusInEveryShapeOfPaperless(mixed $body, array $expected): void {
		$this->responses[] = [200, $body];

		self::assertSame($expected, $this->service->taskStatus('task-1'));
	}

	/**
	 * A file of the inbox whose task Paperless has not finished a day after the upload may be in
	 * Paperless already. Paperless-ngx keeps the SHA-256 of an original since 3.0 and its MD5 before,
	 * so the SHA-256 is asked for first, then the MD5.
	 *
	 * @return array<string, array{list<mixed>, int|null, int}>
	 */
	public static function documentsByChecksum(): array {
		$none = ['count' => 0, 'next' => null, 'previous' => null, 'results' => []];
		$found = static fn (mixed $id): array => ['count' => 1, 'next' => null, 'previous' => null, 'results' => [['id' => $id]]];

		return [
			'3.x, by its SHA-256' => [[$found(456)], 456, 1],
			'2.x, by its MD5' => [[$none, $found(457)], 457, 2],
			'an ID as a text' => [[$found('458')], 458, 1],
			'not in Paperless' => [[$none, $none], null, 2],
			'an answer without an ID' => [[$found(null), $found('none')], null, 2],
		];
	}

	/** @param list<mixed> $bodies */
	#[DataProvider('documentsByChecksum')]
	public function testDocumentIsFoundByTheChecksumsOfItsOriginal(array $bodies, ?int $expected, int $requests): void {
		foreach ($bodies as $body) {
			$this->responses[] = [200, $body];
		}
		$content = new DocumentFingerprint(hash('sha256', '%PDF-inbox'), hash('md5', '%PDF-inbox'));

		self::assertSame($expected, $this->service->findDocument($content));

		self::assertCount($requests, $this->requests);
		self::assertSame(self::URL . '/api/documents/', $this->requests[0]['url']);
		self::assertSame(['checksum__iexact' => $content->sha256, 'fields' => 'id', 'page_size' => 1], $this->requests[0]['options']['query']);
		if ($requests === 2) {
			self::assertSame(['checksum__iexact' => $content->md5, 'fields' => 'id', 'page_size' => 1], $this->requests[1]['options']['query']);
		}
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

		self::assertSame([['id' => 1]], $this->service->documents()->documents);
	}

	public function testUnconfiguredAppDoesNotCallPaperless(): void {
		$this->settings = [];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless is not configured.');
		$this->service->documents();
	}

	/**
	 * Calls an operation of the client that sends the token, with arguments that work; the test of
	 * the connection allows the local network as $localNetwork says, the others as the settings do.
	 */
	private function call(string $operation, bool $localNetwork = false): void {
		match ($operation) {
			'testConnection' => $this->service->testConnection(self::URL, 'secret-token', $localNetwork),
			'documents' => $this->service->documents(),
			'trash' => $this->service->trash(),
			'tags' => $this->service->tags(),
			'downloadDocument' => $this->service->downloadDocument(123, true, self::discard()),
			'documentChecksum' => $this->service->documentChecksum(123, true),
			'findDocument' => $this->service->findDocument(new DocumentFingerprint(hash('sha256', '%PDF-inbox'), hash('md5', '%PDF-inbox'))),
			'uploadDocument' => $this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf'),
			'taskStatus' => $this->service->taskStatus('task-1'),
		};
	}

	/**
	 * What Nextcloud's log writes of an exception: its class, its message and its trace with the
	 * arguments of every frame, and the same of every exception before it.
	 */
	private static function logged(\Throwable $exception): string {
		$entries = [];
		for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
			$entries[] = ['class' => $current::class, 'message' => $current->getMessage(), 'trace' => $current->getTrace()];
		}
		$json = json_encode($entries, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
		self::assertIsString($json);

		return $json;
	}

	/** @return list<array{id: int}> the documents with the IDs from $first to $last */
	private static function entries(int $first, int $last): array {
		return array_map(static fn (int $id): array => ['id' => $id], range($first, $last));
	}

	/** @param array<string, mixed> $options */
	private function respond(string $method, string $url, array $options): IResponse {
		$this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
		$next = array_shift($this->responses);
		self::assertNotNull($next, "Unexpected request {$method} {$url}");
		$answer = $next instanceof Closure ? $next($options) : $next;
		[$status, $body] = $answer;
		// The names of headers are case-insensitive, as in Guzzle.
		$headers = array_change_key_case($answer[2] ?? [], CASE_LOWER);
		if (is_array($body)) {
			$body = json_encode($body, JSON_THROW_ON_ERROR);
		}
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[strtolower($name)] ?? '');

		return $response;
	}

	/** The target of a redirect, as Guzzle passes it to on_redirect. */
	private static function uri(string $uri): \Stringable {
		return new class($uri) implements \Stringable {
			public function __construct(
				private string $uri,
			) {
			}

			public function __toString(): string {
				return $this->uri;
			}
		};
	}

	/** A write of a download that reads nothing. */
	private static function discard(): Closure {
		return static function ($download): void {
		};
	}

	/** A write of a download that a failed download must never reach. */
	private static function noWrite(): Closure {
		return static function ($download): void {
			self::fail('A failed download must not reach the write.');
		};
	}

	/** @return resource a file of the inbox */
	private function source(string $content) {
		$source = fopen('php://temp', 'w+b');
		self::assertIsResource($source);
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
