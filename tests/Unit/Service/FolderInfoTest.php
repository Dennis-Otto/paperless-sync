<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FolderInfo;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class FolderInfoTest extends TestCase {
	use InMemoryConfiguration;

	private const TARGET = TestStorage::USER;
	private const ARCHIVE = 'Documents/Paperless/Archive';
	private const INBOX = 'Documents/Paperless/Inbox';
	private const ERRORS = 'Documents/Paperless/Failed imports';

	private ConfigService $configService;
	private StatusService $statusService;
	private TestStorage $storage;
	/** @var array<string, array<string, bool>> the user settings of the app, by user and key */
	private array $userSettings = [];
	/** @var list<string> the messages that the log got */
	private array $warnings = [];
	private FolderInfo $folderInfo;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(self::validSettings(), 'secret-token');
		$this->statusService = $this->statusService(new TestClock());
		$this->storage = new TestStorage();
		$this->folderInfo = $this->folderInfoOn($this->storage);
	}

	/** The folders of the target user, by the IDs that Nextcloud gives them, at their own paths. */
	public function testTheTargetUserSeesTheFoldersOfTheirFiles(): void {
		$this->foldersOf(self::TARGET);

		self::assertSame([
			['kind' => FolderInfo::ARCHIVE, 'id' => $this->storage->findFileId(self::TARGET, self::ARCHIVE), 'path' => self::ARCHIVE],
			['kind' => FolderInfo::INBOX, 'id' => $this->storage->findFileId(self::TARGET, self::INBOX), 'path' => self::INBOX],
			['kind' => FolderInfo::ERRORS, 'id' => $this->storage->findFileId(self::TARGET, self::ERRORS), 'path' => self::ERRORS],
		], $this->folderInfo->of(self::TARGET)['folders']);
	}

	public function testAFolderThatIsNotThereHasNoNote(): void {
		$this->storage->writeText(self::TARGET, self::INBOX . '/scan.pdf', '%PDF');

		self::assertSame([FolderInfo::INBOX], array_column($this->folderInfo->of(self::TARGET)['folders'], 'kind'));
	}

	/** The answer has the moments of the runs for the note, which each user reads as their own time. */
	public function testTheAnswerHasTheMomentsOfTheRuns(): void {
		$this->foldersOf(self::TARGET);
		$config = $this->configService->save(['enabled' => true] + self::validSettings());
		$this->statusService->completed(new SyncReport(false, 1000, 1060));

		$info = $this->folderInfo->of(self::TARGET);

		self::assertSame(1060, $info['lastSuccess']);
		self::assertSame(1060 + $config->syncIntervalMinutes * 60, $info['nextRun']);
	}

	public function testWithoutASuccessAndWithScheduledRunsOffTheNoteHasNoMoments(): void {
		$this->foldersOf(self::TARGET);
		$this->configService->save(['enabled' => false] + self::validSettings());

		$info = $this->folderInfo->of(self::TARGET);

		self::assertSame(0, $info['lastSuccess']);
		self::assertNull($info['nextRun']);
	}

	/**
	 * Another user who has a folder of the same name, and a user without any: neither finds a folder
	 * of the target user, by its ID or its path, or the moments of the runs.
	 */
	public function testAnotherUserGetsNoFolderOfTheTargetUserAndNoMoments(): void {
		$this->foldersOf(self::TARGET);
		$this->foldersOf('alice');
		$this->statusService->completed(new SyncReport(false, 1000, 1060));

		$nothing = ['folders' => [], 'lastSuccess' => 0, 'nextRun' => null, 'dismissed' => []];
		self::assertSame($nothing, $this->folderInfo->of('alice'), 'In single ownership, the folders of the same name of another user are theirs.');
		self::assertSame($nothing, $this->folderInfo->of('bob'));
	}

	/** A share of the archive of the target user, at the path where the user keeps it, and only that. */
	public function testASharedFolderIsThereAtThePathOfTheUser(): void {
		$this->foldersOf(self::TARGET);
		$id = $this->storage->shareFolder(self::ARCHIVE, 'alice', 'Shared/Our archive');

		self::assertSame([['kind' => FolderInfo::ARCHIVE, 'id' => $id, 'path' => 'Shared/Our archive']], $this->folderInfo->of('alice')['folders']);
		self::assertSame([], $this->folderInfo->of('bob')['folders'], 'A share with another user tells this one nothing.');
	}

	/** A share of the target user, and the folders of the user's own files, which the runs look after. */
	public function testPerUserOwnershipHasTheFoldersOfTheUserAndThoseSharedWithThem(): void {
		$this->configService->save(['ownership' => SyncConfig::PER_USER] + self::validSettings());
		$this->foldersOf(self::TARGET);
		$this->foldersOf('alice');
		$inbox = $this->storage->shareFolder(self::INBOX, 'alice', 'Household inbox');

		self::assertSame([
			['kind' => FolderInfo::ARCHIVE, 'id' => $this->storage->findFileId('alice', self::ARCHIVE), 'path' => self::ARCHIVE],
			['kind' => FolderInfo::INBOX, 'id' => $this->storage->findFileId('alice', self::INBOX), 'path' => self::INBOX],
			['kind' => FolderInfo::INBOX, 'id' => $inbox, 'path' => 'Household inbox'],
			['kind' => FolderInfo::ERRORS, 'id' => $this->storage->findFileId('alice', self::ERRORS), 'path' => self::ERRORS],
		], $this->folderInfo->of('alice')['folders']);
	}

	/** An archive that no run writes is no mirror, and an inbox that no run reads sends nothing. */
	public function testAModuleThatIsOffHasNoNoteAndTheFailedImportsStay(): void {
		$this->foldersOf(self::TARGET);
		$this->configService->save(['export_enabled' => false, 'inbox_enabled' => false] + self::validSettings());

		self::assertSame([FolderInfo::ERRORS], array_column($this->folderInfo->of(self::TARGET)['folders'], 'kind'));
	}

	/** The folders below a base folder and with the names of the settings. */
	public function testTheFoldersHaveTheNamesOfTheSettings(): void {
		$this->configService->save(['base_path' => 'Scans', 'archive_folder' => 'Mirror', 'inbox_folder' => 'In', 'error_folder' => 'Bad'] + self::validSettings());
		foreach (['Scans/Mirror', 'Scans/In', 'Scans/Bad'] as $folder) {
			$this->storage->writeText(self::TARGET, $folder . '/file.pdf', '%PDF');
		}

		self::assertSame(['Scans/Mirror', 'Scans/In', 'Scans/Bad'], array_column($this->folderInfo->of(self::TARGET)['folders'], 'path'));
	}

	public function testAnAppWithoutAConnectionHasNoNotes(): void {
		$this->foldersOf(self::TARGET);
		$this->settings['paperless_url'] = '';

		self::assertSame([], $this->folderInfo->of(self::TARGET)['folders']);
	}

	/** A hint must not break Files, such as for a target user who is gone or a storage that is down. */
	public function testAFailureOfTheLookupLeavesNoNoteAndAWarning(): void {
		$storage = $this->createStub(NextcloudStorageInterface::class);
		$storage->method('findFileId')->willThrowException(new RuntimeException('The storage is down.'));

		self::assertSame([], $this->folderInfoOn($storage)->of('alice')['folders']);
		self::assertCount(1, $this->warnings);
	}

	/** Each kind for each user on its own, and bringing one back leaves no setting behind. */
	public function testAUserHidesAKindAndBringsItBack(): void {
		$this->foldersOf(self::TARGET);
		$this->foldersOf('alice');
		$this->storage->shareFolder(self::ARCHIVE, 'alice', 'Shared archive');

		$this->folderInfo->dismiss(self::TARGET, FolderInfo::INBOX, true);
		$this->folderInfo->dismiss(self::TARGET, FolderInfo::ERRORS, true);
		self::assertSame([FolderInfo::INBOX, FolderInfo::ERRORS], $this->folderInfo->of(self::TARGET)['dismissed']);
		self::assertSame([], $this->folderInfo->of('alice')['dismissed'], 'Another user still sees the note.');

		$this->folderInfo->dismiss(self::TARGET, FolderInfo::INBOX, false);
		self::assertSame([FolderInfo::ERRORS], $this->folderInfo->of(self::TARGET)['dismissed']);
		self::assertSame(['folder_note_dismissed_errors'], array_keys($this->userSettings[self::TARGET]));
	}

	/** A folder with a file in it for each kind, among the files of the user. */
	private function foldersOf(string $user): void {
		foreach ([self::ARCHIVE, self::INBOX, self::ERRORS] as $folder) {
			$this->storage->writeText($user, $folder . '/file.pdf', '%PDF');
		}
	}

	private function folderInfoOn(NextcloudStorageInterface $storage): FolderInfo {
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueBool')->willReturnCallback(function (string $user, string $app, string $key, bool $default = false, bool $lazy = false): bool {
			self::assertSame(AppConstants::APP_ID, $app);
			self::assertTrue($lazy, 'Only this note reads the setting, so no other page of the user loads it.');

			return $this->userSettings[$user][$key] ?? $default;
		});
		$userConfig->method('setValueBool')->willReturnCallback(function (string $user, string $app, string $key, bool $value, bool $lazy = false): bool {
			self::assertSame(AppConstants::APP_ID, $app);
			self::assertTrue($lazy);
			$this->userSettings[$user][$key] = $value;

			return true;
		});
		$userConfig->method('deleteUserConfig')->willReturnCallback(function (string $user, string $app, string $key): void {
			self::assertSame(AppConstants::APP_ID, $app);
			unset($this->userSettings[$user][$key]);
		});
		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->warnings[] = (string)$message;
		});

		return new FolderInfo($this->configService, $this->statusService, $storage, $userConfig, $logger);
	}
}
