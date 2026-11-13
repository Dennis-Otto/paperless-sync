<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Listener;

use OCA\PaperlessSync\Listener\UserDeletedListener;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Nextcloud lets an administrator create a new account with the ID of a deleted one, which must
 * get nothing of the deleted user: neither their lines of the settings nor their state.
 */
final class UserDeletedListenerTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedNotifications;

	private ConfigService $config;
	private TestStateRepository $state;
	/** @var list<string> the entries of the log, each as "level: message" with its values in place */
	private array $log = [];

	protected function setUp(): void {
		$this->usersOfNextcloud = ['paperless', 'admin', 'Katja', 'bob'];
		$this->config = $this->configService();
		$this->config->save([
			'ownership' => 'per-user',
			'user_map' => "jdoe = Katja\nMax Muster = bob",
			'user_tags' => "Katja = Household\nbob = Bob",
		] + self::validSettings(), 'secret-token');
		$this->config->keepTagArchives(['Katja', 'bob']);
		$this->state = new TestStateRepository();
		foreach (['Katja', 'bob', TestStateRepository::OWNER] as $user) {
			$this->state->saveExport($user, 1, ['path' => "Documents/Paperless/{$user}.pdf"]);
			$this->state->saveImport($user, "Documents/Paperless/Inbox/{$user}.pdf", ['etag' => 'e1']);
			$this->state->saveSend($user, 42, ['path' => "Scans/{$user}.pdf"]);
		}
	}

	/**
	 * A deleted user leaves their line of the tags, the lines of the table that name them, their
	 * archive of the tags and every state; the others keep theirs, and the administrators hear
	 * nothing, as the runs go on as before.
	 */
	public function testADeletedUserLeavesTheirLinesAndTheirState(): void {
		$this->usersOfNextcloud = ['paperless', 'admin', 'bob'];

		$this->listener()->handle(new UserDeletedEvent($this->user('Katja')));

		self::assertSame(['bob = Bob', 'Max Muster = bob'], [$this->settings['user_tags'], $this->settings['user_map']]);
		self::assertSame(['bob'], $this->config->tagArchives());
		self::assertSame([[], [], null], [$this->state->allExports('Katja'), $this->state->allImports('Katja'), $this->state->findSend('Katja', 42)]);
		self::assertSame(['bob', TestStateRepository::OWNER], $this->state->exportOwners());
		self::assertNotNull($this->state->findSend('bob', 42));
		self::assertSame([], $this->notifications);
		self::assertSame(['info: Paperless Sync forgot the deleted user Katja: user_tags, user_map, tag_archives of its settings and 3 states.'], $this->log);
	}

	/**
	 * The target user stays in the settings, as a save keeps a target user that is gone, and every
	 * enabled administrator hears that the runs fail until the settings name another; their state
	 * goes as well.
	 */
	public function testADeletedTargetUserLeavesTheirStateAndTheAdministratorsHearOfIt(): void {
		$this->listener()->handle(new UserDeletedEvent($this->user('paperless')));

		self::assertSame('paperless', $this->config->targetUser());
		self::assertSame(['Katja', 'bob'], $this->state->exportOwners());
		self::assertSame(['e2e-admin', 'second-admin'], $this->notifiedUsers(NotificationPublisher::SUBJECT_TARGET_USER_DELETED));
		self::assertSame(['user' => 'paperless'], $this->notifications[0]->getSubjectParameters());
		self::assertSame(['info: Paperless Sync forgot the deleted user paperless: none of its settings and 3 states.'], $this->log);
	}

	/** A user that the app never knew changes nothing and leaves no entry in the log. */
	public function testAUserWithoutLinesOrStateLeavesNothingToForget(): void {
		$tags = $this->settings['user_tags'];

		$this->listener()->handle(new UserDeletedEvent($this->user('stranger')));

		self::assertSame($tags, $this->settings['user_tags']);
		self::assertSame(['Katja', 'bob', TestStateRepository::OWNER], $this->state->exportOwners());
		self::assertSame([], $this->notifications);
		self::assertSame([], $this->log);
	}

	/** Nextcloud deletes the user all the same when the app fails to forget them; the log says so. */
	public function testAFailureIsLoggedAndTheDeletionGoesOn(): void {
		$state = $this->createStub(SyncStateRepositoryInterface::class);
		$state->method('deleteOwner')->willThrowException(new RuntimeException('The database is gone.'));

		$this->listener($state)->handle(new UserDeletedEvent($this->user('paperless')));

		self::assertSame([], $this->notifications);
		self::assertSame(['warning: Paperless Sync could not forget the deleted user paperless: The database is gone.'], $this->log);
	}

	public function testAnotherEventIsNotItsConcern(): void {
		$tags = $this->settings['user_tags'];

		$this->listener()->handle(new Event());

		self::assertSame($tags, $this->settings['user_tags']);
		self::assertSame([], $this->notifications);
		self::assertSame([], $this->log);
	}

	private function listener(?SyncStateRepositoryInterface $state = null): UserDeletedListener {
		$logger = $this->createStub(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string|\Stringable $message, array $context = []) use ($level): void {
				$values = [];
				foreach ($context as $key => $value) {
					$values['{' . $key . '}'] = is_scalar($value) ? (string)$value : get_debug_type($value);
				}
				$this->log[] = $level . ': ' . strtr((string)$message, $values);
			});
		}
		$clock = new TestClock();
		$publisher = $this->notificationPublisher($this->statusService($clock), new TestStorage(), $clock, $logger);

		return new UserDeletedListener($this->config, $state ?? $this->state, $publisher, $logger);
	}

	private function user(string $userId): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($userId);

		return $user;
	}
}
