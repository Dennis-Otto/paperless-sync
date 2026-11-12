<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Command\Status;
use OCA\PaperlessSync\Controller\TagSharesController;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\ConnectionChecklist;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\TagShares;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCP\AppFramework\Http;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Teams\ITeamManager;
use OCP\Teams\Team;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A share of the archive that the target user made by hand would show a user with tags every
 * document in it, whatever their tags: the checklist, the settings page and occ name each, and
 * the page removes those that it listed, and no other share, on request. The target user is
 * paperless, with the base folder Documents/Paperless; Katja may see the tag Katja, and alice,
 * who is in the group family with Katja, may see everything. Both are in the Team of the family,
 * alice also in that of the club; the Teams, Talk and Deck are installed, unless a test takes them
 * away.
 */
#[AllowMockObjectsWithoutExpectations]
final class TagSharesTest extends TestCase {
	use InMemoryConfiguration;

	/** @var list<array{type: int, share: IShare}> the shares of the target user */
	private array $shares = [];
	/** @var list<string> the full IDs of the shares that were removed */
	private array $deleted = [];
	private ?\Throwable $listFailure = null;
	private ?\Throwable $deleteFailure = null;
	/** @var array<string, list<string>> the IDs of the Teams of each user */
	private array $teamsOfUsers = ['Katja' => ['team-family'], 'alice' => ['team-family', 'team-club']];
	private ?\Throwable $teamFailure = null;
	/** @var list<int> the types of the shares of an app that is installed */
	private array $providers = [IShare::TYPE_CIRCLE, IShare::TYPE_ROOM, IShare::TYPE_DECK];
	private ConfigService $configService;
	private TagShares $tagShares;

	protected function setUp(): void {
		$this->usersOfNextcloud = ['paperless', 'Katja', 'alice'];
		$this->groupsOfNextcloud = ['admin', 'family'];
		$this->membersOfGroups = ['family' => ['Katja', 'alice']];
		$this->configService = $this->configService();
		$this->save('Katja = Katja');
		$manager = $this->createStub(IManager::class);
		$manager->method('shareProviderExists')->willReturnCallback(fn (int $type): bool => in_array($type, $this->providers, true));
		$manager->method('getSharesBy')->willReturnCallback(function (string $user, int $type, ?Node $node, bool $reshares, int $limit): array {
			self::assertSame(['paperless', null, true, -1], [$user, $node, $reshares, $limit]);
			self::assertTrue(in_array($type, [IShare::TYPE_USER, IShare::TYPE_GROUP, ...$this->providers], true), 'Nextcloud fails to list the shares of an app that is missing.');
			if ($this->listFailure !== null) {
				throw $this->listFailure;
			}

			return array_values(array_map(static fn (array $entry): IShare => $entry['share'], array_filter($this->shares, static fn (array $entry): bool => $entry['type'] === $type)));
		});
		$manager->method('deleteShare')->willReturnCallback(function (IShare $share): void {
			if ($this->deleteFailure !== null) {
				throw $this->deleteFailure;
			}
			$this->deleted[] = (string)$share->getFullId();
			$this->shares = array_values(array_filter($this->shares, static fn (array $entry): bool => $entry['share'] !== $share));
		});
		$teams = $this->createStub(ITeamManager::class);
		$teams->method('getTeamsForUser')->willReturnCallback(function (string $user): array {
			if ($this->teamFailure !== null) {
				throw $this->teamFailure;
			}

			return array_map(static fn (string $id): Team => new Team($id, 'Team ' . $id, null), $this->teamsOfUsers[$user] ?? []);
		});
		$this->tagShares = new TagShares($manager, $this->groupsOfNextcloud(), $teams);
	}

	/**
	 * A share of the base folder, of a folder or a file in it, or of a folder that holds it, with
	 * Katja or with a group that she is in, shows her more than her tags; a share of another folder,
	 * one with a user without tags and one whose file is gone don't.
	 */
	public function testTheSharesThatShowAUserWithTagsMoreThanHerTags(): void {
		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'katja');
		$this->share(2, IShare::TYPE_GROUP, 'Documents', 'family');
		$this->share(3, IShare::TYPE_USER, 'Documents/Paperless/Archive/Energy Ltd/bill.pdf', 'Katja');
		$this->share(4, IShare::TYPE_USER, 'Documents/Paperless', 'alice');
		$this->share(5, IShare::TYPE_USER, 'Documents/Paperless Other', 'Katja');
		$this->share(6, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'Katja', gone: true);
		$this->share(7, IShare::TYPE_USER, '', 'Katja');

