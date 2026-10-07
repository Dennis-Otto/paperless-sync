<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\NextcloudStorageService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The storage service against a Nextcloud file tree in memory.
 */
final class NextcloudStorageServiceTest extends TestCase {
	use InMemoryConfiguration;

	private const USER = 'paperless';
	private const HOME = '/paperless/files';

	/** @var array<string, string|null> the content of every file, or null for a folder, by absolute path */
	private array $nodes = [self::HOME => null];
	/** @var array<string, true> the folders in which nothing can be created */
	private array $readOnly = [];
	/** @var array<string, \Throwable> failures of the node operations newFile, move and delete */
	private array $failures = [];
	private bool $filesCannotBeOpened = false;
	private NextcloudStorageService $service;

	protected function setUp(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(fn (string $userId): Folder => $this->folder('/' . $userId . '/files'));
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $userId): ?IUser => $userId === self::USER ? $this->createMock(IUser::class) : null);
		$this->service = new NextcloudStorageService($rootFolder, $userManager, new PathTemplateService());
	}

	public function testWritableBaseFolderPassesTheTest(): void {
		$this->nodes[self::HOME . '/Dokumente'] = null;
		$this->nodes[self::HOME . '/Dokumente/Paperless'] = null;
		$this->readOnly[self::HOME] = true;

		$this->expectNotToPerformAssertions();
		$this->service->test(self::USER, '/Dokumente/Paperless/');
	}

	public function testMissingBaseFolderPassesTheTestInAWritableUserFolder(): void {
		$this->expectNotToPerformAssertions();
		$this->service->test(self::USER, 'Dokumente/Paperless');
	}

	public function testUnknownUserFailsTheTest(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Nextcloud user ghost does not exist.');
		$this->service->test('ghost', 'Dokumente/Paperless');
	}

	public function testBaseFolderThatIsAFileFailsTheTest(): void {
		$this->nodes[self::HOME . '/Dokumente'] = 'a file';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The configured Nextcloud base folder is not a writable folder.');
		$this->service->test(self::USER, 'Dokumente');
	}

	public function testReadOnlyBaseFolderFailsTheTest(): void {
		$this->nodes[self::HOME . '/Dokumente'] = null;
		$this->readOnly[self::HOME . '/Dokumente'] = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The configured Nextcloud base folder is not a writable folder.');
		$this->service->test(self::USER, 'Dokumente');
	}

	public function testMissingBaseFolderFailsTheTestInAReadOnlyUserFolder(): void {
		$this->readOnly[self::HOME] = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The configured Nextcloud user folder is not writable.');
		$this->service->test(self::USER, 'Dokumente');
	}

	public function testPrepareCreatesTheFoldersOfBothDirections(): void {
		$this->service->prepare($this->configService()->validate(self::validSettings(), 'token'));

		self::assertSame([
			self::HOME,
			self::HOME . '/Dokumente',
			self::HOME . '/Dokumente/Paperless',
			self::HOME . '/Dokumente/Paperless/Archiv',
			self::HOME . '/Dokumente/Paperless/Eingang',
			self::HOME . '/Dokumente/Paperless/Fehler',
		], array_keys($this->nodes));
	}

	public function testPrepareCreatesOnlyTheFoldersOfTheEnabledDirections(): void {
		$settings = ['export_enabled' => false, 'inbox_enabled' => false] + self::validSettings();

		$this->service->prepare($this->configService()->validate($settings, 'token'));

		self::assertSame([self::HOME, self::HOME . '/Dokumente', self::HOME . '/Dokumente/Paperless'], array_keys($this->nodes));
	}

	public function testFileInThePlaceOfAFolderIsNotReplaced(): void {
		$this->nodes[self::HOME . '/Dokumente'] = 'a file';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Nextcloud path component Dokumente is not a folder.');
		$this->service->prepare($this->configService()->validate(self::validSettings(), 'token'));
	}

	public function testExistsLooksUpTheNormalizedPath(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = '%PDF';

		self::assertTrue($this->service->exists(self::USER, '/Archiv\\a.pdf'));
		self::assertFalse($this->service->exists(self::USER, 'Archiv/b.pdf'));
	}

	public function testWriteAtomicStoresTheWholeStreamInNewFolders(): void {
		$stream = $this->stream('%PDF-content');

		$this->service->writeAtomic(self::USER, 'Archiv/2026/a.pdf', $stream, 'replace');

		self::assertSame('%PDF-content', $this->nodes[self::HOME . '/Archiv/2026/a.pdf']);
		self::assertSame([], $this->temporaryFiles());
	}

	public function testWriteAtomicReplacesAnExistingFile(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'old';

		$this->service->writeAtomic(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');

		self::assertSame('new', $this->nodes[self::HOME . '/Archiv/a.pdf']);
		self::assertSame([], $this->temporaryFiles());
	}

	public function testWriteAtomicKeepsAnExistingFileWhenConflictsAreSkipped(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'old';

		try {
			$this->service->writeAtomic(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'skip');
			self::fail('A conflict must not replace the file.');
		} catch (RuntimeException $exception) {
			self::assertSame('Nextcloud file conflict at Archiv/a.pdf.', $exception->getMessage());
		}
		self::assertSame('old', $this->nodes[self::HOME . '/Archiv/a.pdf']);
	}

	public function testWriteAtomicRequiresAStream(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A readable source stream is required.');
		$this->service->writeAtomic(self::USER, 'Archiv/a.pdf', 'not a stream', 'replace');
	}

	public function testFailedWriteRemovesItsTemporaryFile(): void {
		$this->failures['move'] = new RuntimeException('The storage is full');

		try {
			$this->service->writeAtomic(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');
			self::fail('The failed move must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('The storage is full', $exception->getMessage());
		}
		self::assertSame([], $this->temporaryFiles());
		self::assertArrayNotHasKey(self::HOME . '/Archiv/a.pdf', $this->nodes);
	}

	public function testFailedCleanupKeepsTheErrorOfTheWrite(): void {
		$this->failures['move'] = new RuntimeException('The storage is full');
		$this->failures['delete'] = new NotPermittedException('The temporary file is locked');

		try {
			$this->service->writeAtomic(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');
			self::fail('The failed move must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('The storage is full', $exception->getMessage());
		}
		self::assertCount(1, $this->temporaryFiles());
	}

	public function testWriteThatCreatesNoFileNeedsNoCleanup(): void {
		$this->failures['newFile'] = new NotPermittedException('Quota exceeded');

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Quota exceeded');
		$this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', 'Paperless import failed');
	}

	public function testWriteTextStoresTheText(): void {
		$this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', "Paperless import failed\n");

		self::assertSame("Paperless import failed\n", $this->nodes[self::HOME . '/Fehler/a.pdf.error.txt']);
	}

	public function testMoveOfAMissingFileReportsFalse(): void {
		self::assertFalse($this->service->move(self::USER, 'Archiv/a.pdf', 'Archiv/b.pdf', 'replace'));
		self::assertArrayNotHasKey(self::HOME . '/Archiv', $this->nodes);
	}

	public function testMoveCreatesTheTargetFolders(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = '%PDF';

		self::assertTrue($this->service->move(self::USER, 'Archiv/a.pdf', 'Archiv/_Gelöscht/2026-08-27/a.pdf', 'skip'));

		self::assertSame([
			self::HOME => null,
			self::HOME . '/Archiv' => null,
			self::HOME . '/Archiv/_Gelöscht' => null,
			self::HOME . '/Archiv/_Gelöscht/2026-08-27' => null,
			self::HOME . '/Archiv/_Gelöscht/2026-08-27/a.pdf' => '%PDF',
		], $this->nodes);
	}

	public function testMoveReplacesAnExistingTarget(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'new';
		$this->nodes[self::HOME . '/Archiv/b.pdf'] = 'old';

		self::assertTrue($this->service->move(self::USER, 'Archiv/a.pdf', 'Archiv/b.pdf', 'replace'));

		self::assertSame('new', $this->nodes[self::HOME . '/Archiv/b.pdf']);
		self::assertArrayNotHasKey(self::HOME . '/Archiv/a.pdf', $this->nodes);
	}

	public function testMoveKeepsAnExistingTargetWhenConflictsAreSkipped(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'new';
		$this->nodes[self::HOME . '/Archiv/b.pdf'] = 'old';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Nextcloud file conflict at Archiv/b.pdf.');
		$this->service->move(self::USER, 'Archiv/a.pdf', 'Archiv/b.pdf', 'skip');
	}

	public function testDeleteRemovesTheFileAndIgnoresMissingOnes(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = '%PDF';

		$this->service->delete(self::USER, 'Archiv/a.pdf');
		$this->service->delete(self::USER, 'Archiv/a.pdf');

		self::assertSame([self::HOME => null, self::HOME . '/Archiv' => null], $this->nodes);
	}

	public function testListFilesOfAMissingInboxIsEmpty(): void {
		self::assertSame([], $this->service->listFiles(self::USER, 'Eingang', true));
	}

	public function testListFilesRequiresAFolder(): void {
		$this->nodes[self::HOME . '/Eingang'] = 'a file';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Nextcloud inbox Eingang is not a folder.');
		$this->service->listFiles(self::USER, 'Eingang', true);
	}

	public function testListFilesSkipsHiddenEntriesAndSortsByPath(): void {
		$this->nodes[self::HOME . '/Eingang'] = null;
		$this->nodes[self::HOME . '/Eingang/z.pdf'] = 'z';
		$this->nodes[self::HOME . '/Eingang/Versicherung'] = null;
		$this->nodes[self::HOME . '/Eingang/Versicherung/police.pdf'] = 'police';
		$this->nodes[self::HOME . '/Eingang/.a.pdf.paperless-0123.part'] = 'partial';
		$this->nodes[self::HOME . '/Eingang/.hidden'] = null;
		$this->nodes[self::HOME . '/Eingang/.hidden/secret.pdf'] = 'secret';
		$this->nodes[self::HOME . '/Eingang/a.pdf'] = 'a';

		self::assertSame([
			['path' => 'Eingang/Versicherung/police.pdf', 'name' => 'police.pdf', 'etag' => md5('police')],
			['path' => 'Eingang/a.pdf', 'name' => 'a.pdf', 'etag' => md5('a')],
			['path' => 'Eingang/z.pdf', 'name' => 'z.pdf', 'etag' => md5('z')],
		], $this->service->listFiles(self::USER, 'Eingang', true));
		self::assertSame(
			['Eingang/a.pdf', 'Eingang/z.pdf'],
			array_column($this->service->listFiles(self::USER, 'Eingang', false), 'path'),
		);
	}

	public function testOpenReadReturnsTheContent(): void {
		$this->nodes[self::HOME . '/Eingang'] = null;
		$this->nodes[self::HOME . '/Eingang/a.pdf'] = '%PDF-inbox';

		$stream = $this->service->openRead(self::USER, 'Eingang/a.pdf');

		self::assertSame('%PDF-inbox', stream_get_contents($stream));
		fclose($stream);
	}

	public function testOpenReadRequiresAFile(): void {
		$this->nodes[self::HOME . '/Eingang'] = null;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Nextcloud path Eingang is not a file.');
		$this->service->openRead(self::USER, 'Eingang');
	}

	public function testOpenReadReportsAFileThatCannotBeOpened(): void {
		$this->nodes[self::HOME . '/Eingang'] = null;
		$this->nodes[self::HOME . '/Eingang/a.pdf'] = '%PDF-inbox';
		$this->filesCannotBeOpened = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not open Nextcloud file Eingang/a.pdf.');
		$this->service->openRead(self::USER, 'Eingang/a.pdf');
	}

	public function testPruneRemovesEmptyFoldersUpToTheRoot(): void {
		foreach (['/Archiv', '/Archiv/Energie', '/Archiv/Energie/Rechnung', '/Archiv/Energie/Rechnung/2026'] as $folder) {
			$this->nodes[self::HOME . $folder] = null;
		}

		$removed = $this->service->pruneEmptyParents(self::USER, 'Archiv/Energie/Rechnung/2026/a.pdf', 'Archiv');

		self::assertSame(3, $removed);
		self::assertSame([self::HOME => null, self::HOME . '/Archiv' => null], $this->nodes);
	}

	public function testPruneStopsAtTheFirstFolderThatIsNotEmpty(): void {
		foreach (['/Archiv', '/Archiv/Energie', '/Archiv/Energie/Rechnung'] as $folder) {
			$this->nodes[self::HOME . $folder] = null;
		}
		$this->nodes[self::HOME . '/Archiv/Energie/b.pdf'] = '%PDF';

		$removed = $this->service->pruneEmptyParents(self::USER, 'Archiv/Energie/Rechnung/2026/a.pdf', 'Archiv');

		self::assertSame(1, $removed, 'The missing folder 2026 is skipped and the empty folder Rechnung removed.');
		self::assertArrayHasKey(self::HOME . '/Archiv/Energie', $this->nodes);
		self::assertArrayNotHasKey(self::HOME . '/Archiv/Energie/Rechnung', $this->nodes);
	}

	public function testPruneStopsAtAFileWithTheNameOfAFolder(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = '%PDF';

		self::assertSame(0, $this->service->pruneEmptyParents(self::USER, 'Archiv/a.pdf/b.pdf', 'Archiv'));
		self::assertArrayHasKey(self::HOME . '/Archiv/a.pdf', $this->nodes);
	}

	public function testPruneLeavesFoldersOutsideTheRootAlone(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Eingang'] = null;

		self::assertSame(0, $this->service->pruneEmptyParents(self::USER, 'Eingang/a.pdf', 'Archiv'));
		self::assertSame(0, $this->service->pruneEmptyParents(self::USER, 'a.pdf', 'Archiv'));
		self::assertArrayHasKey(self::HOME . '/Eingang', $this->nodes);
	}

	/** @return resource */
	private function stream(string $content) {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $content);

		return $stream;
	}

	/** @return list<string> */
	private function temporaryFiles(): array {
		return array_values(array_filter(array_keys($this->nodes), static fn (string $path): bool => str_ends_with($path, '.part')));
	}

	private function node(string $path): Node {
		if (!array_key_exists($path, $this->nodes)) {
			throw new NotFoundException($path);
		}

		return $this->nodes[$path] === null ? $this->folder($path) : $this->file($path);
	}

	private function folder(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$this->describe($folder, $path);
		$folder->method('nodeExists')->willReturnCallback(fn (string $relative): bool => array_key_exists($this->resolve($path, $relative), $this->nodes));
		$folder->method('get')->willReturnCallback(fn (string $relative): Node => $this->node($this->resolve($path, $relative)));
		$folder->method('getFullPath')->willReturnCallback(fn (string $relative): string => $this->resolve($path, $relative));
		$folder->method('isCreatable')->willReturnCallback(fn (): bool => !isset($this->readOnly[$path]));
		$folder->method('getDirectoryListing')->willReturnCallback(fn (): array => array_map(
			fn (string $child): Node => $this->node($child),
			array_values(array_filter(array_keys($this->nodes), static fn (string $candidate): bool => dirname($candidate) === $path)),
		));
		$folder->method('newFolder')->willReturnCallback(function (string $relative) use ($path): Folder {
			$target = $this->resolve($path, $relative);
			$this->nodes[$target] = null;

			return $this->folder($target);
		});
		$folder->method('newFile')->willReturnCallback(function (string $relative, mixed $content = null) use ($path): File {
			if (isset($this->failures['newFile'])) {
				throw $this->failures['newFile'];
			}
			$target = $this->resolve($path, $relative);
			$this->nodes[$target] = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;

			return $this->file($target);
		});

		return $folder;
	}

	private function file(string $path): File {
		$file = $this->createMock(File::class);
		$this->describe($file, $path);
		$file->method('getEtag')->willReturnCallback(fn (): string => md5((string)$this->nodes[$path]));
		$file->method('fopen')->willReturnCallback(function () use ($path) {
			if ($this->filesCannotBeOpened) {
				return false;
			}
			$stream = fopen('php://temp', 'w+b');
			fwrite($stream, (string)$this->nodes[$path]);
			rewind($stream);

			return $stream;
		});

		return $file;
	}

	/** The operations of every node: its name, deleting and moving it with everything below it. */
	private function describe(Folder|File $node, string $path): void {
		$node->method('getName')->willReturn(basename($path));
		$node->method('delete')->willReturnCallback(function () use ($path): void {
			if (isset($this->failures['delete'])) {
				throw $this->failures['delete'];
			}
			foreach (array_keys($this->nodes) as $candidate) {
				if ($candidate === $path || str_starts_with($candidate, $path . '/')) {
					unset($this->nodes[$candidate]);
				}
			}
		});
		$node->method('move')->willReturnCallback(function (string $target) use ($path): Node {
			if (isset($this->failures['move'])) {
				throw $this->failures['move'];
			}
			$moved = [];
			foreach ($this->nodes as $candidate => $content) {
				if ($candidate === $path || str_starts_with($candidate, $path . '/')) {
					$moved[$target . substr($candidate, strlen($path))] = $content;
					unset($this->nodes[$candidate]);
				}
			}
			$this->nodes += $moved;

			return $this->node($target);
		});
	}

	private function resolve(string $folder, string $relative): string {
		return $relative === '' ? $folder : $folder . '/' . trim($relative, '/');
	}
}
