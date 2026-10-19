<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

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
			'an owner that is no ID' => ['alice', null],
			'an owner of another type' => [1.5, null],
		];
	}

	/** A document goes to the user of its owner, or to the target user, or to nobody when the run can't tell whose it is. */
	#[DataProvider('documentOwners')]
	public function testGivesADocumentToTheUserOfItsOwner(mixed $owner, ?string $user): void {
		self::assertSame($user, $this->owners->userOf($owner));
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

	public function testListsTheUsersOfPaperlessByTheirNames(): void {
		self::assertSame(['admin', 'alice', 'Bob', 'carol'], array_column($this->owners->routes(), 'name'));
	}
}