		self::assertSame([
			['id' => 'ocinternal:7', 'path' => '/', 'type' => 'user', 'with' => 'Katja', 'users' => ['Katja'], 'known' => true],
			['id' => 'ocinternal:2', 'path' => '/Documents', 'type' => 'group', 'with' => 'family', 'users' => ['Katja'], 'known' => true],
			['id' => 'ocinternal:1', 'path' => '/Documents/Paperless/Archive', 'type' => 'user', 'with' => 'katja', 'users' => ['Katja'], 'known' => true],
			['id' => 'ocinternal:3', 'path' => '/Documents/Paperless/Archive/Energy Ltd/bill.pdf', 'type' => 'user', 'with' => 'Katja', 'users' => ['Katja'], 'known' => true],
		], $this->tagShares->find($this->configService->get()));
	}

	/**
	 * A share with a Team that Katja is in shows her more than her tags, as one with a group does;
	 * one with a Team without a user with tags doesn't. Nextcloud doesn't name the members of a
	 * conversation of Talk or a board of Deck, so a share with one may reach her: it counts, with
	 * its name, or its ID without one (#455).
	 */
	public function testTheSharesWithATeamAConversationOrABoard(): void {
		$this->share(1, IShare::TYPE_CIRCLE, 'Documents/Paperless', 'team-family', 'Family');
		$this->share(2, IShare::TYPE_CIRCLE, 'Documents/Paperless', 'team-club', 'Club');
		$this->share(3, IShare::TYPE_ROOM, 'Documents/Paperless/Archive', 'k7x2p9qe', 'Household');
		$this->share(4, IShare::TYPE_DECK, 'Documents', '12');
		$this->share(5, IShare::TYPE_ROOM, 'Documents/Paperless Other', 'w3n8r1ta', 'Elsewhere');

		$shares = $this->tagShares->find($this->configService->get());
		self::assertSame([
			['id' => 'ocinternal:4', 'path' => '/Documents', 'type' => 'board', 'with' => '12', 'users' => [], 'known' => false],
			['id' => 'ocinternal:1', 'path' => '/Documents/Paperless', 'type' => 'team', 'with' => 'Family', 'users' => ['Katja'], 'known' => true],
			['id' => 'ocinternal:3', 'path' => '/Documents/Paperless/Archive', 'type' => 'conversation', 'with' => 'Household', 'users' => [], 'known' => false],
		], $shares);
		self::assertSame([
			'/Documents → the members of the Deck board 12',
			'/Documents/Paperless → Katja (Team Family)',
			'/Documents/Paperless/Archive → the members of the Talk conversation Household',
		], array_map(TagShares::describe(...), $shares));
	}

	/** When Nextcloud fails to list the Teams of a user, a share with a Team may reach her, and the check goes on. */
	public function testAShareWithATeamThatNextcloudDoesNotResolve(): void {
		$this->share(1, IShare::TYPE_CIRCLE, 'Documents/Paperless', 'team-family', 'Family');
		$this->teamFailure = new \RuntimeException('The Teams are gone.');

		$shares = $this->tagShares->find($this->configService->get());
		self::assertSame([['id' => 'ocinternal:1', 'path' => '/Documents/Paperless', 'type' => 'team', 'with' => 'Family', 'users' => [], 'known' => false]], $shares);
		self::assertSame(['/Documents/Paperless → the members of the Team Family'], array_map(TagShares::describe(...), $shares));
	}

	/** Without the Teams, Talk and Deck, Nextcloud has none of their shares, and the check asks for none. */
	public function testWithoutTheAppsOfTheSharesTheCheckAsksForNone(): void {
		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless', 'Katja');
		$this->share(2, IShare::TYPE_CIRCLE, 'Documents/Paperless', 'team-family', 'Family');
		$this->share(3, IShare::TYPE_ROOM, 'Documents/Paperless', 'k7x2p9qe', 'Household');
		$this->providers = [];

		self::assertSame(['ocinternal:1'], array_column($this->tagShares->find($this->configService->get()), 'id'));
	}

	/** A removal takes a listed share that may reach a user with tags as well. */
	public function testARemovalTakesAListedShareThatMayReachAUserWithTags(): void {
		$this->share(3, IShare::TYPE_ROOM, 'Documents/Paperless/Archive', 'k7x2p9qe', 'Household');

		self::assertSame(1, $this->tagShares->remove($this->configService->get(), ['ocinternal:3']));
		self::assertSame(['ocinternal:3'], $this->deleted);
	}

	/** Without a user with tags, no share shows anybody more than they may see, and nothing is asked. */
	public function testWithoutAUserWithTagsNoShareCounts(): void {
		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless', 'Katja');
		$this->listFailure = new \RuntimeException('Not asked.');
		$config = $this->save('');

		self::assertSame([], $this->tagShares->find($config));
		self::assertNull($this->tagShares->check($config));
	}

	/** A removal takes the listed shares that still show her more, and none other. */
	public function testARemovalTakesOnlyTheListedSharesThatStillCount(): void {
		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'Katja');
		$this->share(2, IShare::TYPE_GROUP, 'Documents/Paperless', 'family');
		$this->share(4, IShare::TYPE_USER, 'Documents/Paperless', 'alice');

		self::assertSame(1, $this->tagShares->remove($this->configService->get(), ['ocinternal:1', 'ocinternal:4', 'ocinternal:9']));
		self::assertSame(['ocinternal:1'], $this->deleted);
	}

	/** The line of the checklist: passed without such a share, failed with each, and a note when Nextcloud can't list them. */
	public function testTheLineOfTheChecklist(): void {
		$config = $this->configService->get();
		$passed = $this->tagShares->check($config);
		self::assertNotNull($passed);
		self::assertSame([ConnectionCheck::TAG_SHARES, ConnectionCheck::PASSED, 'No share of %s shows a user with tags more than their tags.', ['paperless']], [$passed->id, $passed->status, $passed->message, $passed->parameters]);

		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'Katja');
		$this->share(2, IShare::TYPE_GROUP, 'Documents', 'family');
		$failed = $this->tagShares->check($config);
		self::assertNotNull($failed);
		self::assertSame(ConnectionCheck::FAILED, $failed->status);
		self::assertSame(['paperless', '/Documents → Katja (family); /Documents/Paperless/Archive → Katja'], $failed->parameters);
		self::assertSame('user_tags', $failed->setting);
		self::assertFalse($failed->blocks(), 'The copies are right, so a save goes on.');

		$this->share(3, IShare::TYPE_ROOM, 'Documents/Paperless/Archive', 'k7x2p9qe', 'Household');
		$both = $this->tagShares->check($config);
		self::assertNotNull($both);
		self::assertSame([ConnectionCheck::FAILED, 'These shares of %1$s show a user with tags every document in them: %2$s. These may too, as Nextcloud does not name who is in them: %3$s.'], [$both->status, $both->message]);
		self::assertSame(['paperless', '/Documents → Katja (family); /Documents/Paperless/Archive → Katja', '/Documents/Paperless/Archive → the members of the Talk conversation Household'], $both->parameters);

		$this->listFailure = new \RuntimeException('The database is gone.');
		$warning = $this->tagShares->check($config);
		self::assertNotNull($warning);
		self::assertSame([ConnectionCheck::WARNING, ['paperless', 'The database is gone.']], [$warning->status, $warning->parameters]);
	}

	/** A share that may reach a user with tags, and none that surely does, is a warning in place of the pass (#455). */
	public function testTheLineOfTheChecklistWarnsOfAShareThatMayReachAUserWithTags(): void {
		$this->share(3, IShare::TYPE_ROOM, 'Documents/Paperless/Archive', 'k7x2p9qe', 'Household');

		$warning = $this->tagShares->check($this->configService->get());
		self::assertNotNull($warning);
		self::assertSame([ConnectionCheck::TAG_SHARES, ConnectionCheck::WARNING, 'These shares of %1$s may show a user with tags every document in them, as Nextcloud does not name who is in them: %2$s.'], [$warning->id, $warning->status, $warning->message]);
		self::assertSame(['paperless', '/Documents/Paperless/Archive → the members of the Talk conversation Household'], $warning->parameters);
		self::assertSame('user_tags', $warning->setting);
		self::assertFalse($warning->blocks(), 'The copies are right, so a save goes on.');
	}

	/**
	 * The checklist names a tag that Paperless doesn't have, and the shares, beside its other lines;
	 * a failure of the list of tags is a note.
	 */
	public function testTheChecklistNamesTheTagsAndTheShares(): void {
		$paperless = new TestPaperlessClient();
		$paperless->tagInfo = ['names' => ['11' => 'Katja'], 'inbox' => []];
		$storage = $this->createStub(NextcloudStorageInterface::class);
		$english = $this->createStub(IL10N::class);
		$checklist = new ConnectionChecklist($paperless, $storage, $this->ownerMapping(), $english, $this->tagShares);
		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'Katja');

		$checks = $checklist->checks($this->configService->get(), 'token');
		self::assertSame([ConnectionCheck::PAPERLESS, ConnectionCheck::USER_TAGS, ConnectionCheck::FOLDER, ConnectionCheck::TAG_SHARES], array_map(static fn (ConnectionCheck $check): string => $check->id, $checks));
		self::assertSame([ConnectionCheck::PASSED, ConnectionCheck::FAILED], [$checks[1]->status, $checks[3]->status]);

		$this->usersOfNextcloud[] = 'bob';
		$checks = $checklist->checks($this->save("Katja = Katja, Housework\nbob = Taxes"), 'token');
		self::assertSame([ConnectionCheck::WARNING, ['Katja: "Housework"; bob: "Taxes"'], 'user_tags'], [$checks[1]->status, $checks[1]->parameters, $checks[1]->setting]);

		$paperless->tagsException = new \RuntimeException('Paperless is down.');
		$checks = $checklist->checks($this->configService->get(), 'token');
		self::assertSame([ConnectionCheck::WARNING, ['Paperless is down.']], [$checks[1]->status, $checks[1]->parameters]);
	}

	/** The settings page lists the shares and removes those that it listed, with the password confirmed. */
	public function testTheSettingsPageListsAndRemovesTheShares(): void {
		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'Katja');
		$this->share(2, IShare::TYPE_GROUP, 'Documents', 'family');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$controller = new TagSharesController('paperless_sync', $this->createStub(IRequest::class), $this->configService, $this->tagShares, $l10n);

		$listed = $controller->shares()->getData();
		self::assertIsArray($listed);
		self::assertSame(['ocinternal:2', 'ocinternal:1'], array_column($listed['shares'], 'id'));

		$answer = $controller->remove(['ocinternal:1', 7]);
		self::assertSame(['ocinternal:1'], $this->deleted);
		self::assertSame(1, $answer->getData()['removed']);
		self::assertSame(['ocinternal:2'], array_column($answer->getData()['shares'], 'id'));

		$this->deleteFailure = new \RuntimeException('Locked.');
		$refused = $controller->remove(['ocinternal:2']);
		self::assertSame([Http::STATUS_BAD_GATEWAY, ['message' => 'Nextcloud did not remove the shares: Locked.']], [$refused->getStatus(), $refused->getData()]);

		$this->listFailure = new \RuntimeException('The database is gone.');
		$failed = $controller->shares();
		self::assertSame([Http::STATUS_BAD_GATEWAY, ['message' => 'Nextcloud did not list the shares of paperless: The database is gone.']], [$failed->getStatus(), $failed->getData()]);
	}

	/** occ paperless_sync:status names each such share, also in its JSON. */
	public function testOccNamesTheShares(): void {
		$clock = new TestClock();
		$command = new CommandTester(new Status($this->statusService($clock), $this->configService, $clock, $this->tagShares));
		$command->execute([]);
		self::assertStringNotContainsString('These shares', $command->getDisplay());

		$this->share(1, IShare::TYPE_USER, 'Documents/Paperless/Archive', 'Katja');
		$command->execute([]);
		self::assertStringContainsString("These shares of paperless show a user with tags every document in them; remove them under \"Tags that a user may see\" of the settings, or in Files:\n  /Documents/Paperless/Archive → Katja (share ocinternal:1)\n", $command->getDisplay());

		$command->execute(['--output' => 'json']);
		$json = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		self::assertSame('ocinternal:1', $json['tagShares'][0]['id']);

		$this->share(3, IShare::TYPE_ROOM, 'Documents/Paperless/Archive', 'k7x2p9qe', 'Household');
		$command->execute([]);
		self::assertStringContainsString("  /Documents/Paperless/Archive → Katja (share ocinternal:1)\nThese shares of paperless may show a user with tags every document in them, as Nextcloud does not name who is in them; check who is, and remove those that reach a user with tags under \"Tags that a user may see\" of the settings, or in Files:\n  /Documents/Paperless/Archive → the members of the Talk conversation Household (share ocinternal:3)\n", $command->getDisplay());
	}

	private function save(string $userTags): SyncConfig {
		return $this->configService->save(self::validSettings() + ['user_tags' => $userTags], 'test-token');
	}

	/** A share of the target user, of the path in their files, with a user, a group, a Team, a conversation or a board, by its name. */
	private function share(int $id, int $type, string $path, string $with, ?string $name = null, bool $gone = false): void {
		$node = $this->createStub(Node::class);
		$node->method('getPath')->willReturn(rtrim('/paperless/files/' . $path, '/'));
		$share = $this->createStub(IShare::class);
		if ($gone) {
			$share->method('getNode')->willThrowException(new NotFoundException());
		} else {
			$share->method('getNode')->willReturn($node);
		}
		$share->method('getSharedWith')->willReturn($with);
		$share->method('getSharedWithDisplayName')->willReturn($name);
		$share->method('getFullId')->willReturn('ocinternal:' . $id);
		$this->shares[] = ['type' => $type, 'share' => $share];
	}
}
