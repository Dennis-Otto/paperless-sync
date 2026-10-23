<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OC\Files\Filesystem;
use OC\Files\SetupManager;
use OCA\PaperlessSync\Service\UserFilesystem;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The filesystem of the process against the stub of Nextcloud's setup manager, whose default view
 * belongs to the first user set up after a tear-down, as in Nextcloud.
 */
final class UserFilesystemTest extends TestCase {
	private SetupManager $setupManager;
	private UserFilesystem $filesystem;

	protected function setUp(): void {
		Filesystem::$root = null;
		$this->setupManager = new SetupManager();
		$userManager = $this->createStub(IUserManager::class);
		// Nextcloud finds a user whatever the case of the ID and names its own.
		$userManager->method('get')->willReturnCallback(function (string $userId): ?IUser {
			if (!in_array(strtolower($userId), ['admin', 'alice', 'bob'], true)) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn(strtolower($userId));

			return $user;
		});
		$this->filesystem = new UserFilesystem($userManager, $this->setupManager);
	}

	protected function tearDown(): void {
		Filesystem::$root = null;
	}

	/** An administrator's request set up the administrator's files first, whose trash bin would miss a copy of alice. */
	public function testTheFilesystemOfAnotherUserMakesWayForTheUser(): void {
		Filesystem::$root = '/admin/files';

		$this->filesystem->enter('alice');

		self::assertSame(['tearDown', 'setupForUser alice'], $this->setupManager->calls);
		self::assertSame('/alice/files', Filesystem::getRoot());
	}

	/** A process that set up no filesystem yet gets the user's, as its first. */
	public function testAProcessWithoutAFilesystemGetsThatOfTheUser(): void {
		$this->filesystem->enter('alice');

		self::assertSame('/alice/files', Filesystem::getRoot());
	}

	/** The filesystem of the user stays: a run of one user sets it up at most once, and of per-user ownership once for each change of the user. */
	public function testTheFilesystemOfTheUserStays(): void {
		$this->filesystem->enter('alice');
		$this->filesystem->enter('Alice');
		$this->filesystem->enter('alice');
		$this->filesystem->enter('bob');
		$this->filesystem->enter('bob');

		self::assertSame(['tearDown', 'setupForUser alice', 'tearDown', 'setupForUser bob'], $this->setupManager->calls);
		self::assertSame('/bob/files', Filesystem::getRoot());
	}

	/** A user who does not exist has no files to enter; the change itself fails for them. */
	public function testAnUnknownUserLeavesTheFilesystemAlone(): void {
		Filesystem::$root = '/admin/files';

		$this->filesystem->enter('nobody');

		self::assertSame([], $this->setupManager->calls);
		self::assertSame('/admin/files', Filesystem::getRoot());
	}
}
