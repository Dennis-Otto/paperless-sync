<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\ImportExecutor;
use OCA\PaperlessSync\Service\PaperlessOwnerException;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SendService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestActivityEvent;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\Config\IUserConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Per-user ownership: each user of Nextcloud gets the documents that their user of Paperless owns,
 * in their own archive below the same base folder, and the target user the others. The users of
 * Paperless are alice, bob, dave, whom Nextcloud doesn't know, and the account of the app; the
 * users of Nextcloud are the target user paperless, alice and bob.
 */
#[AllowMockObjectsWithoutExpectations]
final class PerUserOwnershipTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	private const ARCHIVE = 'Dokumente/Paperless/Archiv';
	private const INBOX = 'Dokumente/Paperless/Eingang';
	private const ALICE = 1;
	private const BOB = 2;
	private const DAVE = 3;
	private const ACCOUNT = 9;
	/** The user of Paperless of the user of Nextcloud whose ID is digits. */
	private const NUMERIC = 4;

	private TestClock $clock;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	private StatusService $status;
	private SyncService $service;

	protected function setUp(): void {
		$this->defaultLanguage = 'de';
		$this->usersOfNextcloud = ['paperless', 'alice', 'bob'];
		$this->clock = new TestClock();
		$this->paperless = new TestPaperlessClient();
		$this->paperless->users = [(string)self::ALICE => 'alice', (string)self::BOB => 'Bob', (string)self::DAVE => 'dave', (string)self::ACCOUNT => 'paperless-sync'];
		$this->paperless->accountId = self::ACCOUNT;
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$this->configService = $this->configService($paths);
		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER], 'test-token');
		$logger = $this->createStub(LoggerInterface::class);
		$this->status = $this->statusService($this->clock);
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('');
		$this->service = new SyncService(
			$this->configService,
			$this->paperless,
			$this->storage,
			$this->state,
			$paths,
			$this->status,
			$this->createStub(ILockingProvider::class),
			$logger,
			$this->clock,
			$this->activityPublisher($this->storage, $logger),
			new UserTimeZones($userConfig, new TestTimeZones()),
			$this->notificationPublisher($this->status, $this->storage, $this->clock, $logger),
			$this->systemTagService($this->storage),
			$this->ownerMapping(),
		);
	}

	/**
	 * Each user gets the documents of their user of Paperless, Bob as bob whatever the case; the
	 * target user those without an owner, those of dave, whom Nextcloud doesn't know, and those
	 * of the account of the app. Each user's activity names their own files alone.
	 */
	public function testEachUserGetsTheDocumentsThatTheyOwn(): void {
		$report = $this->sync([
			$this->document(1, self::ALICE),
			$this->document(2, self::BOB),
			$this->document(3, self::DAVE),
			$this->document(4, null),
			$this->document(5, self::ACCOUNT),
		]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([$this->path(2)], array_keys($this->filesOf('bob')));
		self::assertSame([$this->path(5), $this->path(4), $this->path(3)], array_keys($this->storage->files));
		self::assertSame(5, $report->exported);
		self::assertSame(['paperless', 'alice', 'bob'], array_keys($report->users));
		self::assertSame([3, 1, 1], array_column($report->users, 'exported'));
		self::assertSame([
			'[paperless] EXPORT P5: ' . $this->path(5),
			'[paperless] EXPORT P4: ' . $this->path(4),
			'[paperless] EXPORT P3: ' . $this->path(3),
			'[alice] EXPORT P1: ' . $this->path(1),
			'[bob] EXPORT P2: ' . $this->path(2),
		], $report->actions);
		self::assertSame(['paperless', 'paperless', 'paperless', 'alice', 'bob'], array_column($report->entries, 'user'));
		self::assertSame(['paperless' => 3, 'alice' => 1, 'bob' => 1], $this->changedFilesOfTheActivities());
		self::assertSame([[5, 4, 3], [1], [2]], [array_keys($this->state->exports), array_keys($this->state->exportsOfOthers['alice']), array_keys($this->state->exportsOfOthers['bob'])], 'Each user keeps the states of their own copies.');
		self::assertSame(['alice', 'bob'], array_values(array_unique(array_map(static fn (array $call): string => $call[1], array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'prepare' && $call[1] !== 'paperless')))), 'The run prepares the folders of each user.');
		self::assertSame(['users'], array_values(array_unique($this->paperless->listsRead)), 'The run reads the users of Paperless once.');
	}

	/** The batch size is that of each archive, so that the backlog of one user never holds up another. */
	public function testEachArchiveHasABatchOfItsOwn(): void {
		$this->configService->save(['batch_size' => 1] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);

		$report = $this->sync([$this->document(1, self::ALICE), $this->document(2, self::ALICE), $this->document(3, self::BOB)]);

		self::assertSame([1, 1], [$report->users['alice']['exported'], $report->users['bob']['exported']]);
		self::assertSame([$this->path(2)], array_keys($this->filesOf('alice')), 'The newest document of alice first.');
	}

	/** A document whose owner changes in Paperless goes to the new owner, and its copy leaves the archive of the old one. */
	public function testADocumentWhoseOwnerChangesMovesToTheArchiveOfTheNewOwner(): void {
		$this->sync([$this->document(1, self::ALICE)]);
		$fileOfAlice = $this->storage->fileId($this->path(1), 'alice');

		$report = $this->sync([$this->document(1, self::BOB)]);

		self::assertSame([], $this->filesOf('alice'));
		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
		self::assertSame([
			'[alice] REMOVE REASSIGNED P1: ' . $this->path(1),
			'[bob] EXPORT P1: ' . $this->path(1),
		], $report->actions);
		self::assertSame(['type' => SyncReport::TYPE_REMOVE_REASSIGNED, 'document' => 1, 'path' => $this->path(1), 'user' => 'alice'], $report->entries[0]);
		self::assertSame([1, 1], [$report->users['alice']['removedReassigned'], $report->users['bob']['exported']]);
		self::assertArrayNotHasKey(1, $this->state->exportsOfOthers['alice'], 'The state of the copy of alice is gone with it.');
		self::assertGreaterThan(0, $fileOfAlice);
	}

	/** The copy of a document whose owner Paperless didn't list stays where it is, and counts as no missing document. */
	public function testADocumentOfAnOwnerThatPaperlessDidNotListStaysWhereItIs(): void {
		$this->sync([$this->document(1, self::ALICE)]);

		$report = $this->sync([$this->document(1, 42)]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([], $this->storage->files);
		self::assertSame([], $report->actions);
		self::assertSame(0, (int)$this->state->exportsOfOthers['alice'][1]['missing_runs']);
	}

	/** A document without the field owner goes to nobody: the run can't tell whose it is. */
	public function testADocumentWithoutTheFieldOwnerGoesToNobody(): void {
		$document = $this->document(1, null);
		unset($document['owner']);

		$report = $this->sync([$document]);

		self::assertSame([0, []], [$report->exported, $this->storage->files]);
	}

	/**
	 * An archive that Nextcloud can't reach is an error of the run, and the documents that it would
	 * get stay where their copies are; the other archives go on.
	 */
	public function testTheDocumentsOfAnArchiveThatNextcloudCantReachWait(): void {
		$this->sync([$this->document(1, self::ALICE), $this->document(2, self::ALICE)]);
		$this->storage->testFailures['bob'] = new RuntimeException('The configured Nextcloud base folder is not a writable folder.');

		$report = $this->sync([$this->document(1, self::BOB), $this->document(2, self::ALICE), $this->document(3, null)]);

		self::assertSame([$this->path(2), $this->path(1)], array_keys($this->filesOf('alice')), 'The copy of the document of bob stays with alice until bob can take it.');
		self::assertSame([], $this->filesOf('bob'));
		self::assertSame([$this->path(3)], array_keys($this->storage->files));
		self::assertSame(['[bob] Archive of bob: The configured Nextcloud base folder is not a writable folder. Its documents wait until Nextcloud can store them.'], $report->errorMessages);
		self::assertSame('completed-with-errors', $this->status->get()['state']);
	}

	/**
	 * Turning per-user ownership on gives each user their documents, whose copies leave the archive
	 * of the target user, who had them all.
	 */
	public function testPerUserOwnershipTakesOverTheArchiveOfTheTargetUser(): void {
		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::SINGLE]);
		$single = $this->sync([$this->document(1, self::ALICE), $this->document(2, null)]);
		self::assertSame([$this->path(2), $this->path(1)], array_keys($this->storage->files));
		self::assertArrayNotHasKey('users', $single->jsonSerialize(), 'Single ownership counts no users.');
		self::assertSame([], $this->state->exportsOfOthers);

		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$report = $this->sync([$this->document(1, self::ALICE), $this->document(2, null)]);

		self::assertSame([$this->path(2)], array_keys($this->storage->files));
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame(['[paperless] REMOVE REASSIGNED P1: ' . $this->path(1), '[alice] EXPORT P1: ' . $this->path(1)], $report->actions);
	}

	/** A user who gets no documents now, such as after they left the group of the setting, loses the copies of their archive. */
	public function testAUserWhoLeavesTheGroupLosesTheirCopies(): void {
		$this->membersOfGroups = ['scanners' => ['alice', 'bob']];
		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER, 'user_group' => 'scanners']);
		$this->sync([$this->document(2, self::BOB)]);
		self::assertSame([$this->path(2)], array_keys($this->filesOf('bob')));

		$this->membersOfGroups = ['scanners' => ['alice']];
		$this->storage->calls = [];
		$report = $this->sync([$this->document(2, self::BOB)]);

		self::assertSame([], $this->filesOf('bob'));
		self::assertSame([$this->path(2)], array_keys($this->storage->files));
		self::assertSame(['[paperless] EXPORT P2: ' . $this->path(2), '[bob] REMOVE REASSIGNED P2: ' . $this->path(2)], $report->actions);
		self::assertSame([], array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'prepare' && $call[1] === 'bob'), 'A user who gets no documents gets no new folders.');
	}

	/**
	 * A user who gets no documents now, such as after they left the group of the setting, keeps the
	 * files of their inbox: the run reaches their archive for the copies that leave it, not their
	 * inbox. It tests the folder of each user once, also of one who gets documents and has copies.
	 */
	public function testTheInboxOfAUserWhoGetsNoDocumentsStaysAsItIs(): void {
		$this->membersOfGroups = ['scanners' => ['alice', 'bob']];
		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER, 'user_group' => 'scanners']);
		$this->sync([$this->document(1, self::ALICE), $this->document(2, self::BOB)]);
		$this->membersOfGroups = ['scanners' => ['bob']];
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->storage->calls = [];

		$report = $this->sync([$this->document(1, self::ALICE), $this->document(2, self::BOB)]);

		self::assertSame([], $this->paperless->uploads);
		self::assertArrayHasKey(self::INBOX . '/scan.pdf', $this->filesOf('alice'));
		self::assertSame(['[paperless] EXPORT P1: ' . $this->path(1), '[alice] REMOVE REASSIGNED P1: ' . $this->path(1)], $report->actions);
		self::assertSame(['paperless', 'bob', 'alice'], array_column(array_values(array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'test')), 1));
	}

	/** The states of a user whom Nextcloud no longer has stay as they are: the files went with the user. */
	public function testTheStatesOfAUserWhoIsGoneStayAsTheyAre(): void {
		$this->state->exportsOfOthers['ghost'] = [1 => ['document_id' => 1, 'path' => $this->path(1), 'fingerprint' => 'x', 'state' => 'active']];

		$report = $this->sync([$this->document(1, self::ALICE)]);

		self::assertSame([], array_filter($this->storage->calls, static fn (array $call): bool => $call[1] === 'ghost'));
		self::assertArrayNotHasKey('ghost', $report->users);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
	}

	/** The files of the inbox of each user go to Paperless, and their documents become those of the user of Paperless of the user. */
	public function testTheInboxOfEachUserGivesItsDocumentsTheirOwner(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->storage->files[self::INBOX . '/brief.pdf'] = '%PDF-target';

		$first = $this->sync([]);

		self::assertSame([1, 1], [$first->users['alice']['importsSubmitted'], $first->users['paperless']['importsSubmitted']]);
		self::assertSame([], $this->paperless->ownerChanges);
		// The target user uploads first, then alice.
		self::assertSame(['brief.pdf', 'scan.pdf'], array_keys($this->paperless->uploads));
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 78];
		$this->paperless->tasks['task-2'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];

		$second = $this->sync([]);

		self::assertSame([[77, self::ALICE]], $this->paperless->ownerChanges, 'The document of the target user, who has no user of Paperless, keeps the account of the app as its owner.');
		self::assertSame(2, $second->importsSucceeded);
		self::assertSame([], $this->filesOf('alice'));
	}

	/**
	 * A user of Nextcloud whose ID is digits, which Nextcloud allows and LDAP often gives, gets the
	 * documents of their user of Paperless, with a report of their own, and the files of their inbox
	 * get that user as their owner, as for any other ID.
	 */
	public function testAUserWhoseIdIsDigitsGetsTheirDocumentsAndTheirInbox(): void {
		$this->usersOfNextcloud[] = '1001';
		$this->paperless->users[(string)self::NUMERIC] = '1001';
		$this->storage->filesOfOthers['1001'][self::INBOX . '/scan.pdf'] = '%PDF-1001';

		$first = $this->sync([$this->document(1, self::NUMERIC), $this->document(2, self::ALICE)]);

		self::assertSame([], $first->errorMessages);
		self::assertSame([self::INBOX . '/scan.pdf', $this->path(1)], array_keys($this->filesOf('1001')), 'The upload stays in the inbox until Paperless has its document.');
		self::assertSame([$this->path(2)], array_keys($this->filesOf('alice')));
		self::assertSame([1, 1], [$first->users['1001']['exported'], $first->users['1001']['importsSubmitted']]);
		self::assertContains('[1001] EXPORT P1: ' . $this->path(1), $first->actions);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];

		$second = $this->sync([$this->document(1, self::NUMERIC), $this->document(2, self::ALICE)]);

		self::assertSame([], $second->errorMessages);
		self::assertSame([[77, self::NUMERIC]], $this->paperless->ownerChanges);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('1001')), 'The next run leaves their copy where it is.');
	}

	/** A target user whose ID is digits gets the documents without an owner, and each other user theirs. */
	public function testATargetUserWhoseIdIsDigitsGetsTheDocumentsWithoutAnOwner(): void {
		$this->usersOfNextcloud[] = '1000';
		$this->configService->save(['target_user' => '1000'] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);

		$report = $this->sync([$this->document(1, self::ALICE), $this->document(2, null)]);

		self::assertSame([], $report->errorMessages);
		self::assertSame([$this->path(2)], array_keys($this->filesOf('1000')));
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([1, 1], [$report->users['1000']['exported'], $report->users['alice']['exported']]);
	}

	/** While Paperless refuses the owner, the upload stays pending, and the next run gives the document its owner. */
	public function testAnOwnerThatPaperlessRefusesWaitsForTheNextRun(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->paperless->ownerException = new RuntimeException('Paperless did not make the user 1 the owner of the document 77: HTTP 403.');

		$refused = $this->sync([]);

		self::assertSame(['[alice] Import task ' . self::INBOX . '/scan.pdf: Paperless did not make the user 1 the owner of the document 77: HTTP 403.'], $refused->errorMessages);
		self::assertArrayHasKey(self::INBOX . '/scan.pdf', $this->filesOf('alice'), 'The file waits for its owner.');
		$this->paperless->ownerException = null;

		$this->sync([]);

		self::assertSame([[77, self::ALICE], [77, self::ALICE]], $this->paperless->ownerChanges);
		self::assertSame([], $this->filesOf('alice'));
	}

	/**
	 * Paperless makes the account of the app the owner of every upload until the app gives the
	 * document its owner: while Paperless refuses, the document of alice's upload stays out of the
	 * archive of the target user, and of every other, and goes to alice once it is hers.
	 */
	public function testTheDocumentOfAnUploadWaitsForItsOwner(): void {
		$this->sync([$this->document(5, self::ACCOUNT)]);
		self::assertSame([$this->path(5)], array_keys($this->storage->files), 'Without a waiting upload, a document of the account goes to the target user.');
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([$this->document(5, self::ACCOUNT)]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->paperless->ownerException = new RuntimeException('Paperless did not make the user 1 the owner of the document 77: HTTP 403.');

		$refused = $this->sync([$this->document(5, self::ACCOUNT), $this->document(77, self::ACCOUNT)]);

		self::assertSame([$this->path(5)], array_keys($this->storage->files), 'The document of the upload of alice never reaches the target user.');
		self::assertSame([], array_filter(array_keys($this->filesOf('alice')), static fn (string $path): bool => str_starts_with($path, self::ARCHIVE)));
		self::assertSame(0, $refused->exported);
		self::assertSame([], $this->archiveDeletions());
		$this->paperless->ownerException = null;

		// The run gives the document its owner first, then exports it.
		$report = $this->sync([$this->document(5, self::ACCOUNT), $this->document(77, self::ACCOUNT)]);

		self::assertSame([$this->path(77)], array_keys($this->filesOf('alice')));
		self::assertSame([$this->path(5)], array_keys($this->storage->files));
		self::assertContains('[alice] EXPORT P77: ' . $this->path(77), $report->actions);
	}

	/** The webhook calls for a new document of an upload before the app has given it its owner: the call leaves it to a later run. */
	public function testTheWebhookLeavesTheDocumentOfAWaitingUploadAlone(): void {
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->paperless->documents = [$this->document(77, self::ACCOUNT)];
		$this->paperless->contents[77] = '%PDF-content';

		$report = $this->service->syncDocument(77);

		self::assertSame([[], [], []], [$report->actions, $this->storage->files, $this->archiveDeletions()]);
	}

	/** A file that alice sent to Paperless waits for its owner in the same way. */
	public function testTheDocumentOfASentFileWaitsForItsOwner(): void {
		$this->state->saveSend('alice', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);

		$report = $this->sync([$this->document(77, self::ACCOUNT), $this->document(2, self::BOB)]);

		self::assertSame(['[bob] EXPORT P2: ' . $this->path(2)], $report->actions);
	}

	/** Paperless may give an owner as a string of digits: the document of the account waits for its owner all the same. */
	public function testADocumentOfTheAccountWithItsOwnerAsDigitsWaitsForItsOwner(): void {
		$this->state->saveSend('alice', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);

		$report = $this->sync([['owner' => (string)self::ACCOUNT] + $this->document(77, null), $this->document(2, self::BOB)]);

		self::assertSame(['[bob] EXPORT P2: ' . $this->path(2)], $report->actions);
	}

	/** The upload of the user of Nextcloud whose user of Paperless is the account keeps the account as the owner, so it holds nothing. */
	public function testAnUploadOfTheUserOfTheAccountHoldsNothing(): void {
		$this->usersOfNextcloud[] = 'paperless-sync';
		$this->state->saveSend('paperless-sync', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame(['[paperless-sync] EXPORT P5: ' . $this->path(5)], $report->actions);
	}

	/** An upload that keeps the account as the owner, such as one of the target user without a user of Paperless, holds nothing. */
	public function testAnUploadWithoutAnotherOwnerHoldsNothing(): void {
		$this->state->saveSend('paperless', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);
		$this->state->saveSend('alice', 13, ['path' => 'Scans/letter.pdf', 'status' => 'imported', 'task_id' => 'task-8']);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame(['[paperless] EXPORT P5: ' . $this->path(5)], $report->actions);
		self::assertSame(0, $this->paperless->accountReads, 'Without a waiting upload, the run does not ask for the account.');
	}

	/** Without the ID of the account, the run can't tell the document of a waiting upload, so the export waits. */
	public function testTheExportWaitsWhilePaperlessDoesNotNameItsAccount(): void {
		$this->paperless->accountId = null;
		$this->state->saveSend('alice', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);

		$report = $this->sync([$this->document(77, self::ACCOUNT), $this->document(2, self::BOB)]);

		self::assertSame([0, []], [$report->exported, $this->filesOf('bob')]);
		self::assertSame(['Paperless did not name its account, which per-user ownership needs while an upload waits for its owner, so the export waits until it has it: in Paperless, under Users & Groups, give the account the permission View for UI Settings.'], $report->errorMessages);
	}

	/** Two users of Paperless for one user of Nextcloud: the run does not guess whose they are, and their copies stay where they are. */
	public function testTheDocumentsOfTwoUsersOfPaperlessForOneUserOfNextcloudStayWhereTheyAre(): void {
		$this->sync([$this->document(1, self::ALICE)]);
		$this->paperless->users['6'] = 'Alice';

		$report = $this->sync([$this->document(1, self::ALICE), $this->document(6, 6)]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([[], []], [$report->actions, $this->storage->files]);
	}

	/** A failed import is news for the user whose inbox held the file, not for the target user. */
	public function testAUserHearsOfTheFailedImportsOfTheirInbox(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'not a PDF'];

		$this->sync([]);

		self::assertSame(['alice'], $this->notifiedUsers(NotificationPublisher::SUBJECT_IMPORT_FAILED));
	}

	/** Without the users of Paperless, a run can't tell whose a document is, so it fails before a copy changes. */
	public function testARunFailsWithoutTheUsersOfPaperless(): void {
		$this->paperless->usersException = new PaperlessRequestException('HTTP 403');

		try {
			$this->sync([$this->document(1, self::ALICE)]);
			self::fail('The run needs the users of Paperless.');
		} catch (RuntimeException $exception) {
			self::assertStringStartsWith('Paperless did not list its users, which per-user ownership needs', $exception->getMessage());
		}
		self::assertSame([], $this->storage->files);
	}

	/** A document without a valid ID fails the run, as it does in single ownership. */
	public function testADocumentWithoutAValidIdFailsTheRun(): void {
		$this->expectExceptionMessage('Paperless returned a document without a valid ID.');

		$this->sync([['id' => 'x', 'owner' => null] + $this->document(1, null)]);
	}

	/** @return array<string, array{mixed}> */
	public static function invalidIds(): array {
		return [
			'no number' => ['x'],
			'zero' => [0],
		];
	}

	/** A document without a valid ID fails the export of the target user, whoever owns it, before a copy changes. */
	#[DataProvider('invalidIds')]
	public function testADocumentWithoutAValidIdFailsTheExportOfTheTargetUser(mixed $id): void {
		try {
			$this->sync([['id' => $id] + $this->document(1, self::ALICE), $this->document(4, null)]);
			self::fail('A document without a valid ID fails the run.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless returned a document without a valid ID.', $exception->getMessage());
		}

		self::assertSame([[], []], [$this->storage->files, $this->filesOf('alice')]);
	}

	/** A run that fails after the inboxes still shows each user what changed for them. */
	public function testARunThatFailsAfterTheInboxesShowsEachUserTheirUploads(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';

		try {
			$this->sync([['id' => 'x'] + $this->document(1, null)]);
			self::fail('A document without a valid ID fails the run.');
		} catch (RuntimeException) {
		}

		self::assertSame(['scan.pdf'], array_keys($this->paperless->uploads));
		self::assertSame(['alice'], array_map(static fn (TestActivityEvent $event): string => $event->getAffectedUser(), $this->activities));
	}

	/**
	 * A dry-run says so when documents go missing while the trash of Paperless lists none, as in
	 * single ownership, with the missing documents of every archive.
	 */
	public function testADryRunCountsTheMissingDocumentsOfEveryArchive(): void {
		$this->sync([$this->document(1, self::ALICE), $this->document(2, self::BOB), $this->document(3, null)]);
		$this->paperless->documents = [$this->document(3, null)];

		$report = $this->service->run(true);

		self::assertSame(['2'], array_map(
			static fn (string $action): string => (string)preg_replace('/^TRASH EMPTY: documents of the archive are gone from Paperless \((\d+)\).*$/s', '$1', $action),
			array_values(array_filter($report->actions, static fn (string $action): bool => str_starts_with($action, 'TRASH EMPTY:'))),
		));
	}

	/** A dry-run shows the changes of each user and changes nothing, nor prepares the folders of the users. */
	public function testADryRunShowsTheChangesOfEachUser(): void {
		$this->paperless->documents = [$this->document(1, self::ALICE)];
		$this->paperless->contents[1] = '%PDF-content';

		$report = $this->service->run(true);

		self::assertSame(['[alice] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame([], $this->filesOf('alice'));
		self::assertSame([], array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'prepare'));
		self::assertSame([], $this->activities);
	}

	/** The inbox alone, with the export off: the uploads of each user, and no read of the documents. */
	public function testTheInboxRunsWithoutTheExport(): void {
		$this->configService->save(['export_enabled' => false] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->paperless->documents = [$this->document(1, self::ALICE)];

		$report = $this->service->run();

		self::assertSame([1, 0], [$report->importsSubmitted, $report->exported]);
	}

	/** The export alone, with the inbox off: the files of the inboxes stay where they are. */
	public function testTheExportRunsWithoutTheInbox(): void {
		$this->configService->save(['inbox_enabled' => false] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';

		$report = $this->sync([$this->document(1, self::ALICE)]);

		self::assertSame([0, 1], [$report->importsSubmitted, $report->exported]);
		self::assertSame([], $this->paperless->uploads);
	}

	/** The run of one document that the webhook asks for reaches the archives that get it or have a copy of it, and no other. */
	public function testTheRunOfOneDocumentReachesTheArchivesThatGetItOrHaveACopy(): void {
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->sync([$this->document(1, self::ALICE), $this->document(2, null)]);
		$this->paperless->documents = [$this->document(1, self::BOB), $this->document(2, null)];
		$this->storage->calls = [];
		$this->activities = [];

		$report = $this->service->syncDocument(1);

		self::assertSame(['[alice] REMOVE REASSIGNED P1: ' . $this->path(1), '[bob] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame(['alice', 'bob'], array_values(array_unique(array_map(static fn (array $call): string => $call[1], array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'test' && $call[1] !== 'paperless')))));
		self::assertSame(['alice', 'bob'], array_map(static fn (TestActivityEvent $event): string => $event->getAffectedUser(), $this->activities), 'The target user, whose archive the run does not reach, gets no entry.');
	}

	/** Paperless may give the ID of a document as a string of digits: the run of that document reaches the same archives. */
	public function testTheRunOfADocumentWithItsIdAsDigitsReachesTheArchivesThatGetItOrHaveACopy(): void {
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->sync([$this->document(1, self::ALICE)]);
		$this->paperless->documents = [['id' => '1'] + $this->document(1, self::BOB)];

		$report = $this->service->syncDocument(1);

		self::assertSame(['[alice] REMOVE REASSIGNED P1: ' . $this->path(1), '[bob] EXPORT P1: ' . $this->path(1)], $report->actions);
	}

	/** The webhook names a document that Paperless doesn't show the account: no archive changes. */
	public function testTheRunOfADocumentThatPaperlessDoesNotShowChangesNothing(): void {
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->sync([$this->document(1, self::ALICE)]);

		$report = $this->service->syncDocument(99);

		self::assertSame([], $report->actions);
	}

	/** "Forget" asks every archive of per-user ownership to write the copies anew. */
	public function testForgettingReachesTheCopiesOfEveryUser(): void {
		$this->sync([$this->document(1, self::ALICE), $this->document(2, null), $this->document(3, self::BOB)]);

		self::assertSame([2, 1, 3], $this->service->forget());
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exportsOfOthers['alice'][1]['fingerprint']);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exportsOfOthers['bob'][3]['fingerprint']);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[2]['fingerprint']);
	}

	/**
	 * A file in alice's inbox with the content of a document of the target user, which the account
	 * of the app owns, leaves that document as it is: it keeps its owner, its copy stays in the
	 * archive of the target user, and alice's file goes up as a document of her own.
	 */
	public function testAFileWithTheContentOfADocumentOfTheTargetUserLeavesItsOwnerAsItIs(): void {
		$this->sync([$this->document(5, self::ACCOUNT)]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/same.pdf'] = '%PDF-content';
		$this->paperless->originals[hash('sha256', '%PDF-content')] = 5;

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame(['same.pdf'], array_keys($this->paperless->uploads));
		self::assertSame([$this->path(5)], array_keys($this->storage->files), 'The copy stays with the target user.');
		self::assertSame([self::INBOX . '/same.pdf'], array_keys($this->filesOf('alice')), 'The file stays until Paperless has imported it.');
		self::assertSame([], $this->archiveDeletions());
		self::assertSame([1, 0], [$report->users['alice']['importsSubmitted'], $report->users['alice']['importsSucceeded']]);
	}

	/**
	 * An upload of alice whose answer never came, which Paperless imported all the same, ends as
	 * imported in the next run without a second upload, and its document, which the account of
	 * the app owns, becomes hers.
	 */
	public function testAnUploadWhoseAnswerNeverCameStillEndsAsImported(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->paperless->uploadFailures['scan.pdf'] = new RuntimeException('Paperless did not answer in time');
		$this->sync([]);
		$this->paperless->uploadFailures = [];
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->clock->advance(900);

		$report = $this->sync([['added' => '2026-08-28T12:05:00+02:00'] + $this->document(77, self::ACCOUNT)]);

		self::assertSame([[77, self::ALICE]], $this->paperless->ownerChanges);
		self::assertSame([], $this->paperless->uploads);
		self::assertSame([$this->path(77)], array_keys($this->filesOf('alice')), 'The file left the inbox, and the copy of its document is hers.');
		self::assertSame(1, $report->users['alice']['importsSucceeded']);
	}

	/**
	 * A document of the account of the app counts as alice's own upload only when its ID is higher
	 * than the one that the record of her upload keeps: without an upload of her file, or with a
	 * document that Paperless had before it, her file goes up, and the document keeps its owner.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function documentsOfTheAccountThatAreNoUploadOfTheFile(): array {
		return [
			'a file without an earlier upload' => [false],
			'a document that Paperless had before the first upload' => [true],
		];
	}

	#[DataProvider('documentsOfTheAccountThatAreNoUploadOfTheFile')]
	public function testAFileWithTheContentOfADocumentOfTheAccountGoesUpWithoutProofOfItsUpload(bool $failedBefore): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		if ($failedBefore) {
			$this->paperless->uploadFailures['scan.pdf'] = new RuntimeException('Paperless did not answer in time');
			$this->sync([]);
			$this->paperless->uploadFailures = [];
			$this->paperless->uploadAttempts = [];
			$this->clock->advance(900);
		}

		$this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame(['scan.pdf'], array_keys($this->paperless->uploads));
		self::assertArrayHasKey(self::INBOX . '/scan.pdf', $this->filesOf('alice'));
	}

	/**
	 * While an upload of bob waits for its owner, a document of the account may be his: alice's
	 * file with its content waits, neither uploaded nor deleted.
	 */
	public function testAFileWithTheContentOfAHeldDocumentWaits(): void {
		$this->state->saveSend('bob', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/same.pdf'] = '%PDF-content';
		$this->paperless->originals[hash('sha256', '%PDF-content')] = 5;

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame([[], []], [$this->paperless->uploadAttempts, $this->paperless->ownerChanges]);
		self::assertSame([self::INBOX . '/same.pdf'], array_keys($this->filesOf('alice')));
		self::assertSame('retry', $this->state->importsOfOthers['alice'][self::INBOX . '/same.pdf']['status']);
		self::assertSame(0, $report->users['alice']['importsSucceeded']);
	}

	/** An upload of alice in a run holds the documents of the account for the inboxes after hers in the same run. */
	public function testAnUploadOfTheRunHoldsTheDocumentsOfTheAccountForTheInboxesAfterIt(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->storage->filesOfOthers['bob'][self::INBOX . '/same.pdf'] = '%PDF-content';
		$this->paperless->originals[hash('sha256', '%PDF-content')] = 5;

		$this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame(['scan.pdf'], $this->paperless->uploadAttempts);
		self::assertSame('retry', $this->state->importsOfOthers['bob'][self::INBOX . '/same.pdf']['status']);
		self::assertSame([self::INBOX . '/same.pdf'], array_keys($this->filesOf('bob')));
	}

	/**
	 * A task that Paperless still lists a day after the upload, and one that its queue lost.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function listedTasks(): array {
		return ['a task that Paperless lists' => [true], 'a task that Paperless lost' => [false]];
	}

	/**
	 * Paperless has not finished alice's upload a day later, and bob has a document with its
	 * content: the upload doesn't end on bob's document. While Paperless lists the task, the file
	 * goes to the error folder, as Paperless may still import it; otherwise it goes up again. No
	 * owner changes, and alice's activity names no document of bob.
	 */
	#[DataProvider('listedTasks')]
	public function testAnUnfinishedUploadDoesNotEndOnADocumentOfAnotherUser(bool $listed): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->clock->advance(86400);
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 2;
		if (!$listed) {
			$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false];
		}

		$report = $this->sync([$this->document(2, self::BOB)]);

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame([], array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'delete' && $call[1] === 'alice'), 'Nothing of alice is deleted.');
		self::assertSame(0, $report->users['alice']['importsSucceeded']);
		self::assertSame([], array_filter($this->activities, static fn (TestActivityEvent $event): bool => str_contains(json_encode($event->getSubjectParameters(), JSON_THROW_ON_ERROR), '"document":2')), 'No activity names the document of bob.');
		$import = $this->state->importsOfOthers['alice'][self::INBOX . '/scan.pdf'] ?? null;
		if ($listed) {
			self::assertNull($import);
			self::assertSame(1, $report->users['alice']['importsFailed']);
			self::assertNotContains(self::INBOX . '/scan.pdf', array_keys($this->filesOf('alice')), 'The file went to the error folder.');
		} else {
			self::assertSame('retry', $import['status'] ?? null, 'The file goes up again.');
			self::assertSame(TestClock::START, $this->state->findUpload('alice', hash('sha256', '%PDF-alice'))['created_at'] ?? null, 'The record of its upload stays.');
			self::assertArrayHasKey(self::INBOX . '/scan.pdf', $this->filesOf('alice'));
		}
	}

	/**
	 * The target user has no user of Paperless, so their inbox counts the documents that go to
	 * their archive: those without an owner, of the account of the app while nothing is held, and
	 * of an owner without a user of Nextcloud. A file with the content of bob's document goes up.
	 *
	 * @return array<string, array{int|null, bool}>
	 */
	public static function documentsForTheInboxOfTheTargetUser(): array {
		return [
			'a document of bob' => [self::BOB, false],
			'a document without an owner' => [null, true],
			'a document of the account' => [self::ACCOUNT, true],
			'a document of dave, whom Nextcloud does not know' => [self::DAVE, true],
		];
	}

	#[DataProvider('documentsForTheInboxOfTheTargetUser')]
	public function testTheInboxOfTheTargetUserCountsOnlyTheDocumentsOfTheTargetUser(?int $owner, bool $imported): void {
		$this->storage->files[self::INBOX . '/brief.pdf'] = '%PDF-content';
		$this->paperless->originals[hash('sha256', '%PDF-content')] = 2;

		$report = $this->sync([$this->document(2, $owner)]);

		self::assertSame($imported ? [] : ['brief.pdf'], array_keys($this->paperless->uploads));
		self::assertSame(!$imported, array_key_exists(self::INBOX . '/brief.pdf', $this->storage->files));
		self::assertSame($imported ? 1 : 0, $report->users['paperless']['importsSucceeded']);
		self::assertSame([], $this->paperless->ownerChanges);
	}

	/**
	 * Alice's upload waits for its task, and her file leaves the inbox before the task ends: the
	 * record of the upload follows the task, so its document gets her as its owner and reaches her
	 * archive in the run that sees it, never that of the target user.
	 */
	public function testAPendingUploadWhoseFileLeavesTheInboxGetsItsOwner(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->clock->advance(300);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		$this->assertTheDocumentIsAlices($report);
		self::assertContains('[alice] IMPORT SUCCESS: ' . self::INBOX . '/scan.pdf -> P77', $report->actions, 'Her report names the document of her file.');
		self::assertNotSame([], array_filter($this->activities, static fn (TestActivityEvent $event): bool => $event->getAffectedUser() === 'alice' && str_contains(json_encode($event->getSubjectParameters(), JSON_THROW_ON_ERROR), '"document":77')), 'Her Activity names it.');
		self::assertSame([], $this->state->uploads, 'The record ends once the document has its owner.');
		self::assertNotContains(77, $this->paperless->documentReads, 'The run takes the owner from the documents that it read.');
	}

	/**
	 * Alice's upload lost its answer, and Paperless created its document before her next attempt:
	 * the run gives the document its owner at once, by its content and its ID, and the attempt
	 * then ends the file as imported, without a second upload.
	 */
	public function testAnUploadWhoseAnswerNeverCameGetsItsOwnerBeforeItsNextAttempt(): void {
		$this->lostAnswer();
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->clock->advance(300);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		$this->assertTheDocumentIsAlices($report);
		$this->clock->advance(900);

		$this->sync([$this->document(77, $this->ownerOf77())]);

		self::assertSame(['scan.pdf'], $this->paperless->uploadAttempts, 'The attempt finds the document of the upload and uploads nothing.');
		self::assertArrayNotHasKey(self::INBOX . '/scan.pdf', $this->filesOf('alice'));
		self::assertSame([], $this->state->uploads);
	}

	/**
	 * The file whose upload lost its answer leaves the inbox, or changes, before the next attempt:
	 * the record of the upload still finds its document.
	 *
	 * @return array<string, array{string|null}>
	 */
	public static function filesAfterALostAnswer(): array {
		return ['a file that leaves the inbox' => [null], 'a file that changes' => ['%PDF-alice, signed']];
	}

	#[DataProvider('filesAfterALostAnswer')]
	public function testTheDocumentOfALostAnswerGetsItsOwnerWhenTheFileLeavesOrChanges(?string $content): void {
		$this->lostAnswer();
		if ($content === null) {
			unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		} else {
			$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = $content;
		}
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->clock->advance(900);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		$this->assertTheDocumentIsAlices($report);
		self::assertSame($content === null ? ['scan.pdf'] : ['scan.pdf', 'scan.pdf'], $this->paperless->uploadAttempts, 'A changed file goes up with its new content.');
	}

	/**
	 * The proof of a lost upload is the ID of its document, not a clock: Paperless says that it
	 * added the document two days before the upload, as a clock of Paperless that runs behind
	 * would, and the document is alice's all the same, on Paperless-ngx 3.x by its SHA-256 and on
	 * 2.x by its MD5 and its original. The file never goes up again.
	 *
	 * @return array<string, array{string}>
	 */
	public static function linesOfPaperless(): array {
		return ['Paperless-ngx 3.x' => ['sha256'], 'Paperless-ngx 2.x' => ['md5']];
	}

	#[DataProvider('linesOfPaperless')]
	public function testTheProofOfALostUploadNeedsNoClock(string $checksum): void {
		$this->lostAnswer();
		$this->paperless->originals[hash($checksum, '%PDF-alice')] = 77;
		$this->paperless->originalContents[77] = '%PDF-alice';
		$this->clock->advance(900);

		$report = $this->sync([['added' => '2026-08-26T10:00:00+02:00'] + $this->document(77, self::ACCOUNT)]);

		$this->assertTheDocumentIsAlices($report);
		self::assertSame(['scan.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(1, $report->users['alice']['importsSucceeded']);
		self::assertSame([], $this->state->uploads, 'The record ends on the document that the attempt gave its owner.');
	}

	/**
	 * Paperless lists alice's task unfinished a day after the upload, so her file moves to the
	 * error folder, and finishes it an hour later: the record follows the task, the document gets
	 * her as its owner, and her report names it. The file in the error folder stays, and so does a
	 * file that changed since its upload, which goes up again with its new content.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function filesOfLateTasks(): array {
		return ['an unchanged file' => [false], 'a changed file' => [true]];
	}

	#[DataProvider('filesOfLateTasks')]
	public function testAnUploadThatPaperlessFinishesLateGetsItsOwner(bool $changed): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		if ($changed) {
			$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice, signed';
		}
		$this->clock->advance(86400);
		$this->sync([]);
		self::assertSame($changed ? [] : ['Fehler/scan.pdf'], array_values(array_filter(array_map(static fn (string $path): string => substr($path, strlen('Dokumente/Paperless/')), array_keys($this->filesOf('alice'))), static fn (string $path): bool => str_starts_with($path, 'Fehler/scan.pdf') && !str_ends_with($path, '.txt'))));
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->clock->advance(300);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		$this->assertTheDocumentIsAlices($report);
		self::assertContains('[alice] IMPORT SUCCESS: ' . self::INBOX . '/scan.pdf -> P77', $report->actions);
		if (!$changed) {
			self::assertArrayHasKey('Dokumente/Paperless/Fehler/scan.pdf', $this->filesOf('alice'), 'Nothing is deleted.');
		}
	}

	/** An upload waits for its task when an administrator turns the inbox off: its record follows it all the same. */
	public function testAnUploadGetsItsOwnerWithTheInboxTurnedOff(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->configService->save(['inbox_enabled' => false] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);

		$held = $this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([], array_filter($held->actions, static fn (string $action): bool => str_contains($action, 'EXPORT')), 'While the task runs, the documents of the account wait.');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];

		$this->assertTheDocumentIsAlices($this->sync([$this->document(77, self::ACCOUNT)]));
	}

	/**
	 * The webhook calls for a new document of the account while an upload may have created it,
	 * also once the record only watches for it: the call leaves it to a scheduled run, which gives
	 * it its owner.
	 *
	 * @return array<string, array{int}>
	 */
	public static function waitsOfARecord(): array {
		return ['an open record' => [300], 'a watching record' => [2 * 86400]];
	}

	#[DataProvider('waitsOfARecord')]
	public function testTheWebhookLeavesADocumentOfTheAccountWhileAnUploadMayCreateIt(int $after): void {
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->lostAnswer();
		$this->clock->advance($after);
		$this->paperless->documents = [$this->document(77, self::ACCOUNT)];
		$this->paperless->contents[77] = '%PDF-content';
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);

		$report = $this->service->syncDocument(77);

		self::assertSame([[], [], []], [$report->actions, $this->storage->files, $this->paperless->ownerChanges]);
		$this->assertTheDocumentIsAlices($this->sync([$this->document(77, self::ACCOUNT)]));
	}

	/**
	 * Alice moves her file back from the error folder after Paperless created its document late:
	 * the file ends as imported, and doesn't become a second document.
	 */
	public function testAFileMovedBackFromTheErrorFolderEndsAsImported(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->clock->advance(86400);
		$this->sync([]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->clock->advance(3600);
		$this->sync([$this->document(77, self::ACCOUNT)]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->clock->advance(3600);

		$report = $this->sync([$this->document(77, $this->ownerOf77())]);

		self::assertSame(['scan.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(1, $report->users['alice']['importsSucceeded']);
		self::assertArrayNotHasKey(self::INBOX . '/scan.pdf', $this->filesOf('alice'));
	}

	/**
	 * A record ends, and nothing is held for good: without a task, a day after an answer that never
	 * came it holds nothing any more; 30 days after its upload it ends, and the report says that
	 * no document appeared; a task that ended without a document ends it at once. A document that
	 * someone gave the account by hand, of another content, then goes to the target user again.
	 *
	 * @return array<string, array{\Closure(self): void, list<string>}>
	 */
	public static function endsOfRecords(): array {
		return [
			'a day after an answer that never came' => [static function (self $test): void {
				$test->lostAnswer();
				unset($test->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
				$test->clock->advance(86400);
			}, []],
			'30 days after the upload' => [static function (self $test): void {
				$test->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
				$test->sync([]);
				$test->clock->advance(30 * 86400);
			}, ['Import ' . self::INBOX . '/scan.pdf: Paperless created no document of its upload within 30 days, so Paperless Sync no longer follows it']],
			'a task without a document' => [static function (self $test): void {
				$test->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
				$test->sync([]);
				$test->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type', 'documentId' => null];
				$test->clock->advance(300);
			}, []],
		];
	}

	/**
	 * @param \Closure(self): void $arrange
	 * @param list<string> $errors
	 */
	#[DataProvider('endsOfRecords')]
	public function testARecordOfAnUploadEnds(\Closure $arrange, array $errors): void {
		$arrange($this);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertContains('[paperless] EXPORT P5: ' . $this->path(5), $report->actions);
		self::assertSame([], $this->paperless->ownerChanges, 'The document of other content keeps its owner.');
		foreach ($errors as $error) {
			self::assertContains($error, $this->errorsOf($report, 'alice'));
		}
		if ($errors !== []) {
			self::assertSame([], $this->state->uploads);
		}
	}

	/**
	 * Alice and bob uploaded files of the same content, and both answers got lost: the document of
	 * that content that Paperless created after both gets neither owner, stays with the account,
	 * and both reports name it.
	 */
	public function testTwoUploadsOfTheSameContentGiveTheDocumentToNeither(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-same';
		$this->storage->filesOfOthers['bob'][self::INBOX . '/scan.pdf'] = '%PDF-same';
		$this->paperless->uploadFailures['scan.pdf'] = new RuntimeException('Paperless did not answer in time');
		$this->sync([]);
		$this->paperless->uploadFailures = [];
		$this->paperless->originals[hash('sha256', '%PDF-same')] = 77;
		$this->clock->advance(2 * 86400);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'], $this->storage->filesOfOthers['bob'][self::INBOX . '/scan.pdf']);

		$report = $this->sync([$this->document(77, self::ACCOUNT), $this->document(5, self::ACCOUNT)]);

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertSame([], array_filter($report->actions, static fn (string $action): bool => str_contains($action, 'EXPORT')), 'The documents of the account stay where they are.');
		$error = 'Paperless created the document 77 with its content after the uploads of more than one user, so it stays with the account of the app until someone in Paperless gives it its owner';
		self::assertContains('Import ' . self::INBOX . "/scan.pdf: {$error}", $this->errorsOf($report, 'alice'));
		self::assertContains('Import ' . self::INBOX . "/scan.pdf: {$error}", $this->errorsOf($report, 'bob'));
	}

	/** Paperless can't answer the search of a record: the run holds the documents of the account, and says why. */
	public function testAFailedSearchHoldsTheDocumentsOfTheAccount(): void {
		$this->lostAnswer();
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->clock->advance(2 * 86400);
		$this->paperless->findException = new RuntimeException('Paperless answered with HTTP 500');

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertNotContains('[paperless] EXPORT P5: ' . $this->path(5), $report->actions);
		self::assertContains('Import ' . self::INBOX . '/scan.pdf: Paperless answered with HTTP 500', $this->errorsOf($report, 'alice'));
		self::assertNotSame([], $this->state->uploads, 'The record stays.');
	}

	/**
	 * Once no record holds, a document of the account with other content keeps its owner and goes
	 * to the target user, also while a record still looks for its own document.
	 */
	public function testADocumentOfTheAccountWithOtherContentKeepsItsOwner(): void {
		$this->lostAnswer();
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->clock->advance(2 * 86400);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame(['[paperless] EXPORT P5: ' . $this->path(5)], $report->actions);
		self::assertSame([], $this->paperless->ownerChanges);
		self::assertCount(1, $this->state->uploads, 'The record looks for its document until 30 days after the upload.');
	}

	/** Single ownership keeps no record, and asks Paperless nothing more than before. */
	public function testSingleOwnershipKeepsNoRecordAndAsksNothingMore(): void {
		$this->configService->save(self::validSettings(), 'test-token');
		$this->storage->files[self::INBOX . '/scan.pdf'] = '%PDF-scan';
		$this->paperless->uploadFailures['scan.pdf'] = new RuntimeException('Paperless did not answer in time');
		$this->sync([]);
		$this->paperless->uploadFailures = [];
		$this->clock->advance(900);

		$this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame([], $this->state->uploads);
		self::assertSame([hash('sha256', '%PDF-scan'), hash('sha256', '%PDF-scan')], $this->paperless->searches, 'One search before each upload.');
		self::assertSame([[], 0], [$this->paperless->documentReads, $this->paperless->accountReads]);
	}

	/** While a record holds the documents of the account, the report says whose upload, and since when, without naming a document. */
	public function testTheReportSaysWhoseUploadHoldsTheDocumentsOfTheAccount(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->clock->advance(300);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertContains('UPLOADS WAITING: the documents of the account of the app stay where they are while an upload may still create its document, of alice (2026-08-28 10:00 UTC).', $report->actions);
		self::assertContains(['type' => SyncReport::TYPE_UPLOADS_WAITING, 'values' => ['uploads' => 'alice (2026-08-28 10:00 UTC)', 'count' => 1]], $report->entries);
	}

	/** A dry-run shows where the document of an upload goes, and changes neither its owner nor the record. */
	public function testADryRunShowsTheDocumentOfAnUploadWithItsUser(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->documents = [$this->document(77, self::ACCOUNT)];
		$this->paperless->contents[77] = '%PDF-content';

		$report = $this->service->run(true);

		self::assertContains('[alice] EXPORT P77: ' . $this->path(77), $report->actions);
		self::assertSame([[], 1], [$this->paperless->ownerChanges, count($this->state->uploads)]);
	}

	/**
	 * Paperless no longer lists the task of an upload, or names no document for it: the record
	 * looks for its document by its content, as after an answer that never came.
	 *
	 * @return array<string, array{array{status: string, message: string, documentId?: int|null, listed?: bool}}>
	 */
	public static function tasksWithoutTheirDocument(): array {
		return [
			'a task that Paperless lost' => [['status' => 'PENDING', 'message' => '', 'listed' => false]],
			'a task that names no document' => [['status' => 'SUCCESS', 'message' => '', 'documentId' => null]],
		];
	}

	/** @param array{status: string, message: string, documentId?: int|null, listed?: bool} $task */
	#[DataProvider('tasksWithoutTheirDocument')]
	public function testARecordWhoseTaskNamesNoDocumentFindsItByItsContent(array $task): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = $task;
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->clock->advance(300);

		$this->assertTheDocumentIsAlices($this->sync([$this->document(77, self::ACCOUNT)]));
	}

	/**
	 * The document that a task names keeps an owner that someone gave it in Paperless meanwhile,
	 * and the record ends; one that the inbox gave its owner already ends it as well.
	 *
	 * @return array<string, array{int}>
	 */
	public static function ownersOfTheDocumentOfATask(): array {
		return ['an owner given by hand' => [self::BOB], 'the owner given by the inbox' => [self::ALICE]];
	}

	#[DataProvider('ownersOfTheDocumentOfATask')]
	public function testARecordEndsOnTheDocumentOfItsTaskWithAnOwner(int $owner): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->clock->advance(300);

		$this->sync([$this->document(77, $owner)]);

		self::assertSame([[], []], [$this->paperless->ownerChanges, $this->state->uploads]);
	}

	/**
	 * A document with the content of a record that another user's task names is that task's: the
	 * record by content doesn't take it.
	 */
	public function testARecordDoesNotTakeTheDocumentOfAnotherUsersTask(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-same';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->state->saveUpload('bob', hash('sha256', '%PDF-same'), ['md5' => hash('md5', '%PDF-same'), 'created_at' => TestClock::START, 'last_attempt_at' => TestClock::START]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->paperless->originals[hash('sha256', '%PDF-same')] = 77;
		$this->clock->advance(300);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		$this->assertTheDocumentIsAlices($report);
		self::assertSame(['bob'], array_column($this->state->uploads, 'user_id'), 'The record of bob looks on.');
	}

	/**
	 * Paperless refuses the owner of the document of a record: the record waits for it while the
	 * refusal may pass, and the document stays where it is; a refusal for good ends the record,
	 * and the document keeps its owner, which the report says.
	 */
	public function testARecordWaitsWhilePaperlessRefusesTheOwner(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->paperless->ownerException = new RuntimeException('Paperless answered with HTTP 503');
		$this->clock->advance(300);

		$waiting = $this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([[], 1], [array_filter($waiting->actions, static fn (string $action): bool => str_contains($action, 'P77')), count($this->state->uploads)]);
		$this->paperless->ownerException = new PaperlessOwnerException('Paperless did not make the user 1 the owner of the document 77: HTTP 403.', 403);

		$ended = $this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([], $this->state->uploads);
		self::assertContains('Import ' . self::INBOX . '/scan.pdf: Paperless refused to change the owner of the document 77, which keeps the owner that Paperless gave it: Paperless did not make the user 1 the owner of the document 77: HTTP 403.', $this->errorsOf($ended, 'alice'));
	}

	/** While Paperless doesn't name its account, the export waits for an upload of bob, and a record of alice still ends after 30 days. */
	public function testARecordEndsWhilePaperlessDoesNotNameItsAccount(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		$this->paperless->accountId = null;
		$this->clock->advance(30 * 86400);
		$this->state->saveSend('bob', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame([0, []], [$report->exported, $this->state->uploads]);
		self::assertContains('Import ' . self::INBOX . '/scan.pdf: Paperless created no document of its upload within 30 days, so Paperless Sync no longer follows it', $this->errorsOf($report, 'alice'));
	}

	/** The records of a user who has no user of Paperless any more end, as their documents stay with the account. */
	public function testARecordOfAUserWithoutAUserOfPaperlessEnds(): void {
		$this->state->saveUpload('carol', hash('sha256', '%PDF-carol'), ['created_at' => TestClock::START, 'last_attempt_at' => TestClock::START]);

		$report = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertSame([[], ['[paperless] EXPORT P5: ' . $this->path(5)]], [$this->state->uploads, $report->actions]);
	}

	/** Without the export, the run still settles the records, with the owners that Paperless names. */
	public function testTheRunSettlesTheRecordsWithoutTheExport(): void {
		$this->configService->save(['export_enabled' => false] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->clock->advance(300);

		$this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([[[77, self::ALICE]], []], [$this->paperless->ownerChanges, $this->state->uploads]);
	}

	/**
	 * A file that alice sent to Paperless loses the answer of each upload until the send gives up:
	 * the record of the upload outlives the send, and the document that Paperless created all the
	 * same gets her as its owner.
	 */
	public function testASendThatGivesUpGivesItsLateDocumentItsOwner(): void {
		$send = $this->sendService();
		$this->queueSend('Scans/receipt.pdf', '%PDF-alice');
		$this->paperless->uploadException = new RuntimeException('Paperless did not answer in time');
		for ($attempt = 0; $attempt < SendService::MAX_ATTEMPTS; ++$attempt) {
			$send->process();
			$this->clock->advance(ImportExecutor::MAX_RETRY_DELAY);
		}
		self::assertSame([], $this->state->allSends(), 'The send gave up.');
		$this->paperless->uploadException = null;
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;

		$this->assertTheDocumentIsAlices($this->sync([$this->document(77, self::ACCOUNT)]));
	}

	/**
	 * Paperless has not finished the task of a sent file a day after the upload, so the send ends,
	 * and it finishes the task an hour later: the record follows the task, and the document gets
	 * alice as its owner.
	 */
	public function testASendThatPaperlessFinishesLateGivesItsDocumentItsOwner(): void {
		$send = $this->sendService();
		$this->queueSend('Scans/receipt.pdf', '%PDF-alice');
		$send->process();
		$this->clock->advance(86400);
		$send->process();
		self::assertSame([], $this->state->allSends(), 'The send ended a day after the upload.');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->clock->advance(3600);

		$this->assertTheDocumentIsAlices($this->sync([$this->document(77, self::ACCOUNT)]));
	}

	/** Send to Paperless, with the doubles of the run. */
	private function sendService(): SendService {
		$logger = $this->createStub(LoggerInterface::class);

		return new SendService($this->configService, $this->paperless, $this->state, $this->storage, $this->notificationPublisher($this->status, $this->storage, $this->clock, $logger), $this->clock, $logger, $this->ownerMapping());
	}

	/** A file of alice that she sent to Paperless from Files. */
	private function queueSend(string $path, string $content): void {
		$this->storage->filesOfOthers['alice'][$path] = $content;
		$this->state->saveSend('alice', $this->storage->fileId($path, 'alice'), ['path' => $path, 'status' => SendService::QUEUED]);
	}

	/**
	 * For a day after an answer that never came, the record holds the documents of the account: a
	 * document of other content that someone gave the account by hand waits, and goes to the
	 * target user once the day is over.
	 */
	public function testAnOpenRecordWithoutATaskHoldsTheDocumentsOfTheAccount(): void {
		$this->lostAnswer();
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->clock->advance(86400 - 1);

		$held = $this->sync([$this->document(5, self::ACCOUNT)]);

		self::assertNotContains('[paperless] EXPORT P5: ' . $this->path(5), $held->actions);
		$this->clock->advance(1);

		self::assertContains('[paperless] EXPORT P5: ' . $this->path(5), $this->sync([$this->document(5, self::ACCOUNT)])->actions);
	}

	/** A document of the account that Paperless had with the content before the upload is no document of the upload: it keeps its owner. */
	public function testARecordDoesNotTakeADocumentThatPaperlessHadBeforeTheUpload(): void {
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->lostAnswer();
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->clock->advance(2 * 86400);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([[], 77], [$this->paperless->ownerChanges, $this->state->findUpload('alice', hash('sha256', '%PDF-alice'))['known_id'] ?? null]);
		self::assertContains('[paperless] EXPORT P77: ' . $this->path(77), $report->actions);
	}

	/**
	 * Paperless refuses for a reason that may pass to give the owner to the document that a record
	 * found by its content, a day after its answer got lost: the run holds the documents of the
	 * account, and the report of the user says why; the next run asks again.
	 */
	public function testARecordWaitsForTheOwnerOfADocumentThatItFoundByItsContent(): void {
		$this->lostAnswer();
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->originals[hash('sha256', '%PDF-alice')] = 77;
		$this->paperless->ownerException = new RuntimeException('Paperless answered with HTTP 503');
		$this->clock->advance(2 * 86400);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([], array_filter($report->actions, static fn (string $action): bool => str_contains($action, 'EXPORT')));
		self::assertContains('Import ' . self::INBOX . '/scan.pdf: Paperless answered with HTTP 503', $this->errorsOf($report, 'alice'));
		$this->paperless->ownerException = null;

		$this->assertTheDocumentIsAlices($this->sync([$this->document(77, self::ACCOUNT)]));
	}

	/**
	 * A record that ends, one of a user without a user of Paperless, one whose task still runs and
	 * one without a document leave the records after them to be settled in the same run.
	 */
	public function testEachRecordIsSettledOnItsOwn(): void {
		$this->state->saveUpload('bob', hash('sha256', '%PDF-old'), ['created_at' => TestClock::START - 31 * 86400, 'last_attempt_at' => TestClock::START - 31 * 86400]);
		$this->state->saveUpload('carol', hash('sha256', '%PDF-carol'), ['created_at' => TestClock::START, 'last_attempt_at' => TestClock::START]);
		$this->state->saveUpload('bob', hash('sha256', '%PDF-bob'), ['task_id' => 'task-8', 'created_at' => TestClock::START, 'last_attempt_at' => TestClock::START]);
		$this->state->saveUpload('bob', hash('sha256', '%PDF-gone'), ['created_at' => TestClock::START, 'last_attempt_at' => TestClock::START]);
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->clock->advance(300);

		$report = $this->sync([$this->document(77, self::ACCOUNT)]);

		self::assertSame([[77, self::ALICE]], $this->paperless->ownerChanges);
		self::assertContains('[alice] EXPORT P77: ' . $this->path(77), $report->actions);
		self::assertSame([hash('sha256', '%PDF-bob'), hash('sha256', '%PDF-gone')], array_column($this->state->allUploads(), 'sha256'));
	}

	/**
	 * Paperless no longer lists the task of a record, and has no document of its content: the record
	 * goes on without the task, as after an answer that never came; a dry-run keeps it as it is.
	 */
	public function testARecordWhoseTaskPaperlessLostGoesOnWithoutIt(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => '', 'listed' => false];
		$this->clock->advance(300);
		$this->paperless->documents = [];

		$this->service->run(true);

		self::assertSame('task-1', $this->state->findUpload('alice', hash('sha256', '%PDF-alice'))['task_id'] ?? null);

		$this->sync([]);

		self::assertSame('', $this->state->findUpload('alice', hash('sha256', '%PDF-alice'))['task_id'] ?? null);
	}

	/** Paperless may give the owner of a document as a string of digits: the document of the account gets its owner all the same. */
	public function testTheDocumentOfARecordWithItsOwnerAsDigitsGetsItsOwner(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->sync([]);
		unset($this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf']);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 77];
		$this->clock->advance(300);

		$this->assertTheDocumentIsAlices($this->sync([['owner' => (string)self::ACCOUNT] + $this->document(77, null)]));
	}

	/** The report names the uploads of each user once, since the first of them. */
	public function testTheReportNamesEachUserWithTheirFirstUpload(): void {
		$this->state->saveUpload('alice', hash('sha256', '%PDF-later'), ['task_id' => 'task-8', 'created_at' => TestClock::START + 60, 'last_attempt_at' => TestClock::START + 60]);
		$this->state->saveUpload('alice', hash('sha256', '%PDF-first'), ['task_id' => 'task-9', 'created_at' => TestClock::START, 'last_attempt_at' => TestClock::START]);
		$this->state->saveUpload('bob', hash('sha256', '%PDF-bob'), ['task_id' => 'task-7', 'created_at' => TestClock::START + 120, 'last_attempt_at' => TestClock::START + 120]);
		$this->clock->advance(300);

		$report = $this->sync([]);

		self::assertContains(['type' => SyncReport::TYPE_UPLOADS_WAITING, 'values' => ['uploads' => 'alice (2026-08-28 10:00 UTC), bob (2026-08-28 10:02 UTC)', 'count' => 2]], $report->entries);
	}

	/** The upload whose answer got lost, of alice's file scan.pdf with the content %PDF-alice: Paperless may have it. */
	private function lostAnswer(): void {
		$this->storage->filesOfOthers['alice'][self::INBOX . '/scan.pdf'] = '%PDF-alice';
		$this->paperless->uploadFailures['scan.pdf'] = new RuntimeException('Paperless did not answer in time');
		$this->sync([]);
		$this->paperless->uploadFailures = [];
	}

	/** The document 77 of alice's upload got her as its owner, and only her archive has it. */
	private function assertTheDocumentIsAlices(SyncReport $report): void {
		self::assertContains([77, self::ALICE], $this->paperless->ownerChanges);
		self::assertContains('[alice] EXPORT P77: ' . $this->path(77), $report->actions);
		self::assertNotContains('[paperless] EXPORT P77: ' . $this->path(77), $report->actions);
		self::assertArrayNotHasKey($this->path(77), $this->storage->files, 'The archive of the target user never gets it.');
	}

	/** The owner of the document 77 as Paperless has it now: alice once a run gave it to her, else the account. */
	private function ownerOf77(): int {
		return in_array([77, self::ALICE], $this->paperless->ownerChanges, true) ? self::ALICE : self::ACCOUNT;
	}

	/** @return list<string> the errors of a user's part of the report */
	private function errorsOf(SyncReport $report, string $user): array {
		return array_values(array_map(static fn (string $action): string => substr($action, strlen("[{$user}] ERROR: ")), array_filter($report->actions, static fn (string $action): bool => str_starts_with($action, "[{$user}] ERROR: "))));
	}

	/**
	 * @param list<array<string, mixed>> $documents
	 */
	private function sync(array $documents): SyncReport {
		$this->paperless->documents = $documents;
		foreach ($documents as $document) {
			if (is_int($document['id'] ?? null)) {
				$this->paperless->contents[$document['id']] = '%PDF-content';
			}
		}

		return $this->service->run();
	}

	/** @return array<string, mixed> */
	private function document(int $id, ?int $owner): array {
		return [
			'id' => $id,
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
			'owner' => $owner,
		];
	}

	private function path(int $id): string {
		return self::ARCHIVE . "/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/** @return array<string, string> */
	private function filesOf(string $user): array {
		return $this->storage->filesOfOthers[$user] ?? [];
	}

	/** @return list<array<array-key, mixed>> the deletions of files of an archive, of every user */
	private function archiveDeletions(): array {
		return array_values(array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'delete' && str_starts_with((string)($call[2] ?? ''), self::ARCHIVE)));
	}

	/** @return array<string, int> the number of the files that the entry of each user's run names */
	private function changedFilesOfTheActivities(): array {
		$counts = [];
		foreach ($this->activities as $event) {
			$parameters = $event->getSubjectParameters();
			$counts[$event->getAffectedUser()] = count(is_array($parameters['files'] ?? null) ? $parameters['files'] : []);
		}

		return $counts;
	}
}
