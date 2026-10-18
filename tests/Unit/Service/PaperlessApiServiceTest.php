<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use Closure;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Service\PaperlessApiService;
use OCA\PaperlessSync\Service\PaperlessClientFactory;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
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
	/** What the checklist says of a trash that lists only the documents of the account, when it can't count those of others. */
	private const TRASH_HINT = 'Paperless shows the account in its trash only the documents that it owns and those without an owner. Trashed documents of other owners count as missing: they move to the deleted folder only after the required missing scans. Make the account of the app the owner of the documents to mirror.';
	/** The same, when Paperless refuses to name the account without the permission View for UI Settings. */
	private const TRASH_UNKNOWN_HINT = 'Paperless shows the account in its trash only the documents that it owns and those without an owner. Trashed documents of other owners count as missing: they move to the deleted folder only after the required missing scans. Make the account of the app the owner of the documents to mirror, and give it the permission View for UI Settings, so that this checklist can check it.';
	/** What the checklist says of the visible documents of other owners. */
	private const FOREIGN_DOCUMENTS_HINT = 'Paperless leaves the documents of other owners out of the trash that it shows the account, and the account sees %s of them. When they go to the trash, their copies count as missing and move to the deleted folder only after the required missing scans. Make the account of the app their owner.';
	/** What the checklist says of the token of a superuser. */
	private const SUPERUSER_HINT = 'The account is a superuser: the trash shows it the documents of every owner, but its token controls all of Paperless. Use an account that is no superuser, and make it the owner of the documents to mirror.';
	/** What the inbox needs, when Paperless refuses to name the account without the permission View for UI Settings. */
	private const UI_SETTINGS_HINT = 'In Paperless, under Users & Groups, give the account the permission View for UI Settings, so that this checklist can read its permissions. The inbox needs Add for Documents and View for Paperless Tasks.';
	/** The query of the documents that the account sees and another account owns, for the account of self::account(). */
	private const FOREIGN_QUERY = ['page' => 1, 'page_size' => 1, 'owner__isnull' => 0, 'owner__id__none' => 3];
	/** What the checklist says when no Paperless answers at the URL. */
	private const NO_API_HINT = 'Check the URL: when Paperless runs under a path, such as https://example.com/paperless, the URL needs that path, and a proxy in front of Paperless may answer with its login page.';
	/** What the checklist says when Paperless answers with an error of its own. */
	private const STATUS_HINT = 'Check that Paperless runs, and the log of Paperless or of the proxy in front of it.';
	/** What the checklist says of a certificate that Nextcloud doesn't trust. */
	private const CERTIFICATE_HINT = 'Nextcloud does not trust the certificate of Paperless. Use a certificate of a public certificate authority, or import your own with occ security:certificates:import.';
	/** What a Paperless that doesn't serve version 9 of its API answers to every request. */
	private const VERSION_REQUIRED = 'Paperless does not serve version 9 of its API, which Paperless Sync uses (HTTP 406 Not Acceptable). Paperless Sync needs Paperless-ngx 2.16 or newer; with a newer Paperless-ngx, update Paperless Sync.';

	/** @var list<array{method: string, url: string, options: array<string, mixed>}> */
	private array $requests = [];
	/** @var list<array{int, mixed}|array{int, mixed, array<string, string>}|Closure> the status, the body and the headers of each response, or a closure that returns them */
	private array $responses = [];
	/** @var ITempManager&MockObject */
	private ITempManager $tempManager;
	/** @var list<string> */
	private array $temporaryFiles = [];
	private IClientService $clientService;
	private IRemoteHostValidator $remoteHostValidator;
	/** The client of the Paperless of the tests, at self::URL with the token secret-token. */
	private PaperlessClientInterface $service;

	protected function setUp(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $url, array $options): IResponse => $this->respond('GET', $url, $options));
		$client->method('post')->willReturnCallback(fn (string $url, array $options): IResponse => $this->respond('POST', $url, $options));
		$this->clientService = $this->createMock(IClientService::class);
		$this->clientService->method('newClient')->willReturn($client);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'paperless-sync-test-');
			self::assertIsString($path);
			$this->temporaryFiles[] = $path;

			return $path;
		});
		// Nextcloud's check of an address against the local network, for two of its addresses.
		$this->remoteHostValidator = $this->createMock(IRemoteHostValidator::class);
		$this->remoteHostValidator->method('isValid')->willReturnCallback(static fn (string $host): bool => !in_array($host, ['paperless', '192.168.1.20'], true));
		$this->service = $this->client();
	}

	/**
	 * A client of the connection with $url, $token and $localNetwork, as PaperlessClientFactory
	 * makes it for a run, by default that of the Paperless of the tests.
	 */
	private function client(string $url = self::URL, string $token = 'secret-token', bool $localNetwork = false): PaperlessClientInterface {
		return (new PaperlessClientFactory($this->clientService, $this->tempManager, $this->remoteHostValidator))
			->connect(new PaperlessConnection($url, $token, $localNetwork));
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

		$server = $this->client('https://other.example.test/', 'other-token')->testConnection();

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

		self::assertSame(['version' => $version, 'apiVersion' => '9'], $this->service->testConnection());
	}

	/** @return array<string, array{string, string}> the URL and the token of a connection that is not complete */
	public static function incompleteConnections(): array {
		return [
			'without a token' => [self::URL, ''],
			'without a URL' => ['', 'secret-token'],
		];
	}

	#[DataProvider('incompleteConnections')]
	public function testConnectionTestNeedsAUrlAndAToken(string $url, string $token): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless is not configured.');
		$this->client($url, $token)->testConnection();
	}

	/**
	 * Each client goes to the Paperless of its own connection, with its token, whatever another
	 * client of the same factory does, and whatever the settings say meanwhile.
	 */
	public function testEveryClientKeepsToItsConnection(): void {
		$this->responses[] = [200, self::page(0)];
		$this->responses[] = [200, self::page(0)];
		$other = $this->client('https://other.example.test', 'other-token');

		$this->service->tags();
		$other->tags();

		self::assertSame([self::URL . '/api/tags/', 'https://other.example.test/api/tags/'], array_column($this->requests, 'url'));
		self::assertSame(['Token secret-token', 'Token other-token'], array_map(static fn (array $request): mixed => $request['options']['headers']['Authorization'] ?? null, $this->requests));
	}

	public function testDocumentsAreReadFromEveryPageInTheOrderOfTheirIds(): void {
		$this->responses[] = [200, ['count' => 2001, 'results' => self::entries(1, 1000)]];
		$this->responses[] = [200, ['count' => 2001, 'results' => self::entries(1001, 2000)]];
		$this->responses[] = [200, ['count' => 2001, 'results' => self::entries(2001, 2001)]];

		$scan = $this->service->documents();

		self::assertSame(self::entries(1, 2001), $scan->documents);
		self::assertTrue($scan->complete);
		self::assertSame(2001, $scan->listed);
		$fields = 'id,title,correspondent,document_type,storage_path,created,added,modified,tags,original_file_name,archived_file_name,mime_type,owner,archive_serial_number,page_count,custom_fields';
		self::assertSame([
			['ordering' => 'id', 'fields' => $fields, 'truncate_content' => 'true', 'page' => 1, 'page_size' => 1000],
			['ordering' => 'id', 'fields' => $fields, 'truncate_content' => 'true', 'page' => 2, 'page_size' => 1000],
			['ordering' => 'id', 'fields' => $fields, 'truncate_content' => 'true', 'page' => 3, 'page_size' => 1000],
		], array_map(static fn (array $request): mixed => $request['options']['query'], $this->requests), 'Ordered by ID, a document added during the scan joins the last page.');
		self::assertSame(self::URL . '/api/documents/', $this->requests[2]['url']);
		self::assertSame('Token secret-token', $this->requests[0]['options']['headers']['Authorization']);
	}

	/**
	 * A run asks Paperless only for the fields that it reads, so a field that the code reads from
	 * a document and the list leaves out would be empty in every run: the path template would put
	 * every document into the folder of an empty value. The text of the documents stays at home.
	 */
	public function testTheDocumentsCarryEveryFieldThatTheAppReadsAndNoText(): void {
		$read = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
				preg_match_all('/\$document\[\'([a-z_]+)\'\]/', (string)file_get_contents($file->getPathname()), $matches);
				$read = [...$read, ...$matches[1]];
			}
		}
		// The trash is read whole: Paperless ignores the list of fields there. The notes are asked for
		// only for the metadata next to the archive, see testTheMetadataNextToTheArchiveAsksForTheNotes().
		$read = array_values(array_diff(array_unique($read), ['deleted_at', 'notes']));

		self::assertNotEmpty($read);
		self::assertSame([], array_values(array_diff($read, PaperlessApiService::DOCUMENT_FIELDS)), 'Every field that the app reads from a document is asked for.');
		self::assertNotContains('content', PaperlessApiService::DOCUMENT_FIELDS);
		self::assertNotContains('notes', PaperlessApiService::DOCUMENT_FIELDS);
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

	public function testUsersByTheirUsernames(): void {
		$this->responses[] = [200, ['count' => 3, 'results' => [
			['id' => 3, 'username' => 'dennis', 'first_name' => 'Dennis'],
			['id' => 4, 'username' => 'paperless-sync'],
			['id' => 5, 'username' => null],
		]]];

		self::assertSame(['3' => 'dennis', '4' => 'paperless-sync'], $this->service->users());
		self::assertSame(self::URL . '/api/users/', $this->requests[0]['url']);
	}

	/**
	 * Each choice of a select field has an ID, which a document holds, and a label; a choice
	 * of another shape has neither and is left out.
	 */
	public function testCustomFieldsWithTheirTypesAndTheChoicesOfSelectFields(): void {
		$this->responses[] = [200, ['count' => 5, 'results' => [
			['id' => 1, 'name' => 'Invoice number', 'data_type' => 'string', 'extra_data' => ['select_options' => null]],
			['id' => 2, 'name' => 'Status', 'data_type' => 'select', 'extra_data' => ['select_options' => [
				['id' => 'oP1', 'label' => 'Open'],
				['id' => 'pA2', 'label' => 'Paid'],
			]]],
			['id' => 3, 'name' => 'Priority', 'data_type' => 'select', 'extra_data' => ['select_options' => [
				['id' => 'aB3', 'label' => 'High'],
				['id' => 'cD4', 'label' => null],
				['label' => 'Medium'],
				'Low',
			]]],
			['id' => 4, 'name' => 'Paid', 'data_type' => true],
			['id' => 5, 'data_type' => 'string'],
		]]];

		self::assertSame([
			'1' => ['name' => 'Invoice number', 'type' => 'string', 'options' => []],
			'2' => ['name' => 'Status', 'type' => 'select', 'options' => ['oP1' => 'Open', 'pA2' => 'Paid']],
			'3' => ['name' => 'Priority', 'type' => 'select', 'options' => ['aB3' => 'High']],
			'4' => ['name' => 'Paid', 'type' => '', 'options' => []],
		], $this->service->customFields());
		self::assertSame(self::URL . '/api/custom_fields/', $this->requests[0]['url']);
	}

	public function testNewestDocumentsAreOnePageOrderedByWhenTheyWereAdded(): void {
		// A Paperless that ignores page_size, as a proxy might, still yields only the documents asked for.
		$this->responses[] = [200, ['count' => 7, 'results' => self::entries(1, 7)]];

		self::assertSame(self::entries(1, 5), $this->service->newestDocuments(5));
		self::assertSame(self::URL . '/api/documents/', $this->requests[0]['url']);
		self::assertSame([
			'page' => 1,
			'page_size' => 5,
			'ordering' => '-added',
			'fields' => implode(',', PaperlessApiService::DOCUMENT_FIELDS),
			'truncate_content' => 'true',
		], $this->requests[0]['options']['query']);
		self::assertSame('Token secret-token', $this->requests[0]['options']['headers']['Authorization']);
	}

	/** @return array<string, array{string, string}> */
	public static function smallLists(): array {
		return [
			'tags' => ['tags', '/api/tags/'],
			'correspondents' => ['correspondents', '/api/correspondents/'],
			'document types' => ['documentTypes', '/api/document_types/'],
			'storage paths' => ['storagePaths', '/api/storage_paths/'],
			'users' => ['users', '/api/users/'],
			'custom fields' => ['customFields', '/api/custom_fields/'],
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
		$this->responses[] = [200, null];

		$this->client(localNetwork: true)->downloadDocument(123, true, self::discard());

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
		$service = new PaperlessApiService(new PaperlessConnection(self::URL, 'secret-token', false), $clientService, $tempManager, $this->createStub(IRemoteHostValidator::class));

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

	/** The run of one document reads it with the fields of the scan of a run, so that it takes the same path. */
	public function testOneDocumentIsReadWithTheFieldsOfARun(): void {
		$document = ['id' => 123, 'title' => 'Strom August', 'tags' => [2], 'custom_fields' => []];
		$this->responses[] = [200, $document];

		self::assertSame($document, $this->service->document(123));
		self::assertSame(self::URL . '/api/documents/123/', $this->requests[0]['url']);
		self::assertSame(['fields' => implode(',', PaperlessApiService::DOCUMENT_FIELDS), 'truncate_content' => 'true'], $this->requests[0]['options']['query']);
		self::assertFalse($this->requests[0]['options']['http_errors']);
		self::assertSame('Token secret-token', $this->requests[0]['options']['headers']['Authorization']);
	}

	/**
	 * The notes of a document, which may be long, reach Nextcloud only for the metadata next to the
	 * archive: then the scan of a run and the read of one document ask for them as well.
	 */
	public function testTheMetadataNextToTheArchiveAsksForTheNotes(): void {
		$this->responses[] = [200, ['count' => 1, 'results' => self::entries(1, 1)]];
		$this->responses[] = [200, ['id' => 123, 'title' => 'Strom August', 'notes' => []]];

		$this->service->documents(true);
		$this->service->document(123, true);

		$fields = implode(',', [...PaperlessApiService::DOCUMENT_FIELDS, 'notes']);
		self::assertSame(['ordering' => 'id', 'fields' => $fields, 'truncate_content' => 'true', 'page' => 1, 'page_size' => 1000], $this->requests[0]['options']['query']);
		self::assertSame(['fields' => $fields, 'truncate_content' => 'true'], $this->requests[1]['options']['query']);
	}

	/** The answer of a real Paperless for one document: the fields of a run, as a scan reads them. */
	#[DataProvider('paperlessLines')]
	public function testPaperlessSendsOneDocumentWithTheFieldsOfARun(string $line): void {
		$this->responses[] = self::recorded($line, 'document');

		$document = $this->service->document((int)self::recording($line, 'document')['body']['id']);

		self::assertNotNull($document);
		$fields = PaperlessApiService::DOCUMENT_FIELDS;
		sort($fields);
		$received = array_keys($document);
		sort($received);
		self::assertSame($fields, $received);
	}

	/**
	 * The answers of a real Paperless while a run writes the metadata next to the archive: the
	 * fields of a run and the notes, each with the name of its author, in the list as for one
	 * document by its ID.
	 */
	#[DataProvider('paperlessLines')]
	public function testPaperlessSendsTheNotesWithTheirAuthors(string $line): void {
		$this->responses[] = self::recorded($line, 'documents-notes');
		$this->responses[] = self::recorded($line, 'document-notes');

		$scan = $this->service->documents(true);
		$document = $this->service->document((int)self::recording($line, 'document-notes')['body']['id'], true);

		$fields = [...PaperlessApiService::DOCUMENT_FIELDS, 'notes'];
		sort($fields);
		self::assertCount(3, $scan->documents);
		foreach ([...$scan->documents, $document] as $received) {
			self::assertIsArray($received);
			$keys = array_keys($received);
			sort($keys);
			self::assertSame($fields, $keys);
			self::assertIsList($received['notes']);
		}
		self::assertNotNull($document);
		self::assertIsArray($document['notes'][0]);
		self::assertSame('contract-admin', $document['notes'][0]['user']['username']);
		self::assertSame(self::recordedQuery($line, 'documents-notes'), self::sentQuery($this->requests[0]), 'The contract tests ask Paperless as the app does.');
		self::assertSame(self::recordedQuery($line, 'document-notes'), self::sentQuery($this->requests[1]));
	}

	/** A real Paperless answers 404 for a document in its trash, which a call then leaves to the runs. */
	#[DataProvider('paperlessLines')]
	public function testPaperlessShowsNoDocumentInItsTrash(string $line): void {
		$this->responses[] = self::recorded($line, 'document-trashed');

		self::assertNull($this->service->document(1));
	}

	/** Paperless answers 404 for a document in its trash, a deleted one and one that the account may not view. */
	public function testADocumentThatPaperlessDoesNotShowIsNone(): void {
		$this->responses[] = [404, ['detail' => 'No Document matches the given query.']];

		self::assertNull($this->service->document(123));
	}

	/** @return array<string, array{int, mixed, string}> */
	public static function invalidDocumentAnswers(): array {
		return [
			'an error of Paperless' => [500, ['detail' => 'Server error'], 'Paperless could not query document 123: HTTP 500.'],
			'a token that Paperless refuses' => [401, ['detail' => 'Invalid token.'], 'Paperless could not query document 123: HTTP 401.'],
			'another document' => [200, ['id' => 124, 'title' => 'Wasser'], 'Paperless returned no document 123.'],
			'a list' => [200, [['id' => 123]], 'Paperless returned no document 123.'],
			'text' => [200, '"document"', 'Paperless returned no document 123.'],
			'no ID' => [200, ['title' => 'Strom August'], 'Paperless returned no document 123.'],
		];
	}

	#[DataProvider('invalidDocumentAnswers')]
	public function testAnInvalidAnswerForOneDocumentFailsTheRun(int $status, mixed $body, string $message): void {
		$this->responses[] = [$status, $body];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		$this->service->document(123);
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
		self::assertCount(1, $request['options']['multipart'], 'Without tags, the file is the only part.');
		self::assertSame('application/json; version=9', $request['options']['headers']['Accept']);
		self::assertSame(180, $request['options']['timeout']);
		self::assertFalse($request['options']['http_errors'], 'Errors must come back as responses, with the reason in their body.');
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'), 'The copy of the file must be removed.');
	}

	/** Paperless takes the tags of a new document as one field tags for each, after the file. */
	public function testUploadSendsEachTagAsAPartOfItsOwn(): void {
		$sent = [];
		$this->responses[] = function (array $options) use (&$sent): array {
			$sent = $this->uploadedFile($options);
			return [200, '"task"'];
		};

		self::assertSame('task', $this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf', [12, 7]));

		$parts = $this->requests[0]['options']['multipart'];
		self::assertSame('%PDF-inbox', $sent['content']);
		self::assertSame(['name' => 'tags', 'contents' => '12'], $parts[1]);
		self::assertSame(['name' => 'tags', 'contents' => '7'], $parts[2]);
		self::assertCount(3, $parts);
	}

	/** A new tag that Paperless assigns to no other document on its own: its matching algorithm is None. */
	public function testCreatesATagWithoutAMatch(): void {
		$this->responses[] = [201, ['id' => 41, 'name' => 'Steuer', 'matching_algorithm' => 0]];

		self::assertSame(41, $this->service->createTag('Steuer'));

		$request = $this->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame(self::URL . '/api/tags/', $request['url']);
		self::assertSame(['name' => 'Steuer', 'matching_algorithm' => 0, 'owner' => null], $request['options']['json'], 'The new tag has no match and no owner.');
		self::assertSame('application/json; version=9', $request['options']['headers']['Accept']);
		self::assertSame('Token secret-token', $request['options']['headers']['Authorization']);
		self::assertFalse($request['options']['http_errors'], 'Errors must come back as responses, with the reason in their body.');
		self::assertSame(60, $request['options']['timeout']);
		self::assertArrayNotHasKey('nextcloud', $request['options'], 'The local network stays closed unless the setting opens it.');
	}

	public function testCreatesATagAlsoInAPaperlessOfTheLocalNetwork(): void {
		$this->responses[] = [201, ['id' => '42']];

		self::assertSame(42, $this->client(localNetwork: true)->createTag('2026'));

		self::assertSame(['allow_local_address' => true], $this->requests[0]['options']['nextcloud']);
	}

	/** @return array<string, array{int, mixed, string}> the answer of Paperless and the message of the failure */
	public static function failedTagCreations(): array {
		return [
			'an account without Add for Tags' => [403, ['detail' => 'You do not have permission to perform this action.'], 'Paperless could not create the tag Steuer: HTTP 403: You do not have permission to perform this action.'],
			'a name that is too long' => [400, ['name' => ['Ensure this field has no more than 128 characters.']], 'Paperless could not create the tag Steuer: HTTP 400: name: Ensure this field has no more than 128 characters.'],
			'an answer without a reason' => [502, '', 'Paperless could not create the tag Steuer: HTTP 502.'],
		];
	}

	#[DataProvider('failedTagCreations')]
	public function testATagThatPaperlessDoesNotCreateIsReportedWithItsReason(int $status, mixed $body, string $message): void {
		$this->responses[] = [$status, $body];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$this->service->createTag('Steuer');
	}

	/** @return array<string, array{mixed}> answers to the creation of a tag that name no tag */
	public static function answersWithoutATag(): array {
		return [
			'no ID' => [['name' => 'Steuer']],
			'an ID of zero' => [['id' => 0]],
			'a text' => ['"created"'],
		];
	}

	#[DataProvider('answersWithoutATag')]
	public function testATagWithoutAnIdIsRejected(mixed $body): void {
		$this->responses[] = [201, $body];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless did not return the ID of the new tag Steuer.');

		$this->service->createTag('Steuer');
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
		$service = new PaperlessApiService(new PaperlessConnection(self::URL, 'secret-token', false), $clientService, $tempManager, $this->createStub(IRemoteHostValidator::class));

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
		$service = new PaperlessApiService(new PaperlessConnection(self::URL, 'secret-token', false), $clientService, $tempManager, $this->createStub(IRemoteHostValidator::class));
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
			'one document' => ['document'],
			'tags' => ['tags'],
			'download' => ['downloadDocument'],
			'checksum' => ['documentChecksum'],
			'document by its checksums' => ['findDocument'],
			'upload' => ['uploadDocument'],
			'creation of a tag' => ['createTag'],
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
		$this->service = $this->client(localNetwork: $localNetwork);
		$this->responses[] = static function (): array {
			throw new RuntimeException('cURL error 7: Failed to connect to paperless.example.test');
		};

		try {
			$this->call($operation);
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

	/** Each client allows the local network as its connection does, whatever another one allows. */
	public function testConnectionTestAllowsTheLocalNetworkAsItsConnectionDoes(): void {
		$this->responses[] = [200, ['count' => 0, 'results' => []]];
		$this->responses[] = [200, ['count' => 0, 'results' => []]];
		$closed = $this->client('http://paperless:8000', 'other-token', false);
		$open = $this->client('http://paperless:8000', 'other-token', true);

		$closed->testConnection();
		$open->testConnection();

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
		$this->responses[] = static function (): array {
			throw new LocalServerException('No DNS record found for paperless.example.test');
		};

		try {
			$this->client(localNetwork: true)->documents();
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

	/** No request of a connection without a token leaves Nextcloud, not even with an empty header. */
	#[DataProvider('requestsWithTheToken')]
	public function testUnconfiguredAppDoesNotCallPaperless(string $operation): void {
		$this->service = $this->client(self::URL, '');

		try {
			$this->call($operation);
			self::fail('A connection without a token must not reach Paperless.');
		} catch (UnexpectedValueException $exception) {
			self::assertSame('Paperless is not configured.', $exception->getMessage());
		}
		self::assertSame([], $this->requests);
		self::assertSame([], array_filter($this->temporaryFiles, 'file_exists'));
	}

	/**
	 * The checklist reads one entry of every list that the enabled modules need, as a run reads
	 * them, and asks Paperless about the account of the token, with the token and its timeouts.
	 * Each line says how many entries the account sees, and the version of Paperless. The trash
	 * passes when the account owns every document that it sees, or they have no owner.
	 */
	public function testPreflightChecksEveryListThatTheModulesNeed(): void {
		$this->responses[] = [200, self::page(12), ['X-Version' => '2.20.15']];
		$this->responses[] = [200, self::account(false, ['view_document', 'add_document', 'view_paperlesstask'])];
		foreach ([2, 0, 3, 4, 0, 5] as $count) {
			$this->responses[] = [200, self::page($count)];
		}

		$checks = $this->preflight();

		self::assertEquals([
			ConnectionCheck::passed('paperless', 'Paperless-ngx %s answers at this URL.', ['2.20.15']),
			ConnectionCheck::passed('documents', 'Visible to the account: %s', ['12']),
			ConnectionCheck::passed('trash', 'Visible to the account: %s', ['2']),
			ConnectionCheck::passed('correspondents', 'Visible to the account: %s', ['3']),
			ConnectionCheck::passed('document_types', 'Visible to the account: %s', ['4']),
			ConnectionCheck::passed('storage_paths', 'Visible to the account: %s', ['0']),
			ConnectionCheck::passed('tags', 'Visible to the account: %s', ['5']),
			ConnectionCheck::passed('upload', 'The account may add documents and view the tasks that import them.'),
		], $checks);
		self::assertSame([
			self::URL . '/api/documents/',
			self::URL . '/api/ui_settings/',
			self::URL . '/api/trash/',
			self::URL . '/api/documents/',
			self::URL . '/api/correspondents/',
			self::URL . '/api/document_types/',
			self::URL . '/api/storage_paths/',
			self::URL . '/api/tags/',
		], array_column($this->requests, 'url'));
		self::assertSame(['page' => 1, 'page_size' => 1], $this->requests[0]['options']['query']);
		self::assertSame([], $this->requests[1]['options']['query']);
		self::assertSame(self::FOREIGN_QUERY, $this->requests[3]['options']['query'], 'The documents with an owner other than the account.');
		foreach ($this->requests as $request) {
			self::assertSame('GET', $request['method']);
			self::assertSame('Token secret-token', $request['options']['headers']['Authorization']);
			self::assertFalse($request['options']['http_errors'], 'An answer with an error is a line of the checklist.');
			self::assertSame(30, $request['options']['timeout']);
			self::assertArrayNotHasKey('nextcloud', $request['options']);
		}
	}

	/** @return array<string, array{array<string, bool>, list<string>, list<string>}> the modules, the requests and the lines */
	public static function modules(): array {
		return [
			'neither module' => [['export_enabled' => false, 'inbox_enabled' => false], ['documents'], ['paperless', 'documents']],
			'the export alone' => [['inbox_enabled' => false], ['documents', 'ui_settings', 'trash', 'correspondents', 'document_types', 'storage_paths', 'tags'], ['paperless', 'documents', 'trash', 'correspondents', 'document_types', 'storage_paths', 'tags']],
			'the inbox alone' => [['export_enabled' => false], ['documents', 'ui_settings'], ['paperless', 'documents', 'upload']],
		];
	}

	/**
	 * @param array<string, bool> $settings
	 * @param list<string> $requests
	 * @param list<string> $lines
	 */
	#[DataProvider('modules')]
	public function testPreflightChecksOnlyWhatTheEnabledModulesNeed(array $settings, array $requests, array $lines): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = [200, self::account(true, [])];
		for ($list = 0; $list < 5; ++$list) {
			$this->responses[] = [200, self::page(1)];
		}

		$checks = $this->preflight($settings);

		self::assertSame($lines, array_map(static fn (ConnectionCheck $check): string => $check->id, $checks));
		self::assertSame($requests, array_map(static fn (array $request): string => explode('/', $request['url'])[4], $this->requests));
	}

	/**
	 * A token without the permission for the tags passes the test of the documents, but every
	 * export would fail on its list of tags. The checklist names it and the permission to grant.
	 */
	public function testATokenWithoutThePermissionForTagsIsReportedWithTheFix(): void {
		$this->responses[] = [200, self::page(12)];
		$this->responses[] = [200, self::account(false, ['view_document', 'view_paperlesstask'])];
		foreach ([0, 0, 1, 1, 1] as $count) {
			$this->responses[] = [200, self::page($count)];
		}
		$this->responses[] = [403, ['detail' => 'You do not have permission to perform this action.']];

		$checks = $this->preflight();

		self::assertEquals(
			ConnectionCheck::failed('tags', 'The account may not view them (HTTP 403).', [], 'In Paperless, under Users & Groups, give the account the permission View for Tags.'),
			$checks[6],
		);
		self::assertFalse($checks[6]->blocks(), 'The other settings may be saved, and the permission granted afterwards.');
		self::assertEquals(
			ConnectionCheck::failed('upload', 'The account may not add documents.', [], 'In Paperless, under Users & Groups, give the account the permission Add for Documents.'),
			$checks[7],
		);
	}

	/** @return array<string, array{string, string}> the list and what to grant for it */
	public static function listsWithoutThePermission(): array {
		return [
			'trash' => ['trash', 'In Paperless, under Users & Groups, give the account the permission View for Documents.'],
			'correspondents' => ['correspondents', 'In Paperless, under Users & Groups, give the account the permission View for Correspondents.'],
			'document types' => ['document_types', 'In Paperless, under Users & Groups, give the account the permission View for Document Types.'],
			'storage paths' => ['storage_paths', 'In Paperless, under Users & Groups, give the account the permission View for Storage Paths.'],
		];
	}

	#[DataProvider('listsWithoutThePermission')]
	public function testEveryListThatTheAccountMayNotViewNamesItsPermission(string $list, string $hint): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = [200, self::account(true, [])];
		foreach (['trash', 'correspondents', 'document_types', 'storage_paths', 'tags'] as $path) {
			$this->responses[] = $path === $list ? [403, ['detail' => 'You do not have permission to perform this action.']] : [200, self::page(1)];
		}

		$checks = $this->preflight(['inbox_enabled' => false]);

		$failed = array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => $check->status === ConnectionCheck::FAILED));
		self::assertEquals([ConnectionCheck::failed($list, 'The account may not view them (HTTP 403).', [], $hint)], $failed);
	}

	/** @return array<string, array{int, mixed, ConnectionCheck}> an answer to the first request that is not a page of Paperless, and its line */
	public static function answersThatAreNoPage(): array {
		$noApi = static fn (string $status): ConnectionCheck => ConnectionCheck::failed('paperless', 'No Paperless API answered at this URL (HTTP %s).', [$status], self::NO_API_HINT, true);
		$status = static fn (string $status): ConnectionCheck => ConnectionCheck::failed('paperless', 'Paperless answered with HTTP %s.', [$status], self::STATUS_HINT, true);

		return [
			'a refused token' => [401, ['detail' => 'Invalid token.'], ConnectionCheck::failed(
				'paperless',
				'Paperless refused the API token (HTTP 401).',
				[],
				'Copy the token again from Paperless, under My Profile in the menu of its account, and check that the account is active.',
				true,
			)],
			'a path without Paperless' => [404, ['detail' => 'Not found.'], $noApi('404')],
			'the login page of a proxy' => [200, '<html><title>Sign in</title></html>', $noApi('200')],
			'JSON of another app' => [200, ['status' => 'ok'], $noApi('200')],
			'a proxy that forbids the path' => [403, '<html><title>403 Forbidden</title></html>', $noApi('403')],
			'a Paperless that is down behind its proxy' => [502, '<html><title>502 Bad Gateway</title></html>', $status('502')],
			'an error of Paperless' => [500, ['detail' => 'Server error'], $status('500')],
			'too many requests' => [429, ['detail' => 'Request was throttled.'], $status('429')],
		];
	}

	/** Without the documents, nothing else of Paperless works, so the checklist ends there and blocks a save. */
	#[DataProvider('answersThatAreNoPage')]
	public function testAnAnswerThatIsNoPageOfPaperlessEndsTheChecklist(int $status, mixed $body, ConnectionCheck $line): void {
		$this->responses[] = [$status, $body];

		$checks = $this->preflight();

		self::assertEquals([$line], $checks);
		self::assertTrue($checks[0]->blocks());
		self::assertCount(1, $this->requests);
	}

	/** An account that may not view documents can't mirror any: a save refuses it, and the other lines say what else it lacks. */
	public function testAnAccountWithoutTheDocumentsBlocksTheSave(): void {
		$this->responses[] = [403, ['detail' => 'You do not have permission to perform this action.'], ['X-Version' => '3.3.0']];
		$this->responses[] = [200, self::account(false, [])];

		$checks = $this->preflight(['export_enabled' => false]);

		self::assertEquals([
			ConnectionCheck::passed('paperless', 'Paperless-ngx %s answers at this URL.', ['3.3.0']),
			ConnectionCheck::failed('documents', 'The account may not view them (HTTP 403).', [], 'In Paperless, under Users & Groups, give the account the permission View for Documents.', true),
			ConnectionCheck::failed('upload', 'The account may neither add documents nor view the tasks that import them.', [], 'In Paperless, under Users & Groups, give the account the permissions Add for Documents and View for Paperless Tasks.'),
		], $checks);
	}

	/** An account that sees no document usually lacks the permissions of the documents themselves. */
	public function testAnAccountThatSeesNoDocumentGetsANote(): void {
		$this->responses[] = [200, self::page(0)];

		$checks = $this->preflight(['export_enabled' => false, 'inbox_enabled' => false]);

		self::assertEquals(ConnectionCheck::warning(
			'documents',
			'Visible to the account: %s',
			['0'],
			'Paperless shows an account only the documents that it owns, that have no owner, or that are shared with it. Give the account access to the documents to mirror: as their owner, through their permissions, or with a workflow of Paperless that sets them.',
		), $checks[1]);
	}

	/** @return array<string, array{string, ConnectionCheck}> the header X-Version and the line of Paperless */
	public static function versions(): array {
		return [
			'Paperless-ngx 3' => ['3.3.0', ConnectionCheck::passed('paperless', 'Paperless-ngx %s answers at this URL.', ['3.3.0'])],
			'the first version with version 9 of the API' => ['2.16.0', ConnectionCheck::passed('paperless', 'Paperless-ngx %s answers at this URL.', ['2.16.0'])],
			'a version with a v' => [' v2.20.15 ', ConnectionCheck::passed('paperless', 'Paperless-ngx %s answers at this URL.', ['2.20.15'])],
			'a version before version 9 of the API, behind a proxy that drops the header Accept' => ['2.15.3', ConnectionCheck::warning(
				'paperless',
				'Paperless-ngx %s answers at this URL, but Paperless Sync needs %s or newer.',
				['2.15.3', '2.16'],
				'Update Paperless-ngx, so that it serves version %s of its API, which every request of Paperless Sync asks for.',
				['9'],
			)],
			'no version, behind a proxy that drops the header' => ['', ConnectionCheck::passed('paperless', 'Paperless answers at this URL.')],
			'a version that is no number' => ['dev', ConnectionCheck::passed('paperless', 'Paperless answers at this URL.')],
		];
	}

	#[DataProvider('versions')]
	public function testTheLineOfPaperlessNamesItsVersion(string $version, ConnectionCheck $line): void {
		$this->responses[] = [200, self::page(1), ['X-Version' => $version]];

		self::assertEquals($line, $this->preflight(['export_enabled' => false, 'inbox_enabled' => false])[0]);
	}

	/** @return array<string, array{string, string}> the failure of the HTTP client and the hint of its line */
	public static function unreachablePaperless(): array {
		return [
			'no connection' => ['cURL error 7: Failed to connect to paperless.example.test port 443', 'Check the Paperless URL, and that the Nextcloud server reaches it through its network, firewall and proxy.'],
			'a name without an address' => ['cURL error 6: Could not resolve host: paperless.example.test', 'Check the Paperless URL, and that the Nextcloud server reaches it through its network, firewall and proxy.'],
			'a self-signed certificate' => ['cURL error 60: SSL certificate problem: self-signed certificate', self::CERTIFICATE_HINT],
			'a failed handshake' => ['cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', self::CERTIFICATE_HINT],
		];
	}

	#[DataProvider('unreachablePaperless')]
	public function testUnreachablePaperlessSaysWhatToCheck(string $failure, string $hint): void {
		$this->responses[] = static function () use ($failure): array {
			throw new RuntimeException($failure . ' for https://paperless.example.test/api/documents/?page=1&page_size=1');
		};

		$checks = $this->preflight();

		self::assertEquals([ConnectionCheck::failed(
			'paperless',
			'Paperless could not be reached: %s',
			[$failure . ' for https://paperless.example.test/api/documents/'],
			$hint,
			true,
		)], $checks);
	}

	/**
	 * A Paperless that doesn't serve version 9 of its API answers every request with 406 Not
	 * Acceptable, so the checklist stops at its first line, which no run gets past, and says
	 * which Paperless the app needs.
	 */
	public function testAPaperlessWithoutApiVersion9FailsTheChecklist(): void {
		$this->responses[] = [406, ['detail' => 'Invalid version in "Accept" header.'], ['X-Version' => '2.15.3']];

		self::assertEquals([ConnectionCheck::failed(
			'paperless',
			'Paperless does not serve version %s of its API, which Paperless Sync uses (HTTP 406 Not Acceptable).',
			['9'],
			'Update Paperless-ngx to %s or newer; with a newer Paperless-ngx, update Paperless Sync.',
			true,
			['2.16'],
		)], $this->preflight());
		self::assertSame('application/json; version=9', $this->requests[0]['options']['headers']['Accept']);
	}

	/** The message of a failure goes into the checklist as the log gets it: without the token, should a server repeat it. */
	public function testTheChecklistKeepsTheTokenOutOfItsLines(): void {
		$this->responses[] = static function (): array {
			throw new RuntimeException('Server error: rejected "Token secret-token"');
		};

		$checks = $this->preflight();

		self::assertSame(['Server error: rejected "Token [redacted]"'], $checks[0]->parameters);
	}

	/**
	 * Nextcloud refuses an address of the local network unless the setting allows it: the line
	 * says what to do and names the setting, which a save moves the focus to.
	 */
	public function testARefusedLocalAddressNamesTheSetting(): void {
		$this->responses[] = static function (): array {
			throw new LocalServerException('Host "paperless" violates local access rules');
		};

		$checks = $this->preflight(['paperless_url' => 'http://paperless:8000']);

		self::assertEquals([new ConnectionCheck(
			'paperless',
			ConnectionCheck::FAILED,
			'Nextcloud blocks connections to local addresses such as %s. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
			['paperless'],
			blocking: true,
			setting: 'paperless_local_network',
		)], $checks);
	}

	/** Every request of the checklist allows the local network as the settings of the form do. */
	public function testThePreflightAllowsTheLocalNetworkAsTheFormAsks(): void {
		$this->responses[] = [200, self::page(1)];

		$this->preflight(['paperless_url' => 'http://paperless:8000', 'paperless_local_network' => true, 'export_enabled' => false, 'inbox_enabled' => false]);

		self::assertSame('http://paperless:8000/api/documents/', $this->requests[0]['url']);
		self::assertSame(['allow_local_address' => true], $this->requests[0]['options']['nextcloud']);
	}

	/**
	 * The answer of /api/ui_settings/, the answer to the count of the documents of other owners
	 * when the checklist asks for it, and the lines of the trash and the inbox.
	 *
	 * @return array<string, array{array{0: int, 1: mixed}, array{0: int, 1: mixed}|null, ConnectionCheck, ConnectionCheck}>
	 */
	public static function accounts(): array {
		$trash = static fn (string $hint, array $parameters = []): ConnectionCheck => ConnectionCheck::warning('trash', 'Visible to the account: %s', ['1'], $hint, $parameters);
		$owner = ConnectionCheck::passed('trash', 'Visible to the account: %s', ['1']);
		$mayUpload = ConnectionCheck::passed('upload', 'The account may add documents and view the tasks that import them.');
		$unknown = static fn (string $hint): ConnectionCheck => ConnectionCheck::warning(
			'upload',
			'Paperless did not say whether the account may add documents and view the tasks that import them.',
			[],
			$hint,
		);
		$firstUpload = $unknown('The first upload from the inbox shows it; the account needs the permissions Add for Documents and View for Paperless Tasks.');
		$mayNeither = ConnectionCheck::failed(
			'upload',
			'The account may neither add documents nor view the tasks that import them.',
			[],
			'In Paperless, under Users & Groups, give the account the permissions Add for Documents and View for Paperless Tasks.',
		);
		$inbox = ['documents.add_document', 'documents.view_paperlesstask'];

		return [
			'a superuser, whose token controls all of Paperless' => [[200, self::account(true, [])], null, $trash(self::SUPERUSER_HINT), $mayUpload],
			'the owner of every document it sees' => [[200, self::account(false, $inbox)], [200, self::page(0)], $owner, $mayUpload],
			'an account that sees documents of others' => [[200, self::account(false, $inbox)], [200, self::page(4)], $trash(self::FOREIGN_DOCUMENTS_HINT, ['4']), $mayUpload],
			'a count of other owners that fails' => [[200, self::account(false, $inbox)], [400, ['owner__id__none' => ['Unknown filter.']]], $trash(self::TRASH_HINT), $mayUpload],
			'an account without its ID' => [[200, ['user' => ['is_superuser' => false], 'permissions' => []]], null, $trash(self::TRASH_HINT), $mayNeither],
			'an account without View for UI Settings' => [[403, ['detail' => 'You do not have permission to perform this action.']], null, $trash(self::TRASH_UNKNOWN_HINT), $unknown(self::UI_SETTINGS_HINT)],
			'a Paperless without the page' => [[404, ['detail' => 'Not found.']], null, $trash(self::TRASH_HINT), $firstUpload],
			'a page without permissions' => [[200, ['user' => ['is_superuser' => true]]], null, $trash(self::TRASH_HINT), $firstUpload],
			'a page that fails' => [[500, '<html>Server Error</html>'], null, $trash(self::TRASH_HINT), $firstUpload],
		];
	}

	/**
	 * @param array{0: int, 1: mixed} $account
	 * @param array{0: int, 1: mixed}|null $foreign
	 */
	#[DataProvider('accounts')]
	public function testTheAccountDecidesTheLinesOfTheTrashAndTheInbox(array $account, ?array $foreign, ConnectionCheck $trash, ConnectionCheck $upload): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = $account;
		$this->responses[] = [200, self::page(1)];
		if ($foreign !== null) {
			$this->responses[] = $foreign;
		}
		for ($list = 0; $list < 4; ++$list) {
			$this->responses[] = [200, self::page(1)];
		}

		$checks = $this->preflight();

		self::assertEquals($trash, $checks[2]);
		self::assertEquals($upload, $checks[7]);
		self::assertCount($foreign === null ? 7 : 8, $this->requests);
	}

	/**
	 * The settings of the tags of the inbox, the answer of /api/ui_settings/, and the line of the tags.
	 *
	 * @return array<string, array{array<string, mixed>, array{0: int, 1: mixed}, ConnectionCheck}>
	 */
	public static function inboxTagAccounts(): array {
		$view = ConnectionCheck::passed('inbox_tags', 'The account may view the tags that the inbox gives its files.');
		$create = ConnectionCheck::passed('inbox_tags', 'The account may view and create the tags that the inbox gives its files.');
		$noView = static fn (string $hint): ConnectionCheck => ConnectionCheck::failed('inbox_tags', 'The account may not view tags, so the files of the inbox wait in the inbox.', [], $hint);
		$unknown = static fn (string $message, string $hint): ConnectionCheck => ConnectionCheck::warning('inbox_tags', $message, [], $hint);
		$uiSettings = 'In Paperless, under Users & Groups, give the account the permission View for UI Settings, so that this checklist can read its permissions. The tags of the inbox need View for Tags, and Add for Tags to create the missing ones.';
		$firstUpload = 'The first upload from the inbox shows it; the tags of the inbox need the permission View for Tags, and Add for Tags to create the missing ones.';
		$folders = ['inbox_folder_tags' => true];
		$creating = ['inbox_folder_tags' => true, 'inbox_create_tags' => true];
		$forbidden = [403, ['detail' => 'You do not have permission to perform this action.']];

		return [
			'subfolders, with View for Tags' => [$folders, [200, self::account(false, ['documents.view_tag'])], $view],
			'tags for every import, with View for Tags' => [['inbox_tags' => 'Scanned'], [200, self::account(false, ['view_tag'])], $view],
			'creating them, with View and Add for Tags' => [$creating, [200, self::account(false, ['view_tag', 'add_tag'])], $create],
			'creating them, as a superuser' => [$creating, [200, self::account(true, [])], $create],
			'creating them, without Add for Tags' => [$creating, [200, self::account(false, ['view_tag'])], new ConnectionCheck(
				'inbox_tags',
				ConnectionCheck::FAILED,
				'The account may not create tags, so a file that needs a tag that Paperless lacks waits in the inbox.',
				[],
				'In Paperless, under Users & Groups, give the account the permission Add for Tags, or turn off "Create missing tags".',
				setting: 'inbox_create_tags',
			)],
			'without View for Tags' => [$folders, [200, self::account(false, ['add_tag'])], $noView('In Paperless, under Users & Groups, give the account the permission View for Tags.')],
			'creating them, with Add for Tags alone' => [$creating, [200, self::account(false, ['add_tag'])], $noView('In Paperless, under Users & Groups, give the account the permission View for Tags.')],
			'creating them, without either' => [$creating, [200, self::account(false, [])], $noView('In Paperless, under Users & Groups, give the account the permissions View and Add for Tags.')],
			'without View for UI Settings' => [$folders, $forbidden, $unknown('Paperless did not say whether the account may view tags.', $uiSettings)],
			'creating them, without View for UI Settings' => [$creating, $forbidden, $unknown('Paperless did not say whether the account may view and create tags.', $uiSettings)],
			'a Paperless without the page' => [['inbox_tags' => 'Scanned'], [404, ['detail' => 'Not found.']], $unknown('Paperless did not say whether the account may view tags.', $firstUpload)],
		];
	}

	/**
	 * With tags for the files of the inbox, the checklist checks that the account may view them,
	 * and create them when it should; a file that can't get its tags waits in the inbox.
	 *
	 * @param array<string, mixed> $settings
	 * @param array{0: int, 1: mixed} $account
	 */
	#[DataProvider('inboxTagAccounts')]
	public function testTheTagsOfTheInboxHaveALineOfTheirOwn(array $settings, array $account, ConnectionCheck $line): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = $account;

		$checks = $this->preflight(['export_enabled' => false] + $settings);

		self::assertSame(['paperless', 'documents', 'upload', 'inbox_tags'], array_map(static fn (ConnectionCheck $check): string => $check->id, $checks));
		self::assertEquals($line, $checks[3]);
		self::assertCount(2, $this->requests, 'The permissions are those of the account, which the checklist read for the upload.');
	}

	/** A count of the documents of other owners that can't be read leaves the trash with its note. */
	public function testACountOfOtherOwnersThatCannotBeReadKeepsTheNote(): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = [200, self::account(false, [])];
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = static function (): array {
			throw new RuntimeException('cURL error 28: Operation timed out');
		};
		for ($list = 0; $list < 4; ++$list) {
			$this->responses[] = [200, self::page(1)];
		}

		$checks = $this->preflight(['inbox_enabled' => false]);

		self::assertEquals(ConnectionCheck::warning('trash', 'Visible to the account: %s', ['1'], self::TRASH_HINT), $checks[2]);
		self::assertSame('passed', $checks[3]->status, 'The other lists are still checked.');
	}

	public function testAnAccountThatCannotBeAskedForIsUnknown(): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = static function (): array {
			throw new RuntimeException('cURL error 28: Operation timed out');
		};

		$checks = $this->preflight(['export_enabled' => false]);

		self::assertSame(ConnectionCheck::WARNING, $checks[2]->status);
		self::assertSame('Paperless did not say whether the account may add documents and view the tasks that import them.', $checks[2]->message);
	}

	/** @return array<string, array{list<string>, ConnectionCheck}> the permissions of the account and the line of the inbox */
	public static function inboxPermissions(): array {
		return [
			'both' => [['add_document', 'view_paperlesstask'], ConnectionCheck::passed('upload', 'The account may add documents and view the tasks that import them.')],
			'only the tasks' => [['view_paperlesstask'], ConnectionCheck::failed(
				'upload',
				'The account may not add documents.',
				[],
				'In Paperless, under Users & Groups, give the account the permission Add for Documents.',
			)],
			'only the upload, which never learns what became of a file' => [['add_document'], ConnectionCheck::failed(
				'upload',
				'The account may not view the tasks that import its uploads.',
				[],
				'In Paperless, under Users & Groups, give the account the permission View for Paperless Tasks. Without it, the inbox never learns whether Paperless imported a file, and keeps the file.',
			)],
			'neither' => [['view_document'], ConnectionCheck::failed(
				'upload',
				'The account may neither add documents nor view the tasks that import them.',
				[],
				'In Paperless, under Users & Groups, give the account the permissions Add for Documents and View for Paperless Tasks.',
			)],
		];
	}

	/**
	 * The inbox uploads with Add for Documents and then asks for the task that imports the file,
	 * which needs View for Paperless Tasks; the line names each permission that is missing.
	 *
	 * @param list<string> $permissions
	 */
	#[DataProvider('inboxPermissions')]
	public function testTheInboxNeedsToAddDocumentsAndViewTheirTasks(array $permissions, ConnectionCheck $line): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = [200, self::account(false, $permissions)];

		$checks = $this->preflight(['export_enabled' => false]);

		self::assertEquals($line, $checks[2]);
	}

	/** @return array<string, array{int, mixed, ConnectionCheck}> an answer for the trash that is no page, and its line */
	public static function trashesThatAreNoPage(): array {
		return [
			'a Paperless without a trash' => [404, ['detail' => 'Not found.'], ConnectionCheck::failed('trash', 'Paperless has no trash (HTTP 404).', [], 'Update Paperless-ngx to %s or newer.', hintParameters: ['2.16'])],
			'an error of Paperless' => [500, '<html>Server Error</html>', ConnectionCheck::failed('trash', 'Paperless answered without the list (HTTP %s).', ['500'], self::STATUS_HINT)],
			'no list' => [200, ['detail' => 'ok'], ConnectionCheck::failed('trash', 'Paperless answered without the list (HTTP %s).', ['200'], self::STATUS_HINT)],
		];
	}

	#[DataProvider('trashesThatAreNoPage')]
	public function testATrashThatIsNoPageIsReported(int $status, mixed $body, ConnectionCheck $line): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = [200, self::account(true, [])];
		$this->responses[] = [$status, $body];
		for ($list = 0; $list < 4; ++$list) {
			$this->responses[] = [200, self::page(1)];
		}

		$checks = $this->preflight(['inbox_enabled' => false]);

		self::assertEquals($line, $checks[2]);
		self::assertFalse($checks[2]->blocks());
	}

	/** A list that can't be reached after the documents could is reported on its own line, and the others are still checked. */
	public function testAListThatCannotBeReachedIsReportedOnItsLine(): void {
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = [200, self::account(true, [])];
		$this->responses[] = [200, self::page(1)];
		$this->responses[] = static function (): array {
			throw new RuntimeException('cURL error 28: Operation timed out');
		};
		for ($list = 0; $list < 3; ++$list) {
			$this->responses[] = [200, self::page(1)];
		}

		$checks = $this->preflight(['inbox_enabled' => false]);

		self::assertEquals(ConnectionCheck::failed(
			'correspondents',
			'Paperless could not be reached: %s',
			['cURL error 28: Operation timed out'],
			'Check the Paperless URL, and that the Nextcloud server reaches it through its network, firewall and proxy.',
		), $checks[3]);
		self::assertCount(7, $checks);
	}

	/** An answer may come as a stream, as Nextcloud's HTTP client gives it. */
	public function testThePreflightReadsAnAnswerFromAStream(): void {
		$this->responses[] = static function (): array {
			$stream = fopen('php://memory', 'w+b');
			self::assertIsResource($stream);
			fwrite($stream, (string)json_encode(['count' => 7, 'results' => [['id' => 1]]]));
			rewind($stream);

			return [200, $stream];
		};

		self::assertEquals(ConnectionCheck::passed('documents', 'Visible to the account: %s', ['7']), $this->preflight(['export_enabled' => false, 'inbox_enabled' => false])[1]);
	}

	/**
	 * The answers of a real Paperless-ngx, which the contract tests (tests/e2e/run-contract.sh)
	 * recorded from the last release of 2.x and the current release of 3.x into
	 * tests/fixtures/paperless-ngx-<line>/. The answers of the tests above follow what the app
	 * expects; these are what Paperless sends, and the weekly contract tests fail when Paperless
	 * stops sending them.
	 *
	 * @return array<string, array{string}>
	 */
	public static function paperlessLines(): array {
		return ['Paperless-ngx 2.x' => ['2'], 'Paperless-ngx 3.x' => ['3']];
	}

	#[DataProvider('paperlessLines')]
	public function testTheConnectionTestNamesTheVersionThatPaperlessSends(string $line): void {
		$this->responses[] = self::recorded($line, 'connection');

		$server = $this->service->testConnection();

		self::assertSame(['version' => self::recording($line, 'connection')['headers']['X-Version'], 'apiVersion' => '9'], $server);
		self::assertStringStartsWith("{$line}.", $server['version']);
	}

	/** Paperless sends the fields of a run alone, and the scan reads every document of the page. */
	#[DataProvider('paperlessLines')]
	public function testTheDocumentsOfPaperlessCarryExactlyTheFieldsOfARun(string $line): void {
		$this->responses[] = self::recorded($line, 'documents');

		$scan = $this->service->documents();

		self::assertTrue($scan->complete);
		self::assertSame(3, $scan->listed);
		self::assertCount(3, $scan->documents);
		$fields = PaperlessApiService::DOCUMENT_FIELDS;
		sort($fields);
		foreach ($scan->documents as $document) {
			$keys = array_keys($document);
			sort($keys);
			self::assertSame($fields, $keys, 'Paperless answers with the fields that the run asked for, and only those.');
			self::assertIsInt($document['id']);
			self::assertIsString($document['title']);
			self::assertIsString($document['created']);
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $document['created']);
			self::assertIsList($document['tags']);
			self::assertIsString($document['archived_file_name']);
			self::assertSame('application/pdf', $document['mime_type']);
		}
		self::assertSame(self::recordedQuery($line, 'documents'), self::sentQuery($this->requests[0]), 'The contract tests ask Paperless as the app does.');
	}

	#[DataProvider('paperlessLines')]
	public function testTheTrashOfPaperlessIsReadCompletely(string $line): void {
		$this->responses[] = self::recorded($line, 'trash');

		$scan = $this->service->trash();

		self::assertTrue($scan->complete);
		self::assertCount(1, $scan->documents);
		self::assertIsInt($scan->documents[0]['id']);
		self::assertIsString($scan->documents[0]['deleted_at']);
		self::assertSame(self::recordedQuery($line, 'trash'), self::sentQuery($this->requests[0]));
	}

	#[DataProvider('paperlessLines')]
	public function testTheListsOfPaperlessGiveTheNamesOfThePathTemplate(string $line): void {
		foreach (['correspondents', 'document_types', 'storage_paths', 'tags', 'users', 'custom_fields'] as $list) {
			$this->responses[] = self::recorded($line, $list);
		}

		self::assertEqualsCanonicalizing(['Example GmbH', 'Example Insurance'], array_values($this->service->correspondents()));
		self::assertEqualsCanonicalizing(['Invoice', 'Statement', 'Letter'], array_values($this->service->documentTypes()));
		self::assertSame(['Contracts'], array_values($this->service->storagePaths()));
		$tags = $this->service->tags();
		self::assertEqualsCanonicalizing(['Inbox', 'Contract'], array_values($tags['names']));
		self::assertSame(['Inbox'], array_map(static fn (string $id): string => $tags['names'][$id], $tags['inbox']));
		self::assertContains('contract-admin', $this->service->users());
		$fields = array_values($this->service->customFields());
		self::assertCount(1, $fields);
		self::assertSame('Priority', $fields[0]['name']);
		self::assertSame('select', $fields[0]['type']);
		self::assertSame(['High', 'Low'], array_values($fields[0]['options']));
		self::assertSame(self::recordedQuery($line, 'tags'), self::sentQuery($this->requests[3]));
	}

	/**
	 * The tasks of uploads as Paperless lists them for a poll of the app: one that made a document,
	 * one of a file that Paperless can't read, the second upload of the same file, which Paperless-ngx
	 * 2.x refuses as a duplicate and 3.x consumes by default, and a task that Paperless doesn't know.
	 *
	 * @return array<string, array{string, string, string, string, bool}>
	 */
	public static function recordedTasks(): array {
		$failure = 'broken.pdf: Error occurred while consuming document broken.pdf: ';
		return [
			'2.x, success' => ['2', 'task-success', 'SUCCESS', 'Success. New document id ', true],
			'2.x, failure' => ['2', 'task-failure', 'FAILURE', $failure, false],
			'2.x, duplicate' => ['2', 'task-duplicate', 'FAILURE', 'copy.pdf: Not consuming copy.pdf: It is a duplicate of ', false],
			'2.x, unknown' => ['2', 'task-unknown', 'PENDING', 'Paperless does not list the task.', false],
			'3.x, success' => ['3', 'task-success', 'SUCCESS', 'Success. New document id ', true],
			'3.x, success in version 10' => ['3', 'task-success.v10', 'SUCCESS', '', true],
			'3.x, failure' => ['3', 'task-failure', 'FAILURE', $failure, false],
			'3.x, failure in version 10' => ['3', 'task-failure.v10', 'FAILURE', $failure, false],
			'3.x, duplicate' => ['3', 'task-duplicate', 'SUCCESS', 'Success. New document id ', true],
			'3.x, unknown' => ['3', 'task-unknown', 'PENDING', 'Paperless does not list the task.', false],
		];
	}

	#[DataProvider('recordedTasks')]
	public function testTheTasksOfPaperlessTellHowAnUploadEnded(string $line, string $name, string $status, string $message, bool $document): void {
		$this->responses[] = self::recorded($line, $name);

		$task = $this->service->taskStatus('task-1');

		self::assertSame($status, $task['status']);
		if ($message === '') {
			self::assertSame('', $task['message'], 'Version 10 tells a success by the ID of its document alone.');
		} else {
			self::assertStringStartsWith($message, $task['message']);
		}
		self::assertSame($status !== 'PENDING', $task['listed']);
		if ($document) {
			self::assertIsInt($task['documentId']);
			self::assertStringContainsString((string)$task['documentId'], json_encode(self::recording($line, $name)['body'], JSON_THROW_ON_ERROR));
		} else {
			self::assertNull($task['documentId']);
		}
	}

	#[DataProvider('paperlessLines')]
	public function testAnUploadToPaperlessGivesItsTask(string $line): void {
		$this->responses[] = self::recorded($line, 'upload');

		$task = $this->service->uploadDocument($this->source('%PDF-inbox'), 'invoice.pdf');

		self::assertSame(self::recording($line, 'upload')['body'], $task);
		self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $task);
	}

	#[DataProvider('paperlessLines')]
	public function testAFileOfATypeThatPaperlessDoesNotTakeIsRefusedForGood(string $line): void {
		$this->responses[] = self::recorded($line, 'upload-refused');

		try {
			$this->service->uploadDocument($this->source('%PDF-inbox'), 'notes.bin');
			self::fail('Paperless refuses the file.');
		} catch (PaperlessUploadException $exception) {
			self::assertSame('Paperless answered the upload with HTTP 400: document: File type application/octet-stream not supported', $exception->getMessage());
			self::assertTrue($exception->permanent);
		}
	}

	/**
	 * The checksums that Paperless keeps of a document are those of its downloads, MD5 on 2.x and
	 * SHA-256 on 3.x, and the fingerprint of a download names both (decision 0009), so a run tells
	 * a change of the metadata from one of the file without a download.
	 */
	#[DataProvider('paperlessLines')]
	public function testTheChecksumsOfPaperlessMatchTheFingerprintsOfItsDownloads(string $line): void {
		$downloads = self::recording($line, 'downloads');
		$original = base64_decode((string)$downloads['true']['base64'], true);
		self::assertIsString($original);
		$this->responses[] = static function (array $options) use ($original): array {
			file_put_contents($options['sink'], $original);
			return [200, null];
		};
		$this->responses[] = self::recorded($line, 'metadata');
		$this->responses[] = self::recorded($line, 'metadata');

		$fingerprint = $this->service->downloadDocument(1, true, self::discard());
		$checksum = $this->service->documentChecksum(1, true);
		$archive = $this->service->documentChecksum(1, false);

		self::assertSame($line === '2' ? 32 : 64, strlen($checksum), 'Paperless-ngx 2.x keeps MD5 checksums, 3.x SHA-256.');
		self::assertTrue(DocumentFingerprint::matches($fingerprint->value(), $checksum));
		self::assertTrue(DocumentFingerprint::matches("{$downloads['false']['sha256']} {$downloads['false']['md5']}", $archive));
		self::assertFalse(DocumentFingerprint::matches($fingerprint->value(), $archive), 'The archived version is another file than the original.');
	}

	/** A file of the inbox whose task got lost is found by the checksum of its original. */
	#[DataProvider('paperlessLines')]
	public function testPaperlessFindsADocumentByTheChecksumOfItsOriginal(string $line): void {
		// Paperless-ngx 3.x finds the SHA-256 at once, 2.x the MD5 after it.
		$checksums = $line === '2' ? ['sha256', 'md5'] : ['sha256'];
		foreach ($checksums as $checksum) {
			$this->responses[] = self::recorded($line, "lookup-{$checksum}");
		}
		$downloads = self::recording($line, 'downloads');

		$found = $this->service->findDocument(new DocumentFingerprint((string)$downloads['true']['sha256'], (string)$downloads['true']['md5']));

		self::assertSame(1, $found);
		self::assertCount(count($checksums), $this->requests);
		foreach ($checksums as $index => $checksum) {
			self::assertSame(self::recordedQuery($line, "lookup-{$checksum}"), self::sentQuery($this->requests[$index]));
		}
	}

	/**
	 * A recording of tests/fixtures/paperless-ngx-<line>/: the request, the status, the headers
	 * and the body of an answer of Paperless.
	 *
	 * @return array<string, mixed>
	 */
	private static function recording(string $line, string $name): array {
		$path = dirname(__DIR__, 2) . "/fixtures/paperless-ngx-{$line}/{$name}.json";
		$recording = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($recording);

		return $recording;
	}

	/**
	 * A recorded answer as the HTTP client of Nextcloud would give it.
	 *
	 * @return array{int, string, array<string, string>}
	 */
	private static function recorded(string $line, string $name): array {
		$recording = self::recording($line, $name);
		self::assertIsInt($recording['status']);
		self::assertIsArray($recording['headers']);

		return [$recording['status'], json_encode($recording['body'], JSON_THROW_ON_ERROR), $recording['headers']];
	}

	/**
	 * The query of a recorded request, as the values that PHP reads from it.
	 *
	 * @return array<string, string>
	 */
	private static function recordedQuery(string $line, string $name): array {
		$request = self::recording($line, $name)['request'];
		self::assertIsString($request);
		parse_str((string)parse_url(explode(' ', $request, 2)[1], PHP_URL_QUERY), $query);

		return array_map('strval', $query);
	}

	/**
	 * The query of a request of the app, as Paperless reads it.
	 *
	 * @param array{method: string, url: string, options: array<string, mixed>} $request
	 * @return array<string, string>
	 */
	private static function sentQuery(array $request): array {
		$query = $request['options']['query'];
		self::assertIsArray($query);

		return array_map('strval', $query);
	}

	/** Calls an operation of the client that sends the token, with arguments that work. */
	private function call(string $operation): void {
		match ($operation) {
			'testConnection' => $this->service->testConnection(),
			'documents' => $this->service->documents(),
			'trash' => $this->service->trash(),
			'document' => $this->service->document(123),
			'tags' => $this->service->tags(),
			'downloadDocument' => $this->service->downloadDocument(123, true, self::discard()),
			'documentChecksum' => $this->service->documentChecksum(123, true),
			'findDocument' => $this->service->findDocument(new DocumentFingerprint(hash('sha256', '%PDF-inbox'), hash('md5', '%PDF-inbox'))),
			'uploadDocument' => $this->service->uploadDocument($this->source('%PDF-inbox'), 'police.pdf'),
			'createTag' => $this->service->createTag('Steuer'),
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

	/**
	 * The checklist of Paperless for the valid settings with $settings, through a client of their
	 * URL and local network with the token of the tests, as the settings page makes it.
	 *
	 * @param array<string, mixed> $settings
	 * @return list<ConnectionCheck>
	 */
	private function preflight(array $settings = []): array {
		$config = $this->configService()->validate($settings + self::validSettings(), 'secret-token');

		return $this->client($config->paperlessUrl, 'secret-token', $config->paperlessLocalNetwork)->preflight($config);
	}

	/** @return array<string, mixed> the first page of a list of Paperless with one entry, that counts $count */
	private static function page(int $count): array {
		return ['count' => $count, 'next' => null, 'previous' => null, 'results' => $count > 0 ? [['id' => 1]] : []];
	}

	/**
	 * What Paperless says of the account of a token in /api/ui_settings/.
	 *
	 * @param list<string> $permissions
	 * @return array<string, mixed>
	 */
	private static function account(bool $superuser, array $permissions): array {
		return [
			'user' => ['id' => 3, 'username' => 'nextcloud', 'is_staff' => false, 'is_superuser' => $superuser, 'groups' => []],
			'settings' => [],
			'permissions' => $permissions,
		];
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
