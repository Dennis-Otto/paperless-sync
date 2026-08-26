<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit;

use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SyncServiceTest extends TestCase {
	/** @var array<string, bool|int|string> */
	private array $settings = [];
	private string $token = 'test-token';
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	private SyncService $service;

	protected function setUp(): void {
		$this->paperless = new TestPaperlessClient();
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$appConfig = $this->appConfig();
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturnCallback(fn (): string => $this->token);
		$credentials->method('store')->willReturnCallback(function (string $userId, string $identifier, mixed $value): void {
			$this->token = is_string($value) ? $value : '';
		});
		$credentials->method('delete')->willReturnCallback(function (): void {
			$this->token = '';
		});
		$this->configService = new ConfigService($appConfig, $credentials, $paths);
		$this->configService->save([
			'paperless_url' => 'https://paperless.example.test',
			'target_user' => 'paperless',
			'inbox_enabled' => true,
			'export_enabled' => true,
		]);
		$this->service = new SyncService(
			$this->configService,
			$this->paperless,
			$this->storage,
			$this->state,
			$paths,
			new StatusService($appConfig),
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testExportsMovesTrashesRestoresAndPermanentlyDeletes(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$first = $this->service->run();
		$initialPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(1, $first->exported);
		self::assertSame('%PDF-content-v1', $this->storage->files[$initialPath]);
		self::assertSame(1, $this->paperless->downloads);

		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$moved = $this->service->run();
		$renamedPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(1, $moved->moved);
		self::assertArrayNotHasKey($initialPath, $this->storage->files);
		self::assertArrayHasKey($renamedPath, $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'Metadata-only changes must not download the PDF again.');
		self::assertSame(1, $this->paperless->checksumRequests);

		$trashedDocument = $this->paperless->documents[0];
		$trashedDocument['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument];
		$trashed = $this->service->run();
		self::assertSame(1, $trashed->movedToTrash);
		$trashPath = 'Dokumente/Paperless/Archiv/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertArrayHasKey($trashPath, $this->storage->files);

		unset($trashedDocument['deleted_at']);
		$this->paperless->documents = [$trashedDocument];
		$this->paperless->trash = [];
		$restored = $this->service->run();
		self::assertSame(1, $restored->moved);
		self::assertArrayHasKey($renamedPath, $this->storage->files);

		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->service->run();
		$this->configService->save(['permanent_delete' => true, 'missing_grace_runs' => 1]);
		$this->paperless->trash = [];
		$deleted = $this->service->run();
		self::assertSame(1, $deleted->permanentlyDeleted);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testImportsInboxAndDeletesSourceAfterSuccessfulTask(): void {
		$path = 'Dokumente/Paperless/Eingang/Versicherung/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';

		$submitted = $this->service->run();
		self::assertSame(1, $submitted->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox'], $this->paperless->uploads);
		self::assertArrayHasKey($path, $this->storage->files);

		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$completed = $this->service->run();
		self::assertSame(1, $completed->importsSucceeded);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	public function testFailedImportMovesFileAndWritesDiagnostic(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];

		$report = $this->service->run();
		$errorPath = 'Dokumente/Paperless/Fehler/broken.pdf';
		self::assertSame(1, $report->importsFailed);
		self::assertSame('broken', $this->storage->files[$errorPath]);
		self::assertStringContainsString('Unsupported mime type', $this->storage->files[$errorPath . '.error.txt']);
	}

	public function testFailedTaskIsNotSubmittedAgainWhenErrorHandlingThrows(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];
		$this->storage->writeTextException = new RuntimeException('Synthetic diagnostic write failure');

		$report = $this->service->run();

		self::assertSame(0, $report->importsSubmitted);
		self::assertSame(1, $report->errors);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testExcludedDocumentRemovesMirroredCopyAndCanBeExportedAgain(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$this->service->run();
		$this->paperless->documents[0]['tags'] = [9];
		$excluded = $this->service->run();

		self::assertSame(1, $excluded->removedExcluded);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);

		$this->paperless->documents[0]['tags'] = [];
		$exportedAgain = $this->service->run();
		self::assertSame(1, $exportedAgain->exported);
		self::assertSame(2, $this->paperless->downloads);
	}

	public function testDryRunDoesNotMutateFilesOrState(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$report = $this->service->run(true);
		self::assertSame(1, $report->exported);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame(0, $this->paperless->downloads);
	}

	public function testScheduleFlagAvoidsNextcloudReservedEnabledKey(): void {
		$this->configService->save(['enabled' => true]);

		self::assertTrue($this->settings['sync_enabled']);
		self::assertArrayNotHasKey('enabled', $this->settings);
	}

	/** @return array<string, mixed> */
	private function document(): array {
		return [
			'id' => 123,
			'title' => 'Strom August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'tags' => [],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];
	}

	/** @return IAppConfig&MockObject */
	private function appConfig(): IAppConfig {
		$mock = $this->createMock(IAppConfig::class);
		$mock->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->settings[$key] ?? $default));
		$mock->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->settings[$key] ?? $default));
		$mock->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->settings[$key] ?? $default));
		$mock->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$mock->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$mock->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$mock->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->settings[$key]);
		});

		return $mock;
	}
}

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
		++$this->downloads;
		fwrite($sink, $this->contents[$documentId] ?? '');
		rewind($sink);
	}
	public function documentChecksum(int $documentId, bool $original): string {
		++$this->checksumRequests;
		return hash('sha256', $this->contents[$documentId] ?? '');
	}
	public function uploadDocument($source, string $filename): string {
		$content = stream_get_contents($source);
		$this->uploads[$filename] = is_string($content) ? $content : '';
		return 'task-' . count($this->uploads);
	}
	public function taskStatus(string $taskId): array {
		return $this->tasks[$taskId] ?? ['status' => 'PENDING', 'message' => ''];
	}
}

