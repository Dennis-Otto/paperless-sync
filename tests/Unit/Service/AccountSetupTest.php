<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Service\AccountSetup;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessAccountExistsException;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The setup of the account of the app in Paperless with the token of a superuser: it creates a
 * user that is no superuser, or updates one only when asked, and answers the token of that user.
 */
final class AccountSetupTest extends TestCase {
	private const PERMISSIONS = ['view_document', 'view_uisettings'];

	private TestPaperlessClient $paperless;
	private AccountSetup $setup;
	/** @var list<array{int, string}> the length and the characters of every random password */
	private array $passwords = [];

	protected function setUp(): void {
		$this->paperless = new TestPaperlessClient();
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(function (int $length, string $characters): string {
			$this->passwords[] = [$length, $characters];

			return 'random-password-' . count($this->passwords);
		});
		$this->setup = new AccountSetup($this->paperless, $random);
	}

	private static function superuser(): PaperlessConnection {
		return new PaperlessConnection('https://paperless.example.test', 'superuser-token', false);
	}

	/** A new user, with the suggested name, a long random password and the permissions asked for. */
	public function testCreatesAUserThatIsNoSuperuser(): void {
		$account = $this->setup->setUp(self::superuser(), ' ', self::PERMISSIONS, false);

		self::assertSame(['token' => 'token-of-nextcloud-sync', 'created' => true, 'username' => 'nextcloud-sync'], $account);
		self::assertSame(['id' => 10, 'superuser' => false, 'password' => 'random-password-1', 'permissions' => self::PERMISSIONS, 'token' => 'token-of-nextcloud-sync'], $this->paperless->accounts['nextcloud-sync']);
		self::assertSame([[64, ISecureRandom::CHAR_ALPHANUMERIC]], $this->passwords);
		self::assertSame(['superuser-token'], array_map(static fn (PaperlessConnection $connection): string => $connection->token(), $this->paperless->connects));
	}

	/**
	 * A user that exists changes only with update: then it gets exactly the permissions and a new
	 * password, for which Paperless gives its token, the one that it had.
	 */
	public function testUpdatesAnExistingUserOnlyWhenAsked(): void {
		$this->paperless->accounts['nextcloud-sync'] = ['id' => 7, 'superuser' => false, 'password' => 'old', 'permissions' => ['delete_document'], 'token' => 'token-it-had'];

		try {
			$this->setup->setUp(self::superuser(), 'nextcloud-sync', self::PERMISSIONS, false);
			self::fail('An existing user changed without update.');
		} catch (PaperlessAccountExistsException $exception) {
			self::assertSame('nextcloud-sync', $exception->username);
			self::assertSame('The user nextcloud-sync exists in Paperless already.', $exception->getMessage());
		}
		self::assertSame('old', $this->paperless->accounts['nextcloud-sync']['password']);

		$account = $this->setup->setUp(self::superuser(), 'nextcloud-sync', self::PERMISSIONS, true);

		self::assertSame(['token' => 'token-it-had', 'created' => false, 'username' => 'nextcloud-sync'], $account);
		self::assertSame(['random-password-1', self::PERMISSIONS], [$this->paperless->accounts['nextcloud-sync']['password'], $this->paperless->accounts['nextcloud-sync']['permissions']]);
	}

	/** The app never takes over a superuser, whose token controls all of Paperless, not even with update. */
	public function testNeverTakesOverASuperuser(): void {
		$this->paperless->accounts['admin'] = ['id' => 1, 'superuser' => true, 'password' => 'secret', 'permissions' => [], 'token' => 'token-of-the-superuser'];

		try {
			$this->setup->setUp(self::superuser(), 'admin', self::PERMISSIONS, true);
			self::fail('The setup took over a superuser.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('The user admin is a superuser of Paperless, whose token controls all of Paperless. Choose another username.', $exception->getMessage());
			self::assertSame('account_username', $exception->setting);
		}
		self::assertSame('secret', $this->paperless->accounts['admin']['password']);
	}

	/** @return array<string, array{string}> */
	public static function invalidUsernames(): array {
		return [
			'a space' => ['nextcloud sync'],
			'a slash' => ['nextcloud/sync'],
			'too long' => [str_repeat('a', 151)],
		];
	}

	/** A username that Paperless doesn't take is refused before a request. */
	#[DataProvider('invalidUsernames')]
	public function testRefusesAUsernameThatPaperlessDoesntTake(string $username): void {
		try {
			$this->setup->setUp(self::superuser(), $username, self::PERMISSIONS, false);
			self::fail("The setup took the username {$username}.");
		} catch (InvalidSettingException $exception) {
			self::assertSame('account_username', $exception->setting);
		}
		self::assertSame([], $this->paperless->connects);
	}

	/** Letters of other languages, digits and @ . + - _ are fine, as in Django. */
	public function testTakesTheUsernamesOfDjango(): void {
		$account = $this->setup->setUp(self::superuser(), 'jürgen.müller+sync@home_2-x', self::PERMISSIONS, false);

		self::assertSame('jürgen.müller+sync@home_2-x', $account['username']);
	}
}
