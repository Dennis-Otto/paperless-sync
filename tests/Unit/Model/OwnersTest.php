<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\DocumentPermissions;
use OCA\PaperlessSync\Model\Owners;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnersTest extends TestCase {
	private Owners $owners;

	protected function setUp(): void {
		$this->owners = new Owners('paperless', [
			1 => ['id' => 1, 'name' => 'alice', 'user' => 'alice', 'reason' => Owners::NAME, 'detail' => ''],
			2 => ['id' => 2, 'name' => 'Bob', 'user' => 'bob.smith', 'reason' => Owners::TABLE, 'detail' => 'bob.smith'],
			3 => ['id' => 3, 'name' => 'carol', 'user' => null, 'reason' => Owners::NONE, 'detail' => ''],
			4 => ['id' => 4, 'name' => 'admin', 'user' => 'paperless', 'reason' => Owners::TABLE, 'detail' => 'paperless'],
			5 => ['id' => 5, 'name' => 'dave', 'user' => null, 'reason' => Owners::SHARED, 'detail' => 'erin (Erin)'],
		], ['nobody']);
	}

	/** @return array<string, array{mixed, string|null}> */
	public static function documentOwners(): array {
		return [
			'a user with a user of Nextcloud' => [1, 'alice'],
			'the ID as a string' => ['2', 'bob.smith'],
			'a user without a user of Nextcloud' => [3, 'paperless'],
			'no owner' => [null, 'paperless'],
			'an owner that Paperless did not list' => [42, null],
			'an owner whose user of Nextcloud another one has as well' => [5, null],
			'an owner that is no ID' => ['alice', null],
			'an owner of another type' => [1.5, null],
		];
	}

	/** A document goes to the user of its owner, or to the target user, or to nobody when the run can't tell whose it is. */
	#[DataProvider('documentOwners')]
	public function testGivesADocumentToTheUserOfItsOwner(mixed $owner, ?string $user): void {
		self::assertSame($user, $this->owners->userOf($owner));
	}

	/** Whoever may view a document, by themselves or through a group, counts once; a user that Paperless didn't list counts for nobody. */
	public function testFindsWhoMayViewADocument(): void {
		$owners = new Owners('paperless', [
			1 => ['id' => 1, 'name' => 'alice', 'user' => 'alice', 'reason' => Owners::NAME, 'detail' => ''],
			2 => ['id' => 2, 'name' => 'Bob', 'user' => 'bob.smith', 'reason' => Owners::TABLE, 'detail' => 'bob.smith'],
			3 => ['id' => 3, 'name' => 'carol', 'user' => null, 'reason' => Owners::NONE, 'detail' => ''],
			4 => ['id' => 4, 'name' => 'dave', 'user' => 'dave', 'reason' => Owners::NAME, 'detail' => ''],
		], [], [8 => [2, 3, 42], 9 => []]);

		$viewers = $owners->viewersOf(new DocumentPermissions([1, 2, 43], [8, 9]));

		self::assertNotNull($viewers);
		self::assertSame([[1, 'alice'], [2, 'bob.smith'], [3, null]], array_map(static fn (array $viewer): array => [$viewer['id'], $viewer['user']], $viewers));
		self::assertSame([], $owners->viewersOf(new DocumentPermissions([], [])));
		self::assertSame([], $owners->viewersOf(new DocumentPermissions([], [9])), 'A group without users names nobody.');
	}

	/** Whom a group holds that Paperless didn't list is not for the app to guess. */
	public function testAGroupThatPaperlessDidNotListSaysNothing(): void {
		self::assertNull($this->owners->viewersOf(new DocumentPermissions([1], [7])));
	}

	public function testKnowsTheUserOfPaperlessOfEachUserOfNextcloud(): void {
		self::assertSame([1, 2, 4, null], [
			$this->owners->paperlessUserOf('alice'),
			$this->owners->paperlessUserOf('bob.smith'),
			$this->owners->paperlessUserOf('paperless'),
			$this->owners->paperlessUserOf('carol'),
		]);
	}

	/** The target user comes first, also when a user of Paperless has them, and the others in the order of their IDs. */
	public function testListsTheUsersWhoGetDocuments(): void {
		self::assertSame(['paperless', 'alice', 'bob.smith'], $this->owners->users());
		self::assertSame(['nobody'], $this->owners->unmatched);
		self::assertSame(['paperless'], (new Owners('paperless', []))->users());
	}

	/** The users who get documents come in the order of their IDs, whatever the order of the users of Paperless. */
	public function testListsTheUsersWhoGetDocumentsInTheOrderOfTheirIds(): void {
		$owners = new Owners('paperless', [
			1 => ['id' => 1, 'name' => 'zoe', 'user' => 'zoe', 'reason' => Owners::NAME, 'detail' => ''],
			2 => ['id' => 2, 'name' => 'alice', 'user' => 'alice', 'reason' => Owners::NAME, 'detail' => ''],
		], []);

		self::assertSame(['paperless', 'alice', 'zoe'], $owners->users());
	}

	public function testListsTheUsersOfPaperlessByTheirNames(): void {
		self::assertSame(['admin', 'alice', 'Bob', 'carol', 'dave'], array_column($this->owners->routes(), 'name'));
	}

	/**
	 * The order of the names holds for letters beyond ASCII whatever their case, and names that
	 * differ in their case alone come in the order of their IDs.
	 *
	 * @return array<string, array{list<string>, list<string>}> the names by their IDs, from 1, and in their order
	 */
	public static function namesBeyondAscii(): array {
		return [
			'one order' => [['Ölaf', 'Olga', 'öla', 'Zed', 'ärger', 'Ärni', 'ÄRGER'], ['Olga', 'Zed', 'ärger', 'ÄRGER', 'Ärni', 'öla', 'Ölaf']],
			'the other order' => [['ÄRGER', 'Ärni', 'ärger', 'Zed', 'öla', 'Olga', 'Ölaf'], ['Olga', 'Zed', 'ÄRGER', 'ärger', 'Ärni', 'öla', 'Ölaf']],
		];
	}

	/**
	 * @param list<string> $names
	 * @param list<string> $expected
	 */
	#[DataProvider('namesBeyondAscii')]
	public function testListsNamesBeyondAsciiByTheirNames(array $names, array $expected): void {
		$routes = [];
		foreach ($names as $index => $name) {
			$routes[$index + 1] = ['id' => $index + 1, 'name' => $name, 'user' => null, 'reason' => Owners::NONE, 'detail' => ''];
		}

		self::assertSame($expected, array_column((new Owners('paperless', $routes, []))->routes(), 'name'));
	}
}
