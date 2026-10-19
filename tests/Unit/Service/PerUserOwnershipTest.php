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
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PathTemplateService;
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

	/** The webhook names a document that Paperless doesn't show the account: no archive changes. */
	public function testTheRunOfADocumentThatPaperlessDoesNotShowChangesNothing(): void {
		$this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => SyncConfig::PER_USER]);
		$this->sync([$this->document(1, self::ALICE)]);

		$report = $this->service->syncDocument(99);

		self::assertSame([], $report->actions);
	}

	/** "Forget" asks every archive of per-user ownership to write the copies anew. */
	public function testForgettingReachesTheCopiesOfEveryUser(): void {
		$this->sync([$this->document(1, self::ALICE), $this->document(2, null)]);

		self::assertSame([2, 1], $this->service->forget());
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exportsOfOthers['alice'][1]['fingerprint']);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[2]['fingerprint']);
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
