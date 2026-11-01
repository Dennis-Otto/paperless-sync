<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\OwnerMapping;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class OwnerMappingTest extends TestCase {
	use InMemoryConfiguration;

	protected function setUp(): void {
		$this->usersOfNextcloud = ['paperless', 'alice', 'bob', 'bob.smith'];
	}

	/** Single sign-on, such as OpenID Connect, gives a user the same ID in both, whatever its case. */
	public function testAUserOfPaperlessGetsTheUserOfNextcloudWithItsId(): void {
		$owners = $this->owners([], ['1' => 'alice', '2' => 'Bob', '3' => 'carol']);

		self::assertSame(['alice', 'bob', 'paperless'], [$owners->userOf(1), $owners->userOf(2), $owners->userOf(3)]);
		self::assertSame([Owners::NAME, Owners::NAME, Owners::NONE], array_column($owners->routes(), 'reason'));
		self::assertSame(['paperless', 'alice', 'bob'], $owners->users());
	}

	public function testTheTableWinsOverTheSameId(): void {
		$owners = $this->owners(['user_map' => 'bob = bob.smith'], ['1' => 'alice', '2' => 'Bob']);

		self::assertSame(['alice', 'bob.smith'], [$owners->userOf(1), $owners->userOf(2)]);
		self::assertSame(['id' => 2, 'name' => 'Bob', 'user' => 'bob.smith', 'reason' => Owners::TABLE, 'detail' => 'bob.smith'], $owners->routes()[1]);
	}

	/** Which of the two is meant is not for the app to guess: the runs hold the documents of both, whose copies stay where they are. */
	public function testTwoUsersOfPaperlessForOneUserOfNextcloudGetNeither(): void {
		$owners = $this->owners([], ['1' => 'alice', '2' => 'Alice', '3' => 'bob']);

		self::assertSame([null, null, 'bob'], [$owners->userOf(1), $owners->userOf(2), $owners->userOf(3)]);
		self::assertSame([
			['id' => 1, 'name' => 'alice', 'user' => null, 'reason' => Owners::SHARED, 'detail' => 'alice (Alice)'],
			['id' => 2, 'name' => 'Alice', 'user' => null, 'reason' => Owners::SHARED, 'detail' => 'alice (alice)'],
		], array_slice($owners->routes(), 0, 2));
		self::assertNull($owners->paperlessUserOf('alice'), 'Alice gets no documents of either.');
	}

	public function testATableThatGivesTwoUsersOfPaperlessOneUserOfNextcloudGivesItNeither(): void {
		$owners = $this->owners(['user_map' => 'carol = alice'], ['1' => 'alice', '3' => 'carol']);

		self::assertSame([null, null], [$owners->userOf(1), $owners->userOf(3)]);
		self::assertSame(['paperless'], $owners->users());
	}

	public function testOnlyMembersOfTheGroupGetTheirDocuments(): void {
		$this->membersOfGroups = ['scanners' => ['alice', 'bob.smith']];

		$owners = $this->owners(['user_group' => 'scanners'], ['1' => 'alice', '2' => 'bob', '3' => 'bob.smith']);

		self::assertSame(['alice', 'paperless', 'bob.smith'], [$owners->userOf(1), $owners->userOf(2), $owners->userOf(3)], 'A user outside the group leaves the users after them alone.');
		self::assertSame(['id' => 2, 'name' => 'bob', 'user' => null, 'reason' => Owners::OUTSIDE_GROUP, 'detail' => 'bob'], $owners->routes()[1]);
	}

	/** A user of Nextcloud of the table that was deleted after the save gets nothing, and the checklist says so. */
	public function testAUserOfTheTableThatIsGoneGetsNothing(): void {
		$config = $this->config(['user_map' => 'alice = bob.smith']);
		$this->usersOfNextcloud = ['paperless', 'alice', 'bob'];

		$owners = $this->ownerMapping()->owners($config, ['1' => 'alice', '2' => 'bob']);

		self::assertSame('paperless', $owners->userOf(1), 'Nor does the user with the same ID: the table names the user of Paperless.');
		self::assertSame('bob', $owners->userOf(2), 'The users after them get their documents.');
		self::assertSame(Owners::UNKNOWN, $owners->routes()[0]['reason']);
		self::assertSame('bob.smith', $owners->routes()[0]['detail']);
	}

	public function testALineOfTheTableWithoutAUserOfPaperlessIsNamed(): void {
		$owners = $this->owners(['user_map' => "nobody = alice\nAlice = alice"], ['1' => 'alice']);

		self::assertSame(['nobody'], $owners->unmatched);
		self::assertSame('alice', $owners->userOf(1));
	}

	/**
	 * A name holds letters beyond ASCII, such as Jürgen, and matches whatever its case: in the
	 * table, in Paperless and in the ID of a user of Nextcloud, as a backend such as LDAP gives it.
	 */
	public function testANameBeyondAsciiMatchesWhateverItsCase(): void {
		$this->usersOfNextcloud[] = 'Ölaf';

		$owners = $this->owners(['user_map' => "JÜRGEN = bob.smith\nzoë = alice"], ['1' => 'jürgen', '2' => 'ZOË', '3' => 'ölaf']);

		self::assertSame(['bob.smith', 'alice', 'Ölaf'], [$owners->userOf(1), $owners->userOf(2), $owners->userOf(3)]);
		self::assertSame([Owners::TABLE, Owners::TABLE, Owners::NAME], array_column($owners->routes(), 'reason'));
		self::assertSame([], $owners->unmatched, 'Each line of the table names a user of Paperless.');
	}

	/**
	 * A table that no save checked, such as one that occ config:app:set wrote, may hold a line
	 * that is no mapping: a run leaves that line out and follows the others.
	 */
	public function testARunLeavesOutAStoredLineThatIsNoMapping(): void {
		$this->config();
		$this->settings['user_map'] = "jdoe john.doe\nbob = bob.smith\nnobody = alice";

		$owners = $this->ownerMapping()->owners($this->configService()->get(), ['1' => 'jdoe', '2' => 'bob']);

		self::assertSame(['paperless', 'bob.smith'], [$owners->userOf(1), $owners->userOf(2)]);
		self::assertSame(['nobody'], $owners->unmatched);
	}

	/** A backend of Nextcloud may find a user by another name, such as an e-mail address; only the same ID counts. */
	public function testOnlyAUserOfNextcloudWithTheSameIdCounts(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice.smith');
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($user);

		$owners = (new OwnerMapping($users, $this->groupsOfNextcloud()))->owners($this->config(), ['1' => 'alice']);

		self::assertSame('paperless', $owners->userOf(1));
		self::assertSame(Owners::NONE, $owners->routes()[0]['reason']);
	}

	public function testReadsTheLinesOfTheTable(): void {
		self::assertSame([
			['line' => 1, 'paperless' => 'jdoe', 'nextcloud' => 'john.doe'],
			['line' => 3, 'paperless' => 'Max Muster', 'nextcloud' => 'max'],
		], OwnerMapping::parse("  jdoe =john.doe \n\n Max Muster = max \r\n"));
		self::assertSame([], OwnerMapping::parse(''));
	}

	/** @return array<string, array{string, string}> */
	public static function linesThatAreNoMapping(): array {
		return [
			'without an equals sign' => ["alice = alice\njdoe john.doe", '2'],
			'before a mapping' => ["jdoe john.doe\nalice = alice", '1'],
			'without a user of Paperless' => ['= john.doe', '1'],
			'without a user of Nextcloud' => ['jdoe =', '1'],
			'with two equals signs' => ['jdoe = john = doe', '1'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('linesThatAreNoMapping')]
	public function testALineThatIsNoMappingIsRefusedWithItsNumber(string $map, string $line): void {
		try {
			OwnerMapping::parse($map);
			self::fail('The line is no mapping.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('user_map', $exception->setting);
			self::assertStringContainsString("line {$line}:", $exception->getMessage());
		}
		self::assertCount(substr_count($map, "\n"), OwnerMapping::parse($map, false), 'A run leaves such a line out.');
	}

	public function testKnowsWhetherAUserOfNextcloudExists(): void {
		self::assertSame([true, true, false], [$this->ownerMapping()->exists('alice'), $this->ownerMapping()->exists('ALICE'), $this->ownerMapping()->exists('ghost')]);
	}

	/** The connection checklist shows who gets the documents of whom, with their number, and what to change for a mistake. */
	public function testTheChecklistShowsWhoGetsWhichDocuments(): void {
		$this->membersOfGroups = ['scanners' => ['alice', 'bob', 'bob.smith']];
		$config = $this->config(['user_map' => "dave = bob.smith\nerin = bob.smith\nnobody = alice", 'user_group' => 'scanners']);
		$this->usersOfNextcloud[] = 'frank';
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice', '2' => 'bob', '3' => 'carol', '4' => 'dave', '5' => 'erin', '6' => 'frank'];
		$paperless->documents = [['id' => 1, 'owner' => 1], ['id' => 2, 'owner' => 1], ['id' => 3, 'owner' => 3], ['id' => 4, 'owner' => null]];
		$storage = new TestStorage();
		$storage->testFailures['bob'] = new \RuntimeException('The configured Nextcloud base folder is not a writable folder.');

		$checks = $this->ownerMapping()->checks($config, $paperless, $storage);

		self::assertSame([
			[ConnectionCheck::OWNERS, ConnectionCheck::PASSED, '%1$s → %2$s, documents: %3$s', ['alice', 'alice', '2'], ''],
			[ConnectionCheck::OWNERS, ConnectionCheck::PASSED, '%1$s → %2$s, documents: %3$s', ['bob', 'bob', '0'], ''],
			[ConnectionCheck::OWNERS, ConnectionCheck::PASSED, '%1$s → %2$s, the target user, as Nextcloud has no user with this ID; documents: %3$s', ['carol', 'paperless', '1'], ''],
			[ConnectionCheck::OWNERS, ConnectionCheck::WARNING, '%1$s → nobody, as other users of Paperless have the user of Nextcloud %2$s as well, so their documents stay where their copies are; documents: %3$s', ['dave', 'bob.smith (erin)', '0'], 'user_map'],
			[ConnectionCheck::OWNERS, ConnectionCheck::WARNING, '%1$s → nobody, as other users of Paperless have the user of Nextcloud %2$s as well, so their documents stay where their copies are; documents: %3$s', ['erin', 'bob.smith (dave)', '0'], 'user_map'],
			[ConnectionCheck::OWNERS, ConnectionCheck::PASSED, '%1$s → %2$s, the target user, as %3$s is no member of the group; documents: %4$s', ['frank', 'paperless', 'frank', '0'], ''],
			[ConnectionCheck::OWNERS, ConnectionCheck::PASSED, 'Without an owner → %1$s, the target user, documents: %2$s', ['paperless', '1'], ''],
			[ConnectionCheck::OWNERS, ConnectionCheck::WARNING, 'The table names %s, but Paperless has no user of that name.', ['nobody'], 'user_map'],
			[ConnectionCheck::FOLDER, ConnectionCheck::WARNING, 'Nextcloud cannot store the files of %1$s there: %2$s', ['bob', 'The configured Nextcloud base folder is not a writable folder.'], ''],
		], array_map(static fn (ConnectionCheck $check): array => [$check->id, $check->status, $check->message, $check->parameters, $check->setting], $checks));
		self::assertFalse(array_filter($checks, static fn (ConnectionCheck $check): bool => $check->blocks()) !== [], 'A mistake of the mapping blocks no save.');
	}

	public function testTheChecklistNamesAUserOfTheTableThatIsGone(): void {
		$config = $this->config(['user_map' => 'alice = bob.smith']);
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice'];

		$check = $this->ownerMapping()->checks($config, $paperless, new TestStorage())[0];

		self::assertSame([ConnectionCheck::WARNING, ['alice', 'paperless', 'bob.smith', '0'], 'user_map'], [$check->status, $check->parameters, $check->setting]);
		self::assertSame('Correct the line of the user in "Paperless users with another Nextcloud ID".', $check->hint);
	}

	/** Each user of Paperless costs a question for the number of its documents, so the checklist names the first ones. */
	public function testTheChecklistNamesTheFirstUsersOfPaperless(): void {
		$paperless = new TestPaperlessClient();
		foreach (range(1, OwnerMapping::CHECKED_USERS + 2) as $id) {
			$paperless->users[(string)$id] = sprintf('user%02d', $id);
		}

		$checks = $this->ownerMapping()->checks($this->config(), $paperless, new TestStorage());

		self::assertCount(OwnerMapping::CHECKED_USERS + 2, $checks);
		self::assertSame(['And %s more users of Paperless, which a run gives to their users of Nextcloud in the same way.', ['2']], [$checks[OwnerMapping::CHECKED_USERS]->message, $checks[OwnerMapping::CHECKED_USERS]->parameters]);
	}

	public function testTheChecklistNamesEveryUserOfPaperlessUpToItsLimit(): void {
		$paperless = new TestPaperlessClient();
		foreach (range(1, OwnerMapping::CHECKED_USERS) as $id) {
			$paperless->users[(string)$id] = sprintf('user%02d', $id);
		}

		$checks = $this->ownerMapping()->checks($this->config(), $paperless, new TestStorage());

		self::assertCount(OwnerMapping::CHECKED_USERS + 1, $checks, 'A line for each user, and one for the documents without an owner.');
		self::assertSame('Without an owner → %1$s, the target user, documents: %2$s', $checks[OwnerMapping::CHECKED_USERS]->message);
	}

	/** A user of the table gets their line as any other. */
	public function testTheChecklistShowsTheUserOfTheTable(): void {
		$paperless = new TestPaperlessClient();
		$paperless->users = ['3' => 'carol'];
		$paperless->documents = [['id' => 1, 'owner' => 3]];

		$check = $this->ownerMapping()->checks($this->config(['user_map' => 'carol = bob.smith']), $paperless, new TestStorage())[0];

		self::assertSame([ConnectionCheck::PASSED, '%1$s → %2$s, documents: %3$s', ['carol', 'bob.smith', '1']], [$check->status, $check->message, $check->parameters]);
	}

	/**
	 * The checklist tests the base folder of each user who gets documents, but not that of the
	 * target user, which a line of its own tests already.
	 */
	public function testTheChecklistTestsTheFolderOfEachUserButTheTargetUser(): void {
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice', '2' => 'bob', '3' => 'paperless'];
		$storage = new TestStorage();
		$storage->testException = new \RuntimeException('The configured Nextcloud base folder is not a writable folder.');

		$checks = $this->ownerMapping()->checks($this->config(), $paperless, $storage);

		self::assertSame([['alice', 'The configured Nextcloud base folder is not a writable folder.'], ['bob', 'The configured Nextcloud base folder is not a writable folder.']], array_map(
			static fn (ConnectionCheck $check): array => $check->parameters,
			array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => $check->id === ConnectionCheck::FOLDER)),
		));
		self::assertSame(['alice', 'bob'], array_column($storage->calls, 1));
	}

	/** Each test of a folder may take a while, so the checklist tests those of the first users. */
	public function testTheChecklistTestsTheFoldersOfTheFirstUsers(): void {
		$paperless = new TestPaperlessClient();
		foreach (range(1, OwnerMapping::CHECKED_USERS + 2) as $id) {
			$this->usersOfNextcloud[] = $paperless->users[(string)$id] = sprintf('user%02d', $id);
		}
		$storage = new TestStorage();

		$this->ownerMapping()->checks($this->config(), $paperless, $storage);

		self::assertSame(array_map(static fn (int $id): string => sprintf('user%02d', $id), range(1, OwnerMapping::CHECKED_USERS)), array_column($storage->calls, 1));
	}

	/** Without the users of Paperless, a run of per-user ownership fails, and the checklist says which permission is missing. */
	public function testTheChecklistFailsWithoutTheUsersOfPaperless(): void {
		$paperless = new TestPaperlessClient();
		$paperless->usersException = new PaperlessRequestException('Paperless answered HTTP 403.');

		$checks = $this->ownerMapping()->checks($this->config(), $paperless, new TestStorage());

		self::assertCount(1, $checks);
		self::assertSame([ConnectionCheck::OWNERS, ConnectionCheck::FAILED, ['Paperless answered HTTP 403.'], false], [$checks[0]->id, $checks[0]->status, $checks[0]->parameters, $checks[0]->blocks()]);
		self::assertStringContainsString('View for Users', $checks[0]->hint);
	}

	/**
	 * With the documents that Paperless shares, the checklist says who also gets how many documents
	 * from the archive of whom, the owner of a document having no line for it, the target user among
	 * them, and who may view documents without getting a copy, but not the account of the app, which
	 * may view them so that a run can read them. Whose owner the run can't tell has no line.
	 */
	public function testTheChecklistNamesWhoAlsoGetsDocumentsOfWhom(): void {
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice', '2' => 'bob', '3' => 'carol', '4' => '1234', '9' => 'paperless-sync'];
		$paperless->groupMembers = [8 => [2, 3]];
		$paperless->accountId = 9;
		$paperless->documents = [
			self::shared(5, 42, [1], []),
			['id' => 6, 'permissions' => ['view' => ['users' => [1], 'groups' => []]]],
			self::shared(1, 1, [1, 4, 9], [8]),
			self::shared(2, 1, [2, 3], []),
			self::shared(3, 2, [1], []),
			self::shared(4, null, [2], []),
		];

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());

		$lines = array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => str_contains($check->message, 'also gets') || str_contains($check->message, 'may view')));
		self::assertSame([
			['%1$s also gets documents from the archive of %2$s: %3$s', ['alice', 'bob', '1']],
			['%1$s also gets documents from the archive of %2$s: %3$s', ['bob', 'alice', '2']],
			['%1$s also gets documents from the archive of %2$s: %3$s', ['bob', 'paperless', '1']],
			['%1$s may view documents of others in Paperless but gets no copies, without a user of Nextcloud of its own: %2$s', ['1234', '1']],
			['%1$s may view documents of others in Paperless but gets no copies, without a user of Nextcloud of its own: %2$s', ['carol', '2']],
		], array_map(static fn (ConnectionCheck $check): array => [$check->message, $check->parameters], $lines));
		self::assertSame([ConnectionCheck::PASSED], array_values(array_unique(array_map(static fn (ConnectionCheck $check): string => $check->status, $lines))));
		self::assertSame([true], $paperless->permissionsAsked);
		self::assertSame([false], $paperless->notesAsked, 'The checklist has no use for the notes of the documents.');
	}

	/** A viewer without a user of Nextcloud has a line. */
	public function testTheChecklistNamesAViewerWithoutAUser(): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = 9;
		$paperless->users = ['1' => 'alice', '3' => 'carol'];
		$paperless->documents = [self::shared(1, 1, [3], [])];

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());

		self::assertSame(['carol', '1'], end($checks)->parameters);
	}

	/** The lines of who gets what have a limit of their own, as the lines of the users do. */
	public function testTheChecklistNamesWhoAlsoGetsDocumentsUpToItsLimit(): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = 999;
		$documents = [];
		foreach (range(1, OwnerMapping::CHECKED_USERS + 3) as $id) {
			$this->usersOfNextcloud[] = $paperless->users[(string)$id] = sprintf('user%02d', $id);
			$documents[] = self::shared($id, 1, [$id], []);
		}
		$paperless->documents = $documents;

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());
		$lines = array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => str_contains($check->message, 'also gets')));

		self::assertCount(OwnerMapping::CHECKED_USERS + 1, $lines);
		self::assertSame(['user02', 'user01', '1'], $lines[0]->parameters, 'The first lines stay.');
		self::assertSame(['And %s more lines of who also gets which documents, which a run treats in the same way.', ['2']], [$lines[OwnerMapping::CHECKED_USERS]->message, $lines[OwnerMapping::CHECKED_USERS]->parameters]);
	}

	/** As many lines as the limit need no line for the rest. */
	public function testTheChecklistNamesWhoAlsoGetsDocumentsWithoutALineForTheRest(): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = 999;
		$documents = [];
		foreach (range(1, OwnerMapping::CHECKED_USERS + 1) as $id) {
			$this->usersOfNextcloud[] = $paperless->users[(string)$id] = sprintf('user%02d', $id);
			$documents[] = self::shared($id, 1, [$id], []);
		}
		$paperless->documents = $documents;

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());
		$lines = array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => str_contains($check->message, 'also gets')));

		self::assertCount(OwnerMapping::CHECKED_USERS, $lines);
		self::assertStringStartsWith('%1$s also gets', $lines[OwnerMapping::CHECKED_USERS - 1]->message);
	}

	/** A document that doesn't say who may view it, or names a group that Paperless didn't list, gets a warning. */
	public function testTheChecklistWarnsOfDocumentsThatDoNotSayWhoMayViewThem(): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = 9;
		$paperless->users = ['1' => 'alice'];
		$paperless->documents = [['id' => 1, 'owner' => 1], self::shared(2, 1, [], [77])];

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());
		$check = end($checks);

		self::assertSame([ConnectionCheck::WARNING, 'Paperless did not say who may view documents: %s', ['2']], [$check->status, $check->message, $check->parameters]);
		self::assertStringContainsString('proxy', $check->hint);
	}

	/** Without the groups of Paperless, a run fails, and the checklist says which permission is missing. */
	public function testTheChecklistFailsWithoutTheGroupsOfPaperless(): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = 9;
		$paperless->users = ['1' => 'alice'];
		$paperless->groupsException = new PaperlessRequestException('Paperless answered HTTP 403.');

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());

		self::assertCount(1, $checks);
		self::assertSame([ConnectionCheck::OWNERS, ConnectionCheck::FAILED, 'Paperless did not list its groups: %s', ['Paperless answered HTTP 403.'], false], [$checks[0]->id, $checks[0]->status, $checks[0]->message, $checks[0]->parameters, $checks[0]->blocks()]);
		self::assertStringContainsString('View for Groups', $checks[0]->hint);
	}

	/** Without the ID of the account, a run fails, and the checklist says which permission is missing. */
	public function testTheChecklistFailsWithoutTheAccountOfTheApp(): void {
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice'];

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());

		self::assertCount(1, $checks);
		self::assertSame([ConnectionCheck::OWNERS, ConnectionCheck::FAILED, 'Paperless did not name its account.'], [$checks[0]->id, $checks[0]->status, $checks[0]->message]);
		self::assertStringContainsString('View for UI Settings', $checks[0]->hint);
	}

	/** The account of the app may view each document, which gives its user of Nextcloud no line, directly or through a group. */
	public function testTheChecklistGivesTheAccountOfTheAppNoCopy(): void {
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice', '2' => 'bob', '9' => 'paperless'];
		$paperless->groupMembers = [8 => [9]];
		$paperless->accountId = 9;
		$paperless->documents = [self::shared(1, 1, [9], []), self::shared(2, 2, [], [8])];

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());

		self::assertSame([], array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => str_contains($check->message, 'also gets') || str_contains($check->message, 'may view'))));
	}

	/** A scan that fails is a line of its own, after the lines of the users, which it doesn't take away. */
	public function testTheChecklistFailsWithoutTheDocuments(): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = 9;
		$paperless->users = ['1' => 'alice'];
		$paperless->documentsException = new PaperlessRequestException('Paperless answered HTTP 500.');

		$checks = $this->ownerMapping()->checks($this->sharedConfig(), $paperless, new TestStorage());
		$check = end($checks);

		self::assertCount(3, $checks);
		self::assertSame([ConnectionCheck::FAILED, 'Paperless did not list who may view its documents: %s', ['Paperless answered HTTP 500.']], [$check->status, $check->message, $check->parameters]);
	}

	/** Per-user ownership alone reads neither the groups nor who may view each document. */
	public function testTheChecklistOfPerUserOwnershipReadsNoPermissions(): void {
		$paperless = new TestPaperlessClient();
		$paperless->users = ['1' => 'alice'];

		$this->ownerMapping()->checks($this->config(), $paperless, new TestStorage());

		self::assertSame([[], false, 0], [$paperless->permissionsAsked, in_array('groups', $paperless->listsRead, true), $paperless->accountReads]);
	}

	/**
	 * A document with an owner, in the shape of Paperless.
	 *
	 * @param list<int> $users
	 * @param list<int> $groups
	 * @return array<string, mixed>
	 */
	private static function shared(int $id, ?int $owner, array $users, array $groups): array {
		return ['id' => $id, 'owner' => $owner, 'permissions' => ['view' => ['users' => $users, 'groups' => $groups], 'change' => ['users' => [], 'groups' => []]]];
	}

	private function sharedConfig(): SyncConfig {
		return $this->configService()->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER_SHARED], 'secret-token');
	}

	/** @param array<string, string> $settings */
	private function config(array $settings = []): SyncConfig {
		return $this->configService()->save(self::validSettings() + ['ownership' => SyncConfig::PER_USER] + $settings, 'secret-token');
	}

	/**
	 * @param array<string, string> $settings
	 * @param array<string, string> $paperlessUsers
	 */
	private function owners(array $settings, array $paperlessUsers): Owners {
		return $this->ownerMapping()->owners($this->config($settings), $paperlessUsers);
	}
}