final class TestStorage implements NextcloudStorageInterface {
	/** @var array<string, string> */
	public array $files = [];
	public ?\Throwable $writeTextException = null;
	public function test(string $userId, string $basePath): void {
	}
	public function prepare(SyncConfig $config): void {
	}
	public function exists(string $userId, string $path): bool {
		return array_key_exists($path, $this->files);
	}
	public function writeAtomic(string $userId, string $path, $source, string $conflictMode): void {
		if ($conflictMode === 'skip' && isset($this->files[$path])) {
			throw new RuntimeException('conflict');
		}
		$content = stream_get_contents($source);
		$this->files[$path] = is_string($content) ? $content : '';
		fclose($source);
	}
	public function move(string $userId, string $source, string $destination, string $conflictMode): bool {
		if (!isset($this->files[$source])) {
			return false;
		}
		if ($conflictMode === 'skip' && isset($this->files[$destination])) {
			throw new RuntimeException('conflict');
		}
		$this->files[$destination] = $this->files[$source];
		unset($this->files[$source]);
		return true;
	}
	public function delete(string $userId, string $path): void {
		unset($this->files[$path]);
	}
	public function listFiles(string $userId, string $path, bool $recursive): array {
		$result = [];
		foreach ($this->files as $filePath => $content) {
			if (!str_starts_with($filePath, rtrim($path, '/') . '/')) {
				continue;
			}
			$relative = substr($filePath, strlen(rtrim($path, '/') . '/'));
			if (!$recursive && str_contains($relative, '/')) {
				continue;
			}
			$result[] = ['path' => $filePath, 'name' => basename($filePath), 'etag' => hash('sha256', $content)];
		}
		return $result;
	}
	public function openRead(string $userId, string $path) {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $this->files[$path]);
		rewind($stream);
		return $stream;
	}
	public function writeText(string $userId, string $path, string $content, string $conflictMode = 'replace'): void {
		if ($this->writeTextException !== null) {
			throw $this->writeTextException;
		}
		$this->files[$path] = $content;
	}
	public function pruneEmptyParents(string $userId, string $filePath, string $stopAt): int {
		return 0;
	}
}

final class TestStateRepository implements SyncStateRepositoryInterface {
	/** @var array<int, array<string, int|string|null>> */
	public array $exports = [];
	/** @var array<string, array<string, int|string|null>> */
	public array $imports = [];
	public function findExport(string $ownerUid, int $documentId): ?array {
		return $this->exports[$documentId] ?? null;
	}
	public function allExports(string $ownerUid): array {
		return array_values($this->exports);
	}
	public function saveExport(string $ownerUid, int $documentId, array $values): void {
		$this->exports[$documentId] = array_merge($this->exports[$documentId] ?? ['document_id' => $documentId], $values);
	}
	public function deleteExport(string $ownerUid, int $documentId): void {
		unset($this->exports[$documentId]);
	}
	public function findImport(string $ownerUid, string $path): ?array {
		return $this->imports[$path] ?? null;
	}
	public function allImports(string $ownerUid): array {
		return array_values($this->imports);
	}
	public function saveImport(string $ownerUid, string $path, array $values): void {
		$this->imports[$path] = array_merge($this->imports[$path] ?? ['path' => $path], $values);
	}
	public function deleteImport(string $ownerUid, string $path): void {
		unset($this->imports[$path]);
	}
}
