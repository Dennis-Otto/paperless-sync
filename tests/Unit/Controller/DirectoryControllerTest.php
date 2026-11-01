<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\Controller\DirectoryController;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class DirectoryControllerTest extends TestCase {
	private function controller(?IUserManager $users = null, ?IGroupManager $groups = null): DirectoryController {
		return new DirectoryController(
			'paperless_sync',
			$this->createStub(IRequest::class),
			$users ?? $this->createStub(IUserManager::class),
			$groups ?? $this->createStub(IGroupManager::class),
		);
	}

	/** @param list<IUser> $found */
	private function usersThatAnswer(string $search, array $found): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('searchDisplayName')->with($search, DirectoryController::SEARCH_LIMIT)->willReturn($found);

		return $users;
	}

	private function user(string $id, string $name = '', bool $enabled = true): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($id);
		$user->method('getDisplayName')->willReturn($name === '' ? $id : $name);
		$user->method('isEnabled')->willReturn($enabled);

		return $user;
	}

	/**
	 * A search answers what the picker shows, and so nothing else of the accounts, such as their
	 * e-mail addresses, even for a user whom it found by that address.
	 */
	public function testASearchOfUsersAnswersWhatThePickerShows(): void {
		$controller = $this->controller($this->usersThatAnswer('sync', [
			$this->user('sync-target', 'Sync Target'),
			$this->user('sync-old', 'Former Owner', false),
		]));

		$response = $controller->users('sync');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['users' => [
			['id' => 'sync-target', 'displayname' => 'Sync Target', 'enabled' => true],
			['id' => 'sync-old', 'displayname' => 'Former Owner', 'enabled' => false],
		]], $response->getData());
	}

	/** An empty field offers the first users, and so does a request that names no search. */
	public function testWithoutASearchTheFirstUsersAreOffered(): void {
		$controller = $this->controller($this->usersThatAnswer('', [$this->user('alice')]));

		self::assertSame(['users' => [['id' => 'alice', 'displayname' => 'alice', 'enabled' => true]]], $controller->users()->getData());
	}

	/** Each backend of users, such as LDAP next to the database, answers up to the limit by itself. */
	public function testASearchAnswersAtMostTheLimitOverAllBackendsOfUsers(): void {
		$found = array_map(fn (int $number): IUser => $this->user("user-{$number}"), range(1, DirectoryController::SEARCH_LIMIT + 2));

		$data = $this->controller($this->usersThatAnswer('user', $found))->users('user')->getData();

		self::assertIsArray($data);
		self::assertIsArray($data['users']);
		self::assertSame(
			array_map(static fn (int $number): string => "user-{$number}", range(1, 10)),
			array_column($data['users'], 'id'),
		);
	}

	public function testAUserWhoExistsIsFound(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('userExists')->with('e2e-other')->willReturn(true);

		$response = $this->controller($users)->user('e2e-other');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['exists' => true], $response->getData());
	}

	/** The page marks the field by this answer: Nextcloud has no such user. */
	public function testAnUnknownUserIsNotFound(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('userExists')->with('nobody-here')->willReturn(false);

		$response = $this->controller($users)->user('nobody-here');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['exists' => false], $response->getData());
	}

	public function testASearchOfGroupsAnswersWhatThePickerShows(): void {
		$group = $this->createStub(IGroup::class);
		$group->method('getGID')->willReturn('archive-admins');
		$group->method('getDisplayName')->willReturn('Archive admins');
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects(self::once())->method('search')->with('arch', DirectoryController::SEARCH_LIMIT)->willReturn([$group]);

		$response = $this->controller(null, $groups)->groups('arch');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['groups' => [['id' => 'archive-admins', 'displayname' => 'Archive admins']]], $response->getData());
	}

	public function testASearchAnswersAtMostTheLimitOverAllBackendsOfGroups(): void {
		$found = array_map(function (int $number): IGroup {
			$group = $this->createStub(IGroup::class);
			$group->method('getGID')->willReturn("group-{$number}");
			$group->method('getDisplayName')->willReturn("Group {$number}");

			return $group;
		}, range(1, DirectoryController::SEARCH_LIMIT + 2));
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('search')->willReturn($found);

		$data = $this->controller(null, $groups)->groups('group')->getData();

		self::assertIsArray($data);
		self::assertIsArray($data['groups']);
		self::assertSame(
			array_map(static fn (int $number): string => "group-{$number}", range(1, 10)),
			array_column($data['groups'], 'id'),
		);
	}

	public function testWithoutASearchTheFirstGroupsAreOffered(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects(self::once())->method('search')->with('', DirectoryController::SEARCH_LIMIT)->willReturn([]);

		self::assertSame(['groups' => []], $this->controller(null, $groups)->groups()->getData());
	}
}
