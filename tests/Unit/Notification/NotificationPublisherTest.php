<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Notification;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class NotificationPublisherTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedNotifications;

	private TestClock $clock;
	private TestStorage $storage;
	private StatusService $status;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private NotificationPublisher $publisher;
	private SyncConfig $config;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->storage = new TestStorage();
		$this->status = $this->statusService($this->clock);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->publisher = $this->notificationPublisher($this->status, $this->storage, $this->clock, $this->logger);
		$this->config = $this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
	}

	/**
	 * The third run in a row that did not succeed notifies every enabled administrator once, with
	 * the error of the last run; the runs after it, while the mirror keeps failing, add none.
	 */
	public function testTheThirdRunInARowThatFailedNotifiesTheAdministratorsOnce(): void {
		$this->failedRun('Paperless is down.');
		$this->failedRun('Paperless is down.');
		self::assertSame([], $this->notifications, 'Two runs that failed may be a restart of Paperless.');

		$this->failedRun('Paperless answered 401: ' . str_repeat('x', 300));

		self::assertSame(['e2e-admin', 'second-admin'], $this->notifiedUsers(NotificationPublisher::SUBJECT_FAILING), 'A disabled administrator gets none.');
		$notification = $this->notifications[0];
		self::assertSame(AppConstants::APP_ID, $notification->getApp());
		self::assertSame([NotificationPublisher::OBJECT_SYNCHRONIZATION, 'failing'], [$notification->getObjectType(), $notification->getObjectId()]);
		self::assertSame(TestClock::START + 1800, $notification->getDateTime()->getTimestamp());
		$parameters = $notification->getSubjectParameters();
		self::assertSame([3, TestClock::START, true], [$parameters['runs'], $parameters['since'], $parameters['failed']]);
		self::assertSame(NotificationPublisher::ERROR_LIMIT, mb_strlen($parameters['error']));
		self::assertStringStartsWith('Paperless answered 401: ', $parameters['error']);

		$this->failedRun('Paperless is down.');
		$this->failedRun('Paperless is down.');
		self::assertCount(2, $this->notifications, 'A mirror that keeps failing notifies once.');
	}

	public function testRunsWithErrorsCountAsWell(): void {
		for ($run = 0; $run < NotificationPublisher::ALERT_RUNS; ++$run) {
			$report = new SyncReport(false, $this->clock->time, $this->clock->time + 60);
			$report->error('P123: the download failed');
			$this->status->completed($report);
			$this->publisher->publish($this->config, $report);
		}

		self::assertCount(2, $this->notifications);
		self::assertFalse($this->notifications[0]->getSubjectParameters()['failed']);
	}

	/** With an interval of a day, the second run that failed comes a day after the first and notifies. */
	public function testARowOfADayNotifiesWithFewerRuns(): void {
		$this->failedRun('Paperless is down.', 0);
		$this->clock->advance(NotificationPublisher::ALERT_AFTER - 1);
		$this->failedRun('Paperless is down.', 0);
		self::assertSame([], $this->notifications);

		$this->clock->advance(1);
		$this->failedRun('Paperless is down.', 0);
		self::assertCount(2, $this->notifications, 'The third run notifies as well.');
		$this->notifications = [];
		$this->succeededRun();

		$this->failedRun('Paperless is down.', 0);
		$this->clock->advance(NotificationPublisher::ALERT_AFTER);
		$this->failedRun('Paperless is down.', 0);
		self::assertSame(['e2e-admin', 'second-admin'], $this->notifiedUsers(NotificationPublisher::SUBJECT_FAILING), 'A day of failures notifies after two runs.');
	}

	/** The next successful run removes the notification of every administrator, and a new row notifies anew. */
	public function testASuccessRemovesTheNotificationOnce(): void {
		$this->failedRuns(NotificationPublisher::ALERT_RUNS);
		self::assertCount(2, $this->notifications);

		$this->succeededRun();

		self::assertSame([], $this->notifications);
		self::assertCount(1, $this->processedNotifications);
		$processed = $this->processedNotifications[0];
		self::assertSame([AppConstants::APP_ID, '', NotificationPublisher::OBJECT_SYNCHRONIZATION, 'failing'], [$processed->getApp(), $processed->getUser(), $processed->getObjectType(), $processed->getObjectId()]);
		self::assertArrayNotHasKey('notification_failing_sent', $this->settings);

		$this->succeededRun();
		self::assertCount(1, $this->processedNotifications, 'A success without a notification has nothing to remove.');

		$this->failedRuns(NotificationPublisher::ALERT_RUNS);
		self::assertCount(2, $this->notifications, 'A new row of failures notifies again.');
	}

	public function testADryRunNotifiesNobody(): void {
		$this->failedRuns(NotificationPublisher::ALERT_RUNS - 1);
		$report = new SyncReport(true, $this->clock->time);
		$report->importResult(SyncReport::IMPORT_FAILED, 'Documents/Paperless/Errors/broken.pdf', null, 'Unsupported');
		$this->status->failed(new RuntimeException('Paperless is down.'), $report);

		$this->publisher->publish($this->config, $report);

		self::assertSame([], $this->notifications);
		self::assertSame(NotificationPublisher::ALERT_RUNS - 1, $this->status->failures()['runs'], 'A dry-run neither counts nor notifies.');
	}

	public function testTheGroupOfTheSettingGetsTheNotification(): void {
		$this->groupMembers['paperless-admins'] = ['archivist' => true];
		$this->settings[NotificationPublisher::GROUP_SETTING] = 'paperless-admins';

		$this->failedRuns(NotificationPublisher::ALERT_RUNS);

		self::assertSame(['archivist'], $this->notifiedUsers(NotificationPublisher::SUBJECT_FAILING));
	}

	public function testAGroupThatDoesNotExistLeavesTheNotificationToTheAdministrators(): void {
		$this->settings[NotificationPublisher::GROUP_SETTING] = 'gone';
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('does not exist'), ['group' => 'gone']);

		$this->failedRuns(NotificationPublisher::ALERT_RUNS);

		self::assertSame(['e2e-admin', 'second-admin'], $this->notifiedUsers(NotificationPublisher::SUBJECT_FAILING));
	}

	public function testWithoutAdministratorsNobodyIsNotified(): void {
		$this->groupMembers = [];
		$this->logger->expects(self::never())->method('warning');

		$this->failedRuns(NotificationPublisher::ALERT_RUNS);

		self::assertSame([], $this->notifications);
	}

	/** A run that failed before it read its settings still counts, and has no imports to tell of. */
	public function testARunWithoutSettingsNotifiesTheAdministratorsAlone(): void {
		for ($run = 0; $run < NotificationPublisher::ALERT_RUNS; ++$run) {
			$report = new SyncReport(false, $this->clock->time);
			$report->importResult(SyncReport::IMPORT_FAILED, 'Documents/Paperless/Errors/broken.pdf', null, 'Unsupported');
			$this->status->failed(new RuntimeException('The settings could not be read.'), $report);
			$this->publisher->publish(null, $report);
		}

		self::assertSame(['e2e-admin', 'second-admin'], array_map(static fn ($notification): string => $notification->getUser(), $this->notifications));
	}

	/**
	 * The target user hears of every file of the inbox that Paperless could not import or refused,
	 * with the reason of Paperless, but not of one that it imported.
	 */
	public function testTheTargetUserHearsOfFailedAndRefusedImports(): void {
		$this->storage->files['Documents/Paperless/Errors/broken.pdf'] = 'synthetic';
		$this->storage->files['Documents/Paperless/Inbox/notes.bin'] = 'synthetic';
		$report = new SyncReport(false, $this->clock->time, $this->clock->time + 60);
		$report->importResult(SyncReport::IMPORTED, 'Documents/Paperless/Inbox/scan.pdf', 501);
		$report->importResult(SyncReport::IMPORT_FAILED, 'Documents/Paperless/Errors/broken.pdf', null, str_repeat('Unsupported ', 30));
		$report->importResult(SyncReport::IMPORT_REJECTED, 'Documents/Paperless/Inbox/notes.bin', null, 'File type not supported');
		$report->importResult(SyncReport::IMPORT_FAILED, 'Documents/Paperless/Errors/gone.pdf', null, 'Unsupported');
		$this->status->completed($report);

		$this->publisher->publish($this->config, $report);

		self::assertCount(3, $this->notifications);
		[$failed, $rejected, $gone] = $this->notifications;
		self::assertSame(['paperless', NotificationPublisher::SUBJECT_IMPORT_FAILED, NotificationPublisher::OBJECT_IMPORT], [$failed->getUser(), $failed->getSubject(), $failed->getObjectType()]);
		$fileId = $this->storage->findFileId('paperless', 'Documents/Paperless/Errors/broken.pdf');
		self::assertSame((string)$fileId, $failed->getObjectId());
		self::assertSame(['id' => $fileId, 'path' => 'Documents/Paperless/Errors/broken.pdf'], $failed->getSubjectParameters()['file']);
		self::assertSame(NotificationPublisher::ERROR_LIMIT, mb_strlen($failed->getSubjectParameters()['reason']));
		self::assertSame(NotificationPublisher::SUBJECT_IMPORT_REJECTED, $rejected->getSubject());
		self::assertSame('File type not supported', $rejected->getSubjectParameters()['reason']);
		self::assertSame(md5('Documents/Paperless/Errors/gone.pdf'), $gone->getObjectId(), 'A file that is gone has the hash of its path as its object.');
		self::assertSame(0, $gone->getSubjectParameters()['file']['id']);
	}

	public function testANotificationThatNextcloudRefusesDoesNotFailTheRun(): void {
		$this->notificationFailure = new RuntimeException('The database is full.');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('could not send its notifications'), self::callback(
			static fn (array $context): bool => $context['error'] === 'The database is full.',
		));

		$this->failedRuns(NotificationPublisher::ALERT_RUNS);

		self::assertSame([], $this->notifications);
	}

	private function failedRuns(int $count): void {
		for ($run = 0; $run < $count; ++$run) {
			$this->failedRun('Paperless is down.');
		}
	}

	/** A run that failed, and the interval of 15 minutes after its start. */
	private function failedRun(string $error, int $interval = 900): void {
		$report = new SyncReport(false, $this->clock->time);
		$this->status->failed(new RuntimeException($error), $report);
		$this->publisher->publish($this->config, $report);
		$this->clock->advance($interval);
	}

	private function succeededRun(): void {
		$report = new SyncReport(false, $this->clock->time, $this->clock->time + 60);
		$this->status->completed($report);
		$this->publisher->publish($this->config, $report);
	}
}
