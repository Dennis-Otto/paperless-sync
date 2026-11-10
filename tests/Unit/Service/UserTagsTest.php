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
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTags;
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

/**
 * The tags that a user may see: the administrator gives some users of Nextcloud a list of tags of
 * Paperless, and each of them gets every document with at least one of them, in an archive of their
 * own, and no other; everybody else gets what they got before. The users of Nextcloud are the
 * target user paperless, katja, who may see the tags Katja and Household, and alice; the users of
 * Paperless are alice and katja.
 */
#[AllowMockObjectsWithoutExpectations]
final class UserTagsTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	private const ARCHIVE = 'Documents/Paperless/Archive';
	private const ALICE = 1;
	private const KATJA = 2;
	private const TAG_KATJA = 11;
	private const TAG_HOUSEHOLD = 12;
	private const TAG_TAXES = 13;

	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	private SyncService $service;

	protected function setUp(): void {
		$this->usersOfNextcloud = ['paperless', 'Katja', 'alice'];
		$this->paperless = new TestPaperlessClient();
		$this->paperless->users = [(string)self::ALICE => 'alice', (string)self::KATJA => 'katja'];
		$this->paperless->correspondents = ['4' => 'Energy Ltd'];
		$this->paperless->documentTypes = ['7' => 'Invoice'];
		$this->paperless->tagInfo = ['names' => [(string)self::TAG_KATJA => 'Katja', (string)self::TAG_HOUSEHOLD => 'Household', (string)self::TAG_TAXES => 'Taxes'], 'inbox' => []];
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$clock = new TestClock();
		$this->configService = $this->configService($paths);
		$this->save();
		$logger = $this->createStub(LoggerInterface::class);
		$status = $this->statusService($clock);
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('');
		$this->service = new SyncService(
			$this->configService,
			$this->paperless,
			$this->storage,
			$this->state,
			$paths,
			$status,
			$this->createStub(ILockingProvider::class),
			$logger,
			$clock,
			$this->activityPublisher($this->storage, $logger),
			new UserTimeZones($userConfig, new TestTimeZones()),
			$this->notificationPublisher($status, $this->storage, $clock, $logger),
			$this->systemTagService($this->storage),
			$this->ownerMapping(),
		);
	}

	/**
	 * In single ownership, the target user keeps every document, and a user with tags gets a copy
	 * of each with one of them, whatever the case of the names, in an archive without an inbox,
	 * with a report and an entry in the Activity stream of their own; a user without a line gets
	 * nothing, as before.
	 */
	public function testAUserWithTagsGetsTheDocumentsWithOneOfThemBesideTheTargetUser(): void {
		$report = $this->sync([$this->document(1, [self::TAG_KATJA]), $this->document(2, [self::TAG_TAXES]), $this->document(3, [self::TAG_HOUSEHOLD, self::TAG_TAXES])]);

		self::assertSame([$this->path(1), $this->path(2), $this->path(3)], $this->sorted(array_keys($this->storage->files)));
		self::assertSame([$this->path(1), $this->path(3)], $this->sorted(array_keys($this->filesOf('Katja'))));
		self::assertSame([], $this->filesOf('alice'));
		self::assertSame([3, 2], [$report->users['paperless']['exported'], $report->users['Katja']['exported']]);
		self::assertSame([], $report->errorMessages);
		self::assertSame(['Documents/Paperless', 'Documents/Paperless/Archive'], $this->storage->folders['Katja'], 'Katja has no inbox: her files go nowhere.');
		self::assertSame(['Katja'], $this->configService->tagArchives());
		self::assertSame(['Katja', 'paperless'], $this->activityUsers(), 'Each archive has its own Activity.');
	}

	/** A document that loses the last of her tags leaves her archive, into her trash bin, and stays with the target user. */
	public function testADocumentWithoutHerTagsLeavesHerArchive(): void {
		$this->sync([$this->document(1, [self::TAG_KATJA])]);

		$report = $this->sync([$this->document(1, [self::TAG_TAXES])]);

		self::assertSame([], $this->filesOf('Katja'));
		self::assertSame([$this->path(1)], array_keys($this->storage->files));
		self::assertSame(['[Katja] REMOVE REASSIGNED P1: ' . $this->path(1)], $report->actions);
	}

	/**
	 * Once her line is gone, the next run still reaches her archive and takes every copy away, and
	 * then the runs are those of single ownership again.
	 */
	public function testACopyLeavesOnceTheLineOfItsUserIsGone(): void {
		$this->sync([$this->document(1, [self::TAG_KATJA])]);
		$this->save('');

		$report = $this->sync([$this->document(1, [self::TAG_KATJA])]);

		self::assertSame([], $this->filesOf('Katja'));
		self::assertSame(['[Katja] REMOVE REASSIGNED P1: ' . $this->path(1)], $report->actions);
		self::assertSame([], $this->configService->tagArchives());

		$report = $this->sync([$this->document(1, [self::TAG_KATJA])]);

		self::assertSame([], $report->users, 'A run of single ownership again.');
		self::assertSame([$this->path(1)], array_keys($this->storage->files));
	}

	/** An archive that Nextcloud can't reach keeps its copies, and the runs keep reaching it until they are gone. */
	public function testTheRunsKeepReachingAnArchiveWhoseCopiesStayed(): void {
		$this->sync([$this->document(1, [self::TAG_KATJA])]);
		$this->save('');
		$this->storage->testFailures['Katja'] = new \RuntimeException('The storage of Katja is not available.');

		$report = $this->sync([$this->document(1, [self::TAG_KATJA])]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('Katja')));
		self::assertSame(['Katja'], $this->configService->tagArchives());
		self::assertStringContainsString('Archive of Katja', implode("\n", $report->errorMessages));
	}

	/** A dry-run changes nothing, also not which archives the tags filled. */
	public function testADryRunKeepsTheArchivesOfTheTags(): void {
		$this->paperless->documents = [$this->document(1, [self::TAG_KATJA])];
		$this->paperless->contents[1] = '%PDF-content';

		$report = $this->service->run(true);

		self::assertSame(['[Katja] EXPORT P1: ' . $this->path(1), '[paperless] EXPORT P1: ' . $this->path(1)], $this->sorted($report->actions));
		self::assertSame([], $this->configService->tagArchives());
		self::assertSame([], $this->filesOf('Katja'));
	}

	/** A tag that Paperless doesn't have, after a typo or a rename, would take her copies, so the run stops before one moves. */
	public function testARunStopsAtATagThatPaperlessDoesNotHave(): void {
		$this->sync([$this->document(1, [self::TAG_KATJA])]);
		$this->save('Katja = Katja, Housework, housework');

		try {
			$this->sync([$this->document(1, [self::TAG_KATJA])]);
			self::fail('The run needs every tag of the lines.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('Paperless has no tag named "Housework", which "Tags that a user may see" names; a run would take from a user the copies of the documents that they may see.', $exception->getMessage());
			self::assertSame('user_tags', $exception->setting);
		}
		self::assertSame([$this->path(1)], array_keys($this->filesOf('Katja')));
	}

	/**
	 * In per-user ownership, a user with tags gets the documents with one of them whoever owns them,
	 * and not her own without one, which the target user takes, as one whose owner has no user of
	 * Nextcloud; the others get what they own.
	 */
	public function testTheTagsDecideForTheOwnersOfPerUserOwnership(): void {
		$this->save(ownership: SyncConfig::PER_USER);

		$report = $this->sync([$this->document(1, [self::TAG_KATJA], self::ALICE), $this->document(2, [], self::KATJA), $this->document(3, [self::TAG_HOUSEHOLD], self::KATJA)]);

		self::assertSame([$this->path(1)], array_keys($this->filesOf('alice')));
		self::assertSame([$this->path(1), $this->path(3)], $this->sorted(array_keys($this->filesOf('Katja'))));
		self::assertSame([$this->path(2)], array_keys($this->storage->files));
		self::assertSame([], $report->errorMessages);
	}

	/** With the documents that Paperless shares, a user with tags gets no copy of a shared document without one of them. */
	public function testTheTagsDecideForTheViewersOfSharedDocuments(): void {
		$this->paperless->accountId = 9;
		$this->save(ownership: SyncConfig::PER_USER_SHARED);

		$this->sync([$this->document(1, [], self::ALICE, [self::KATJA]), $this->document(2, [self::TAG_KATJA], self::ALICE, [self::KATJA])]);

		self::assertSame([$this->path(1), $this->path(2)], $this->sorted(array_keys($this->filesOf('alice'))));
		self::assertSame([$this->path(2)], array_keys($this->filesOf('Katja')));
		self::assertSame([], $this->storage->files);
	}

	/** The run of one document, which the webhook asks for, gives it to a user with one of its tags as well. */
	public function testTheRunOfOneDocumentReachesAUserWithItsTag(): void {
		$this->paperless->documents = [$this->document(5, [self::TAG_HOUSEHOLD])];
		$this->paperless->contents[5] = '%PDF-content';

		$report = $this->service->syncDocument(5);

		self::assertSame([$this->path(5)], array_keys($this->filesOf('Katja')));
		self::assertSame([$this->path(5)], array_keys($this->storage->files));
		self::assertSame(1, $report->users['Katja']['exported']);
		self::assertSame(['Katja', 'paperless'], $this->activityUsers());
	}

	/** Forgetting the copies forgets hers as well, so that the next run writes them again. */
	public function testForgettingTheCopiesForgetsThoseOfAUserWithTags(): void {
		$this->sync([$this->document(1, [self::TAG_KATJA])]);

		self::assertSame([1], $this->service->forget());
		self::assertSame('pending', $this->state->exportsOfOthers['Katja'][1]['fingerprint']);
	}

	/** A save writes each line as the contract of both apps: the ID of the user as Nextcloud spells it, each tag once, and no line without tags. */
	public function testASaveWritesTheLinesAsBothAppsReadThem(): void {
		$config = $this->save(" katja =  Katja ,household, Household\n\nalice =\n");

		self::assertSame('Katja = Katja, household', $config->userTags);
		self::assertSame(['Katja'], UserTags::of($config)->users());
	}

	/** @return iterable<string, array{string, string}> */
	public static function refusedLines(): iterable {
		yield 'no equals sign' => ["Katja\n", '"Tags that a user may see", line 1: write the user ID of Nextcloud, an equals sign and the tags of Paperless, separated by commas, such as katja = Katja, Household.'];
		yield 'no user' => ["alice = Taxes\n = Katja", '"Tags that a user may see", line 2: write the user ID of Nextcloud, an equals sign and the tags of Paperless, separated by commas, such as katja = Katja, Household.'];
		yield 'an unknown user' => ['bob = Katja', '"Tags that a user may see", line 1: Nextcloud has no user with the ID bob.'];
		yield 'a user twice' => ["katja = Katja\nKATJA = Household", '"Tags that a user may see", line 2: the user Katja has a line already.'];
		yield 'the target user' => ['Paperless = Katja', '"Tags that a user may see", line 1: paperless is the target user, who gets every document.'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedLines')]
	public function testASaveRefusesALineThatGivesNoClearUser(string $lines, string $message): void {
		try {
			$this->save($lines);
			self::fail('The save should refuse the line.');
		} catch (InvalidSettingException $exception) {
			self::assertSame($message, $exception->getMessage());
			self::assertSame('user_tags', $exception->setting);
		}
	}

	/** A line that is no such line counts for nobody in a run, as one that an earlier version stored. */
	public function testARunLeavesOutALineThatIsNoSuchLine(): void {
		$tags = UserTags::of($this->configService->get()->withBatchSize(1));
		self::assertSame(['Katja'], $tags->users());
		self::assertSame([], UserTags::parse("Katja\n= Katja", false));
		self::assertTrue($tags->allows('alice', $this->document(1, []), []));
		self::assertTrue($tags->allows('Katja', ['tags' => [['id' => self::TAG_KATJA]]], [(string)self::TAG_KATJA => 'KATJA']));
		self::assertFalse($tags->allows('Katja', ['tags' => [99]], [(string)self::TAG_KATJA => 'Katja']));
	}

	/** A dry-run of other lines than the saved ones would read other archives, so it needs a save first; the same lines in another case do not. */
	public function testADryRunNeedsTheLinesSaved(): void {
		try {
			$this->configService->validateForDryRun(['user_tags' => 'Katja = Taxes'] + self::validSettings());
			self::fail('The lines decide which archives a run reads.');
		} catch (\OCA\PaperlessSync\Service\UnsavedSettingException $exception) {
			self::assertSame('user_tags', $exception->setting);
		}
		self::assertSame('Katja = katja, HOUSEHOLD', $this->configService->validateForDryRun(['user_tags' => "katja = katja, HOUSEHOLD\n"] + self::validSettings())->userTags);
	}

	private function save(string $userTags = 'Katja = Katja, Household', string $ownership = SyncConfig::SINGLE): SyncConfig {
		return $this->configService->save(['enabled' => true] + self::validSettings() + ['ownership' => $ownership, 'user_tags' => $userTags], 'test-token');
	}

	/** @param list<array<string, mixed>> $documents */
	private function sync(array $documents): SyncReport {
		$this->paperless->documents = $documents;
		foreach ($documents as $document) {
			$this->paperless->contents[(int)$document['id']] = '%PDF-content';
		}

		return $this->service->run();
	}

	/**
	 * @param list<int> $tags
	 * @param list<int> $viewers
	 * @return array<string, mixed>
	 */
	private function document(int $id, array $tags, ?int $owner = null, array $viewers = []): array {
		return [
			'id' => $id,
			'title' => 'Power August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'tags' => $tags,
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
			'owner' => $owner,
			'permissions' => ['view' => ['users' => $viewers, 'groups' => []], 'change' => ['users' => [], 'groups' => []]],
		];
	}

	private function path(int $id): string {
		return self::ARCHIVE . "/Energy Ltd/Invoice/2026/2026-08-26 - Power August [P{$id}].pdf";
	}

	/** @return array<string, string> */
	private function filesOf(string $user): array {
		return $this->storage->filesOfOthers[$user] ?? [];
	}

	/** @return list<string> the users whose Activity streams have entries, each once */
	private function activityUsers(): array {
		return $this->sorted(array_values(array_unique(array_map(static fn (\OCA\PaperlessSync\Tests\Doubles\TestActivityEvent $event): string => $event->getAffectedUser(), $this->activities))));
	}

	/**
	 * @param list<string> $values
	 * @return list<string>
	 */
	private function sorted(array $values): array {
		sort($values);

		return $values;
	}
}
