<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\PaperlessConnection;
use OCP\Security\ISecureRandom;

/**
 * The account of the app in Paperless, set up with the token of a superuser of Paperless that is
 * used for these requests alone (decision 0027): a user that is no superuser, with the permissions
 * that the modules need, a random password that nobody learns, and its own token, which the app
 * stores in place of the one of the superuser.
 */
final class AccountSetup {
	/** The username that the settings page suggests. */
	public const USERNAME = 'nextcloud-sync';
	/** The length of the random password, which only this setup ever knows. */
	private const PASSWORD_LENGTH = 64;
	/** The usernames that Django, under Paperless, takes: letters, digits and @ . + - _, up to 150. */
	private const USERNAME_PATTERN = '/^[\w.@+-]{1,150}$/u';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessClientFactoryInterface $clients,
		private ISecureRandom $random,
	) {
	}

	/**
	 * Creates the user $username in Paperless with $permissions, or, with $update, gives the user
	 * that exists already exactly these permissions, and answers its token and whether the user is
	 * new. The user gets a new random password either way, because Paperless gives the token of a
	 * user only for its password; the token stays the one that the user had.
	 *
	 * @param PaperlessConnection $superuser the Paperless of the settings, with the token of a superuser
	 * @param list<string> $permissions such as view_document
	 * @return array{token: string, created: bool, username: string}
	 * @throws PaperlessAccountExistsException when the user exists, without $update
	 * @throws InvalidSettingException when the username is invalid, the user is a superuser, or Paperless refuses the token
	 * @throws PaperlessRequestException when Paperless can't be reached
	 * @throws \RuntimeException when Paperless doesn't do what was asked
	 */
	public function setUp(PaperlessConnection $superuser, string $username, array $permissions, bool $update): array {
		$username = trim($username) === '' ? self::USERNAME : trim($username);
		if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
			throw new InvalidSettingException('Enter a username for the account of up to 150 letters, digits and the characters @ . + - _.', [], 'account_username');
		}
		$paperless = $this->clients->connect($superuser);
		$user = $paperless->user($username);
		// The token of a superuser controls all of Paperless, so the app never takes one over.
		if ($user !== null && $user['superuser']) {
			throw new InvalidSettingException('The user %s is a superuser of Paperless, whose token controls all of Paperless. Choose another username.', [$username], 'account_username');
		}
		if ($user !== null && !$update) {
			throw new PaperlessAccountExistsException($username);
		}
		$password = $this->random->generate(self::PASSWORD_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		if ($user === null) {
			$paperless->createUser($username, $password, $permissions);
		} else {
			$paperless->updateUser($user['id'], $password, $permissions);
		}

		return ['token' => $paperless->userToken($username, $password), 'created' => $user === null, 'username' => $username];
	}
}
