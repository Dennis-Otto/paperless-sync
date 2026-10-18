<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FileActions;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileActionsTest extends TestCase {
	use InMemoryConfiguration;

	/** The user who sends files in the tests, a member of the group of the setting. */
	private const SENDER = 'alice';

	private ConfigService $configService;
	private TestStorage $storage;
	private TestStateRepository $state;
	/** @var array<string, list<string>> the members of each group, by its ID */
	private array $members = ['admin' => ['e2e-admin'], 'scanners' => [self::SENDER, TestStorage::USER]];
	private FileActions $actions;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(['send_group' => 'scanners', 'batch_size' => 3] + self::validSettings(), 'secret-token');
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $userId, string $groupId): bool => in_array($userId, $this->members[$groupId] ?? [], true));
		$this->actions = new FileActions($this->configService, $groups, $this->storage, $this->state);
	}

	/** @return array<string, array{string, int|null}> */
	public static function names(): array {
		return [
			'a copy of the archive' => ['2026-03-01 - Invoice [P123].pdf', 123],
			'the last of two markers' => ['Copy of [P7] - Letter [P42].pdf', 42],
			'no marker' => ['scan.pdf', null],
			'a number without brackets' => ['P123 invoice.pdf', null],
			'no document has the ID 0' => ['Draft [P0].pdf', null],
			'a marker in lower case is none' => ['notes [p5].txt', null],
		];
	}

	#[DataProvider('names')]
	public function testTheMarkerOfANameNamesItsDocument(string $name, ?int $expected): void {
		self::assertSame($expected, FileActions::documentId($name));
	}

	public function testAMemberOfTheGroupMaySendAndEveryoneMayOpen(): void {
		self::assertSame(['send' => true, 'paperlessUrl' => 'https://paperless.example.test'], $this->actions->offers(self::SENDER));
		self::assertSame(['send' => false, 'paperlessUrl' => 'https://paperless.example.test'], $this->actions->offers('bob'));
	}

	public function testNobodyMaySendWithoutAGroup(): void {
		$this->configService->save(['send_group' => ''] + self::validSettings());

		self::assertFalse($this->actions->offers(self::SENDER)['send']);
	}

	/** Without the token, an upload can't go; the address of Paperless still opens a document. */
	public function testNobodyMaySendWhileTheAppIsNotConnected(): void {
		$this->configService->reset();

		self::assertSame(['send' => false, 'paperlessUrl' => 'https://paperless.example.test'], $this->actions->offers(self::SENDER));
	}

	public function testAFileIsQueuedOnceAndStaysWhereItIs(): void {
		$id = $this->file('Scans/receipt.pdf');

		self::assertSame(['refused' => '', 'name' => '', 'limit' => 3, 'queued' => 1, 'waiting' => 0, 'skipped' => 0], $this->send([$id]));
		self::assertSame('queued', $this->state->findSend(self::SENDER, $id)['status'] ?? null);
		self::assertSame('Scans/receipt.pdf', $this->state->findSend(self::SENDER, $id)['path'] ?? null);
		self::assertSame('', $this->state->findSend(self::SENDER, $id)['folder'] ?? null);

		self::assertSame(['refused' => '', 'name' => '', 'limit' => 3, 'queued' => 0, 'waiting' => 1, 'skipped' => 0], $this->send([$id, $id]));
		self::assertCount(1, $this->state->sends);
		self::assertArrayHasKey('Scans/receipt.pdf', $this->storage->filesOfOthers[self::SENDER]);
	}

	public function testAFileThatPaperlessHasStaysOut(): void {
		$copy = $this->file('Downloads/Invoice [P123].pdf');

		self::assertSame(1, $this->send([$copy])['skipped']);
		self::assertSame([], $this->state->sends);
	}

	public function testTheFilesBelowAFolderAreQueuedWithTheirSubfolders(): void {
		$this->file('Taxes/2026/receipt.pdf');
		$this->file('Taxes/summary.pdf');
		$this->file('Taxes/2026/Invoice [P9].pdf');
		$folder = $this->storage->folderId('Taxes', self::SENDER);

		self::assertSame(['refused' => '', 'name' => '', 'limit' => 3, 'queued' => 2, 'waiting' => 0, 'skipped' => 1], $this->send([$folder]));
		self::assertSame(
			[['Taxes/2026/receipt.pdf', '2026'], ['Taxes/summary.pdf', '']],
			array_map(static fn (array $send): array => [$send['path'], $send['folder']], array_values($this->state->sends)),
		);
	}

	/** @return array<string, array{list<int>}> */
	public static function unknownFiles(): array {
		return [
			'an ID that the user doesn\'t see' => [[999]],
			'one of several' => [[1, 999]],
		];
	}

	/** @param list<int> $ids */
	#[DataProvider('unknownFiles')]
	public function testAFileThatTheUserDoesNotSeeRefusesTheWholeClick(array $ids): void {
		self::assertSame(1, $this->file('Scans/receipt.pdf'));

		self::assertSame(FileActions::REFUSED_MISSING, $this->send($ids)['refused']);
		self::assertSame([], $this->state->sends);
	}

	public function testAFileThatTheUserMayNotReadIsRefused(): void {
		$id = $this->file('Shared/secret.pdf');
		$this->storage->unreadable[] = 'Shared/secret.pdf';

		self::assertSame(FileActions::REFUSED_MISSING, $this->send([$id])['refused']);
	}

	/** The runs look after the folder of the app: a file of the inbox goes with the next run, and a copy of the archive came from Paperless. */
	public function testAFileOfTheFolderOfTheAppIsRefused(): void {
		$this->storage->files['Documents/Paperless/Inbox/scan.pdf'] = 'Synthetic scan';
		$id = $this->storage->fileId('Documents/Paperless/Inbox/scan.pdf');

		$result = $this->actions->send($this->configService->get(), TestStorage::USER, [$id]);

		self::assertSame(['refused' => FileActions::REFUSED_APP_FOLDER, 'name' => 'scan.pdf', 'limit' => 3, 'queued' => 0, 'waiting' => 0, 'skipped' => 0], $result);
	}

	public function testTheFolderOfTheAppItselfIsRefused(): void {
		$folder = $this->storage->folderId('Documents/Paperless', TestStorage::USER);

		self::assertSame(FileActions::REFUSED_APP_FOLDER, $this->actions->send($this->configService->get(), TestStorage::USER, [$folder])['refused']);
	}

	/** A share shows the inbox of the target user to another user at another path; the file is the same. */
	public function testAFileOfTheFolderOfTheAppIsRefusedAlsoThroughAShare(): void {
		$this->storage->files['Documents/Paperless/Inbox/scan.pdf'] = 'Synthetic scan';
		$id = $this->storage->shareFile('Documents/Paperless/Inbox/scan.pdf', self::SENDER, 'Shared inbox/scan.pdf');

		self::assertSame(FileActions::REFUSED_APP_FOLDER, $this->send([$id])['refused']);
	}

	/** A folder named like the base folder but beside it is no part of it. */
	public function testAFolderBesideTheFolderOfTheAppIsNoPartOfIt(): void {
		$this->storage->files['Documents/Paperless old/scan.pdf'] = 'Synthetic scan';
		$id = $this->storage->fileId('Documents/Paperless old/scan.pdf');

		self::assertSame(1, $this->actions->send($this->configService->get(), TestStorage::USER, [$id])['queued']);
	}

	/** The target user sends a folder that holds the folder of the app: its files stay out, the others go. */
	public function testTheFilesOfTheFolderOfTheAppBelowAFolderStayOut(): void {
		$this->storage->files['Documents/Paperless/Inbox/scan.pdf'] = 'Synthetic scan';
		$this->storage->files['Documents/Letters/letter.pdf'] = 'Synthetic letter';
		$folder = $this->storage->folderId('Documents', TestStorage::USER);

		$result = $this->actions->send($this->configService->get(), TestStorage::USER, [$folder]);

		self::assertSame([1, 1], [$result['queued'], $result['skipped']]);
		self::assertSame(['Documents/Letters/letter.pdf'], array_column(array_values($this->state->sends), 'path'));
	}

	/** A file of another user that the target user can't see is no file of the app. */
	public function testAFileThatTheTargetUserDoesNotSeeIsNoFileOfTheApp(): void {
		$id = $this->file('Documents/Paperless/Inbox/scan.pdf');

		self::assertSame(1, $this->send([$id])['queued']);
	}

	public function testAFolderWithMoreFilesThanABatchIsRefusedByItsName(): void {
		foreach (['a', 'b', 'c', 'd'] as $name) {
			$this->file("Scans/{$name}.pdf");
		}
		$folder = $this->storage->folderId('Scans', self::SENDER);

		self::assertSame(['refused' => FileActions::REFUSED_TOO_MANY, 'name' => 'Scans', 'limit' => 3, 'queued' => 0, 'waiting' => 0, 'skipped' => 0], $this->send([$folder]));
		self::assertSame([], $this->state->sends);
	}

	public function testMoreFilesThanABatchAreRefused(): void {
		$ids = array_map(fn (string $name): int => $this->file("Scans/{$name}.pdf"), ['a', 'b', 'c', 'd']);

		self::assertSame(['refused' => FileActions::REFUSED_TOO_MANY, 'name' => '', 'limit' => 3, 'queued' => 0, 'waiting' => 0, 'skipped' => 0], $this->send($ids));
	}

	/** A file in a sent folder and sent by itself as well goes once, with the folder of the first. */
	public function testAFileOfAFolderAndOfTheSelectionIsQueuedOnce(): void {
		$file = $this->file('Taxes/2026/receipt.pdf');
		$folder = $this->storage->folderId('Taxes', self::SENDER);

		self::assertSame(1, $this->send([$file, $folder])['queued']);
		self::assertSame('', $this->state->findSend(self::SENDER, $file)['folder'] ?? null);
	}

	/** @param list<int> $ids */
	private function send(array $ids): array {
		return $this->actions->send($this->configService->get(), self::SENDER, $ids);
	}

	/** A file of the sender, and its ID. */
	private function file(string $path): int {
		$this->storage->filesOfOthers[self::SENDER][$path] = 'Synthetic content of ' . $path;

		return $this->storage->fileId($path, self::SENDER);
	}
}
