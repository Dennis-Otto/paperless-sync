<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\Config\IUserConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The documents that Paperless shares: on top of per-user ownership, each user of Nextcloud also
 * gets a copy of the documents that their user of Paperless may view, by itself or through a group.
 * The users of Paperless are alice, bob, carol, erin, dave, whom Nextcloud doesn't know, and the
 * account of the app; the users of Nextcloud are the target user paperless, alice, bob, carol and
 * erin. Bob and dave are in the group of the household.
 */
#[AllowMockObjectsWithoutExpectations]
final class SharedDocumentsTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	private const ARCHIVE = 'Dokumente/Paperless/Archiv';
	private const INBOX = 'Dokumente/Paperless/Eingang';
	private const ALICE = 1;
	private const BOB = 2;
	private const CAROL = 4;
	private const DAVE = 3;
	private const ERIN = 5;
	private const ACCOUNT = 9;
	private const HOUSEHOLD = 8;

	private TestClock $clock;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	private StatusService $status;
	private SyncService $service;

	protected function setUp(): void {
		$this->defaultLanguage = 'de';
		$this->usersOfNextcloud = ['paperless', 'alice', 'bob', 'carol', 'erin'];
		$this->clock = new TestClock();
		$this->paperless = new TestPaperlessClient();
		$this->paperless->users = [(string)self::ALICE => 'alice', (string)self::BOB => 'Bob', (string)self::CAROL => 'carol', (string)self::ERIN => 'erin', (string)self::DAVE => 'dave', (string)self::ACCOUNT => 'paperless-sync'];
		$this->paperless->groupMembers = [self::HOUSEHOLD => [self::BOB, self::DAVE]];
		$this->paperless->accountId = self::ACCOUNT;
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$this->configService = $this->configService($paths);
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER_SHARED], 'test-token');
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
	 * The owner and whoever may view the document each get a copy of their own at the same path,
	 * with a state, a report and an entry in the Activity stream of their own; each copy counts.
	 */
	public function testAUserGetsACopyOfADocumentThatTheirUserOfPaperlessMayView(): void {
		$report = $this->sync([$this->document(1, self::ALICE, [self::BOB])]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
		self::assertSame([], $this->filesOf('carol'));
		self::assertSame([], $this->storage->files, 'The target user is no one who may view it.');
		self::assertSame(['[alice] EXPORT P1: ' . $this->path(1), '[bob] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame(2, $report->exported);
		self::assertSame([1, 1], [$report->users['alice']['exported'], $report->users['bob']['exported']]);
		self::assertSame([1, 1], [(int)$this->state->exportsOfOthers['alice'][1]['document_id'], (int)$this->state->exportsOfOthers['bob'][1]['document_id']], 'Each user keeps the state of their own copy.');
		self::assertSame([], $report->errorMessages);
		self::assertSame([true], $this->paperless->permissionsAsked, 'The run reads the permissions once for all of them.');
		self::assertSame(['users', 'groups'], array_values(array_unique($this->paperless->listsRead)));
	}

	/** A group of Paperless names its users, and a user of Paperless without a user of Nextcloud gets no copy, nor does the target user in its place. */
	public function testAUserGetsACopyThroughAGroupOfPaperless(): void {
		$report = $this->sync([$this->document(1, self::ALICE, [], [self::HOUSEHOLD])]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
		self::assertSame([], $this->storage->files, 'Dave has no user of Nextcloud, and the target user may not be allowed to see the document.');
		self::assertSame(2, $report->exported);
	}

	/** Who owns a document or may view it twice, by themselves and through a group, gets one copy. */
	public function testAUserGetsOneCopyHoweverManyWaysLeadToIt(): void {
		$report = $this->sync([$this->document(1, self::ALICE, [self::ALICE, self::BOB], [self::HOUSEHOLD])]);

		self::assertSame(['[alice] EXPORT P1: ' . $this->path(1), '[bob] EXPORT P1: ' . $this->path(1)], $report->actions);
	}

	/** The account of the app and the users whom Paperless doesn't list get nothing, though the account may view each document that a run reads. */
	public function testTheAccountOfTheAppAndAnUnlistedUserGetNoCopy(): void {
		$report = $this->sync([$this->document(1, self::ALICE, [self::ACCOUNT, 42])]);

		self::assertSame(['[alice] EXPORT P1: ' . $this->path(1)], $report->actions);
	}

	/** The target user keeps the documents without an owner and those whose owner has no user of Nextcloud, and who may view them gets a copy. */
	public function testADocumentOfTheTargetUserIsSharedAsWell(): void {
		$report = $this->sync([$this->document(1, null, [self::BOB]), $this->document(2, self::DAVE, [self::CAROL])]);

		self::assertSame([$this->path(2), $this->path(1)], array_keys($this->storage->files));
		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
		self::assertSame([$this->path(2)], array_keys($this->filesOf('carol')));
		self::assertSame([2, 1, 1], [$report->users['paperless']['exported'], $report->users['bob']['exported'], $report->users['carol']['exported']]);
	}

	/** A removed permission removes the copy of that user alone, into their trash bin, and the owner's stays. */
	public function testAPermissionThatIsGoneTakesTheCopyOfThatUserAlone(): void {
		$this->sync([$this->document(1, self::ALICE, [self::BOB], [self::HOUSEHOLD])]);
		$this->storage->calls = [];

		$report = $this->sync([$this->document(1, self::ALICE)]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([], $this->filesOf('bob'));
		self::assertSame(['[bob] REMOVE REASSIGNED P1: ' . $this->path(1)], $report->actions);
		self::assertSame(['type' => SyncReport::TYPE_REMOVE_REASSIGNED, 'document' => 1, 'path' => $this->path(1), 'user' => 'bob'], $report->entries[0]);
		self::assertSame(1, $report->users['bob']['removedReassigned']);
		self::assertArrayNotHasKey(1, $this->state->exportsOfOthers['bob'], 'The state of the copy of bob is gone with it.');
		self::assertSame([['delete', 'bob']], array_map(static fn (array $call): array => [$call[0], $call[1]], $this->archiveDeletions()), 'Nextcloud moves what the user deletes to their trash bin.');
	}

	/** A new permission adds the copy to the user, and the archive of the owner stays as it is. */
	public function testANewPermissionAddsACopy(): void {
		$this->sync([$this->document(1, self::ALICE)]);

		$report = $this->sync([$this->document(1, self::ALICE, [self::CAROL])]);

		self::assertSame(['[carol] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
	}

	/** When the owner changes, the copy of the old owner stays only while the old owner may still view the document. */
	public function testACopyStaysWithAnOldOwnerWhoMayStillViewTheDocument(): void {
		$this->sync([$this->document(1, self::ALICE)]);

		$report = $this->sync([$this->document(1, self::BOB, [self::ALICE])]);

		self::assertSame(['[bob] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
	}

	/** A dry-run says what would change for each user, and changes nothing. */
	public function testADryRunShowsTheChangesOfEachUser(): void {
		$this->sync([$this->document(1, self::ALICE, [self::BOB])]);
		$this->paperless->documents = [$this->document(1, self::ALICE, [self::CAROL])];

		$report = $this->service->run(true);

		self::assertSame(['[bob] REMOVE REASSIGNED P1: ' . $this->path(1), '[carol] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
		self::assertSame([], $this->filesOf('carol'));
	}

	/**
	 * An answer that doesn't say who may view a document can't take a copy away from anyone: the
	 * owner gets the document, every copy stays where it is, and the report says how many there are.
	 */
	public function testADocumentThatDoesNotSayWhoMayViewItGoesToItsOwnerAlone(): void {
		$this->sync([$this->document(1, self::ALICE, [self::BOB])]);
		$unreadable = $this->document(1, self::ALICE);
		unset($unreadable['permissions']);
		$unlistedGroup = $this->document(2, self::CAROL, [], [77]);

		$report = $this->sync([$unreadable, $unlistedGroup]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')), 'The copy of bob stays.');
		self::assertSame([$this->path(2)], array_keys($this->filesOf('carol')), 'The new document goes to its owner.');
		self::assertSame(['[carol] EXPORT P2: ' . $this->path(2)], array_values(array_filter($report->actions, static fn (string $action): bool => !str_starts_with($action, 'ERROR'))));
		self::assertSame(0, (int)$this->state->exportsOfOthers['bob'][1]['missing_runs'], 'The copy counts as no missing document.');
		self::assertSame(['Paperless did not say who may view 2 documents, so only their owners get them, and their copies stay in every archive that has them. Check that no proxy changes the answers of Paperless.'], $report->errorMessages);
		self::assertSame('completed-with-errors', $this->status->get()['state']);
	}

	/** A document whose owner the run can't tell stays where its copies are, whoever may view it. */
	public function testADocumentOfAnOwnerThatPaperlessDidNotListStaysWhereItIs(): void {
		$this->sync([$this->document(1, self::ALICE, [self::BOB])]);

		$report = $this->sync([$this->document(1, 42, [self::CAROL])]);

		self::assertSame([], $report->actions);
		self::assertSame([], $report->errorMessages);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
		self::assertSame([], $this->filesOf('carol'));
	}

	/** While an upload waits for its owner, the documents of the account stay where they are, whoever may view them. */
	public function testADocumentOfTheAccountWaitsForItsOwner(): void {
		$this->state->saveSend('alice', 12, ['path' => 'Scans/receipt.pdf', 'status' => 'pending', 'task_id' => 'task-9']);

		$report = $this->sync([$this->document(77, self::ACCOUNT, [self::BOB]), $this->document(2, self::CAROL)]);

		self::assertSame(['[carol] EXPORT P2: ' . $this->path(2)], $report->actions);
		self::assertSame([], $report->errorMessages);
	}

	/** An archive that Nextcloud can't reach waits, and the owner gets the document all the same. */
	public function testAnArchiveThatNextcloudCantReachWaits(): void {
		$this->storage->testFailures['bob'] = new RuntimeException('The configured Nextcloud base folder is not a writable folder.');

		$report = $this->sync([$this->document(1, self::ALICE, [self::BOB])]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([], $this->filesOf('bob'));
		self::assertSame(['[alice] EXPORT P1: ' . $this->path(1), '[bob] ERROR: Archive of bob: The configured Nextcloud base folder is not a writable folder. Its documents wait until Nextcloud can store them.'], $report->actions);
		self::assertCount(1, $report->errorMessages);
	}

	/** A copy leaves an archive once any archive that the run reaches takes the document, not only the archive of its owner. */
	public function testACopyLeavesOnceAnotherArchiveTakesTheDocument(): void {
		$this->sync([$this->document(1, self::ALICE)]);
		$this->storage->testFailures['bob'] = new RuntimeException('The configured Nextcloud base folder is not a writable folder.');

		$report = $this->sync([$this->document(1, self::BOB, [self::CAROL])]);

		self::assertSame(['[alice] REMOVE REASSIGNED P1: ' . $this->path(1), '[bob] ERROR: Archive of bob: The configured Nextcloud base folder is not a writable folder. Its documents wait until Nextcloud can store them.', '[carol] EXPORT P1: ' . $this->path(1)], $report->actions);
	}

	/** While no archive that the run reaches takes the document, its copy stays where it is. */
	public function testACopyStaysWhileNoArchiveTakesTheDocument(): void {
		$this->sync([$this->document(1, self::ALICE)]);
		$this->storage->testFailures['bob'] = new RuntimeException('The configured Nextcloud base folder is not a writable folder.');

		$report = $this->sync([$this->document(1, self::BOB)]);

		self::assertSame(['[bob] ERROR: Archive of bob: The configured Nextcloud base folder is not a writable folder. Its documents wait until Nextcloud can store them.'], $report->actions);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
	}

	/** The run of one document, which the webhook asks for, reads who may view it and reaches the archives that get it or have a copy. */
	public function testTheRunOfOneDocumentFollowsItsPermissions(): void {
		$this->sync([$this->document(1, self::ALICE, [self::BOB]), $this->document(2, null)]);
		$this->paperless->documents = [$this->document(1, self::ALICE, [self::CAROL]), $this->document(2, null)];
		$this->paperless->permissionsAsked = [];
		$this->storage->calls = [];

		$report = $this->service->syncDocument(1);

		self::assertSame(['[bob] REMOVE REASSIGNED P1: ' . $this->path(1), '[carol] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame([true], $this->paperless->permissionsAsked);
		self::assertSame(['alice', 'bob', 'carol'], array_values(array_unique(array_map(static fn (array $call): string => $call[1], array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'test' && $call[1] !== 'paperless')))), 'Erin neither gets the document nor has a copy.');
	}

	/** A document without a valid ID fails the export of the target user, as in per-user ownership, and is none that doesn't say who may view it. */
	public function testADocumentWithoutAValidIdIsNoDocumentThatDoesNotSayWhoMayViewIt(): void {
		try {
			$this->sync([['id' => 0] + $this->document(1, self::ALICE, [self::BOB])]);
			self::fail('A document without a valid ID fails the run.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless returned a document without a valid ID.', $exception->getMessage());
		}

		self::assertSame([], $this->filesOf('bob'));
		self::assertStringNotContainsString('may view', (string)json_encode($this->status->get()));
	}

	/** Without the groups of Paperless, the copies of the users of a group would look as if the document was no longer shared, so a run fails before a copy changes. */
	public function testARunFailsWithoutTheGroupsOfPaperless(): void {
		$this->sync([$this->document(1, self::ALICE, [], [self::HOUSEHOLD])]);
		$this->paperless->groupsException = new PaperlessRequestException('HTTP 403');

		try {
			$this->sync([$this->document(1, self::ALICE, [], [self::HOUSEHOLD])]);
			self::fail('The run needs the groups of Paperless.');
		} catch (RuntimeException $exception) {
			self::assertStringStartsWith('Paperless did not list its groups, which the documents that it shares need', $exception->getMessage());
		}
		self::assertSame([$this->path(1)], array_keys($this->filesOf('bob')));
	}

	/** Only the option of the shared documents asks for the permissions, which Paperless reads for each document, and for the groups, and gives copies. */
	public function testPerUserOwnershipAloneReadsNoPermissions(): void {
		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER]);

		$report = $this->sync([$this->document(1, self::ALICE, [self::BOB], [self::HOUSEHOLD])]);

		self::assertSame(['[alice] EXPORT P1: ' . $this->path(1)], $report->actions);
		self::assertSame([false], $this->paperless->permissionsAsked);
		self::assertNotContains('groups', $this->paperless->listsRead);
	}

	/** Turning the option off takes the copies of those who may only view a document away again, and those of the owners stay. */
	public function testTurningTheOptionOffTakesTheCopiesAway(): void {
		$this->sync([$this->document(1, self::ALICE, [self::BOB])]);
		$this->configService->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER]);

		$report = $this->sync([$this->document(1, self::ALICE, [self::BOB])]);

		self::assertSame(['[bob] REMOVE REASSIGNED P1: ' . $this->path(1)], $report->actions);
		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
	}

	/** @param list<array<string, mixed>> $documents */
	private function sync(array $documents): SyncReport {
		$this->paperless->documents = $documents;
		foreach ($documents as $document) {
			if (is_int($document['id'] ?? null)) {
				$this->paperless->contents[$document['id']] = '%PDF-content';
			}
		}

		return $this->service->run();
	}

	/**
	 * A document with the permissions of Paperless, in which the account of the app may view it.
	 *
	 * @param list<int> $users
	 * @param list<int> $groups
	 * @return array<string, mixed>
	 */
	private function document(int $id, ?int $owner, array $users = [], array $groups = []): array {
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
			'permissions' => ['view' => ['users' => $users, 'groups' => $groups], 'change' => ['users' => [], 'groups' => []]],
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
		return array_values(array_filter($this->storage->calls, static fn (array $call): bool => $call[0] === 'delete' && is_array($call[2]) && str_starts_with((string)$call[2][0], self::ARCHIVE)));
	}
}
