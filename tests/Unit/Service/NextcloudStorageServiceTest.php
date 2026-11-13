<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\NextcloudStorageService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\StorageStreams;
use OCA\PaperlessSync\Tests\Doubles\TestUserFilesystem;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
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
	/** @var array<string, true> the files and folders that the user may not read, by absolute path */
	private array $unreadable = [];
	/** @var array<string, bool|null> the nodes that reach the user through a share, by absolute path: whether it allows downloads, null without attributes */
	private array $shareDownload = [];
	/** @var array<string, \Throwable> failures of the node operations newFile, putContent, move and delete, and of the storage operations unlink and opendir */
	private array $failures = [];
	/** @var array<string, true> the .part files that Folder::newFile() created, which Nextcloud keeps out of its file cache, and whose nodes keep no permissions */
	private array $withoutPermissions = [];
	/** @var list<string> the absolute paths of every file that was removed through its storage, past the trash bin */
	private array $unlinked = [];
	/** Whether the storage refuses to create a file, as a read-only one. */
	private bool $storageRefusesFiles = false;
	/** Whether the storage takes no byte of a file that it created, as a full disk. */
	private bool $storageRefusesWrites = false;
	/** @var array<string, int> the file ID of every node, which Nextcloud keeps through moves and updates in place */
	private array $fileIds = [];
	private int $lastFileId = 0;
	/** @var list<string> the absolute paths of every node that was deleted */
	private array $deleted = [];
	/** @var array<string, int|float> the free space of a folder, by absolute path; unlimited for the others */
	private array $freeSpace = [];
	private bool $filesCannotBeOpened = false;
	/** How often the service looked up the folder of the files of a user. */
	private int $userFolderLookups = 0;
	/** @var list<string> the users for whom Nextcloud gives no folder of files, as for a user who is gone or whose user backend can't answer */
	private array $withoutUserObject = [];
	private TestUserFilesystem $filesystem;
	private NextcloudStorageService $service;

	protected function setUp(): void {
		// A German instance, as the documents of the tests: the folders have their German names.
		$this->defaultLanguage = 'de';
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(function (string $userId): Folder {
			++$this->userFolderLookups;
			if (in_array($userId, $this->withoutUserObject, true)) {
				throw new RuntimeException("Backends provided no user object for {$userId}");
			}

			return $this->folder('/' . $userId . '/files');
		});
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $userId): ?IUser => $userId === self::USER ? $this->createStub(IUser::class) : null);
		$userManager->method('userExists')->willReturnCallback(static fn (string $userId): bool => $userId === self::USER);
		// Each user whose filesystem a change entered, with the number of lookups of the files of a
		// user and of deleted nodes before.
		$this->filesystem = new TestUserFilesystem(fn (string $userId): array => [$userId, $this->userFolderLookups, count($this->deleted)]);
		$this->service = new NextcloudStorageService($rootFolder, $userManager, new PathTemplateService(), $this->filesystem, $this->appConfig(), $this->recordingLogger());
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
		$this->expectException(InvalidSettingException::class);
		$this->expectExceptionMessage('Nextcloud user ghost does not exist.');
		$this->service->test('ghost', 'Dokumente/Paperless');
	}

	public function testBaseFolderThatIsAFileFailsTheTest(): void {
		$this->nodes[self::HOME . '/Dokumente'] = 'a file';

		$this->expectException(InvalidSettingException::class);
		$this->expectExceptionMessage('The configured Nextcloud base folder is not a writable folder.');
		$this->service->test(self::USER, 'Dokumente');
	}

	public function testReadOnlyBaseFolderFailsTheTest(): void {
		$this->nodes[self::HOME . '/Dokumente'] = null;
		$this->readOnly[self::HOME . '/Dokumente'] = true;

		$this->expectException(InvalidSettingException::class);
		$this->expectExceptionMessage('The configured Nextcloud base folder is not a writable folder.');
		$this->service->test(self::USER, 'Dokumente');
	}

	public function testMissingBaseFolderFailsTheTestInAReadOnlyUserFolder(): void {
		$this->readOnly[self::HOME] = true;

		$this->expectException(InvalidSettingException::class);
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

	/**
	 * The writes that failed on Nextcloud 33.0.11, 34.0.6, 35.0.3 and newer left their temporary
	 * files, which Files doesn't show (#490). The first run removes them from the archive and the
	 * errors, in every folder below them, through the storage, which lists them, and past the trash
	 * bin; it leaves every other file alone, also one of a similar name, and the inbox, whose files
	 * are the user's.
	 */
	public function testPrepareRemovesTheTemporaryFilesThatEarlierRunsLeftOnce(): void {
		$base = self::HOME . '/Dokumente/Paperless';
		foreach (['/Dokumente', '/Dokumente/Paperless', '/Dokumente/Paperless/Archiv', '/Dokumente/Paperless/Archiv/2026', '/Dokumente/Paperless/Eingang', '/Dokumente/Paperless/Fehler'] as $folder) {
			$this->nodes[self::HOME . $folder] = null;
		}
		$leftovers = [
			$base . '/Archiv/.a.pdf.paperless-0123456789abcdef.part',
			$base . '/Archiv/2026/.2026-11-11 Example - Title [P46].pdf.paperless-ca86b84c4d096f66.part',
			$base . '/Fehler/.b.pdf.error.txt.paperless-00112233445566ff.part',
		];
		$kept = [
			$base . '/Archiv/2026/a.pdf' => '%PDF',
			$base . '/Archiv/.notes.part' => 'not the app\'s',
			$base . '/Archiv/a.pdf.paperless-0123456789abcdef.part' => 'not hidden',
			$base . '/Archiv/.a.pdf.paperless-0123.part' => 'too short',
			$base . '/Archiv/.a.pdf.paperless-0123456789ABCDEF.part' => 'not the app\'s hexadecimal',
			$base . '/Eingang/.c.pdf.paperless-0123456789abcdef.part' => 'in the inbox',
		];
		foreach ($leftovers as $leftover) {
			$this->nodes[$leftover] = 'partial';
		}
		$this->nodes += $kept;
		// A folder with such a name is no temporary file.
		$this->nodes[$base . '/Archiv/.d.paperless-0123456789abcdef.part'] = null;

		$this->service->prepare($this->configService()->validate(self::validSettings(), 'token'));

		foreach ($leftovers as $leftover) {
			self::assertArrayNotHasKey($leftover, $this->nodes);
		}
		self::assertEqualsCanonicalizing($leftovers, $this->unlinked);
		self::assertSame([], $this->deleted, 'The trash bin takes no temporary file.');
		foreach ($kept as $path => $content) {
			self::assertSame($content, $this->nodes[$path]);
		}
		self::assertArrayHasKey($base . '/Archiv/.d.paperless-0123456789abcdef.part', $this->nodes);
		self::assertSame([], $this->loggedWarnings);

		// The next runs leave the folders alone: the writes of this version remove their own.
		$this->nodes[$base . '/Archiv/.e.pdf.paperless-0123456789abcdef.part'] = 'partial';
		$this->service->prepare($this->configService()->validate(self::validSettings(), 'token'));

		self::assertArrayHasKey($base . '/Archiv/.e.pdf.paperless-0123456789abcdef.part', $this->nodes);
		self::assertCount(3, $this->unlinked);
	}

	public function testFolderWhoseTemporaryFilesCannotBeListedIsClearedByTheNextRun(): void {
		$leftover = self::HOME . '/Dokumente/Paperless/Archiv/.a.pdf.paperless-0123456789abcdef.part';
		$this->failures['opendir'] = new RuntimeException('The storage is not available');
		$config = $this->configService()->validate(['inbox_enabled' => false] + self::validSettings(), 'token');

		$this->service->prepare($config);
		$this->nodes[$leftover] = 'partial';

		self::assertSame(['Paperless Sync could not remove the temporary files that earlier runs left in Dokumente/Paperless/Archiv of paperless; the next run tries again.'], $this->loggedWarnings);

		unset($this->failures['opendir']);
		$this->service->prepare($config);

		self::assertSame([$leftover], $this->unlinked);
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

	public function testFindsTheIdOfAFileOrFolderAtTheNormalizedPath(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = '%PDF';

		$file = $this->service->findFileId(self::USER, '/Archiv\\a.pdf');

		self::assertSame($this->fileId(self::HOME . '/Archiv/a.pdf'), $file);
		self::assertSame($this->fileId(self::HOME . '/Archiv'), $this->service->findFileId(self::USER, 'Archiv/'));
		self::assertNotSame($file, $this->service->findFileId(self::USER, 'Archiv'));
		self::assertNull($this->service->findFileId(self::USER, 'Archiv/b.pdf'), 'A path without a file has no ID.');
	}

	public function testWriteStreamStoresTheWholeStreamInNewFolders(): void {
		$stream = $this->stream('%PDF-content');

		$this->service->writeStream(self::USER, 'Archiv/2026/a.pdf', $stream, 'replace');

		self::assertSame('%PDF-content', $this->nodes[self::HOME . '/Archiv/2026/a.pdf']);
		self::assertSame([], $this->temporaryFiles());
	}

	/**
	 * Deleting the file and moving a new one into its place would give it a new file ID, which
	 * loses its shares, comments, tags, favorites and versions and leaves the old copy in the trash bin.
	 */
	public function testWriteStreamUpdatesAnExistingFileInPlace(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'old';
		$fileId = $this->fileId(self::HOME . '/Archiv/a.pdf');

		$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');

		self::assertSame('new', $this->nodes[self::HOME . '/Archiv/a.pdf']);
		self::assertSame($fileId, $this->fileId(self::HOME . '/Archiv/a.pdf'));
		self::assertSame([], $this->deleted, 'Nothing goes to the trash bin.');
		self::assertSame([], $this->temporaryFiles());
	}

	public function testWriteTextUpdatesAnExistingFileInPlace(): void {
		$this->nodes[self::HOME . '/Fehler'] = null;
		$this->nodes[self::HOME . '/Fehler/a.pdf.error.txt'] = 'Paperless import failed';
		$fileId = $this->fileId(self::HOME . '/Fehler/a.pdf.error.txt');

		$this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', 'Paperless import failed again');

		self::assertSame('Paperless import failed again', $this->nodes[self::HOME . '/Fehler/a.pdf.error.txt']);
		self::assertSame($fileId, $this->fileId(self::HOME . '/Fehler/a.pdf.error.txt'));
	}

	public function testFailedUpdateInPlaceIsReported(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'old';
		$this->failures['putContent'] = new NotPermittedException('The file is locked');

		try {
			$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');
			self::fail('The failed update must be reported.');
		} catch (NotPermittedException $exception) {
			self::assertSame('The file is locked', $exception->getMessage());
		}
		self::assertSame('old', $this->nodes[self::HOME . '/Archiv/a.pdf']);
		self::assertSame([], $this->temporaryFiles());
	}

	public function testWriteStreamReplacesAFolderInThePlaceOfTheFile(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf/b.pdf'] = 'b';

		$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');

		self::assertSame('new', $this->nodes[self::HOME . '/Archiv/a.pdf']);
		self::assertArrayNotHasKey(self::HOME . '/Archiv/a.pdf/b.pdf', $this->nodes);
		self::assertSame([], $this->temporaryFiles());
	}

	public function testWriteStreamKeepsAnExistingFileWhenConflictsAreSkipped(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Archiv/a.pdf'] = 'old';

		try {
			$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'skip');
			self::fail('A conflict must not replace the file.');
		} catch (RuntimeException $exception) {
			self::assertSame('Nextcloud file conflict at Archiv/a.pdf.', $exception->getMessage());
		}
		self::assertSame('old', $this->nodes[self::HOME . '/Archiv/a.pdf']);
	}

	public function testWriteStreamRequiresAStream(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A readable source stream is required.');
		$this->service->writeStream(self::USER, 'Archiv/a.pdf', 'not a stream', 'replace');
	}

	/**
	 * Nextcloud 33.0.11, 34.0.6, 35.0.3 and newer move a file only when its node may be updated, or
	 * deleted in a folder that takes new files, and keep no permissions for the node of a .part
	 * file that Folder::newFile() created: every new file failed with "No permission to move", and
	 * its temporary file stayed (#490).
	 */
	public function testANewFileReachesItsPlaceOnANextcloudThatChecksTheMoveOfItsTemporaryFile(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;

		$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('%PDF-content'), 'replace');
		$this->service->writeText(self::USER, 'Archiv/a.txt', 'text');

		self::assertSame('%PDF-content', $this->nodes[self::HOME . '/Archiv/a.pdf']);
		self::assertSame('text', $this->nodes[self::HOME . '/Archiv/a.txt']);
		self::assertSame([], $this->temporaryFiles());
		self::assertSame([], $this->deleted, 'Nothing goes to the trash bin.');
		self::assertSame([], $this->unlinked);
	}

	public function testFailedWriteRemovesItsTemporaryFile(): void {
		$this->failures['move'] = new RuntimeException('The storage is full');

		try {
			$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');
			self::fail('The failed move must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('The storage is full', $exception->getMessage());
		}
		self::assertSame([], $this->temporaryFiles());
		self::assertArrayNotHasKey(self::HOME . '/Archiv/a.pdf', $this->nodes);
		self::assertCount(1, $this->unlinked, 'The storage removed the temporary file.');
		self::assertSame([], $this->deleted, 'The trash bin takes no temporary file.');
		self::assertSame([], $this->loggedWarnings);
	}

	public function testFailedCleanupKeepsTheErrorOfTheWrite(): void {
		$this->failures['move'] = new RuntimeException('The storage is full');
		$this->failures['unlink'] = new NotPermittedException('The temporary file is locked');

		try {
			$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('new'), 'replace');
			self::fail('The failed move must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('The storage is full', $exception->getMessage());
		}
		$temporary = $this->temporaryFiles();
		self::assertCount(1, $temporary);
		self::assertMatchesRegularExpression('#^/paperless/files/Archiv/\.a\.pdf\.paperless-[0-9a-f]{16}\.part$#', $temporary[0], 'A hidden file next to the target, with a random name and the ending .part that Nextcloud keeps out of the quota.');
		self::assertSame(["Paperless Sync could not remove the temporary file {$temporary[0]}."], $this->loggedWarnings, 'The log names the file that stays.');
	}

	public function testWriteThatCreatesNoFileNeedsNoCleanup(): void {
		$this->storageRefusesFiles = true;

		try {
			$this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', 'Paperless import failed');
			self::fail('The refused file must be reported.');
		} catch (NotPermittedException $exception) {
			self::assertMatchesRegularExpression('#^Could not create /paperless/files/Fehler/\.a\.pdf\.error\.txt\.paperless-[0-9a-f]{16}\.part$#', $exception->getMessage());
		}
		self::assertSame([], $this->temporaryFiles());
		self::assertSame([], $this->unlinked);
		self::assertSame([], $this->loggedWarnings);
	}

	/** A storage that takes less than the whole file, as a full disk, fails the write, and the file never reaches its place. */
	public function testIncompleteWriteFailsAndRemovesItsTemporaryFile(): void {
		$this->storageRefusesWrites = true;
		$writes = [
			'text' => fn () => $this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', 'Paperless import failed'),
			'stream' => fn () => $this->service->writeStream(self::USER, 'Fehler/a.pdf.error.txt', $this->stream('Paperless import failed'), 'replace'),
		];
		foreach ($writes as $kind => $write) {
			try {
				$write();
				self::fail("The incomplete write of a {$kind} must be reported.");
			} catch (NotPermittedException $exception) {
				self::assertMatchesRegularExpression('#^Could not write the whole of /paperless/files/Fehler/\.a\.pdf\.error\.txt\.paperless-[0-9a-f]{16}\.part$#', $exception->getMessage(), $kind);
			}
			self::assertArrayNotHasKey(self::HOME . '/Fehler/a.pdf.error.txt', $this->nodes, $kind);
			self::assertSame([], $this->temporaryFiles(), $kind);
		}
		self::assertCount(2, $this->unlinked);
	}

	/**
	 * Nextcloud applies no quota to the temporary .part file of a write, and a full quota refuses
	 * new folders with a NotPermittedException, so the write checks the space before either.
	 */
	public function testWriteThatExceedsTheFreeSpaceIsRefusedBeforeAnyFolderIsCreated(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->freeSpace[self::HOME . '/Archiv'] = 11;

		try {
			$this->service->writeStream(self::USER, 'Archiv/2026/a.pdf', $this->stream('%PDF-content'), 'replace');
			self::fail('A file larger than the free space must be refused.');
		} catch (NotEnoughSpaceException $exception) {
			self::assertSame('Not enough free space in Nextcloud: the file needs 12 bytes, and 11 are free.', $exception->getMessage());
		}
		self::assertSame([self::HOME, self::HOME . '/Archiv'], array_keys($this->nodes));
	}

	public function testWriteThatExceedsAFreeSpaceOfManyBytesIsRefused(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->freeSpace[self::HOME . '/Archiv'] = 11.0;

		$this->expectException(NotEnoughSpaceException::class);
		$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('%PDF-content'), 'replace');
	}

	/** The free space comes from the deepest folder of the path that exists, not from a folder of the same name elsewhere. */
	public function testFreeSpaceComesFromTheDeepestFolderOfThePath(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->freeSpace[self::HOME . '/Archiv'] = 0;

		$this->service->writeStream(self::USER, 'Neu/Archiv/a.pdf', $this->stream('%PDF-content'), 'replace');

		self::assertSame('%PDF-content', $this->nodes[self::HOME . '/Neu/Archiv/a.pdf']);
	}

	public function testWriteThatFitsIntoTheFreeSpaceIsStored(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->freeSpace[self::HOME . '/Archiv'] = 12.0;

		$this->service->writeStream(self::USER, 'Archiv/a.pdf', $this->stream('%PDF-content'), 'replace');

		self::assertSame('%PDF-content', $this->nodes[self::HOME . '/Archiv/a.pdf']);
	}

	public function testTextThatExceedsTheFreeSpaceOfTheUserFolderIsRefused(): void {
		$this->freeSpace[self::HOME] = 0;

		$this->expectException(NotEnoughSpaceException::class);
		$this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', 'Paperless import failed');
	}

	public function testUnknownFreeSpaceIsNotChecked(): void {
		$this->freeSpace[self::HOME] = FileInfo::SPACE_UNKNOWN;

		$this->service->writeText(self::USER, 'Fehler/a.pdf.error.txt', 'Paperless import failed');

		self::assertSame('Paperless import failed', $this->nodes[self::HOME . '/Fehler/a.pdf.error.txt']);
	}

	public function testWriteBelowAFileWithTheNameOfAFolderFails(): void {
		$this->nodes[self::HOME . '/Archiv'] = 'a file';
		// The folder of the next part of the path, elsewhere, has no say in the free space.
		$this->nodes[self::HOME . '/2026'] = null;
		$this->freeSpace[self::HOME . '/2026'] = 0;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Nextcloud path component Archiv is not a folder.');
		$this->service->writeText(self::USER, 'Archiv/2026/a.pdf.error.txt', 'Paperless import failed');
	}

	public function testWritableFolderIsAFolderInWhichFilesCanBeCreated(): void {
		$this->nodes[self::HOME . '/Archiv'] = null;
		$this->nodes[self::HOME . '/Fest'] = null;
		$this->nodes[self::HOME . '/a.pdf'] = '%PDF';
		$this->readOnly[self::HOME . '/Fest'] = true;

		self::assertTrue($this->service->isWritableFolder(self::USER, '/Archiv/'));
		self::assertFalse($this->service->isWritableFolder(self::USER, 'Fest'));
		self::assertFalse($this->service->isWritableFolder(self::USER, 'a.pdf'));
		self::assertFalse($this->service->isWritableFolder(self::USER, 'Fehlt'));
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
		$fileId = $this->fileId(self::HOME . '/Archiv/a.pdf');

		self::assertTrue($this->service->move(self::USER, 'Archiv/a.pdf', 'Archiv/b.pdf', 'replace'));

		self::assertSame('new', $this->nodes[self::HOME . '/Archiv/b.pdf']);
		self::assertSame($fileId, $this->fileId(self::HOME . '/Archiv/b.pdf'), 'The moved file keeps its ID.');
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
		$this->nodes[self::HOME . '/Eingang/Versicherung/2026'] = null;
		$this->nodes[self::HOME . '/Eingang/Versicherung/2026/beitrag.pdf'] = 'beitrag';
		$this->nodes[self::HOME . '/Eingang/.a.pdf.paperless-0123.part'] = 'partial';
		$this->nodes[self::HOME . '/Eingang/.hidden'] = null;
		$this->nodes[self::HOME . '/Eingang/.hidden/secret.pdf'] = 'secret';
		$this->nodes[self::HOME . '/Eingang/a.pdf'] = 'a';

		self::assertSame([
			['path' => 'Eingang/Versicherung/2026/beitrag.pdf', 'name' => 'beitrag.pdf', 'etag' => md5('beitrag'), 'id' => $this->fileId(self::HOME . '/Eingang/Versicherung/2026/beitrag.pdf')],
			['path' => 'Eingang/Versicherung/police.pdf', 'name' => 'police.pdf', 'etag' => md5('police'), 'id' => $this->fileId(self::HOME . '/Eingang/Versicherung/police.pdf')],
			['path' => 'Eingang/a.pdf', 'name' => 'a.pdf', 'etag' => md5('a'), 'id' => $this->fileId(self::HOME . '/Eingang/a.pdf')],
			['path' => 'Eingang/z.pdf', 'name' => 'z.pdf', 'etag' => md5('z'), 'id' => $this->fileId(self::HOME . '/Eingang/z.pdf')],
		], $this->service->listFiles(self::USER, 'Eingang', true));
		self::assertSame(
			['Eingang/a.pdf', 'Eingang/z.pdf'],
			array_column($this->service->listFiles(self::USER, 'Eingang', false), 'path'),
		);
	}

	/** A file or folder by its ID, as the user sees it among their files, which "Send to Paperless" names. */
	public function testANodeIsFoundByItsIdAmongTheFilesOfTheUser(): void {
		$this->nodes[self::HOME . '/Scans'] = null;
		$this->nodes[self::HOME . '/Scans/receipt.pdf'] = 'receipt';
		$this->nodes[self::HOME . '/Scans/secret.pdf'] = 'secret';
		$this->unreadable[self::HOME . '/Scans/secret.pdf'] = true;

		self::assertSame(['path' => 'Scans/receipt.pdf', 'name' => 'receipt.pdf', 'folder' => false, 'readable' => true], $this->service->nodeById(self::USER, $this->fileId(self::HOME . '/Scans/receipt.pdf')));
		self::assertSame(['path' => 'Scans', 'name' => 'Scans', 'folder' => true, 'readable' => true], $this->service->nodeById(self::USER, $this->fileId(self::HOME . '/Scans')));
		self::assertFalse($this->service->nodeById(self::USER, $this->fileId(self::HOME . '/Scans/secret.pdf'))['readable'] ?? true);
		self::assertNull($this->service->nodeById(self::USER, 999));
	}

	/** A share whose owner allows viewing only makes its files unreadable for sending; other shares don't. */
	public function testAFileOfAViewOnlyShareIsNotReadable(): void {
		foreach (['view-only' => false, 'download' => true, 'plain' => null] as $name => $download) {
			$this->nodes[self::HOME . "/{$name}.pdf"] = $name;
			$this->shareDownload[self::HOME . "/{$name}.pdf"] = $download;
		}

		self::assertFalse($this->service->nodeById(self::USER, $this->fileId(self::HOME . '/view-only.pdf'))['readable'] ?? true);
		self::assertTrue($this->service->nodeById(self::USER, $this->fileId(self::HOME . '/download.pdf'))['readable'] ?? false);
		self::assertTrue($this->service->nodeById(self::USER, $this->fileId(self::HOME . '/plain.pdf'))['readable'] ?? false);
	}

	/** The folder of the files of the user itself, and a node that its files don't hold, are no files of the user. */
	public function testTheFolderOfTheUserAndANodeOutsideItAreNoFilesOfTheUser(): void {
		$this->nodes[self::HOME] = null;
		$this->nodes['/someone/files/letter.pdf'] = 'letter';

		self::assertNull($this->service->nodeById(self::USER, $this->fileId(self::HOME)));
		self::assertNull($this->service->nodeById(self::USER, $this->fileId('/someone/files/letter.pdf')));
	}

	/**
	 * A user who is gone has no files left, but a lookup that fails for a user who exists, such as
	 * while their user backend can't answer, fails, as the file may be back once it passes (#440).
	 */
	public function testAUserWhoIsGoneHasNoFilesAndAFailedLookupFails(): void {
		$this->withoutUserObject = ['ghost', self::USER];

		self::assertNull($this->service->nodeById('ghost', 1));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Backends provided no user object for ' . self::USER);
		$this->service->nodeById(self::USER, 1);
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
		$this->nodes[self::HOME . '/Archiv-alt'] = null;

		self::assertSame(0, $this->service->pruneEmptyParents(self::USER, 'Eingang/a.pdf', 'Archiv'));
		self::assertSame(0, $this->service->pruneEmptyParents(self::USER, 'a.pdf', 'Archiv'));
		self::assertSame(0, $this->service->pruneEmptyParents(self::USER, 'Archiv-alt/a.pdf', 'Archiv'), 'A folder whose name only starts with the name of the root is outside it.');
		self::assertArrayHasKey(self::HOME . '/Eingang', $this->nodes);
		self::assertArrayHasKey(self::HOME . '/Archiv-alt', $this->nodes);
	}

	/**
	 * Nextcloud's trash bin takes a deleted file only from the filesystem of the process: each
	 * change of the files of a user enters the filesystem of that user before it looks at them,
	 * so that what it deletes or replaces goes into the user's trash bin, whoever runs it.
	 */
	public function testEveryChangeEntersTheFilesystemOfItsUserBeforeItLooksAtTheFiles(): void {
		$changes = [
			'prepare' => fn () => $this->service->prepare($this->configService()->validate(self::validSettings(), 'token')),
			'writeStream' => fn () => $this->service->writeStream(self::USER, 'Archiv/Neu/a.pdf', $this->stream('%PDF'), 'replace'),
			'writeText' => fn () => $this->service->writeText(self::USER, 'Archiv/Neu/a.txt', 'text'),
			'move' => fn () => $this->service->move(self::USER, 'Archiv/Neu/a.pdf', 'Archiv/Neu/a.txt', 'replace'),
			'delete' => fn () => $this->service->delete(self::USER, 'Archiv/Neu/a.txt'),
			'pruneEmptyParents' => fn () => $this->service->pruneEmptyParents(self::USER, 'Archiv/Neu/a.txt', 'Archiv'),
		];
		foreach ($changes as $name => $change) {
			$this->filesystem->entered = [];
			$lookups = $this->userFolderLookups;
			$deleted = count($this->deleted);

			$change();

			self::assertSame([[self::USER, $lookups, $deleted]], $this->filesystem->entered, "{$name} enters the filesystem of the user first, once.");
		}
		self::assertSame([self::HOME . '/Archiv/Neu/a.txt', self::HOME . '/Archiv/Neu/a.txt', self::HOME . '/Archiv/Neu'], $this->deleted, 'The move replaced the text, and the delete and the pruning deleted the rest.');
	}

	/** Reading leaves the filesystem of the process as it is, such as that of a user who sends a file of theirs. */
	public function testReadingLeavesTheFilesystemOfTheProcessAlone(): void {
		$this->nodes[self::HOME . '/Eingang'] = null;
		$this->nodes[self::HOME . '/Eingang/a.pdf'] = '%PDF';

		$this->service->test(self::USER, 'Eingang');
		$this->service->exists(self::USER, 'Eingang/a.pdf');
		$this->service->findFileId(self::USER, 'Eingang/a.pdf');
		$this->service->isWritableFolder(self::USER, 'Eingang');
		$this->service->listFiles(self::USER, 'Eingang', true);
		$this->service->nodeById(self::USER, $this->fileId(self::HOME . '/Eingang/a.pdf'));
		fclose($this->service->openRead(self::USER, 'Eingang/a.pdf'));

		self::assertSame([], $this->filesystem->entered);
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

	/** The file ID of a node, which Nextcloud gives it when it creates it. */
	private function fileId(string $path): int {
		return $this->fileIds[$path] ??= ++$this->lastFileId;
	}

	private function node(string $path): Node {
		if (!array_key_exists($path, $this->nodes)) {
			throw new NotFoundException($path);
		}

		return $this->nodes[$path] === null ? $this->folder($path) : $this->file($path);
	}

	private function folder(string $path): Folder {
		$folder = $this->createStub(Folder::class);
		$this->describe($folder, $path);
		$folder->method('nodeExists')->willReturnCallback(fn (string $relative): bool => array_key_exists($this->resolve($path, $relative), $this->nodes));
		$folder->method('get')->willReturnCallback(fn (string $relative): Node => $this->node($this->resolve($path, $relative)));
		$folder->method('getFullPath')->willReturnCallback(fn (string $relative): string => $this->resolve($path, $relative));
		$folder->method('isCreatable')->willReturnCallback(fn (): bool => !isset($this->readOnly[$path]));
		// The node with the ID, as Nextcloud finds it among the files that the folder shows, here among all.
		$folder->method('getFirstNodeById')->willReturnCallback(function (int $id): ?Node {
			$found = array_search($id, $this->fileIds, true);

			return $found === false ? null : $this->node($found);
		});
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $absolute): ?string => match (true) {
			$absolute === $path => '/',
			str_starts_with($absolute, $path . '/') => substr($absolute, strlen($path)),
			default => null,
		});
		$folder->method('getFreeSpace')->willReturnCallback(fn (): int|float => $this->freeSpace[$path] ?? FileInfo::SPACE_UNLIMITED);
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
			$this->fileIds[$target] = ++$this->lastFileId;
			if (str_ends_with($target, '.part')) {
				$this->withoutPermissions[$target] = true;
			}

			return $this->file($target);
		});

		return $folder;
	}

	private function file(string $path): File {
		$file = $this->createStub(File::class);
		$this->describe($file, $path);
		$file->method('getEtag')->willReturnCallback(fn (): string => md5((string)$this->nodes[$path]));
		$file->method('putContent')->willReturnCallback(function (mixed $content) use ($path): void {
			if (isset($this->failures['putContent'])) {
				throw $this->failures['putContent'];
			}
			$this->nodes[$path] = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
		});
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

	/** The operations of every node: its name and ID, deleting and moving it with everything below it. */
	private function describe(Folder|File $node, string $path): void {
		$node->method('getName')->willReturn(basename($path));
		$node->method('getPath')->willReturn($path);
		$node->method('isReadable')->willReturnCallback(fn (): bool => !isset($this->unreadable[$path]));
		$node->method('getStorage')->willReturnCallback(fn (): IStorage => $this->storageOf($path));
		// The path inside the storage of the files of the user, which starts at the folder of the user.
		$node->method('getInternalPath')->willReturn((string)preg_replace('#^/[^/]+/?#', '', $path));
		$node->method('getId')->willReturnCallback(fn (): int => $this->fileId($path));
		$node->method('delete')->willReturnCallback(function () use ($path): void {
			if (isset($this->failures['delete'])) {
				throw $this->failures['delete'];
			}
			if (isset($this->withoutPermissions[$path])) {
				throw new NotPermittedException('No delete permission for path "' . $path . '"');
			}
			$this->deleted[] = $path;
			foreach (array_keys($this->nodes) as $candidate) {
				if ($candidate === $path || str_starts_with($candidate, $path . '/')) {
					unset($this->nodes[$candidate], $this->fileIds[$candidate]);
				}
			}
		});
		$node->method('move')->willReturnCallback(function (string $target) use ($path): Node {
			if (isset($this->failures['move'])) {
				throw $this->failures['move'];
			}
			if (isset($this->withoutPermissions[$path])) {
				throw new NotPermittedException('No permission to move ' . $path);
			}
			$moved = [];
			foreach ($this->nodes as $candidate => $content) {
				if ($candidate === $path || str_starts_with($candidate, $path . '/')) {
					$destination = $target . substr($candidate, strlen($path));
					$moved[$destination] = $content;
					$this->fileIds[$destination] = $this->fileId($candidate);
					unset($this->nodes[$candidate], $this->fileIds[$candidate]);
				}
			}
			$this->nodes += $moved;

			return $this->node($target);
		});
	}

	/**
	 * The storage of a node: a share for those of $shareDownload, with or without its attribute for
	 * downloads, and else the storage of the files of the user.
	 */
	private function storageOf(string $path): IStorage {
		if (!array_key_exists($path, $this->shareDownload)) {
			return $this->userStorage('/' . explode('/', $path)[1]);
		}
		$download = $this->shareDownload[$path];
		$attributes = null;
		if ($download !== null) {
			$attributes = $this->createStub(IAttributes::class);
			$attributes->method('getAttribute')->willReturnCallback(static fn (string $scope, string $key): ?bool => $scope === 'permissions' && $key === 'download' ? $download : null);
		}
		$share = $this->createStub(IShare::class);
		$share->method('getAttributes')->willReturn($attributes);
		$storage = $this->createStub(ISharedStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class): bool => $class === ISharedStorage::class);
		$storage->method('getShare')->willReturn($share);

		return $storage;
	}

	/**
	 * The storage of the files of the user in $root, such as /paperless, whose paths start at that
	 * folder, such as files/Archiv. Like the storages of Nextcloud, it lists and writes a .part file
	 * as any other, and removes a file without the trash bin.
	 */
	private function userStorage(string $root): IStorage {
		$absolute = static fn (string $internal): string => $internal === '' ? $root : $root . '/' . $internal;
		$storage = $this->createStub(IStorage::class);
		$storage->method('fopen')->willReturnCallback(function (string $internal, string $mode) use ($absolute) {
			if ($this->storageRefusesFiles) {
				return false;
			}
			$target = $absolute($internal);
			$this->nodes[$target] = '';

			return StorageStreams::file(function (string $content) use ($target): void {
				$this->nodes[$target] = $content;
			}, $this->storageRefusesWrites);
		});
		$storage->method('file_exists')->willReturnCallback(fn (string $internal): bool => array_key_exists($absolute($internal), $this->nodes));
		$storage->method('is_file')->willReturnCallback(fn (string $internal): bool => is_string($this->nodes[$absolute($internal)] ?? null));
		$storage->method('unlink')->willReturnCallback(function (string $internal) use ($absolute): bool {
			if (isset($this->failures['unlink'])) {
				throw $this->failures['unlink'];
			}
			$target = $absolute($internal);
			$this->unlinked[] = $target;
			unset($this->nodes[$target], $this->fileIds[$target]);

			return true;
		});
		$storage->method('opendir')->willReturnCallback(function (string $internal) use ($absolute) {
			if (isset($this->failures['opendir'])) {
				throw $this->failures['opendir'];
			}
			$folder = $absolute($internal);

			return StorageStreams::directory(array_values(array_map(
				static fn (string $child): string => substr($child, strlen($folder) + 1),
				array_filter(array_keys($this->nodes), static fn (string $candidate): bool => dirname($candidate) === $folder),
			)));
		});

		return $storage;
	}

	private function resolve(string $folder, string $relative): string {
		return $relative === '' ? $folder : $folder . '/' . trim($relative, '/');
	}
}
