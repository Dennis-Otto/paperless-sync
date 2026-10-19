<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\SyncController;
use OCA\PaperlessSync\Cron\ManualSyncJob;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class SyncControllerTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var IJobList&MockObject */
	private IJobList $jobList;
	/** The administrator of the request, none without a session. */
	private ?IUser $user = null;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private SyncController $controller;

	protected function setUp(): void {
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'secret-token');
		$this->locking = $this->createMock(ILockingProvider::class);
		$clock = new TestClock();
		$this->jobList = $this->createMock(IJobList::class);
		$status = $this->statusService($clock, $this->locking);
		$this->paperless = new TestPaperlessClient();
		$this->storage = new TestStorage();
		$sync = new SyncService(
			$configService,
			$this->paperless,
			$this->storage,
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createStub(LoggerInterface::class),
			$clock,
			$this->activityPublisher(new TestStorage(), $this->createStub(LoggerInterface::class)),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($status, new TestStorage(), $clock, $this->createStub(LoggerInterface::class)),
			$this->systemTagService(new TestStorage()),
			$this->ownerMapping(),
		);
		$this->user = $this->createConfiguredStub(IUser::class, ['getUID' => 'admin']);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$this->controller = new SyncController(AppConstants::APP_ID, $this->createStub(IRequest::class), $sync, $status, $this->germanL10n(), $configService, $this->jobList, $session);
	}

	public function testDryRunReturnsTheReport(): void {
		$this->jobList->expects(self::never())->method('add');

		$response = $this->controller->run();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$report = $response->getData();
		self::assertInstanceOf(SyncReport::class, $report);
		self::assertTrue($report->dryRun, 'A run from the settings page is a dry run unless asked otherwise.');
		self::assertSame('dry-run-completed', $this->settings['status_last_state']);
	}

	/**
	 * A run doesn't wait in the request, which a proxy or the web server may end before the run: a
	 * background job runs it, and the answer brings the status that the page follows.
	 */
	public function testRunIsQueuedAsABackgroundJob(): void {
		$this->settings['status_last_state'] = 'completed';
		$this->jobList->expects(self::once())->method('add')->willReturnCallback(function (string $job, mixed $argument = null): void {
			self::assertSame(ManualSyncJob::class, $job);
			self::assertSame(['author' => 'admin'], $argument, 'The Activity stream names who started the run, and a second click before it starts queues no second run.');
			self::assertSame('queued', $this->settings['status_last_state'], 'The status says "queued" before a job may start.');
		});

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$data = $response->getData();
		self::assertIsArray($data);
		self::assertTrue($data['queued']);
		self::assertSame('queued', $data['status']['state']);
		self::assertArrayNotHasKey('status_last_started', $this->settings, 'The run itself has not started.');
	}

	/** Without a session, such as for a request with an app password, the run has no author. */
	public function testRunWithoutASessionIsQueuedWithoutAnAuthor(): void {
		$this->user = null;
		$this->jobList->expects(self::once())->method('add')->with(ManualSyncJob::class, null);

		self::assertSame(Http::STATUS_ACCEPTED, $this->controller->run(false)->getStatus());
	}

	public function testRunWhileAnotherRunIsActiveIsAConflict(): void {
		$this->locking->method('isLocked')->willReturn(true);
		$this->jobList->expects(self::never())->method('add');

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Es läuft bereits eine andere Synchronisation.'], $response->getData());
		self::assertArrayNotHasKey('status_last_state', $this->settings, 'The status stays that of the active run.');
	}

	public function testDryRunWhileAnotherRunIsActiveIsAConflict(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		$response = $this->controller->run();

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Es läuft bereits eine andere Synchronisation.'], $response->getData());
	}

	/** A run that would fail at once is not queued: the page says why at once, in the language of the user. */
	public function testIncompleteConfigurationQueuesNoRun(): void {
		$this->settings = [];
		$this->jobList->expects(self::never())->method('add');

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Paperless Sync ist noch nicht vollständig eingerichtet.'], $response->getData());
		self::assertArrayNotHasKey('status_last_state', $this->settings);
	}

	public function testFailedDryRunReturnsItsError(): void {
		$this->settings = [];

		$response = $this->controller->run();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Paperless Sync ist noch nicht vollständig eingerichtet.'], $response->getData());
	}

	/** An error that names no setting reaches the page as it is. */
	public function testAnUnexpectedErrorReturnsItsMessage(): void {
		$this->storage->testException = new \RuntimeException('The disk is full.');

		$response = $this->controller->run();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'The disk is full.'], $response->getData());
	}

	/** A run checks the target user again, who may be gone since the save, and says so in German. */
	public function testARunWithoutItsTargetUserSaysSoInGerman(): void {
		$this->storage->testException = new InvalidSettingException('Nextcloud user %s does not exist.', ['paperless'], 'target_user');

		$response = $this->controller->run();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Den Nextcloud-Benutzer paperless gibt es nicht.'], $response->getData());
	}

	/** A dry-run of settings with an invalid folder says why, also without a setting to move the focus to. */
	public function testADryRunOfAnInvalidFolderSaysWhy(): void {
		$response = $this->controller->run(true, ['base_path' => '../Paperless']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Ordnerpfade müssen relativ sein und dürfen keine leeren Teile, . oder .. enthalten.'], $response->getData());
	}

	/** A run that Nextcloud doesn't let reach Paperless in the local network says what to do. */
	public function testRunRefusedInTheLocalNetworkSaysWhatToDo(): void {
		$this->paperless->connectionException = new PaperlessLocalNetworkException('paperless');

		$response = $this->controller->run();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame([
			'message' => 'Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless. Wenn Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.',
			'setting' => 'paperless_local_network',
		], $response->getData());
	}

	/** A dry-run previews the settings of the page before they are saved, and saves none of them. */
	public function testADryRunTriesTheSettingsOfThePageWithoutSavingThem(): void {
		$this->paperless->documents = [['id' => 5, 'title' => 'Strom', 'created' => '2026-08-26', 'original_file_name' => 'scan.pdf']];
		$saved = $this->settings;

		$response = $this->controller->run(true, [
			// The saved connection and target user, as the page sends them, spelled a little differently.
			'paperless_url' => ' https://paperless.example.test/ ',
			'paperless_local_network' => 'false',
			'target_user' => ' paperless ',
			'path_template' => '{{ created_year }}/{{ title }} [P{{ id }}]',
			'inbox_enabled' => false,
		]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$report = $response->getData();
		self::assertInstanceOf(SyncReport::class, $report);
		self::assertTrue($report->unsavedSettings);
		self::assertSame(['EXPORT P5: Documents/Paperless/Archive/2026/Strom [P5].pdf'], $report->actions);
		foreach (['path_template', 'inbox_enabled', 'paperless_url', 'target_user'] as $key) {
			self::assertSame($saved[$key], $this->settings[$key], "The dry-run saves no setting, {$key} neither.");
		}
	}

	public function testOnlyADryRunTakesSettingsThatAreNotSaved(): void {
		$this->locking->expects(self::never())->method('acquireLock');

		$response = $this->controller->run(false, ['path_template' => '{{ title }} [P{{ id }}]']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Speichere zuerst die Einstellungen: Nur ein Testlauf kann ungespeicherte Einstellungen verwenden.'], $response->getData());
	}

	/** @return array<string, array{string, mixed}> */
	public static function changesOfTheConnection(): array {
		return [
			'another Paperless' => ['paperless_url', 'https://other.example.test'],
			'the local network' => ['paperless_local_network', true],
			'another target user' => ['target_user', 'someone-else'],
			// Who gets the documents decides whose files a run reads, as the target user does.
			'per-user ownership' => ['ownership', 'per-user'],
			'another table of users' => ['user_map', 'jdoe = paperless'],
			'another group of users' => ['user_group', 'scanners'],
		];
	}

	/**
	 * A dry-run asks for no password, so it keeps the connection and the target user that a save
	 * tested and confirmed: it never sends the token elsewhere, nor reads the files of another user.
	 */
	#[DataProvider('changesOfTheConnection')]
	public function testADryRunKeepsTheSavedConnectionAndTargetUser(string $setting, mixed $value): void {
		$this->locking->expects(self::never())->method('acquireLock');

		$response = $this->controller->run(true, [$setting => $value]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'Ein Testlauf ungespeicherter Einstellungen verwendet die gespeicherte Verbindung zu Paperless und die gespeicherten Benutzer, die die Dokumente erhalten. Speichere die Einstellungen, um andere auszuprobieren.',
			'setting' => $setting,
		], $response->getData());
		self::assertSame([], $this->paperless->connections);
	}

	public function testADryRunOfInvalidSettingsSaysWhyAsASaveWould(): void {
		$response = $this->controller->run(true, ['sync_interval_minutes' => 3]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Der Wert von „Intervall in Minuten“ muss zwischen 5 und 1440 liegen.', 'setting' => 'sync_interval_minutes'], $response->getData());
	}

	public function testStatusOfTheLastRun(): void {
		$this->controller->run();

		$status = $this->controller->status()->getData();

		self::assertIsArray($status);
		self::assertSame('dry-run-completed', $status['state']);
		self::assertSame(0, $status['summary']['errors']);
		self::assertSame([], $status['report']['errorMessages']);
		self::assertNull($status['nextRun'], 'Scheduled runs are off until the settings turn them on.');
	}
}
