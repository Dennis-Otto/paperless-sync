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
		$this->membersOfGroups = ['scanners' => ['alice']];

		$owners = $this->owners(['user_group' => 'scanners'], ['1' => 'alice', '2' => 'bob']);

		self::assertSame(['alice', 'paperless'], [$owners->userOf(1), $owners->userOf(2)]);
		self::assertSame(['id' => 2, 'name' => 'bob', 'user' => null, 'reason' => Owners::OUTSIDE_GROUP, 'detail' => 'bob'], $owners->routes()[1]);
	}

	/** A user of Nextcloud of the table that was deleted after the save gets nothing, and the checklist says so. */
	public function testAUserOfTheTableThatIsGoneGetsNothing(): void {
		$config = $this->config(['user_map' => 'alice = bob.smith']);
		$this->usersOfNextcloud = ['paperless', 'alice'];

		$owners = $this->ownerMapping()->owners($config, ['1' => 'alice']);

		self::assertSame('paperless', $owners->userOf(1), 'Nor does the user with the same ID: the table names the user of Paperless.');
		self::assertSame(Owners::UNKNOWN, $owners->routes()[0]['reason']);
		self::assertSame('bob.smith', $owners->routes()[0]['detail']);
	}

	public function testALineOfTheTableWithoutAUserOfPaperlessIsNamed(): void {
		$owners = $this->owners(['user_map' => "nobody = alice\nAlice = alice"], ['1' => 'alice']);

		self::assertSame(['nobody'], $owners->unmatched);
		self::assertSame('alice', $owners->userOf(1));
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

	/** Without the users of Paperless, a run of per-user ownership fails, and the checklist says which permission is missing. */
	public function testTheChecklistFailsWithoutTheUsersOfPaperless(): void {
		$paperless = new TestPaperlessClient();
		$paperless->usersException = new PaperlessRequestException('Paperless answered HTTP 403.');

		$checks = $this->ownerMapping()->checks($this->config(), $paperless, new TestStorage());

		self::assertCount(1, $checks);
		self::assertSame([ConnectionCheck::OWNERS, ConnectionCheck::FAILED, ['Paperless answered HTTP 403.'], false], [$checks[0]->id, $checks[0]->status, $checks[0]->parameters, $checks[0]->blocks()]);
		self::assertStringContainsString('View for Users', $checks[0]->hint);
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
