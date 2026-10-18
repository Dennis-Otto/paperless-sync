<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\MetadataSidecars;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessApiService;
use OCA\PaperlessSync\Service\PaperlessClientFactory;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PaperlessUploadException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Service\WebhookService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\Config\IUserConfig;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

final class SyncServiceTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	private const ARCHIVE = 'Dokumente/Paperless/Archiv';
	private const EXPORTED_PATH = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';

	private TestClock $clock;
	private TestTimeZones $timeZones;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private StatusService $status;
	private SyncService $service;

	protected function setUp(): void {
		// A German instance, as the documents of the tests: the folders have their German names.
		$this->defaultLanguage = 'de';
		$this->clock = new TestClock();
		$this->timeZones = new TestTimeZones();
		$this->paperless = new TestPaperlessClient();
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$this->configService = $this->configService($paths);
		$this->configService->save(self::validSettings(), 'test-token');
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->status = $this->statusService($this->clock);
		$this->service = new SyncService(
			$this->configService,
			$this->paperless,
			$this->storage,
			$this->state,
			$paths,
			$this->status,
			$this->locking,
			$this->logger,
			$this->clock,
			$this->activityPublisher($this->storage, $this->logger),
			new UserTimeZones($this->userConfig(), $this->timeZones),
			$this->notificationPublisher($this->status, $this->storage, $this->clock, $this->logger),
		);
	}

	/**
	 * The service with the client of Paperless that a run takes in Nextcloud, whose HTTP client
	 * passes the URL and the header Authorization of each request to $request and answers it with
	 * an empty list, as an empty Paperless does.
	 *
	 * @param callable(string, string): void $request
	 */
	private function serviceWithHttp(callable $request): SyncService {
		$answer = $this->createStub(IResponse::class);
		$answer->method('getStatusCode')->willReturn(200);
		$answer->method('getBody')->willReturn('{"count": 0, "next": null, "previous": null, "results": []}');
		$answer->method('getHeader')->willReturn('');
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('post');
		$client->method('get')->willReturnCallback(static function (string $url, array $options) use ($request, $answer): IResponse {
			/** @var array{headers: array{Authorization: string}} $options */
			$request($url, $options['headers']['Authorization']);

			return $answer;
		});
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new SyncService(
			$this->configService,
			new PaperlessClientFactory($clientService, $this->createStub(ITempManager::class), $this->createStub(IRemoteHostValidator::class)),
			$this->storage,
			$this->state,
			new PathTemplateService(),
			$this->status,
			$this->locking,
			$this->logger,
			$this->clock,
			$this->activityPublisher($this->storage, $this->logger),
			new UserTimeZones($this->userConfig(), $this->timeZones),
			$this->notificationPublisher($this->status, $this->storage, $this->clock, $this->logger),
		);
	}

	/** The personal settings of the users, with the time zones that $this->timeZones holds. */
	private function userConfig(): IUserConfig {
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnCallback(
			fn (string $userId, string $app, string $key): string => $app === 'core' && $key === 'timezone' ? ($this->timeZones->users[$userId] ?? '') : '',
		);

		return $userConfig;
	}

	public function testExportsMovesTrashesRestoresAndPermanentlyDeletes(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$first = $this->service->run();
		$initialPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(1, $first->exported);
		self::assertSame(['EXPORT P123: ' . $initialPath], $first->actions, 'A run lists its changes as a dry-run does.');
		self::assertSame('%PDF-content-v1', $this->storage->files[$initialPath]);
		self::assertSame(1, $this->paperless->downloads);

		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$moved = $this->service->run();
		$renamedPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(1, $moved->moved);
		self::assertSame(['MOVE P123: ' . $renamedPath], $moved->actions);
		self::assertArrayNotHasKey($initialPath, $this->storage->files);
		self::assertArrayHasKey($renamedPath, $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'Metadata-only changes must not download the PDF again.');
		self::assertSame(1, $this->paperless->checksumRequests);

		$trashedDocument = $this->paperless->documents[0];
		$trashedDocument['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument];
		$trashed = $this->service->run();
		self::assertSame(1, $trashed->movedToTrash);
		$trashPath = 'Dokumente/Paperless/Archiv/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(['TRASH P123: ' . $trashPath], $trashed->actions);
		self::assertArrayHasKey($trashPath, $this->storage->files);

		unset($trashedDocument['deleted_at']);
		$this->paperless->documents = [$trashedDocument];
		$this->paperless->trash = [];
		$restored = $this->service->run();
		self::assertSame(1, $restored->moved);
		self::assertSame(['MOVE P123: ' . $renamedPath], $restored->actions);
		self::assertArrayHasKey($renamedPath, $this->storage->files);

		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->service->run();
		$this->configService->save(['permanent_delete' => true, 'missing_grace_runs' => 1]);
		$this->paperless->trash = [];
		$deleted = $this->service->run();
		self::assertSame(1, $deleted->permanentlyDeleted);
		self::assertSame(['DELETE P123: ' . $trashPath], $deleted->actions);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testRefusesToRunBeforeTheAppIsConfigured(): void {
		$this->configService->reset();
		$this->locking->expects(self::once())->method('acquireLock');
		$this->locking->expects(self::once())->method('releaseLock');
		$this->logger->expects(self::once())->method('error')->with('Paperless synchronization failed', self::callback(
			static fn (array $context): bool => array_keys($context) === ['exception']
				&& $context['exception'] instanceof InvalidSettingException
				&& $context['exception']->getMessage() === 'Paperless Sync is not completely configured.',
		));

		try {
			$this->service->run();
			self::fail('An unconfigured app must not synchronize.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('Paperless Sync is not completely configured.', $exception->getMessage());
		}

		self::assertSame('failed', $this->settings['status_last_state']);
		self::assertSame('Paperless Sync is not completely configured.', $this->settings['status_last_error']);
	}

	/**
	 * Nextcloud lets the lock of a run expire after filelocking.ttl, an hour by default, also while
	 * the run is still active, such as the first import of a large archive: its signs of life keep
	 * a second run from starting beside it and writing the same copies.
	 */
	public function testARunThatOutlastedItsLockKeepsAnotherFromStarting(): void {
		$this->paperless->documents = [$this->document()];
		$this->settings['status_last_state'] = 'running';
		$this->settings['status_last_started'] = $this->clock->time - 7200;
		$this->settings['status_last_heartbeat'] = $this->clock->time - 30;
		$this->locking->expects(self::exactly(2))->method('acquireLock');
		$this->locking->expects(self::exactly(2))->method('releaseLock')->with('paperless_sync::synchronization', ILockingProvider::LOCK_EXCLUSIVE);

		foreach ([fn (): mixed => $this->service->run(), fn (): mixed => $this->service->forget()] as $start) {
			try {
				$start();
				self::fail('A second run must not start beside an active one.');
			} catch (LockedException) {
			}
		}

		self::assertSame([], $this->paperless->connections);
		self::assertSame([], $this->state->exports);
		self::assertSame('running', $this->settings['status_last_state'], 'The status keeps following the active run.');
	}

	/** A run whose signs of life stopped a while ago was interrupted, and the next run starts. */
	public function testARunStartsAfterAnInterruptedOne(): void {
		$this->paperless->documents = [$this->document()];
		$this->settings['status_last_state'] = 'running';
		$this->settings['status_last_heartbeat'] = $this->clock->time - StatusService::HEARTBEAT_GRACE - 1;

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertSame($this->clock->time, $this->settings['status_last_heartbeat'], 'A run gives a sign of life when it starts.');
	}

	/**
	 * A dry-run shows what the settings of the page would do before they are saved: with another
	 * path template here, and without saving it, the state of a document or a file.
	 */
	public function testADryRunUsesSettingsThatAreNotSaved(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$unsaved = $this->configService->validateForDryRun(['path_template' => '{{ created_year }}/{{ title }} [P{{ id }}]', 'inbox_enabled' => false]);

		$report = $this->service->run(true, $unsaved);

		self::assertTrue($report->dryRun);
		self::assertTrue($report->unsavedSettings);
		self::assertSame(['EXPORT P123: ' . self::ARCHIVE . '/2026/Strom August [P123].pdf'], $report->actions, 'The inbox is off in the settings of the dry-run.');
		self::assertSame(PathTemplateService::DEFAULT_TEMPLATE, $this->settings['path_template'], 'The settings stay unsaved.');
		self::assertTrue($this->settings['inbox_enabled']);
		self::assertSame([], $this->state->exports);
		self::assertSame(['Dokumente/Paperless/Eingang/scan.pdf'], array_keys($this->storage->files));
		self::assertSame([['https://paperless.example.test', 'test-token', false]], $this->paperless->connections, 'A dry-run reaches the saved Paperless with the saved token.');
		$kept = json_decode((string)$this->settings['status_last_report'], true, 512, JSON_THROW_ON_ERROR);
		self::assertTrue($kept['unsavedSettings'], 'The kept report says that it shows unsaved settings.');
		self::assertFalse($this->service->run(true)->unsavedSettings);
	}

	public function testOnlyADryRunUsesSettingsThatAreNotSaved(): void {
		$this->locking->expects(self::never())->method('acquireLock');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Only a dry-run can use settings that are not saved.');
		$this->service->run(false, $this->configService->get());
	}

	/**
	 * The users and the custom fields of Paperless are read only for a template that needs them,
	 * so that a token without the permission to view them works with every other template.
	 */
	public function testReadsUsersAndCustomFieldsOnlyForATemplateThatUsesThem(): void {
		$this->paperless->documents = [['owner' => 3, 'tags' => [9], 'custom_fields' => [['field' => 1, 'value' => 'INV-7']]] + $this->document()];
		$this->paperless->users = ['3' => 'dennis'];
		$this->paperless->customFields = ['1' => ['name' => 'Invoice number', 'type' => 'string', 'options' => []]];
		$this->paperless->tagInfo = ['names' => ['9' => 'Strom'], 'inbox' => []];

		$this->service->run(true);
		self::assertSame([], $this->paperless->listsRead);

		$this->configService->save(['path_template' => '{{ owner_username }}/{{ tag_list }}/{{ custom_fields.Invoice number }} [P{{ id }}]']);
		$report = $this->service->run(true);

		self::assertSame(['users', 'custom fields'], $this->paperless->listsRead);
		self::assertSame(['EXPORT P123: ' . self::ARCHIVE . '/dennis/Strom/INV-7 [P123].pdf'], $report->actions);
	}

	/**
	 * A run asks Paperless only for the fields of DOCUMENT_FIELDS, and these carry every variable
	 * of the path template: a document reduced to them renders the same path.
	 */
	public function testATemplateRendersFromTheFieldsThatARunAsksFor(): void {
		$document = ['owner' => 3, 'archive_serial_number' => 42, 'page_count' => 2, 'tags' => [9], 'custom_fields' => [['field' => 1, 'value' => 'INV-7']]] + $this->document();
		$this->paperless->documents = [array_intersect_key($document + ['content' => 'OCR text', 'notes' => []], array_flip(PaperlessApiService::DOCUMENT_FIELDS))];
		$this->paperless->users = ['3' => 'dennis'];
		$this->paperless->customFields = ['1' => ['name' => 'Invoice number', 'type' => 'string', 'options' => []]];
		$this->paperless->tagInfo = ['names' => ['9' => 'Strom'], 'inbox' => []];
		$this->configService->save(['path_template' => '{{ owner_username }}/{{ custom_fields.Invoice number }} {{ asn }} {{ page_count }} [P{{ id }}]']);

		$report = $this->service->run(true);

		self::assertSame(['EXPORT P123: ' . self::ARCHIVE . '/dennis/INV-7 42 2 [P123].pdf'], $report->actions);
	}

	/** @return array<string, array{string, string, string}> */
	public static function refusedLists(): array {
		return [
			'users' => [
				'{{ owner_username }} [P{{ id }}]',
				'usersException',
				'Paperless did not list its users, which the archive path template needs for {{ owner_username }}; the user of the API token needs the permission to view users: HTTP 403.',
			],
			'custom fields' => [
				'{{ custom_fields.Invoice number }} [P{{ id }}]',
				'customFieldsException',
				'Paperless did not list its custom fields, which the archive path template uses; the user of the API token needs the permission to view custom fields: HTTP 403.',
			],
		];
	}

	/** A list that Paperless refuses fails the run before a file moves, with the permission that is missing. */
	#[DataProvider('refusedLists')]
	public function testAListThatPaperlessRefusesFailsTheRunWithThePermissionItNeeds(string $template, string $failure, string $message): void {
		$this->paperless->documents = [$this->document()];
		$this->configService->save(['path_template' => $template]);
		$this->paperless->{$failure} = new RuntimeException('HTTP 403.');

		try {
			$this->service->run();
			self::fail('A list that Paperless refuses fails the run.');
		} catch (RuntimeException $exception) {
			self::assertSame($message, $exception->getMessage());
		}
		self::assertSame([], $this->state->exports);
	}

	public function testACustomFieldThatPaperlessDoesNotHaveFailsTheRunBeforeAFileMoves(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->service->run();
		$this->configService->save(['path_template' => '{{ custom_fields.Invoice numbr }} [P{{ id }}]']);

		try {
			$this->service->run();
			self::fail('A custom field with a typo would empty the paths of every document.');
		} catch (\InvalidArgumentException $exception) {
			self::assertSame('Paperless has no custom field named "Invoice numbr", which the archive path template uses.', $exception->getMessage());
		}
		self::assertSame([self::EXPORTED_PATH], array_keys($this->storage->files), 'The file stays where it is.');
	}

	/**
	 * The preview of the path template: the paths of the newest documents with the settings of
	 * the page, skipped as a run skips them, and nothing written.
	 */
	public function testPreviewsThePathsOfTheNewestDocuments(): void {
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['1' => 'Inbox', '2' => 'Privat'], 'inbox' => ['1']];
		$documents = [];
		foreach (range(1, 7) as $day) {
			$documents[] = ['id' => 200 + $day, 'title' => "Brief {$day}", 'added' => sprintf('2026-09-%02dT08:00:00+02:00', $day)] + $this->document();
		}
		$documents[6]['tags'] = [1];
		$documents[5]['tags'] = [2];
		$documents[4]['id'] = 'abc';
		$this->paperless->documents = $documents;
		$config = $this->configService->validate(['path_template' => '{{ correspondent }}/{{ added_month }}/{{ title }} [P{{ id }}]', 'excluded_tags' => 'privat']);

		$paths = $this->service->previewPaths($config, 5);

		self::assertSame([
			['id' => 207, 'title' => 'Brief 7', 'path' => 'Energie GmbH/09/Brief 7 [P207].pdf', 'skipped' => true],
			['id' => 206, 'title' => 'Brief 6', 'path' => 'Energie GmbH/09/Brief 6 [P206].pdf', 'skipped' => true],
			['id' => 204, 'title' => 'Brief 4', 'path' => 'Energie GmbH/09/Brief 4 [P204].pdf', 'skipped' => false],
			['id' => 203, 'title' => 'Brief 3', 'path' => 'Energie GmbH/09/Brief 3 [P203].pdf', 'skipped' => false],
		], $paths, 'A document without a valid ID has no path, and one of the inbox or with an excluded tag is skipped.');
		self::assertSame([], $this->paperless->connections, 'The preview tests no connection, which a run does.');
		self::assertSame([], $this->state->exports);
		self::assertSame([], $this->storage->files);
		self::assertArrayNotHasKey('status_last_started', $this->settings, 'The preview is no run.');
	}

	public function testThePreviewOfADocumentWithoutATitleShowsItsPath(): void {
		$this->paperless->documents = [['title' => null] + $this->document()];

		self::assertSame(
			[['id' => 123, 'title' => '', 'path' => '_Ohne Korrespondent/_Ohne Dokumenttyp/2026/2026-08-26 - Ohne Titel [P123].pdf', 'skipped' => false]],
			$this->service->previewPaths($this->configService->get(), 5),
		);
	}

	/** @return array<string, array{string}> */
	public static function incompleteConfigurations(): array {
		return [
			'without a URL' => ['paperless_url'],
			'without a token' => ['token'],
			'without a target user' => ['target_user'],
		];
	}

	/** A run that misses a part of its configuration fails before it sends the token anywhere or touches a file. */
	#[DataProvider('incompleteConfigurations')]
	public function testRunWithoutAUrlATokenOrATargetUserChangesNothing(string $missing): void {
		if ($missing === 'token') {
			$this->credential = null;
		} else {
			$this->settings[$missing] = '';
		}
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$this->paperless->documents = [$this->document()];

		try {
			$this->service->run();
			self::fail('A run without its ' . $missing . ' must not start.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('Paperless Sync is not completely configured.', $exception->getMessage());
		}

		self::assertSame([], $this->paperless->connections);
		self::assertSame([], $this->storage->calls);
		self::assertSame([], $this->state->owners);
		self::assertSame('failed', $this->settings['status_last_state']);
	}

	public function testFailedConnectionTestChangesNothing(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$this->paperless->documents = [$this->document()];
		$this->paperless->connectionException = new RuntimeException('Paperless answered with HTTP 401.');

		try {
			$this->service->run();
			self::fail('A run that cannot reach Paperless must fail.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless answered with HTTP 401.', $exception->getMessage());
		}

		self::assertSame([], $this->storage->calls, 'The run neither tests nor prepares the folders.');
		self::assertSame([], $this->state->owners);
		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame('failed', $this->settings['status_last_state']);
		self::assertSame('Paperless answered with HTTP 401.', $this->settings['status_last_error']);
	}

	public function testFailedTestOfTheBaseFolderChangesNothing(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$this->paperless->documents = [$this->document()];
		$this->storage->testException = new RuntimeException('Nextcloud user paperless does not exist.');

		try {
			$this->service->run();
			self::fail('A run without a usable base folder must fail.');
		} catch (RuntimeException $exception) {
			self::assertSame('Nextcloud user paperless does not exist.', $exception->getMessage());
		}

		self::assertSame([['test', TestStorage::USER, ['Dokumente/Paperless']]], $this->storage->calls, 'Nothing is prepared, read or written.');
		self::assertSame([], $this->storage->folders);
		self::assertSame([], $this->state->owners);
		self::assertSame([], $this->paperless->downloadRequests);
		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame('failed', $this->settings['status_last_state']);
	}

	public function testRunPreparesTheFoldersAndADryRunDoesNot(): void {
		$this->service->run(true);

		self::assertSame([], $this->storage->folders, 'A dry-run creates no folder.');
		self::assertNotContains('prepare', $this->storage->methods());

		$this->storage->calls = [];
		$this->service->run();

		self::assertSame(['test', 'prepare'], array_slice($this->storage->methods(), 0, 2));
		self::assertSame([TestStorage::USER => [
			'Dokumente/Paperless',
			'Dokumente/Paperless/Archiv',
			'Dokumente/Paperless/Eingang',
			'Dokumente/Paperless/Fehler',
		]], $this->storage->folders);
	}

	public function testEveryCallNamesTheTargetUser(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/police.pdf'] = '%PDF-inbox';
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$this->trashDocument();

		$this->service->run();

		self::assertContains('move', $this->storage->methods());
		self::assertSame([TestStorage::USER], $this->storage->users());
		self::assertSame([TestStateRepository::OWNER], array_values(array_unique($this->state->owners)));
		self::assertSame([], $this->storage->filesOfOthers);
	}

	/** The state belongs to the target user, so another one starts with an archive of its own and leaves the copies of the first alone. */
	public function testAnotherTargetUserGetsAnArchiveOfItsOwn(): void {
		$this->exportDocument();
		$files = $this->storage->files;
		$exports = $this->state->exports;
		$this->configService->save(['target_user' => 'archivist']);
		$this->storage->calls = [];

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(['archivist'], $this->storage->users());
		self::assertSame(['archivist' => [self::EXPORTED_PATH => '%PDF-content']], $this->storage->filesOfOthers);
		self::assertSame(self::EXPORTED_PATH, $this->state->exportsOfOthers['archivist'][123]['path']);
		self::assertSame($files, $this->storage->files);
		self::assertSame($exports, $this->state->exports);
	}

	public function testRunRecordsWhenItStartedAndEnded(): void {
		$this->exportDocument();
		$this->clock->advance(300);

		$report = $this->service->run();

		self::assertSame(TestClock::START + 300, $report->startedAt);
		self::assertSame(TestClock::START + 300, $report->completedAt);
		self::assertSame(TestClock::START + 300, $this->settings['status_last_started']);
		self::assertSame(TestClock::START + 300, $this->settings['status_last_completed']);
	}

	public function testFailedRunKeepsTheReportOfWhatItDidInTheStatus(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$this->paperless->documents = [['id' => 'abc', 'title' => 'Broken']];

		try {
			$this->service->run();
			self::fail('A document without a valid ID fails the run.');
		} catch (RuntimeException) {
		}

		$report = json_decode((string)$this->settings['status_last_report'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['IMPORT: Dokumente/Paperless/Eingang/scan.pdf'], $report['actions'], 'The import before the failure stays in the report.');
		self::assertSame(0, $report['completedAt']);
		self::assertSame('Paperless returned a document without a valid ID.', $this->settings['status_last_error']);
		self::assertArrayNotHasKey('status_last_completed', $this->settings, 'A run that failed completed nothing.');
	}

	/**
	 * The provisioning API of Nextcloud may change the URL in the app configuration without the
	 * settings page, which asks for the token again. The next run must not send the token there.
	 */
	public function testAUrlChangedElsewhereNeverGetsTheToken(): void {
		$this->exportDocument();
		self::assertSame([['https://paperless.example.test', 'test-token', false]], $this->paperless->connections);
		$this->settings['paperless_url'] = 'https://attacker.example.test';

		try {
			$this->service->run();
			self::fail('A URL that the token was not saved with must not get it.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('Paperless Sync is not completely configured.', $exception->getMessage());
		}

		self::assertCount(1, $this->paperless->connections);
		self::assertSame(1, $this->paperless->downloads);
	}

	public function testARunReachesPaperlessInTheLocalNetworkWhenTheSettingsAllowIt(): void {
		$this->configService->save(['paperless_url' => 'http://paperless:8000', 'paperless_local_network' => true] + self::validSettings(), 'test-token');

		$this->service->run(true);

		self::assertSame([['http://paperless:8000', 'test-token', true]], $this->paperless->connections);
	}

	/**
	 * A run keeps the connection that it started with. An administrator who connects another
	 * Paperless while a run is busy changes the next run: every request of this one still goes to
	 * the Paperless that it started with, with its token, so that it records no ID of one Paperless
	 * as that of another, and sends no token to a URL that it wasn't saved for.
	 */
	public function testASaveDuringARunReachesTheNextRunNotTheRestOfThisOne(): void {
		$requests = [];
		$saved = false;
		$service = $this->serviceWithHttp(function (string $url, string $authorization) use (&$requests, &$saved): void {
			$requests[] = [(string)parse_url($url, PHP_URL_HOST), $authorization];
			if (!$saved) {
				$saved = true;
				$this->configService->save(['paperless_url' => 'https://other.example.test'] + self::validSettings(), 'other-token');
			}
		});

		$report = $service->run();

		self::assertSame(0, $report->errors);
		self::assertSame('https://other.example.test', $this->configService->get()->paperlessUrl, 'The save took effect during the run.');
		self::assertCount(7, $requests, 'The test of the connection, the documents, the trash and four lists.');
		self::assertSame([['paperless.example.test', 'Token test-token']], array_values(array_unique($requests, SORT_REGULAR)));

		$requests = [];
		$service->run();

		self::assertSame([['other.example.test', 'Token other-token']], array_values(array_unique($requests, SORT_REGULAR)), 'The next run takes the new connection.');
	}

	/**
	 * Every read of the token is a query of Nextcloud's credentials store and a decryption: a run
	 * reads it once, at its start, not with each of its requests to Paperless.
	 */
	public function testARunDecryptsTheTokenOnce(): void {
		$requests = 0;
		$service = $this->serviceWithHttp(static function () use (&$requests): void {
			++$requests;
		});
		$this->credentialReads = 0;

		$service->run();

		self::assertSame(7, $requests);
		self::assertSame(1, $this->credentialReads);
	}

	/** A dry-run of settings that are not saved reaches the saved Paperless, through one connection. */
	public function testADryRunOfUnsavedSettingsTakesOneConnection(): void {
		$settings = $this->configService->validateForDryRun(['batch_size' => 1]);
		$this->credentialReads = 0;

		$this->service->run(true, $settings);

		self::assertEquals([new PaperlessConnection('https://paperless.example.test', 'test-token', false)], $this->paperless->connects);
		self::assertSame(1, $this->credentialReads);
	}

	/**
	 * A run that Nextcloud doesn't let reach Paperless in the local network, after an update that
	 * turned allow_local_remote_servers off for example, records what to do in the status.
	 */
	public function testARunRefusedInTheLocalNetworkRecordsWhatToDo(): void {
		$this->paperless->connectionException = new PaperlessLocalNetworkException('paperless');

		try {
			$this->service->run();
			self::fail('A refused connection must fail the run.');
		} catch (PaperlessLocalNetworkException) {
		}

		self::assertSame('failed', $this->settings['status_last_state']);
		self::assertSame(
			'Nextcloud blocks connections to local addresses such as paperless. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
			$this->settings['status_last_error'],
		);
	}

	public function testCompletedRunIsLoggedAndRecordedInTheStatus(): void {
		$this->exportDocument();
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization completed', self::callback(
			static fn (array $summary): bool => $summary['unchanged'] === 1 && !array_key_exists('actions', $summary),
		));

		$report = $this->service->run();

		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertSame($report->completedAt, $this->settings['status_last_completed']);
	}

	/**
	 * A run records when it changed a document, or tried to and failed, so that documents that keep
	 * failing take turns. A document that stayed as it was costs no write.
	 */
	public function testStateRecordsWhenARunLastChangedEachDocumentAndFile(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->exportDocuments([$this->document(), $this->document(124)]);

		self::assertSame([TestClock::START, TestClock::START], [$this->state->exports[123]['last_seen'], $this->state->exports[124]['last_seen']]);
		self::assertSame(TestClock::START, $this->state->imports[$path]['submitted_at']);

		$this->clock->advance(60);
		$this->paperless->documents = [$this->document()];
		$this->paperless->trash = [$this->document(124) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->service->run();

		self::assertSame([TestClock::START, TestClock::START + 60], [$this->state->exports[123]['last_seen'], $this->state->exports[124]['last_seen']]);
	}

	public function testUnchangedDocumentIsNeitherDownloadedNorRewritten(): void {
		$this->exportDocument();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $report->exported);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(0, $this->paperless->checksumRequests, 'An unchanged revision needs no checksum.');
	}

	public function testNewRevisionWithTheSameContentOnlyRecordsTheRevision(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->checksumRequests);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * After a change of their titles, documents move to their new paths, a batch in each run.
	 * Paperless reads the checksum of a document from its files, so a run asks for it only for the
	 * documents that it moves: one for each document, not one for each document in each run.
	 */
	public function testRenamedDocumentsAskForTheirChecksumOnceEach(): void {
		$this->exportDocuments($this->documents(50));
		$this->configService->save(['batch_size' => 5]);
		foreach (array_keys($this->paperless->documents) as $index) {
			$this->paperless->documents[$index]['title'] = 'Strom September';
			$this->paperless->documents[$index]['modified'] = '2026-08-26T11:00:00+02:00';
		}

		$moved = [];
		do {
			$report = $this->service->run();
			$moved[] = $report->moved;
		} while ($report->moved > 0 && count($moved) < 20);

		self::assertSame([5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 0], $moved);
		self::assertSame(50, $report->unchanged);
		self::assertSame(50, $this->paperless->checksumRequests, 'Not 275, one for each document that waits in each run.');
		self::assertSame(50, $this->paperless->downloads, 'A move downloads nothing.');
	}

	/**
	 * A document whose content changed at its path waits for a later run when the batch is full,
	 * and keeps the checksum that Paperless reported for its revision until the revision changes.
	 */
	public function testAWaitingDocumentAsksForItsChecksumOnceForEachRevision(): void {
		$this->exportDocuments($this->documents(2));
		$this->configService->save(['batch_size' => 1]);
		foreach ([0, 1] as $index) {
			$this->paperless->documents[$index]['modified'] = '2026-08-26T11:00:00+02:00';
		}
		$this->paperless->contents = [1 => '%PDF-new-1', 2 => '%PDF-new-2'];

		$first = $this->service->run();

		self::assertSame([1, 1], [$first->exported, $first->skipped]);
		self::assertSame(2, $this->paperless->checksumRequests);
		self::assertSame(['remote_checksum' => hash('sha256', '%PDF-new-1'), 'remote_revision' => 'archive:2026-08-26T11:00:00+02:00'], array_intersect_key($this->state->exports[1], array_flip(['remote_checksum', 'remote_revision'])));

		$second = $this->service->run();

		self::assertSame(['EXPORT P1: ' . $this->archivePath(1)], $second->actions);
		self::assertSame(2, $this->paperless->checksumRequests, 'The document that waited asks Paperless no more.');
		self::assertSame('%PDF-new-1', $this->storage->files[$this->archivePath(1)]);

		$this->paperless->documents[0]['modified'] = '2026-08-26T12:00:00+02:00';
		$third = $this->service->run();

		self::assertSame(2, $third->unchanged);
		self::assertSame(3, $this->paperless->checksumRequests, 'A new revision has a checksum of its own.');
	}

	/** A dry-run asks for the checksums that it needs, and keeps none of them. */
	public function testDryRunKeepsNoChecksum(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$exports = $this->state->exports;

		$this->service->run(true);
		$this->service->run(true);

		self::assertSame(2, $this->paperless->checksumRequests);
		self::assertSame($exports, $this->state->exports);
	}

	/**
	 * A run reads the state of the exports once, and writes it only where something changed: a run
	 * over an archive without changes, also with documents in the trash folder, writes nothing.
	 */
	public function testARunWithoutChangesReadsTheStateOnceAndWritesNothing(): void {
		$this->exportDocuments($this->documents(3));
		$this->paperless->documents = $this->documents(2);
		$this->paperless->trash = [$this->document(3) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->state->exportCalls = [];

		$moving = $this->service->run();

		self::assertSame(1, $moving->movedToTrash);
		self::assertSame(['allExports', 'saveExport'], $this->state->exportCalls, 'One read for the run, one write for the move.');
		$this->state->exportCalls = [];
		$this->clock->advance(300);

		$report = $this->service->run();

		self::assertSame(3, $report->unchanged);
		self::assertSame(['allExports'], $this->state->exportCalls);
		self::assertSame(0, $this->state->exportWrites());
	}

	/**
	 * A change of the metadata that keeps the path, such as a tag that the template doesn't use,
	 * writes the state of each document once: the checksum with the new revision.
	 */
	public function testAMetadataChangeThatKeepsThePathWritesEachDocumentOnce(): void {
		$this->exportDocuments($this->documents(3));
		foreach (array_keys($this->paperless->documents) as $index) {
			$this->paperless->documents[$index]['modified'] = '2026-08-26T11:00:00+02:00';
		}
		$this->state->exportCalls = [];

		$report = $this->service->run();

		self::assertSame(3, $report->unchanged);
		self::assertSame(['allExports', 'saveExport', 'saveExport', 'saveExport'], $this->state->exportCalls);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[1]['remote_revision']);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[1]['source_revision']);
	}

	public function testOriginalIsExportedWhenPaperlessHasNoArchiveVersion(): void {
		$document = $this->document();
		$document['archived_file_name'] = null;
		$this->exportDocument($document);

		self::assertSame('original:2026-08-26T10:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * A new content replaces the content of the file in place, so that it keeps its file ID in
	 * Nextcloud, with its shares, comments, tags, favorites and versions. Before, the file was
	 * deleted and written anew, which lost all of these and left the old copy in the trash bin.
	 */
	public function testChangedContentUpdatesTheFileInPlace(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->exported);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH));
		self::assertSame([[self::EXPORTED_PATH, 'created'], [self::EXPORTED_PATH, 'updated']], $this->storage->writes);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	/** A change of the content and of the path moves the file first, which keeps its ID, and then updates it in place. */
	public function testChangedContentAndPathMoveTheFileAndUpdateItInPlace(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		self::assertSame(['EXPORT P123: ' . $newPath], $report->actions);
		self::assertSame(1, $report->exported);
		self::assertSame(0, $report->moved);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId($newPath));
		self::assertSame([[self::EXPORTED_PATH, 'created'], [$newPath, 'updated']], $this->storage->writes);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0]);
		self::assertSame(1, $report->foldersPruned);
		self::assertSame($newPath, $this->state->exports[123]['path']);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	/** When the download fails after the move, the state knows the new path of the file, and the next run updates it there. */
	public function testFileMovedBeforeAFailedDownloadIsUpdatedAtItsNewPathInTheNextRun(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';

		$failed = $this->service->run();

		self::assertSame(['ERROR: P123: Paperless is unavailable'], $failed->actions);
		self::assertSame([$newPath => '%PDF-content'], $this->storage->files);
		self::assertSame($newPath, $this->state->exports[123]['path']);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint'], 'The fingerprint still names the content of the file.');
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0], 'The folder that the file left is pruned at once.');

		$this->paperless->downloadException = null;
		$retried = $this->service->run();

		self::assertSame(['EXPORT P123: ' . $newPath], $retried->actions);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId($newPath));
		self::assertCount(1, $this->storage->prunes, 'The file stayed where it is.');
	}

	/**
	 * Paperless-ngx reports MD5 checksums up to 2.x and SHA-256 since 3.0. Before, a change of the
	 * title on 2.x downloaded the document again and replaced the file, which lost its shares,
	 * comments and versions in Nextcloud.
	 */
	public function testMetadataChangeOnPaperless2MovesTheFileWithoutADownload(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		$renamedPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(1, $report->moved);
		self::assertSame(0, $report->exported);
		self::assertSame([$renamedPath => '%PDF-content'], $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'A change of the metadata alone must not download the document again.');
		self::assertSame(1, $this->paperless->checksumRequests);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint'], 'A move keeps the checksums of the file.');
	}

	/** An earlier version stored the SHA-256 alone, which Paperless-ngx 3 still recognizes. */
	public function testFingerprintOfAnEarlierVersionStillMovesTheFileOnPaperless3(): void {
		$this->exportDocument();
		$this->state->exports[123]['fingerprint'] = hash('sha256', '%PDF-content');
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->moved);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame([], $this->storage->reads, 'A SHA-256 of Paperless needs no read of the mirrored file.');
		self::assertSame(hash('sha256', '%PDF-content'), $this->state->exports[123]['fingerprint']);
	}

	/**
	 * On Paperless-ngx 2, the SHA-256 alone of an earlier version can't tell an unchanged document.
	 * Before, the first change of the metadata after the update downloaded every such document once
	 * more and replaced its file; now the run reads the mirrored file instead, whose SHA-256 shows
	 * that it still has the content of that download, and keeps its MD5 for the changes after it.
	 */
	public function testFingerprintOfAnEarlierVersionGainsTheMd5OfTheMirroredFileOnPaperless2(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$first = $this->service->run();
		$this->paperless->documents[0]['title'] = 'Strom August final';
		$this->paperless->documents[0]['modified'] = '2026-08-26T12:00:00+02:00';
		$second = $this->service->run();

		self::assertSame([1, 0], [$first->moved, $first->exported]);
		self::assertSame([1, 0], [$second->moved, $second->exported]);
		self::assertSame(1, $this->paperless->downloads, 'A change of the metadata alone must not download the document again.');
		self::assertSame([self::EXPORTED_PATH], $this->storage->reads, 'Only the first change reads the mirrored file.');
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint']);
		self::assertSame([self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August final [P123].pdf' => '%PDF-content'], $this->storage->files);
	}

	public function testFingerprintOfAnEarlierVersionGainsTheMd5AlsoWhenThePathStaysOnPaperless2(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint']);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * A move that waits for the next run, because the batch is full or Nextcloud refused a write,
	 * keeps the MD5 that the read of its mirrored file found, so every mirrored file is read once,
	 * also when the moves of a large archive take many runs. A dry-run saves nothing.
	 */
	public function testFingerprintOfAnEarlierVersionReadsTheMirroredFileOnceWhenTheMoveWaitsOnPaperless2(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocuments([$this->document(123), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		foreach ($this->paperless->documents as $index => $document) {
			$this->state->exports[(int)$document['id']]['fingerprint'] = hash('sha256', '%PDF-content');
			$this->paperless->documents[$index]['title'] = 'Strom August korrigiert';
			$this->paperless->documents[$index]['modified'] = '2026-08-26T11:00:00+02:00';
		}

		$dryRun = $this->service->run(true);
		self::assertSame(1, $dryRun->moved);
		self::assertSame([hash('sha256', '%PDF-content'), hash('sha256', '%PDF-content')], [$this->state->exports[123]['fingerprint'], $this->state->exports[124]['fingerprint']], 'A dry-run saves nothing.');
		$this->storage->reads = [];
		$first = $this->service->run();
		$second = $this->service->run();

		self::assertSame([1, 1, 0], [$first->moved, $first->skipped, $first->exported]);
		self::assertSame([1, 1, 0], [$second->moved, $second->unchanged, $second->exported]);
		self::assertSame(2, $this->paperless->downloads, 'A change of the metadata alone must not download the documents again.');
		self::assertEqualsCanonicalizing([$this->archivePath(123), $this->archivePath(124)], $this->storage->reads, 'Each mirrored file is read once.');
		foreach ([123, 124] as $documentId) {
			self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[$documentId]['fingerprint']);
		}
	}

	/**
	 * A mirrored file with other content than the download that the earlier version saved, or one
	 * that Nextcloud can't read, can't vouch for the document, so the document is downloaded again:
	 * the file moves to its new path and gets the content of the download there.
	 */
	#[DataProvider('mirroredFilesThatCantVouchForTheDocument')]
	public function testFingerprintOfAnEarlierVersionDownloadsWhenTheMirroredFileCantVouchForIt(string $content, ?\Throwable $readFailure): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		$this->storage->files[self::EXPORTED_PATH] = $content;
		$this->storage->readException = $readFailure;
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$report = $this->service->run();

		self::assertSame([0, 1], [$report->moved, $report->exported]);
		self::assertSame(0, $report->errors);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH], $this->storage->reads);
		self::assertSame([self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf' => '%PDF-content'], $this->storage->files);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint']);
	}

	/** @return array<string, array{string, ?\Throwable}> */
	public static function mirroredFilesThatCantVouchForTheDocument(): array {
		return [
			'changed in Nextcloud' => ['%PDF-changed-in-nextcloud', null],
			'not readable' => ['%PDF-content', new RuntimeException('Storage is not available.')],
		];
	}

	public function testFingerprintOfAnEarlierVersionDownloadsWhenTheMirroredFileIsGone(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		unset($this->storage->files[self::EXPORTED_PATH]);

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([], $this->storage->reads, 'A file that is gone is not read.');
		self::assertSame([self::EXPORTED_PATH => '%PDF-content'], $this->storage->files);
	}

	/** New content in Paperless downloads the document, also when the mirrored file still has the old one. */
	public function testFingerprintOfAnEarlierVersionDownloadsNewContentOnPaperless2(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	public function testNewRevisionOnPaperless2WithTheSameContentOnlyRecordsTheRevision(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * Before, an export read the whole document into a string to compute its checksum, so that a
	 * document larger than the free memory of PHP ended the run with a fatal error, before it saved
	 * its state, and every run after it died on the same document.
	 */
	public function testExportPassesTheDocumentToNextcloudWithoutHoldingItInMemory(): void {
		$this->configService->save(['inbox_enabled' => false]);
		$size = 16 * 1024 * 1024;
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = str_repeat('%PDF-large', intdiv($size, 10));
		$written = hash_init('sha256');
		$storage = $this->createMock(NextcloudStorageInterface::class);
		$storage->expects(self::once())->method('writeStream')->willReturnCallback(
			/** @param resource $source */
			static function (string $userId, string $path, $source) use ($written): void {
				hash_update_stream($written, $source);
			},
		);
		$service = new SyncService(
			$this->configService,
			$this->paperless,
			$storage,
			$this->state,
			new PathTemplateService(),
			$this->status,
			$this->locking,
			$this->logger,
			$this->clock,
			$this->activityPublisher($storage, $this->logger),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($this->status, $storage, $this->clock, $this->logger),
		);
		memory_reset_peak_usage();
		$before = memory_get_usage();

		$report = $service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(hash('sha256', $this->paperless->contents[123]), hash_final($written), 'Nextcloud receives the whole document.');
		self::assertLessThan($size / 2, memory_get_peak_usage() - $before, 'The document must reach Nextcloud in chunks.');
	}

	public function testDocumentsThatShareAFileAreDownloadedAgainInsteadOfMoved(): void {
		$this->paperless->documents = [$this->document(123), $this->document(456)];
		$this->paperless->contents = [123 => '%PDF-content-123', 456 => '%PDF-content-456'];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->service->run();
		// An older version rendered both documents to one path without their markers, so the
		// second write replaced the first, and the file holds the content of P456 only.
		$shared = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August.pdf';
		$this->storage->files = [$shared => '%PDF-content-456'];
		$this->state->exports[123]['path'] = $shared;
		$this->state->exports[456]['path'] = $shared;

		$report = $this->service->run();

		self::assertSame(0, $report->moved, 'Moving the shared file would give P123 the content of P456.');
		self::assertSame(2, $report->exported);
		self::assertSame([
			self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P456].pdf' => '%PDF-content-456',
			self::EXPORTED_PATH => '%PDF-content-123',
		], $this->storage->files, 'The most recently added document comes first.');
		self::assertSame(4, $this->paperless->downloads);
	}

	public function testBatchSizeLimitsTheChangesOfOneRun(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(), $this->document(124)];
		$this->paperless->contents = [123 => '%PDF-123', 124 => '%PDF-124'];

		$first = $this->service->run();
		self::assertSame(1, $first->exported);
		self::assertSame(1, $first->skipped);
		self::assertArrayNotHasKey(123, $this->state->exports);

		$second = $this->service->run();
		self::assertSame(1, $second->unchanged);
		self::assertSame(1, $second->exported);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	/** A run on the command line may make more or fewer changes than the batch size of the settings. */
	public function testBatchSizeOfARunTakesThePlaceOfThatOfTheSettings(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = $this->documents(3);
		$this->paperless->contents = [1 => '%PDF-1', 2 => '%PDF-2', 3 => '%PDF-3'];

		$dryRun = $this->service->run(true, batchSize: 2);
		$report = $this->service->run(false, batchSize: PHP_INT_MAX);

		self::assertSame(2, $dryRun->exported);
		self::assertSame(1, $dryRun->skipped);
		self::assertSame(3, $report->exported);
		self::assertSame(0, $report->skipped);
		self::assertSame(1, $this->configService->get()->batchSize, 'The settings keep their batch size.');
	}

	public function testBatchSizeOfARunIsAtLeastOne(): void {
		$this->locking->expects(self::never())->method('acquireLock');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The batch size must be at least 1.');

		$this->service->run(false, batchSize: 0);
	}

	/**
	 * occ paperless_sync:forget: the next run downloads the document again and writes it in place
	 * of its copy, also of a damaged one that it took for the document before, and also when
	 * conflicts are skipped, because the copy stays the app's own.
	 */
	public function testForgottenCopyIsExportedAgainInItsPlace(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-damaged';
		self::assertSame(1, $this->service->run()->unchanged, 'Before, the run takes the copy for the document.');

		self::assertSame([123], $this->service->forget([123, 999]), 'P999 has no copy to forget.');
		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH), 'The copy keeps its ID, with its shares and versions.');
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame(1, $this->service->run()->unchanged, 'Written again, the copy is that of the document.');
	}

	public function testForgettingEveryCopyLeavesDocumentsWithoutOneAlone(): void {
		$this->exportDocuments([$this->document(1), $this->document(2)]);
		$this->state->exports[3] = ['document_id' => 3, 'last_error' => 'Nextcloud file conflict'];

		$forgotten = $this->service->forget();

		sort($forgotten);
		self::assertSame([1, 2], $forgotten);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[1]['fingerprint']);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[2]['fingerprint']);
		self::assertSame($this->archivePath(1), $this->state->exports[1]['path'], 'The copy keeps its path, so that none is left behind.');
		self::assertSame(['document_id' => 3, 'last_error' => 'Nextcloud file conflict'], $this->state->exports[3]);
	}

	/**
	 * The copy of a document in the trash of Paperless, or of a missing one, keeps its fingerprint:
	 * it tells whether someone changed the copy before the app deletes it.
	 */
	public function testForgettingLeavesTheCopiesOfTrashedAndMissingDocumentsAlone(): void {
		$this->exportDocuments([$this->document(1), $this->document(2), $this->document(3)]);
		$this->state->exports[2]['state'] = 'trash';
		$this->state->exports[3]['state'] = 'missing';
		$exports = $this->state->exports;

		self::assertSame([1], $this->service->forget([1, 2, 3]));
		self::assertSame($exports[2], $this->state->exports[2]);
		self::assertSame($exports[3], $this->state->exports[3]);
		self::assertSame([1], $this->service->forget());
	}

	/** No run saves what forgetting forgets at the same time. */
	public function testForgettingHoldsTheLockOfARun(): void {
		$this->exportDocument();
		$this->locking->expects(self::once())->method('acquireLock')->with('paperless_sync::synchronization', ILockingProvider::LOCK_EXCLUSIVE, 'Paperless Sync');
		$this->locking->expects(self::once())->method('releaseLock')->with('paperless_sync::synchronization', ILockingProvider::LOCK_EXCLUSIVE);

		$this->service->forget([123]);
	}

	public function testForgettingWhileARunIsActiveChangesNothing(): void {
		$this->exportDocument();
		$exports = $this->state->exports;
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		try {
			$this->service->forget();
			self::fail('Forgetting must wait for no run.');
		} catch (LockedException) {
		}

		self::assertSame($exports, $this->state->exports);
	}

	public function testForgettingNeedsATargetUser(): void {
		$this->settings = [];
		$this->locking->expects(self::once())->method('releaseLock');

		$this->expectException(InvalidSettingException::class);
		$this->expectExceptionMessage('Paperless Sync is not completely configured.');

		$this->service->forget();
	}

	public function testMostRecentlyAddedDocumentsComeFirst(): void {
		$this->configService->save(['batch_size' => 2]);

		$report = $this->exportDocuments([$this->document(5), $this->document(9), $this->document(7)]);

		self::assertSame(['EXPORT P9: ' . $this->archivePath(9), 'EXPORT P7: ' . $this->archivePath(7)], $report->actions, 'A new document does not wait behind a backlog.');
	}

	public function testFailedExportsTakeTheirPlaceInTheBatch(): void {
		$this->configService->save(['batch_size' => 5]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');

		$first = $this->exportDocuments($this->documents(50));

		self::assertSame(5, $this->paperless->downloads, 'A run that cannot write downloads no more documents than the batch size allows.');
		self::assertSame(5, $first->errors);
		self::assertSame(45, $first->skipped);
		self::assertSame('The file name is not allowed', $this->state->exports[46]['last_error']);
		self::assertArrayNotHasKey(45, $this->state->exports);

		$second = $this->service->run();

		self::assertSame(10, $this->paperless->downloads);
		self::assertSame(45, $second->skipped);
		self::assertSame(array_map(static fn (int $id): string => "ERROR: P{$id}: The file name is not allowed", range(45, 41)), $second->actions, 'The documents that failed wait behind the others.');
	}

	public function testExportsThatKeepFailingLeaveTheBatchToTrashedDocuments(): void {
		$this->exportDocuments([$this->document(123), $this->document(124)]);
		$this->configService->save(['batch_size' => 1, 'conflict_mode' => 'skip']);
		$this->paperless->documents = [$this->document(122), $this->document(123)];
		$this->storage->files[$this->archivePath(122)] = 'a file of the user';
		$this->paperless->trash = [$this->document(124) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$trashPath = self::ARCHIVE . '/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P124].pdf';

		$report = $this->service->run();

		self::assertSame([
			'ERROR: P122: Nextcloud file conflict at ' . $this->archivePath(122) . '.',
			'TRASH P124: ' . $trashPath,
		], $report->actions, 'A failed export is no change, so the trashed document still moves.');
		self::assertSame(1, $report->movedToTrash);
	}

	public function testExportsThatKeepFailingLeaveTheBatchToMissingDocuments(): void {
		$this->exportDocuments([$this->document(123)]);
		$this->configService->save(['batch_size' => 1, 'conflict_mode' => 'skip', 'missing_grace_runs' => 1]);
		$this->paperless->documents = [$this->document(122)];
		$this->storage->files[$this->archivePath(122)] = 'a file of the user';

		$report = $this->service->run();

		self::assertSame([
			'ERROR: P122: Nextcloud file conflict at ' . $this->archivePath(122) . '.',
			'MISSING P123: ' . $this->deletedToday(),
		], $report->actions);
		self::assertSame('missing', $this->state->exports[123]['state']);
	}

	public function testFailedMovesToTheDeletedFolderTakeTheirPlaceInTheBatch(): void {
		$documents = $this->documents(5);
		$this->exportDocuments($documents);
		$this->configService->save(['batch_size' => 2]);
		$this->paperless->documents = [];
		$this->paperless->trash = array_map(static fn (array $document): array => $document + ['deleted_at' => '2026-08-27T08:00:00+02:00'], $documents);
		$this->storage->moveException = new RuntimeException('The file is locked');

		$report = $this->service->run();

		self::assertSame(['ERROR: Trash P1: The file is locked', 'ERROR: Trash P2: The file is locked'], $report->actions, 'A run that cannot move tries no more moves than the batch size allows.');
		self::assertSame(3, $report->skipped);
	}

	public function testDocumentWhoseExportFailedWaitsBehindTheOthers(): void {
		$this->storage->writeException = new RuntimeException('The file name is not allowed');
		$this->exportDocument();
		$this->storage->writeException = null;
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents[] = $this->document(124);
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();

		self::assertSame(['EXPORT P124: ' . $this->archivePath(124)], $first->actions);
		self::assertSame(1, $first->skipped);

		$second = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $second->actions);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testDocumentsThatKeepFailingTakeTurns(): void {
		$this->configService->save(['batch_size' => 2]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');
		$this->exportDocuments($this->documents(4));
		$this->service->run();
		// The first two failed in an earlier run than the other two.
		foreach ([1 => 100, 2 => 100, 3 => 200, 4 => 200] as $id => $lastSeen) {
			$this->state->exports[$id]['last_seen'] = $lastSeen;
		}

		$third = $this->service->run();

		self::assertSame(['ERROR: P2: The file name is not allowed', 'ERROR: P1: The file name is not allowed'], $third->actions);

		$fourth = $this->service->run();

		self::assertSame(['ERROR: P4: The file name is not allowed', 'ERROR: P3: The file name is not allowed'], $fourth->actions);
	}

	/** @return array<string, array{\Throwable}> */
	public static function storageFailures(): array {
		return [
			'full quota' => [new NotEnoughSpaceException('Not enough free space')],
			'read-only storage' => [new NotPermittedException('Could not create path')],
			'unavailable external storage' => [new class('The storage is not available') extends StorageNotAvailableException {
				// Nextcloud's constructor translates a hint, which needs a running server.
				public function __construct(string $message) {
					\Exception::__construct($message);
				}
			}],
		];
	}

	#[DataProvider('storageFailures')]
	public function testStorageThatCannotBeWrittenEndsTheExportsOfTheRun(\Throwable $failure): void {
		$this->storage->writeException = $failure;
		$this->storage->writable = false;
		$this->logger->expects(self::once())->method('warning')->with(
			'Nextcloud cannot store files in the archive, so this run exports and moves no further documents: {error}',
			['error' => $failure->getMessage(), 'exception' => $failure],
		);

		$report = $this->exportDocuments($this->documents(3));

		$error = $failure->getMessage();
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(1, $report->errors);
		self::assertSame(2, $report->skipped);
		self::assertSame([
			"ERROR: P3: {$error}",
			'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.',
		], $report->actions);
		self::assertSame([['type' => 'error', 'document' => 3, 'detail' => "P3: {$error}"], ['type' => 'export-stopped']], $report->entries);
		self::assertSame($error, $this->state->exports[3]['last_error']);
		self::assertArrayNotHasKey(2, $this->state->exports);
	}

	/** @return array<string, array{bool|\Throwable}> */
	public static function archiveChecks(): array {
		return [
			'an archive that can still be written' => [true],
			'a check that fails' => [new RuntimeException('The storage is not available')],
		];
	}

	#[DataProvider('archiveChecks')]
	public function testPathThatNextcloudRefusesConcernsOnlyItsDocument(bool|\Throwable $writable): void {
		$this->storage->writeFailures[$this->archivePath(3)] = new NotPermittedException('Could not create folder');
		$this->storage->writable = $writable;

		$report = $this->exportDocuments($this->documents(3));

		$stopped = $writable instanceof \Throwable;
		self::assertSame(['ERROR: P3: Could not create folder', ...($stopped
			? ['EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.']
			: ['EXPORT P2: ' . $this->archivePath(2), 'EXPORT P1: ' . $this->archivePath(1)])], $report->actions);
		self::assertSame([self::ARCHIVE], $this->storage->writableChecks, 'Only the archive folder tells whether the whole archive refuses files.');
	}

	public function testStorageThatCannotBeWrittenStillRemovesExcludedCopies(): void {
		$this->exportDocuments([$this->document(122), $this->document()]);
		$this->configService->save(['excluded_tags' => 'Privat']);
		$this->paperless->tagInfo = ['names' => ['9' => 'Privat'], 'inbox' => []];
		$this->paperless->documents = [$this->document(122), $this->document(123), $this->document(124)];
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->contents[124] = '%PDF-124';
		$this->storage->writeException = new NotEnoughSpaceException('Not enough free space');

		$report = $this->service->run();

		self::assertSame(3, $this->paperless->downloads, 'Only the first document of this run is downloaded.');
		self::assertSame(['ERROR: P124: Not enough free space', 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.', 'REMOVE EXCLUDED P122: ' . $this->archivePath(122)], $report->actions, 'Removing a copy frees space, so it goes on.');
		self::assertArrayNotHasKey($this->archivePath(122), $this->storage->files);
		self::assertSame(1, $report->unchanged);
	}

	public function testStorageThatCannotBeWrittenMovesNoDocumentToTheDeletedFolder(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(122), $this->document(123)]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';
		$this->paperless->trash = [$this->document(122) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->storage->writeException = new NotEnoughSpaceException('Not enough free space');

		$report = $this->service->run();

		self::assertSame(['ERROR: P124: Not enough free space', 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.'], $report->actions);
		self::assertSame(2, $report->skipped, 'The trashed and the missing document wait for the next run.');
		self::assertSame('active', $this->state->exports[122]['state']);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	/** @return array<string, array{bool, string}> */
	public static function phasesThatMove(): array {
		return [
			// The trash in the order of Paperless, the missing documents in the order of their state.
			'trash' => [true, 'Trash P122'],
			'missing' => [false, 'Missing P123'],
		];
	}

	#[DataProvider('phasesThatMove')]
	public function testStorageThatCannotBeWrittenEndsTheMovesToTheDeletedFolder(bool $trashed, string $error): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(122), $this->document(123)]);
		$this->paperless->documents = [];
		if ($trashed) {
			$this->paperless->trash = array_map(fn (int $id): array => $this->document($id) + ['deleted_at' => '2026-08-27T08:00:00+02:00'], [122, 123]);
		}
		$this->storage->moveException = new NotPermittedException('Could not create folder');
		$this->storage->writable = false;

		$report = $this->service->run();

		self::assertSame(["ERROR: {$error}: Could not create folder", 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.'], $report->actions);
		self::assertSame(1, $report->skipped, 'The other document waits for the next run.');
	}

	public function testNoticesOfTheRunAreKeptPastTheLimitOfTheReport(): void {
		$this->configService->save(['batch_size' => 100]);
		foreach (range(1, 100) as $number) {
			$this->storage->files["Dokumente/Paperless/Eingang/scan{$number}.pdf"] = "%PDF-{$number}";
		}
		$this->paperless->documentsListed = 1;

		$report = $this->service->run();

		self::assertCount(101, $report->actions);
		self::assertStringStartsWith('SCAN INCOMPLETE:', $report->actions[100], 'A hundred imports do not hide that the run counted no document as missing.');
	}

	public function testDryRunShowsTheTrashAlsoBehindAHundredImports(): void {
		$this->exportDocument();
		$this->trashDocument();
		foreach (range(1, 100) as $number) {
			$this->storage->files["Dokumente/Paperless/Eingang/scan{$number}.pdf"] = "%PDF-{$number}";
		}

		$report = $this->service->run(true);

		self::assertSame(100, $report->importsSubmitted);
		self::assertCount(100, $report->actions);
		self::assertSame('TRASH P123: ' . $this->trashFolderPath(), $report->actions[99], 'The move to the deleted folder takes the place of the last import.');
		self::assertSame(1, $report->omittedActions);
	}

	public function testErrorOfADocumentIsLoggedOnceUntilItChanges(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});
		$unavailable = new RuntimeException('Paperless is unavailable');
		$this->paperless->downloadException = $unavailable;

		$this->exportDocument();
		$this->service->run();
		$timedOut = new RuntimeException('Paperless timed out');
		$this->paperless->downloadException = $timedOut;
		$this->service->run();

		$message = 'Paperless Sync could not synchronize the document P{documentId}: {error}';
		self::assertSame([
			[$message, ['documentId' => 123, 'path' => self::EXPORTED_PATH, 'error' => 'Paperless is unavailable']],
			[$message, ['documentId' => 123, 'path' => self::EXPORTED_PATH, 'error' => 'Paperless timed out']],
		], $warnings, 'The second run fails as the first did, so only the first and the third are logged, without the trace of the exception.');
	}

	public function testDryRunLogsTheErrorOfADocumentThatNoRunRecorded(): void {
		$this->paperless->documents = [['id' => 12.5] + $this->document()];
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not synchronize the document P{documentId}: {error}',
			self::callback(static fn (array $context): bool => $context['documentId'] === 12 && $context['path'] === ''),
		);

		$this->service->run(true);
	}

	/** @return array<string, array{mixed}> */
	public static function invalidIds(): array {
		return [
			'missing' => [null],
			'text' => ['abc'],
			'a number followed by text' => ['12abc'],
			'zero' => [0],
			'negative' => [-3],
		];
	}

	#[DataProvider('invalidIds')]
	public function testDocumentWithoutAValidIdFailsTheRun(mixed $id): void {
		$this->paperless->documents = [['id' => $id, 'title' => 'Broken']];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Paperless returned a document without a valid ID.');
		$this->service->run();
	}

	public function testFailedDownloadIsReportedAndRetriedInTheNextRun(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');

		$failed = $this->exportDocument();

		self::assertSame(1, $failed->errors);
		self::assertSame(['ERROR: P123: Paperless is unavailable'], $failed->actions);
		self::assertSame('Paperless is unavailable', $this->state->exports[123]['last_error']);
		self::assertSame([], $this->storage->files);
		self::assertSame('completed-with-errors', $this->settings['status_last_state']);

		$this->paperless->downloadException = null;
		$retried = $this->service->run();
		self::assertSame(1, $retried->exported);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	/** @return array<string, array{string}> */
	public static function emptyMessages(): array {
		return [
			'empty' => [''],
			'only whitespace' => [" \n"],
		];
	}

	#[DataProvider('emptyMessages')]
	public function testErrorWithoutMessageNamesTheExceptionClass(string $message): void {
		$this->paperless->downloadException = new LogicException($message);

		$report = $this->exportDocument();

		self::assertSame(['ERROR: P123: LogicException'], $report->actions);
	}

	public function testErrorIsReportedWithoutTheWhitespaceAroundIt(): void {
		$this->paperless->downloadException = new RuntimeException("  Paperless timed out\n");

		$report = $this->exportDocument();

		self::assertSame(['ERROR: P123: Paperless timed out'], $report->actions);
		self::assertSame('Paperless timed out', $this->state->exports[123]['last_error']);
	}

	/** @return array<string, array{string}> */
	public static function phasesWithErrors(): array {
		return [
			'export' => ['export'],
			'removal of an excluded copy' => ['excluded'],
			'move to the trash folder' => ['trash'],
			'move of a missing document' => ['missing'],
			'check of an import' => ['task'],
			'upload that Paperless refused' => ['refused'],
			'upload that failed' => ['upload'],
		];
	}

	/** The state keeps the first 4000 characters of an error, not bytes, in every phase of a run. */
	#[DataProvider('phasesWithErrors')]
	public function testLongErrorKeepsItsFirst4000Characters(string $phase): void {
		$error = str_repeat('ä', 5000);
		$inbox = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->configService->save(['missing_grace_runs' => 1]);
		switch ($phase) {
			case 'export':
				$this->paperless->downloadException = new RuntimeException($error);
				$this->exportDocument();
				break;
			case 'excluded':
				$this->exportDocument();
				$this->paperless->documents[0]['tags'] = [9];
				$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
				$this->storage->deleteException = new RuntimeException($error);
				$this->service->run();
				break;
			case 'trash':
				$this->exportDocument();
				$this->trashDocument();
				$this->storage->moveException = new RuntimeException($error);
				$this->service->run();
				break;
			case 'missing':
				$this->exportDocument();
				$this->paperless->documents = [];
				$this->storage->moveException = new RuntimeException($error);
				$this->service->run();
				break;
			case 'task':
				$this->storage->files[$inbox] = '%PDF-inbox';
				$this->service->run();
				$this->paperless->taskException = new RuntimeException($error);
				$this->service->run();
				break;
			case 'refused':
				$this->storage->files[$inbox] = '%PDF-inbox';
				$this->paperless->uploadException = new PaperlessUploadException($error, true, 400);
				$this->service->run();
				break;
			default:
				$this->storage->files[$inbox] = '%PDF-inbox';
				$this->paperless->uploadException = new RuntimeException($error);
				$this->service->run();
		}

		$state = in_array($phase, ['task', 'refused', 'upload'], true) ? $this->state->imports[$inbox] : $this->state->exports[123];
		self::assertSame(str_repeat('ä', 4000), $state['last_error']);
	}

	public function testLongErrorThatRecursIsLoggedOnce(): void {
		$error = str_repeat('ä', 5000);
		$this->paperless->downloadException = new RuntimeException($error);
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not synchronize the document P{documentId}: {error}',
			['documentId' => 123, 'path' => self::EXPORTED_PATH, 'error' => $error],
		);

		$this->exportDocument();
		$this->service->run();
	}

	/** A document whose path can't be rendered names the copy of its last export in the warning. */
	public function testErrorOfAnExportedDocumentWithoutANewPathNamesItsCopy(): void {
		$this->exportDocument($this->document(12));
		$this->paperless->documents = [['id' => 12.5] + $this->document(12)];
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not synchronize the document P{documentId}: {error}',
			['documentId' => 12, 'path' => $this->archivePath(12), 'error' => 'Paperless returned a document without a numeric ID.'],
		);

		$this->service->run();
	}

	public function testDryRunReportsAnUnusableDocumentWithoutRecordingIt(): void {
		$document = $this->document();
		$document['id'] = 12.5;

		$this->paperless->documents = [$document];
		$report = $this->service->run(true);

		self::assertSame(['ERROR: P12: Paperless returned a document without a numeric ID.'], $report->actions);
		self::assertSame([], $this->state->exports);
	}

	public function testExistingFileIsKeptWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->storage->files[self::EXPORTED_PATH] = 'a file of the user';

		$report = $this->exportDocument();

		self::assertSame(1, $report->errors);
		self::assertSame('a file of the user', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('Nextcloud file conflict at ' . self::EXPORTED_PATH . '.', $this->state->exports[123]['last_error']);
		self::assertSame(0, $this->paperless->downloads, 'The file stays, so the download would be in vain.');
	}

	/** The skip policy protects the files of the user, not the copy that the app wrote itself (#98). */
	public function testOwnCopyIsUpdatedInPlaceWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH));
		self::assertNull($this->state->exports[123]['last_error']);
	}

	/** A copy whose content Paperless changed while the document was missing is its own as well. */
	public function testReturningDocumentUpdatesItsOwnCopyWhenConflictsAreSkipped(): void {
		$this->configService->save(['trash_mode' => 'keep', 'missing_grace_runs' => 1, 'conflict_mode' => 'skip']);
		$this->exportDocument();
		$listed = $this->paperless->documents;
		$this->paperless->documents = [];
		$this->service->run();
		$listed[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->documents = $listed;
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	/** A copy that the user changed in Nextcloud is no longer the app's own, and the skip policy leaves it alone. */
	public function testCopyThatTheUserChangedIsAConflictWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-annotated by the user';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.'], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-annotated by the user'], $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'The file stays, so the download would be in vain.');
	}

	public function testCopyThatCantBeReadIsAConflictWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->storage->readException = new RuntimeException('The storage is not available');
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.'], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content'], $this->storage->files);
	}

	/** The move takes the own copy to a free path, so the update in place that follows is no conflict. */
	public function testChangedContentAndPathMoveTheOwnCopyWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		self::assertSame(['EXPORT P123: ' . $newPath], $report->actions);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId($newPath));
	}

	/** The move would take a copy that someone changed along, and the new content would replace their edit. */
	public function testChangedCopyStaysWhenContentAndPathChangeAndConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-annotated by the user';
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$dryRun = $this->service->run(true);
		$report = $this->service->run();

		$conflict = 'ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.';
		self::assertSame([$conflict], $dryRun->actions);
		self::assertSame([$conflict], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-annotated by the user'], $this->storage->files);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame(1, $this->paperless->downloads, 'The copy stays, so the download would be in vain.');
	}

	/** A write in place that fails halfway leaves the own copy incomplete, which the next run writes again. */
	public function testOwnCopyThatAFailedUpdateLeftIncompleteIsWrittenAgainWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';
		$this->storage->writeFailures[self::EXPORTED_PATH] = new RuntimeException('The disk is full');

		$failed = $this->service->run();
		self::assertSame(['ERROR: P123: The disk is full'], $failed->actions);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[123]['fingerprint']);
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-cont';
		$this->storage->writeFailures = [];

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	/** The state records the new path after the move and the write in place before it starts, so a failed write leaves the moved copy the app's own. */
	public function testMovedCopyThatAFailedUpdateLeftIncompleteIsWrittenAgainWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';
		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		$this->storage->writeFailures[$newPath] = new RuntimeException('The disk is full');

		$this->service->run();
		self::assertSame(['path' => $newPath, 'fingerprint' => DocumentFingerprint::PENDING], array_intersect_key($this->state->exports[123], ['path' => true, 'fingerprint' => true]));
		$this->storage->files[$newPath] = '%PDF-cont';
		$this->storage->writeFailures = [];

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . $newPath], $report->actions);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
	}

	public function testMoveOntoAnExistingFileIsAConflictWhenConflictsAreSkipped(): void {
		$this->exportDocument();
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		$this->storage->files[$newPath] = 'a file of the user';

		$report = $this->service->run();

		self::assertSame(["ERROR: P123: Nextcloud file conflict at {$newPath}."], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content', $newPath => 'a file of the user'], $this->storage->files);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testDryRunReportsAConflictWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->storage->files[self::EXPORTED_PATH] = 'a file of the user';
		$this->paperless->documents = [$this->document()];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$report = $this->service->run(true);

		self::assertSame(['ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.'], $report->actions);
		self::assertSame(0, $report->exported);
		self::assertSame([], $this->state->exports);
	}

	public function testImportsInboxAndDeletesSourceAfterSuccessfulTask(): void {
		$path = 'Dokumente/Paperless/Eingang/Versicherung/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';

		$submitted = $this->service->run();
		self::assertSame(1, $submitted->importsSubmitted);
		self::assertSame(['IMPORT: ' . $path], $submitted->actions);
		self::assertSame(['police.pdf' => '%PDF-inbox'], $this->paperless->uploads);
		self::assertArrayHasKey($path, $this->storage->files);

		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$completed = $this->service->run();
		self::assertSame(1, $completed->importsSucceeded);
		self::assertSame(['IMPORT SUCCESS: ' . $path], $completed->actions);
		self::assertSame([['type' => 'import-success', 'path' => $path]], $completed->entries, 'Paperless named no document.');
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	public function testFailedImportMovesFileAndWritesDiagnostic(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];

		$report = $this->service->run();
		$errorPath = 'Dokumente/Paperless/Fehler/broken.pdf';
		self::assertSame(1, $report->importsFailed);
		self::assertSame(['IMPORT ERROR: ' . $path . ' -> ' . $errorPath], $report->actions);
		self::assertSame([['type' => 'import-error', 'path' => $path, 'target' => $errorPath, 'detail' => 'Unsupported mime type']], $report->entries, 'The report says why, which its line leaves out.');
		self::assertSame('broken', $this->storage->files[$errorPath]);
		self::assertSame("Paperless import failed\n\nUnsupported mime type\n\nPaperless task: task-1\n", $this->storage->files[$errorPath . '.error.txt']);
		self::assertSame([], $this->state->imports, 'The file left the inbox, and so did its import.');
	}

	public function testSuccessfulImportNamesTheDocumentThatPaperlessCreated(): void {
		$this->configService->save(['delete_inbox_after_success' => false]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => 'Success. New document id 456 created', 'documentId' => 456];

		$report = $this->service->run();

		self::assertSame(['IMPORT SUCCESS: ' . $path . ' -> P456'], $report->actions);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame('success', $this->state->imports[$path]['status']);
	}

	/**
	 * Every end of a task that created no document moves the file to the error folder, with what
	 * Paperless said and the task to look it up by: a failure, a duplicate, which Paperless-ngx 3.x
	 * also reports as a failure, and a task that Paperless revoked, which ends no other way.
	 *
	 * @return array<string, array{array{status: string, message: string}, string}>
	 */
	public static function failedTasks(): array {
		return [
			'a failure' => [
				['status' => 'FAILURE', 'message' => 'police.pdf: Unsupported mime type application/octet-stream'],
				"Paperless import failed\n\npolice.pdf: Unsupported mime type application/octet-stream\n\nPaperless task: task-1\n",
			],
			'a duplicate' => [
				['status' => 'FAILURE', 'message' => 'Not consuming: It is a duplicate of document #123'],
				"Paperless import failed\n\nNot consuming: It is a duplicate of document #123\n\nPaperless task: task-1\n",
			],
			'a revoked task' => [
				['status' => 'REVOKED', 'message' => ''],
				"Paperless import failed\n\nPaperless cancelled the task before it finished (REVOKED), so it created no document.\n\nPaperless task: task-1\n",
			],
			'a revoked task with a reason' => [
				['status' => 'REVOKED', 'message' => 'Terminated'],
				"Paperless import failed\n\nPaperless cancelled the task before it finished (REVOKED), so it created no document: Terminated\n\nPaperless task: task-1\n",
			],
			'a failure without a reason' => [
				['status' => 'FAILURE', 'message' => ''],
				"Paperless import failed\n\nPaperless gave no reason; its log may have one.\n\nPaperless task: task-1\n",
			],
		];
	}

	/** @param array{status: string, message: string} $task */
	#[DataProvider('failedTasks')]
	public function testFailedTaskMovesTheFileWithWhatPaperlessSaid(array $task, string $diagnostic): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;

		$report = $this->service->run();

		$errorPath = 'Dokumente/Paperless/Fehler/police.pdf';
		self::assertSame(['IMPORT ERROR: ' . $path . ' -> ' . $errorPath], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(0, $report->errors);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame($diagnostic, $this->storage->files[$errorPath . '.error.txt']);
		self::assertSame([], $this->state->imports);
	}

	/**
	 * A task that Paperless doesn't list a day after the upload: its queue lost it, as after a
	 * restart of Redis. Without an end, the file would wait in the inbox for good, so it is uploaded
	 * again, as after a failed upload. So is a file that changed since its upload, also while
	 * Paperless still lists the task of its old content.
	 *
	 * @return array<string, array{array{status: string, message: string, listed: bool}, string, bool}>
	 */
	public static function lostTasks(): array {
		return [
			'not listed' => [['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false], 'PENDING: Paperless does not list the task.', false],
			'listed, of a file that changed since' => [['status' => 'STARTED', 'message' => '', 'listed' => true], 'STARTED', true],
		];
	}

	/** @param array{status: string, message: string, listed: bool} $task */
	#[DataProvider('lostTasks')]
	public function testTaskThatPaperlessLostIsUploadedAgainADayAfterTheUpload(array $task, string $state, bool $changed): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->clock->advance(86400);
		if ($changed) {
			$this->storage->files[$path] = '%PDF-inbox, saved anew';
		}
		$error = "Paperless has not finished the import task task-1 a day after the upload ({$state})";
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless has not finished the import of the inbox file {path} a day after its upload; it is uploaded again in {minutes} minutes: {error}',
			['path' => $path, 'error' => $error, 'task' => 'task-1', 'minutes' => 15],
		);

		$lost = $this->service->run();

		self::assertSame([hash('sha256', $this->storage->files[$path])], $this->paperless->searches, 'Paperless may have the file after all.');
		self::assertSame(['ERROR: Import ' . $path . ': ' . $error . ' (attempt 1, the next in 15 minutes)'], $lost->actions);
		self::assertSame(1, $lost->errors);
		self::assertSame(0, $lost->importsFailed);
		self::assertSame('retry', $this->state->imports[$path]['status']);
		self::assertSame(1, $this->state->imports[$path]['attempts']);
		self::assertSame(900, $this->state->imports[$path]['retry_at'] - $this->state->imports[$path]['submitted_at']);
		self::assertSame($error, $this->state->imports[$path]['last_error']);
		self::assertArrayHasKey($path, $this->storage->files, 'The file stays in the inbox.');
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts);

		$this->clock->advance(900);
		$again = $this->service->run();
		self::assertSame(1, $again->importsSubmitted);
		self::assertSame(['police.pdf', 'police.pdf'], $this->paperless->uploadAttempts);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	/**
	 * A task that Paperless still lists a day after the upload, unfinished: its worker may be down,
	 * or the task may wait in a long queue, after a large import on a small machine. Paperless-ngx
	 * 3.x imports a duplicate as a second document unless PAPERLESS_CONSUMER_DELETE_DUPLICATES is
	 * on, so a new upload could put the document into Paperless twice. The file moves to the error
	 * folder instead, with a note that the document may still appear.
	 *
	 * @return array<string, array{array{status: string, message: string}, string}>
	 */
	public static function unfinishedTasks(): array {
		return [
			'pending' => [['status' => 'PENDING', 'message' => ''], 'PENDING'],
			'started' => [['status' => 'STARTED', 'message' => ''], 'STARTED'],
			'a status of the future, with a text' => [['status' => 'RETRYING', 'message' => 'Waiting for OCR'], 'RETRYING: Waiting for OCR'],
		];
	}

	/** @param array{status: string, message: string} $task */
	#[DataProvider('unfinishedTasks')]
	public function testTaskThatPaperlessHasNotFinishedADayAfterTheUploadMovesTheFileWithoutUploadingItAgain(array $task, string $state): void {
		$path = 'Dokumente/Paperless/Eingang/Versicherung/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->clock->advance(86400);
		$error = "Paperless has not finished the import task task-1 a day after the upload ({$state})";
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless has not finished the import of the inbox file {path} a day after its upload; it moves to the error folder, as Paperless may still import it: {error}',
			['path' => $path, 'error' => $error, 'task' => 'task-1'],
		);

		$report = $this->service->run();

		$errorPath = 'Dokumente/Paperless/Fehler/Versicherung/police.pdf';
		self::assertSame(['IMPORT ERROR: ' . $path . ' -> ' . $errorPath], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(0, $report->errors);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame('%PDF-inbox', $this->storage->files[$errorPath]);
		self::assertSame(
			"Paperless import not finished\n\n"
			. "Paperless has not finished the import task a day after the upload ({$state}). Its worker may be down, or the task may still wait in its queue, so Paperless may still create the document. The file was not uploaded again, because Paperless-ngx 3.x imports a duplicate as a second document.\n\n"
			. "Should the document not appear in Paperless, check that the worker of Paperless runs, then move the file back into the inbox.\n\n"
			. "Paperless task: task-1\n",
			$this->storage->files[$errorPath . '.error.txt'],
		);
		self::assertSame([], $this->state->imports);
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts, 'No second upload, which could make a duplicate.');
	}

	/**
	 * Paperless has the content of the file a day after the upload, though its task doesn't say
	 * so: someone removed the finished task, or Paperless no longer lists it. The checksum of its
	 * original is the SHA-256 of the file since Paperless-ngx 3.0 and its MD5 before.
	 *
	 * @return array<string, array{array{status: string, message: string, listed: bool}, string, bool}>
	 */
	public static function importedFiles(): array {
		return [
			'3.x, not listed' => [['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false], 'sha256', true],
			'2.x, still pending' => [['status' => 'PENDING', 'message' => '', 'listed' => true], 'md5', true],
			'3.x, still started, the file kept' => [['status' => 'STARTED', 'message' => '', 'listed' => true], 'sha256', false],
		];
	}

	/** @param array{status: string, message: string, listed: bool} $task */
	#[DataProvider('importedFiles')]
	public function testFileThatPaperlessHasADayAfterTheUploadCountsAsImported(array $task, string $algorithm, bool $deleteAfterSuccess): void {
		$this->configService->save(['delete_inbox_after_success' => $deleteAfterSuccess]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->paperless->originals[hash($algorithm, '%PDF-inbox')] = 456;
		$this->clock->advance(86400);
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run();

		self::assertSame(['IMPORT SUCCESS: ' . $path . ' -> P456'], $report->actions);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame(0, $report->errors);
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts);
		if ($deleteAfterSuccess) {
			self::assertArrayNotHasKey($path, $this->storage->files);
			self::assertSame([], $this->state->imports);
		} else {
			self::assertSame('success', $this->state->imports[$path]['status']);
			self::assertSame(hash('sha256', '%PDF-inbox'), $this->state->imports[$path]['etag']);
			self::assertSame(0, $this->service->run()->importsSubmitted, 'The kept file stays imported.');
		}
	}

	/** A file that can't be read can't be looked for in Paperless; it ends as if Paperless didn't have it. */
	public function testUnreadableFileIsNotLookedForInPaperless(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false];
		$this->clock->advance(86400);
		$this->storage->readException = new RuntimeException('Synthetic read failure');

		$report = $this->service->run();

		self::assertSame([], $this->paperless->searches);
		self::assertSame('retry', $this->state->imports[$path]['status']);
		self::assertSame(1, $report->errors);
	}

	public function testTaskThatPaperlessHasNotFinishedYetWaitsForADay(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'STARTED', 'message' => ''];
		// The last second of the day after the upload, which the clock holds: a second later, the
		// tests above give up on the task.
		$this->clock->advance(86400 - 1);
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->errors);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertSame([], $this->paperless->searches);
	}

	/** @return array<string, array{string}> */
	public static function unansweredQuestions(): array {
		return [
			'the task' => ['taskException'],
			'the document with the content of the file' => ['findException'],
		];
	}

	#[DataProvider('unansweredQuestions')]
	public function testTaskOfAPaperlessThatDoesNotAnswerIsNotGivenUp(string $failure): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->clock->advance(3 * 86400);
		$this->paperless->{$failure} = new RuntimeException('Paperless timed out');

		$report = $this->service->run();

		self::assertSame('pending', $this->state->imports[$path]['status'], 'Only an answer of Paperless tells that it has not finished the task.');
		self::assertSame(1, $report->errors);
		self::assertArrayHasKey($path, $this->storage->files);
		self::assertCount(1, $this->paperless->uploadAttempts);
	}

	/** @return array<string, array{array{status: string, message: string, listed: bool}, string}> */
	public static function unfinishedTasksInADryRun(): array {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';

		return [
			'not listed' => [
				['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false],
				'ERROR: Import ' . $path . ': Paperless has not finished the import task task-1 a day after the upload (PENDING: Paperless does not list the task.); a run uploads it again',
			],
			'still pending' => [['status' => 'PENDING', 'message' => '', 'listed' => true], 'IMPORT ERROR: ' . $path . ' -> Dokumente/Paperless/Fehler/police.pdf'],
		];
	}

	/** @param array{status: string, message: string, listed: bool} $task */
	#[DataProvider('unfinishedTasksInADryRun')]
	public function testDryRunReportsATaskThatPaperlessHasNotFinishedWithoutChangingAnything(array $task, string $action): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->clock->advance(86400);
		$imports = $this->state->imports;
		$files = $this->storage->files;
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run(true);

		self::assertSame([$action], $report->actions);
		self::assertSame($imports, $this->state->imports);
		self::assertSame($files, $this->storage->files);
		self::assertCount(1, $this->paperless->uploadAttempts);
	}

	public function testDryRunReportsAFileThatPaperlessHasWithoutChangingAnything(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->originals[hash('sha256', '%PDF-inbox')] = 456;
		$this->clock->advance(86400);
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame(['IMPORT SUCCESS: ' . $path . ' -> P456'], $report->actions);
		self::assertSame($imports, $this->state->imports);
		self::assertArrayHasKey($path, $this->storage->files);
	}

	public function testRunThatCannotReachPaperlessNamesNoPaperless(): void {
		$this->paperless->server = ['version' => '3.3.0', 'apiVersion' => '9'];
		$this->service->run(true);
		$this->paperless->connectionException = new RuntimeException('Paperless answered 406');

		try {
			$this->service->run(true);
			self::fail('A failed connection must fail the run.');
		} catch (RuntimeException) {
		}

		self::assertSame('', $this->settings['status_paperless_version'], 'The settings page must not name the Paperless of the run before.');
		self::assertSame('', $this->settings['status_paperless_api_version']);
	}

	public function testRunKeepsTheVersionOfPaperlessForTheStatus(): void {
		$this->paperless->server = ['version' => '3.3.0', 'apiVersion' => '9'];

		$this->service->run(true);

		self::assertSame('3.3.0', $this->settings['status_paperless_version']);
		self::assertSame('9', $this->settings['status_paperless_api_version']);
	}

	public function testFailedTaskIsNotSubmittedAgainWhenErrorHandlingThrows(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];
		$this->storage->writeTextException = new RuntimeException('Synthetic diagnostic write failure');

		$report = $this->service->run();

		self::assertSame(0, $report->importsSubmitted);
		self::assertSame(1, $report->errors);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testUnreadableTaskStatusIsRetriedInTheNextRun(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$timedOut = new RuntimeException('Paperless timed out');
		$this->paperless->taskException = $timedOut;
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not check the import of the inbox file {path}: {error}',
			['path' => $path, 'error' => 'Paperless timed out'],
		);

		$report = $this->service->run();
		$this->service->run();
		$this->service->run(true);

		self::assertSame(['ERROR: Import task ' . $path . ': Paperless timed out'], $report->actions);
		self::assertSame([['type' => 'error', 'path' => $path, 'detail' => 'Import task ' . $path . ': Paperless timed out']], $report->entries);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertSame('Paperless timed out', $this->state->imports[$path]['last_error']);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testImportStateOfAVanishedFileIsForgotten(): void {
		$this->state->imports['Dokumente/Paperless/Eingang/gone.pdf'] = ['path' => 'Dokumente/Paperless/Eingang/gone.pdf', 'status' => 'pending', 'task_id' => 'task-9'];

		$report = $this->service->run();

		self::assertSame([], $this->state->imports);
		self::assertSame(0, $report->importsSubmitted);
	}

	public function testDryRunKeepsTheImportStateOfAVanishedFile(): void {
		$this->state->imports['Dokumente/Paperless/Eingang/gone.pdf'] = ['path' => 'Dokumente/Paperless/Eingang/gone.pdf', 'status' => 'pending', 'task_id' => 'task-9'];

		$this->service->run(true);

		self::assertArrayHasKey('Dokumente/Paperless/Eingang/gone.pdf', $this->state->imports);
	}

	/** Each import of the state is checked, whatever the imports before it did. */
	public function testEveryImportOfTheStateIsChecked(): void {
		$inbox = 'Dokumente/Paperless/Eingang/';
		foreach (['kept', 'changed', 'due', 'done'] as $name) {
			$this->storage->files["{$inbox}{$name}.pdf"] = "%PDF-{$name}";
		}
		$this->state->imports = [
			$inbox . 'gone.pdf' => ['path' => $inbox . 'gone.pdf', 'status' => 'pending', 'etag' => 'e', 'task_id' => 'task-7'],
			$inbox . 'kept.pdf' => ['path' => $inbox . 'kept.pdf', 'status' => 'success', 'etag' => hash('sha256', '%PDF-kept')],
			$inbox . 'changed.pdf' => ['path' => $inbox . 'changed.pdf', 'status' => 'success', 'etag' => hash('sha256', '%PDF-old')],
			$inbox . 'due.pdf' => ['path' => $inbox . 'due.pdf', 'status' => 'retry', 'etag' => hash('sha256', '%PDF-due'), 'attempts' => 2, 'retry_at' => $this->clock->time],
			$inbox . 'done.pdf' => ['path' => $inbox . 'done.pdf', 'status' => 'pending', 'etag' => hash('sha256', '%PDF-done'), 'task_id' => 'task-8'],
		];
		$this->paperless->tasks['task-8'] = ['status' => 'SUCCESS', 'message' => ''];

		$report = $this->service->run();

		self::assertSame(['IMPORT SUCCESS: ' . $inbox . 'done.pdf', 'IMPORT: ' . $inbox . 'changed.pdf', 'IMPORT: ' . $inbox . 'due.pdf'], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame(['changed.pdf' => '%PDF-changed', 'due.pdf' => '%PDF-due'], $this->paperless->uploads, 'Only the changed file and the one whose next attempt is due go up.');
		self::assertSame([$inbox . 'kept.pdf', $inbox . 'due.pdf', $inbox . 'changed.pdf'], array_keys($this->state->imports));
	}

	/** A file that changed after its import is uploaded again; the import of its old content is forgotten, also while the upload waits for the next run. */
	public function testFileChangedAfterItsImportForgetsTheImportAlsoWhenItWaits(): void {
		$this->configService->save(['delete_inbox_after_success' => false]);
		$changed = 'Dokumente/Paperless/Eingang/b.pdf';
		$new = 'Dokumente/Paperless/Eingang/a.pdf';
		$this->storage->files[$changed] = '%PDF-b';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$this->service->run();
		$this->configService->save(['batch_size' => 1]);
		// The new file comes first in the inbox, so the changed one waits for the next run.
		$this->storage->files[$new] = '%PDF-a';
		unset($this->storage->files[$changed]);
		$this->storage->files[$changed] = '%PDF-b-corrected';
		$imports = $this->state->imports;

		$dryRun = $this->service->run(true);

		self::assertSame(['IMPORT: ' . $new], $dryRun->actions);
		self::assertSame($imports, $this->state->imports, 'A dry-run changes no state.');

		$report = $this->service->run();

		self::assertSame(['IMPORT: ' . $new], $report->actions);
		self::assertSame(1, $report->skipped);
		self::assertSame([$new], array_keys($this->state->imports));
	}

	public function testKeptInboxFileIsImportedOnceUntilItChanges(): void {
		$this->configService->save(['delete_inbox_after_success' => false]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCEEDED', 'message' => ''];

		$succeeded = $this->service->run();
		self::assertSame(1, $succeeded->importsSucceeded);
		self::assertSame('success', $this->state->imports[$path]['status']);
		self::assertArrayHasKey($path, $this->storage->files);

		$unchanged = $this->service->run();
		self::assertSame(1, $unchanged->unchanged);
		self::assertSame(0, $unchanged->importsSubmitted);

		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$changed = $this->service->run();
		self::assertSame(1, $changed->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox-corrected'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testFileChangedDuringItsImportIsSubmittedAgain(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];

		$succeeded = $this->service->run();
		self::assertSame(1, $succeeded->importsSucceeded);
		self::assertSame(0, $succeeded->importsSubmitted);
		self::assertArrayHasKey($path, $this->storage->files, 'A file that changed after its upload must not be deleted.');
		self::assertSame([], $this->state->imports);

		$resubmitted = $this->service->run();
		self::assertSame(1, $resubmitted->importsSubmitted);
		self::assertSame('%PDF-inbox-corrected', $this->paperless->uploads['police.pdf']);
	}

	public function testDryRunReportsImportsWithoutTouchingTheInbox(): void {
		$succeeded = 'Dokumente/Paperless/Eingang/succeeded.pdf';
		$failed = 'Dokumente/Paperless/Eingang/Ordner/failed.pdf';
		$this->storage->files[$succeeded] = '%PDF-1';
		$this->storage->files[$failed] = '%PDF-2';
		$this->service->run();
		$this->paperless->tasks = [
			'task-1' => ['status' => 'SUCCESS', 'message' => ''],
			'task-2' => ['status' => 'FAILED', 'message' => 'Broken'],
		];
		$new = 'Dokumente/Paperless/Eingang/new.pdf';
		$this->storage->files[$new] = '%PDF-3';
		$files = $this->storage->files;
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame([
			'IMPORT SUCCESS: ' . $succeeded,
			'IMPORT ERROR: ' . $failed . ' -> Dokumente/Paperless/Fehler/Ordner/failed.pdf',
			'IMPORT: ' . $new,
		], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame($files, $this->storage->files);
		self::assertSame($imports, $this->state->imports);
	}

	public function testDryRunKeepsTheStateOfAFileChangedDuringItsImport(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];

		$this->service->run(true);

		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	/** As PAPERLESS_CONSUMER_SUBDIRS_AS_TAGS in the consume folder of Paperless: each folder below the inbox is a tag. */
	public function testFilesOfTheInboxGetATagForEachOfTheirSubfolders(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true]);
		$this->paperless->tagInfo = ['names' => ['5' => 'Steuer', '6' => '2026', '9' => 'Posteingang'], 'inbox' => ['9']];
		$inbox = 'Dokumente/Paperless/Eingang/';
		$this->storage->files[$inbox . 'steuer/2026/beleg.pdf'] = '%PDF-beleg';
		$this->storage->files[$inbox . 'brief.pdf'] = '%PDF-brief';

		$report = $this->service->run();

		self::assertSame(['beleg.pdf' => [5, 6], 'brief.pdf' => []], $this->paperless->uploadTags, 'The names match whatever their case, and a file in the inbox itself gets none.');
		self::assertSame(["IMPORT: {$inbox}steuer/2026/beleg.pdf (tags: Steuer, 2026)", "IMPORT: {$inbox}brief.pdf"], $report->actions);
		self::assertSame([
			['type' => 'import', 'path' => "{$inbox}steuer/2026/beleg.pdf", 'values' => ['tags' => 'Steuer, 2026']],
			['type' => 'import', 'path' => "{$inbox}brief.pdf"],
		], $report->entries, 'The settings page shows the tags of an import.');
		self::assertSame([2, 0], [$report->importsSubmitted, $report->errors]);
		self::assertSame(1, $this->paperless->tagReads, 'A run reads the tags of Paperless once.');
	}

	public function testEveryImportGetsTheTagsForEveryImport(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_tags' => 'scanned, Steuer']);
		// Two tags whose names differ in their case alone, such as of two owners: the oldest.
		$this->paperless->tagInfo = ['names' => ['8' => 'Scanned', '3' => 'scanned', '5' => 'Steuer'], 'inbox' => []];
		$inbox = 'Dokumente/Paperless/Eingang/';
		$this->storage->files[$inbox . 'Versicherung/police.pdf'] = '%PDF-police';

		$report = $this->service->run();

		self::assertSame(['police.pdf' => [3, 5]], $this->paperless->uploadTags, 'Without "Subfolders become tags", a folder is no tag.');
		self::assertSame(["IMPORT: {$inbox}Versicherung/police.pdf (tags: scanned, Steuer)"], $report->actions);
	}

	public function testATagOfASubfolderAndOfEveryImportIsSentOnce(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true, 'inbox_tags' => 'Steuer']);
		$this->paperless->tagInfo = ['names' => ['5' => 'Steuer'], 'inbox' => []];
		$this->storage->files['Dokumente/Paperless/Eingang/STEUER/beleg.pdf'] = '%PDF-beleg';

		$this->service->run();

		self::assertSame(['beleg.pdf' => [5]], $this->paperless->uploadTags);
	}

	/** @return array<string, array{array<string, string>, string, string, array<string, list<int>>}> the files, the line of the report, the names in the log and the tags of the uploads */
	public static function unknownTags(): array {
		return [
			'one name' => [
				['2026/b.pdf' => '%PDF-b'],
				'TAGS UNKNOWN: Paperless shows the account no tag named 2026, so the files of the inbox go to Paperless without it. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".',
				'2026',
				['b.pdf' => []],
			],
			'several names' => [
				['Steuer/2026/a.pdf' => '%PDF-a', '2026/b.pdf' => '%PDF-b', 'Rechnungen/c.pdf' => '%PDF-c'],
				'TAGS UNKNOWN: Paperless shows the account no tags named 2026, Rechnungen, so the files of the inbox go to Paperless without them. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".',
				'2026, Rechnungen',
				['a.pdf' => [5], 'b.pdf' => [], 'c.pdf' => []],
			],
		];
	}

	/**
	 * A name that Paperless has no tag of doesn't stop the import, and doesn't vanish either: the
	 * report says it once for the run, and the log warns of it.
	 *
	 * @param array<string, string> $files
	 * @param array<string, list<int>> $tags
	 */
	#[DataProvider('unknownTags')]
	public function testANameWithoutATagIsReportedAndTheFileGoesWithoutIt(array $files, string $notice, string $names, array $tags): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true]);
		$this->paperless->tagInfo = ['names' => ['5' => 'Steuer'], 'inbox' => []];
		foreach ($files as $file => $content) {
			$this->storage->files['Dokumente/Paperless/Eingang/' . $file] = $content;
		}
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless shows the account of Paperless Sync no tags named {names}, so files of the inbox went to Paperless without them. Create the tags in Paperless or share them with the account, or turn on "Create missing tags" in the settings of Paperless Sync.',
			['names' => $names],
		);

		$report = $this->service->run();

		self::assertSame(count($files), $report->importsSubmitted);
		self::assertSame(0, $report->errors);
		self::assertSame($notice, $report->actions[count($files)], 'The names come once, after the imports.');
		self::assertSame(['type' => 'tags-unknown', 'values' => ['names' => $names, 'count' => count(explode(', ', $names))]], $report->entries[count($files)]);
		self::assertSame($tags, $this->paperless->uploadTags);
	}

	public function testMissingTagsAreCreatedOnceWhenTheRunMay(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true, 'inbox_create_tags' => true]);
		$this->paperless->tagInfo = ['names' => ['5' => 'Steuer'], 'inbox' => []];
		$inbox = 'Dokumente/Paperless/Eingang/';
		$this->storage->files[$inbox . 'Steuer/2026/a.pdf'] = '%PDF-a';
		$this->storage->files[$inbox . '2026/b.pdf'] = '%PDF-b';
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run();

		self::assertSame(['2026'], $this->paperless->createdTags);
		self::assertSame(['a.pdf' => [5, 100], 'b.pdf' => [100]], $this->paperless->uploadTags);
		self::assertSame(['NEW TAG: 2026', "IMPORT: {$inbox}Steuer/2026/a.pdf (tags: Steuer, 2026)", "IMPORT: {$inbox}2026/b.pdf (tags: 2026)"], $report->actions);
		self::assertSame([
			['type' => 'new-tag', 'detail' => '2026'],
			['type' => 'import', 'path' => "{$inbox}Steuer/2026/a.pdf", 'values' => ['tags' => 'Steuer, 2026']],
			['type' => 'import', 'path' => "{$inbox}2026/b.pdf", 'values' => ['tags' => '2026']],
		], $report->entries);
		self::assertSame(1, $this->paperless->tagReads);
	}

	/** A dry-run reads the tags of Paperless and says what a run would do with them, and creates none. */
	public function testADryRunReportsTheTagsWithoutCreatingThem(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true, 'inbox_create_tags' => true]);
		$this->paperless->tagInfo = ['names' => ['5' => 'Steuer'], 'inbox' => []];
		$inbox = 'Dokumente/Paperless/Eingang/';
		$this->storage->files[$inbox . 'Steuer/2026/a.pdf'] = '%PDF-a';
		$this->storage->files[$inbox . '2026/b.pdf'] = '%PDF-b';

		$report = $this->service->run(true);

		self::assertSame(['NEW TAG: 2026', "IMPORT: {$inbox}Steuer/2026/a.pdf (tags: Steuer, 2026)", "IMPORT: {$inbox}2026/b.pdf (tags: 2026)"], $report->actions);
		self::assertSame(['type' => 'new-tag', 'detail' => '2026'], $report->entries[0]);
		self::assertSame(0, $this->paperless->tagCreations);
		self::assertSame([], $this->paperless->uploadAttempts);
	}

	public function testADryRunReportsTheNamesWithoutATagWithoutAWarningInTheLog(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_tags' => 'Scanned']);
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run(true);

		self::assertSame([
			'IMPORT: Dokumente/Paperless/Eingang/a.pdf',
			'TAGS UNKNOWN: Paperless shows the account no tag named Scanned, so the files of the inbox go to Paperless without it. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".',
		], $report->actions);
	}

	/**
	 * A file whose tag Paperless doesn't create, such as for a token without the permission Add
	 * for Tags, waits as after a failed upload, and so does every other file that needs the tag,
	 * without another attempt in this run. Files without it go on, and once Paperless creates the
	 * tag, the files go with it.
	 */
	public function testAFileWaitsForATagThatPaperlessDoesNotCreate(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true, 'inbox_create_tags' => true]);
		$this->paperless->createTagException = new RuntimeException('Paperless could not create the tag 2026: HTTP 403: You do not have permission to perform this action.');
		$inbox = 'Dokumente/Paperless/Eingang/';
		$this->storage->files[$inbox . '2026/a.pdf'] = '%PDF-a';
		$this->storage->files[$inbox . '2026/b.pdf'] = '%PDF-b';
		$this->storage->files[$inbox . 'c.pdf'] = '%PDF-c';

		$report = $this->service->run();

		self::assertSame(1, $this->paperless->tagCreations);
		self::assertSame(['c.pdf'], $this->paperless->uploadAttempts);
		self::assertSame([2, 1], [$report->errors, $report->importsSubmitted]);
		self::assertSame("ERROR: Import {$inbox}2026/a.pdf: Paperless could not create the tag 2026: HTTP 403: You do not have permission to perform this action. (attempt 1, the next in 15 minutes)", $report->actions[0]);
		self::assertSame(['retry', 1], [$this->state->imports[$inbox . '2026/a.pdf']['status'], $this->state->imports[$inbox . '2026/a.pdf']['attempts']]);
		self::assertSame('retry', $this->state->imports[$inbox . '2026/b.pdf']['status']);

		$this->paperless->createTagException = null;
		$this->clock->advance(900);
		$retried = $this->service->run();

		self::assertSame(['c.pdf' => [], 'a.pdf' => [100], 'b.pdf' => [100]], $this->paperless->uploadTags);
		self::assertSame(0, $retried->errors);
	}

	/** Without the list of the tags, every file that needs a tag would fail, so the others wait for the next run. */
	public function testFilesWaitForTheTagsOfAPaperlessThatCannotListThem(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_folder_tags' => true]);
		$this->paperless->tagsException = new RuntimeException('Paperless could not read /api/tags/: HTTP 403.');
		$inbox = 'Dokumente/Paperless/Eingang/';
		$this->storage->files[$inbox . 'brief.pdf'] = '%PDF-brief';
		$this->storage->files[$inbox . 'A/a.pdf'] = '%PDF-a';
		$this->storage->files[$inbox . 'B/b.pdf'] = '%PDF-b';

		$report = $this->service->run();

		self::assertSame(['brief.pdf'], $this->paperless->uploadAttempts, 'A file in the inbox itself needs no tag.');
		self::assertSame(1, $this->paperless->tagReads);
		self::assertSame([1, 1, 1], [$report->errors, $report->skipped, $report->importsSubmitted]);
		self::assertSame('retry', $this->state->imports[$inbox . 'A/a.pdf']['status']);
		self::assertArrayNotHasKey($inbox . 'B/b.pdf', $this->state->imports);
	}

	public function testAnUnreachablePaperlessHoldsBackTheOtherUploadsAlsoWhenItCreatesATag(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_tags' => 'Scanned', 'inbox_create_tags' => true]);
		$this->paperless->createTagException = new PaperlessRequestException('cURL error 7: Failed to connect to paperless.example.test');
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';

		$report = $this->service->run();

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertSame([1, 1], [$report->errors, $report->skipped]);
	}

	/** A dry-run reports the failure of the tags of a file and keeps the state of the inbox. */
	public function testADryRunReportsTagsThatCannotBeReadAndChangesNothing(): void {
		$this->configService->save(['export_enabled' => false, 'inbox_tags' => 'Scanned']);
		$this->paperless->tagsException = new RuntimeException('Paperless could not read /api/tags/: HTTP 403.');
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';

		$report = $this->service->run(true);

		self::assertSame(['ERROR: Import Dokumente/Paperless/Eingang/a.pdf: Paperless could not read /api/tags/: HTTP 403.'], $report->actions);
		self::assertSame([], $this->state->imports);
	}

	public function testImportsBeyondTheBatchSizeWaitForTheNextRun(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(1, $report->skipped);
		self::assertSame(['a.pdf' => '%PDF-a'], $this->paperless->uploads);
	}

	public function testFailedUploadWaitsAndIsRetriedWhenItsTimeHasCome(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 502: Bad Gateway', false, 502);

		$failed = $this->service->run();
		self::assertSame(['ERROR: Import ' . $path . ': Paperless answered the upload with HTTP 502: Bad Gateway (attempt 1, the next in 15 minutes)'], $failed->actions);
		self::assertSame(1, $failed->errors);
		self::assertSame('retry', $this->state->imports[$path]['status']);
		self::assertSame(1, $this->state->imports[$path]['attempts']);
		self::assertSame(TestClock::START, $this->state->imports[$path]['submitted_at']);
		self::assertSame(TestClock::START + 900, $this->state->imports[$path]['retry_at']);
		self::assertSame('Paperless answered the upload with HTTP 502: Bad Gateway', $this->state->imports[$path]['last_error']);

		$this->clock->advance(900 - 1);
		$waiting = $this->service->run();
		self::assertSame([], $waiting->actions);
		self::assertSame(0, $waiting->errors);
		self::assertSame(1, $waiting->skipped);
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts, 'The file must wait for its next attempt.');

		$this->paperless->uploadException = null;
		$this->clock->advance(1);
		$retried = $this->service->run();
		self::assertSame(1, $retried->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertSame(0, $this->state->imports[$path]['attempts']);
		self::assertSame(0, $this->state->imports[$path]['retry_at']);
		self::assertNull($this->state->imports[$path]['last_error']);
	}

	/** @return array<string, array{\Throwable}> */
	public static function uploadFailuresThatMayPass(): array {
		return [
			'a timeout' => [new RuntimeException('Paperless timed out')],
			'an unavailable Paperless' => [new PaperlessUploadException('Paperless timed out', false, 503)],
		];
	}

	#[DataProvider('uploadFailuresThatMayPass')]
	public function testWaitAfterAFailedUploadDoublesUpToADay(\Throwable $failure): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = $failure;
		$logs = [];
		$this->logger->method('log')->willReturnCallback(static function (string $level, string $message, array $context) use (&$logs): void {
			$logs[] = [$level, $context];
		});

		$waits = [];
		for ($run = 1; $run <= 10; ++$run) {
			$report = $this->service->run();
			$import = $this->state->imports[$path];
			self::assertSame($run, $import['attempts']);
			self::assertSame($this->clock->time, $import['submitted_at']);
			$minutes = intdiv($import['retry_at'] - $this->clock->time, 60);
			self::assertSame(["ERROR: Import {$path}: Paperless timed out (attempt {$run}, the next in {$minutes} minutes)"], $report->actions);
			$waits[] = $minutes;
			$this->clock->time = $import['retry_at'];
		}

		self::assertSame([15, 30, 60, 120, 240, 480, 960, 1440, 1440, 1440], $waits);
		self::assertSame([LogLevel::WARNING, ...array_fill(0, 9, LogLevel::INFO)], array_column($logs, 0), 'Only the first failure is a warning.');
		self::assertSame(['path' => $path, 'error' => 'Paperless timed out', 'attempts' => 10, 'minutes' => 1440], $logs[9][1]);
	}

	public function testRefusedFileForgetsItsEarlierAttempts(): void {
		$path = 'Dokumente/Paperless/Eingang/notes.zip';
		$this->storage->files[$path] = 'PK-archive';
		$this->paperless->uploadException = new RuntimeException('Paperless timed out');
		$this->service->run();
		$this->clock->advance(900);
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 415.', true, 415);

		$this->service->run();

		$import = $this->state->imports[$path];
		self::assertSame(['rejected', 0, 0, $this->clock->time], [$import['status'], $import['attempts'], $import['retry_at'], $import['submitted_at']]);
	}

	public function testEveryFileOpenedForAnUploadIsClosed(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';
		$this->paperless->uploadFailures['a.pdf'] = new RuntimeException('Could not copy the file for the Paperless upload.');

		$this->service->run();

		self::assertCount(2, $this->storage->openedStreams);
		foreach ($this->storage->openedStreams as $stream) {
			self::assertIsClosedResource($stream, 'A stream is closed also after a failed upload.');
		}
	}

	public function testFileThatPaperlessRefusedWaitsUntilItChanges(): void {
		$path = 'Dokumente/Paperless/Eingang/notes.zip';
		$this->storage->files[$path] = 'PK-archive';
		$reason = 'Paperless answered the upload with HTTP 400: document: File type application/zip not supported';
		$this->paperless->uploadException = new PaperlessUploadException($reason, true, 400);
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless refused the inbox file {path}; it stays in the inbox until it changes: {reason}',
			['path' => $path, 'reason' => $reason, 'status' => 400],
		);

		$refused = $this->service->run();
		self::assertSame(['IMPORT REJECTED: ' . $path . ': ' . $reason], $refused->actions);
		self::assertSame([['type' => 'import-rejected', 'path' => $path, 'detail' => $reason]], $refused->entries);
		self::assertSame(1, $refused->importsFailed);
		self::assertSame(0, $refused->errors);
		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertSame('rejected', $this->state->imports[$path]['status']);
		self::assertSame($reason, $this->state->imports[$path]['last_error']);
		self::assertArrayHasKey($path, $this->storage->files, 'A refused file stays in the inbox.');

		$this->paperless->uploadException = null;
		for ($run = 0; $run < 3; ++$run) {
			$skipped = $this->service->run();
			self::assertSame([], $skipped->actions);
			self::assertSame(0, $skipped->importsFailed);
			self::assertSame(1, $skipped->skipped);
		}
		self::assertSame(['notes.zip'], $this->paperless->uploadAttempts, 'Paperless would refuse the same file again.');

		$this->storage->files[$path] = '%PDF-replaced';
		$changed = $this->service->run();
		self::assertSame(1, $changed->importsSubmitted);
		self::assertSame(['notes.zip' => '%PDF-replaced'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testRefusedFileIsUploadedAgainUnderANewName(): void {
		$path = 'Dokumente/Paperless/Eingang/notes.zip';
		$this->storage->files[$path] = '%PDF-1';
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 400: document: No file was submitted.', true, 400);
		$this->service->run();
		$this->paperless->uploadException = null;
		$renamed = 'Dokumente/Paperless/Eingang/notes.pdf';
		$this->storage->files[$renamed] = $this->storage->files[$path];
		unset($this->storage->files[$path]);

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame([$renamed], array_keys($this->state->imports));
	}

	public function testChangedFileIsUploadedAtOnceAfterAFailedUpload(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = new RuntimeException('Paperless timed out');
		$this->service->run();
		$this->paperless->uploadException = null;
		$this->storage->files[$path] = '%PDF-inbox-corrected';

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(0, $this->state->imports[$path]['attempts']);
	}

	public function testRefusedFileDoesNotHoldUpTheOthers(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/a.zip'] = 'PK-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';
		$this->paperless->uploadFailures['a.zip'] = new PaperlessUploadException('Paperless answered the upload with HTTP 413.', true, 413);

		$report = $this->service->run();

		self::assertSame(1, $report->importsFailed);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(['b.pdf' => '%PDF-b'], $this->paperless->uploads);
	}

	public function testFailureOfOneFileDoesNotHoldUpTheOthers(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';
		$this->paperless->uploadFailures['a.pdf'] = new RuntimeException('Could not copy the file for the Paperless upload.');

		$report = $this->service->run();

		self::assertSame(1, $report->errors);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame('retry', $this->state->imports['Dokumente/Paperless/Eingang/a.pdf']['status']);
	}

	public function testUnavailablePaperlessEndsTheUploadsOfTheRun(): void {
		foreach (['a', 'b', 'c'] as $name) {
			$this->storage->files["Dokumente/Paperless/Eingang/{$name}.pdf"] = "%PDF-{$name}";
		}
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 503.', false, 503);

		$first = $this->service->run();
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(1, $first->errors);
		self::assertSame(2, $first->skipped);
		self::assertSame(['Dokumente/Paperless/Eingang/a.pdf'], array_keys($this->state->imports), 'Files that waited were not attempted.');

		$second = $this->service->run();
		self::assertSame(['a.pdf', 'b.pdf'], $this->paperless->uploadAttempts, 'Each run tries one more file while Paperless fails.');
		self::assertSame(2, $second->skipped);

		$this->paperless->uploadException = null;
		$recovered = $this->service->run();
		self::assertSame(1, $recovered->importsSubmitted);
		self::assertSame(['c.pdf' => '%PDF-c'], $this->paperless->uploads);
	}

	public function testFailedUploadsTakeTheirPlaceInTheBatch(): void {
		$this->configService->save(['batch_size' => 2]);
		foreach (['a', 'b', 'c'] as $name) {
			$this->storage->files["Dokumente/Paperless/Eingang/{$name}.zip"] = "PK-{$name}";
		}
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 400.', true, 400);

		$report = $this->service->run();

		self::assertSame(['a.zip', 'b.zip'], $this->paperless->uploadAttempts);
		self::assertSame(2, $report->importsFailed);
		self::assertSame(1, $report->skipped);
	}

	public function testDryRunSkipsWaitingFilesAndKeepsTheirState(): void {
		$refused = 'Dokumente/Paperless/Eingang/refused.zip';
		$waiting = 'Dokumente/Paperless/Eingang/waiting.pdf';
		$due = 'Dokumente/Paperless/Eingang/due.pdf';
		$this->storage->files[$refused] = 'PK';
		$this->storage->files[$waiting] = '%PDF-waiting';
		$this->storage->files[$due] = '%PDF-due';
		$this->paperless->uploadFailures['refused.zip'] = new PaperlessUploadException('Paperless answered the upload with HTTP 400.', true, 400);
		$this->paperless->uploadException = new RuntimeException('Paperless timed out');
		$this->service->run();
		$this->service->run();
		$this->state->imports[$due]['retry_at'] = $this->clock->time;
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame(['IMPORT: ' . $due], $report->actions);
		self::assertSame(2, $report->skipped);
		self::assertSame($imports, $this->state->imports);
	}

	public function testExcludedDocumentRemovesMirroredCopyAndCanBeExportedAgain(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$this->service->run();
		$this->paperless->documents[0]['tags'] = [9];
		$excluded = $this->service->run();

		self::assertSame(1, $excluded->removedExcluded);
		self::assertSame(['REMOVE EXCLUDED P123: ' . self::EXPORTED_PATH], $excluded->actions);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);

		$this->paperless->documents[0]['tags'] = [];
		$exportedAgain = $this->service->run();
		self::assertSame(1, $exportedAgain->exported);
		self::assertSame(2, $this->paperless->downloads);
	}

	public function testExcludedDocumentThatWasNeverExportedIsOnlySkipped(): void {
		$document = $this->document();
		$document['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->exportDocument($document);

		self::assertSame(1, $report->skipped);
		self::assertSame(0, $report->removedExcluded);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testExcludedTagNamesMatchWithoutRegardToCase(): void {
		$this->configService->save(['excluded_tags' => 'Privat, Steuer']);
		$this->paperless->tagInfo = ['names' => ['5' => 'privat', '6' => 'Haushalt'], 'inbox' => []];
		$private = $this->document();
		$private['tags'] = [['id' => 6], ['id' => 5]];
		$household = $this->document(124);
		$household['tags'] = [['id' => 6], ['name' => 'without an id']];

		$report = $this->exportDocuments([$private, $household]);

		self::assertSame(1, $report->skipped);
		self::assertSame(1, $report->exported);
		self::assertArrayNotHasKey(123, $this->state->exports);
		self::assertArrayHasKey(124, $this->state->exports);
	}

	/** @return array<string, array{string, string, bool}> */
	public static function excludedTagSettings(): array {
		return [
			'the second of two names' => ['Privat, Steuer', 'Steuer', true],
			'a name between spaces' => [' steuer ', 'Steuer', true],
			'names on lines of their own' => ["Privat\nSteuer", 'Steuer', true],
			'a name in capitals' => ['STEUER', 'Steuer', true],
			'a name with an umlaut in capitals' => ['ÄRZTE', 'Ärzte', true],
			'names set past the settings page' => ['Privat ,  steuer ,', 'Steuer', false],
		];
	}

	/** A document with an excluded tag is skipped, whichever way the name was entered, and holds up no other document. */
	#[DataProvider('excludedTagSettings')]
	public function testExcludedTagsMatchByTheirNames(string $setting, string $tagName, bool $saved): void {
		if ($saved) {
			$this->configService->save(['excluded_tags' => $setting]);
		} else {
			$this->settings['excluded_tags'] = $setting;
		}
		$this->paperless->tagInfo = ['names' => ['5' => $tagName, '6' => 'Haushalt'], 'inbox' => []];
		$excluded = $this->document(130);
		$excluded['tags'] = [6, 5];
		$household = $this->document(120);
		$household['tags'] = [6];

		$report = $this->exportDocuments([$household, $excluded]);

		self::assertSame(['EXPORT P120: ' . $this->archivePath(120)], $report->actions);
		self::assertSame(1, $report->skipped);
		self::assertArrayNotHasKey(130, $this->state->exports);
	}

	public function testExcludedDocumentWhoseExportNeverSucceededIsForgottenQuietly(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->downloadException = null;
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->errors);
		self::assertSame([], $this->state->exports);
	}

	public function testExcludedDocumentWithoutItsCopyIsForgottenWithoutARemoval(): void {
		$this->exportDocument();
		$this->storage->files = [];
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->removedExcluded);
		self::assertNotContains('delete', $this->storage->methods());
		self::assertSame([], $this->state->exports);
	}

	public function testRemovalOfAnExcludedCopyTakesItsPlaceInTheBatch(): void {
		$this->exportDocument($this->document(125));
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$excluded = $this->document(125);
		$excluded['tags'] = [9];
		$this->paperless->documents = [$excluded, $this->document(120)];
		$this->paperless->contents[120] = '%PDF-120';

		$report = $this->service->run();

		self::assertSame(['REMOVE EXCLUDED P125: ' . $this->archivePath(125)], $report->actions);
		self::assertSame(0, $report->exported);
		self::assertSame(2, $report->skipped, 'The excluded document, and the export that waits for the next run.');
	}

	public function testDocumentsBehindAnExcludedCopyThatWaitsAreStillCounted(): void {
		$this->exportDocuments([$this->document(120), $this->document(125)]);
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$excluded = $this->document(125);
		$excluded['tags'] = [9];
		$this->paperless->documents = [$this->document(120), $excluded, $this->document(130)];
		$this->paperless->contents[130] = '%PDF-130';

		$report = $this->service->run();

		self::assertSame(['EXPORT P130: ' . $this->archivePath(130)], $report->actions);
		self::assertSame(0, $report->removedExcluded, 'The batch is full, so the copy waits for the next run.');
		self::assertArrayHasKey($this->archivePath(125), $this->storage->files);
		self::assertSame(1, $report->unchanged, 'The document behind it is still counted.');
	}

	public function testInboxTagDoesNotExcludeWhenTheInboxIsSynchronizedToo(): void {
		$this->configService->save(['skip_inbox' => false]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$document = $this->document();
		$document['tags'] = [9];

		$report = $this->exportDocument($document);

		self::assertSame(1, $report->exported);
	}

	public function testDryRunReportsTheRemovalOfAnExcludedCopy(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->service->run(true);

		self::assertSame(['REMOVE EXCLUDED P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->removedExcluded);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testExcludedCopyWaitsWhenTheBatchIsFull(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$excluded = $this->paperless->documents[0];
		$excluded['tags'] = [9];
		$this->paperless->documents = [$this->document(124), $excluded];
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();
		self::assertSame(1, $first->exported);
		self::assertSame(0, $first->removedExcluded);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);

		$second = $this->service->run();
		self::assertSame(1, $second->removedExcluded);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testFailedRemovalOfAnExcludedCopyIsReported(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$this->storage->deleteException = new RuntimeException('File is locked');
		$this->expectDocumentWarning(self::EXPORTED_PATH, 'File is locked');
		$this->clock->advance(60);

		$report = $this->service->run();
		$this->service->run();

		self::assertSame(['ERROR: Excluded P123: File is locked'], $report->actions);
		self::assertSame([['type' => 'error', 'document' => 123, 'path' => self::EXPORTED_PATH, 'detail' => 'Excluded P123: File is locked']], $report->entries);
		self::assertSame('File is locked', $this->state->exports[123]['last_error']);
		self::assertSame(TestClock::START + 60, $this->state->exports[123]['last_seen'], 'The document failed in this run, so it waits behind those that failed earlier.');
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testTrashedDocumentThatWasNeverExportedIsIgnored(): void {
		$this->paperless->trash = [$this->document() + ['deleted_at' => '2026-08-27T08:00:00+02:00']];

		$report = $this->service->run();

		self::assertSame(1, $report->trashedDocuments);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame([], $this->state->exports);
	}

	public function testTrashedDocumentThatWasNeverExportedHoldsUpNoOther(): void {
		$this->exportDocument();
		$this->trashDocument();
		array_unshift($this->paperless->trash, $this->document(99) + ['deleted_at' => '2026-08-27T08:00:00+02:00']);

		$report = $this->service->run();

		self::assertSame(['TRASH P123: ' . $this->trashFolderPath()], $report->actions);
	}

	public function testDocumentAlreadyInTheTrashFolderStaysThere(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->service->run();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('trash', $this->state->exports[123]['state']);
	}

	public function testDocumentsInTheTrashFolderForgetTheirErrors(): void {
		$this->moveToTheTrashFolder([$this->document(), $this->document(124)]);
		$this->clock->advance(60);

		$report = $this->service->run();

		self::assertSame(2, $report->unchanged);
		self::assertSame([], $report->actions);
		foreach ([123, 124] as $id) {
			self::assertNull($this->state->exports[$id]['last_error']);
			self::assertSame(0, $this->state->exports[$id]['missing_runs']);
			self::assertSame(TestClock::START, $this->state->exports[$id]['last_seen'], 'Only what changed is written.');
		}
	}

	public function testDryRunLeavesTheStateOfDocumentsInTheTrashFolder(): void {
		$this->moveToTheTrashFolder([$this->document(), $this->document(124)]);
		$exports = $this->state->exports;

		$report = $this->service->run(true);

		self::assertSame(2, $report->unchanged);
		self::assertSame($exports, $this->state->exports);
	}

	public function testDryRunReportsTheMoveToTheTrashFolder(): void {
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run(true);

		$trashPath = self::ARCHIVE . '/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(['TRASH P123: ' . $trashPath], $report->actions);
		self::assertSame(1, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testMoveToTheTrashFolderWaitsWhenTheBatchIsFull(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->trashDocument();
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(1, $report->skipped);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testFailedMoveToTheTrashFolderIsReported(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->moveException = new RuntimeException('Storage is full');
		$this->expectDocumentWarning(self::EXPORTED_PATH, 'Storage is full');

		$report = $this->service->run();
		$this->service->run();

		self::assertSame(['ERROR: Trash P123: Storage is full'], $report->actions);
		self::assertSame('Storage is full', $this->state->exports[123]['last_error']);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testKeepPolicyLeavesTheCopyOfATrashedDocumentInPlace(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame(strtotime('2026-08-27T08:00:00+02:00'), $this->state->exports[123]['trash_date']);
	}

	/**
	 * A document restored from the Paperless trash finds its copy where the keep policy left it,
	 * with the same content, so nothing is downloaded or written. Before, the copy was downloaded
	 * and written again, which gave it a new file ID.
	 */
	public function testRestoredDocumentKeepsItsCopyUnderTheKeepPolicy(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$restored = $this->paperless->documents;
		$this->trashDocument();
		$this->service->run();
		$this->paperless->documents = $restored;
		$this->paperless->trash = [];

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame([[self::EXPORTED_PATH, 'created']], $this->storage->writes);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH));
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['trash_date']);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	/** Also with conflicts skipped: the copy at the path of the document is its own. */
	#[DataProvider('conflictModes')]
	public function testDocumentListedAgainKeepsItsCopyUnderTheKeepPolicy(string $conflictMode): void {
		$this->configService->save(['trash_mode' => 'keep', 'missing_grace_runs' => 1, 'conflict_mode' => $conflictMode]);
		$this->exportDocument();
		$listed = $this->paperless->documents;
		$this->paperless->documents = [];
		$this->service->run();
		self::assertSame('missing', $this->state->exports[123]['state']);
		$this->paperless->documents = $listed;

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame([[self::EXPORTED_PATH, 'created']], $this->storage->writes);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	/** @return array<string, array{string}> */
	public static function conflictModes(): array {
		return ['replace' => ['replace'], 'skip' => ['skip']];
	}

	public function testDryRunKeepsTheStateOfARestoredDocument(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$restored = $this->paperless->documents;
		$this->trashDocument();
		$this->service->run();
		$this->paperless->documents = $restored;
		$this->paperless->trash = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame('trash', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsNothingWhenTheKeepPolicyLeavesTheCopyInPlace(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testTrashedDocumentWithoutAFileIsOnlyMarked(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->files = [];

		$first = $this->service->run();

		self::assertSame(0, $first->movedToTrash);
		self::assertSame([], $this->storage->prunes);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'Nothing was moved, so the state keeps the path.');

		$second = $this->service->run();

		self::assertSame(0, $second->movedToTrash);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'The path must not grow by a deleted folder in every run.');
	}

	public function testTrashedDocumentWithoutAFileTakesNoPlaceInTheBatch(): void {
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->paperless->trash = array_map(
			static fn (array $document): array => $document + ['deleted_at' => '2026-08-27T08:00:00+02:00'],
			$this->paperless->documents,
		);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->skipped);
		self::assertSame([$this->trashFolderPath(124) => '%PDF-content'], $this->storage->files);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testDryRunReportsNoMoveToTheTrashFolderWithoutAFile(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->files = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
	}

	public function testTrashedDocumentThatWasNeverExportedIsOnlyMarked(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run();

		self::assertSame(0, $report->errors);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['last_error']);
		self::assertArrayNotHasKey('path', $this->state->exports[123], 'A folder of the deleted folder must not become the path of the document.');
	}

	/** @return array<string, array{mixed}> */
	public static function unreadableDeletionDates(): array {
		return [
			'missing' => [null],
			'empty' => [''],
			'unparsable' => ['not a date'],
		];
	}

	#[DataProvider('unreadableDeletionDates')]
	public function testTrashFolderUsesTheCurrentDateWithoutAReadableDeletionDate(mixed $deletedAt): void {
		$this->exportDocument();
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = $deletedAt;
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];

		$this->service->run();

		self::assertSame([$this->deletedToday() => '%PDF-content'], $this->storage->files);
	}

	/**
	 * The time zones of the target user and of Nextcloud, and the day of a deletion at 22:30 UTC, at
	 * half past midnight in Berlin.
	 *
	 * @return array<string, array{array<string, string>, string, string, 3?: string}>
	 */
	public static function timeZonesOfADeletion(): array {
		return [
			'the target user in Berlin' => [['paperless' => 'Europe/Berlin'], 'UTC', '2026-08-27'],
			'Nextcloud in Berlin' => [[], 'Europe/Berlin', '2026-08-27'],
			'another user in Berlin' => [['admin' => 'Europe/Berlin'], 'UTC', '2026-08-26'],
			'the target user in New York' => [['paperless' => 'America/New_York'], 'Europe/Berlin', '2026-08-26'],
			'a time zone that PHP no longer knows' => [['paperless' => 'Europe/Atlantis'], 'Europe/Berlin', '2026-08-27'],
			'a target user without one, run by an administrator in Tokyo' => [[], 'UTC', '2026-08-26', 'Asia/Tokyo'],
		];
	}

	/**
	 * The folder of a trashed document is the day of its deletion in the time zone of the target
	 * user, whose files they are, or else in that of Nextcloud: a document trashed in Berlin shortly
	 * after midnight lands in the folder of that day, not in the one of the day before, as in UTC.
	 * Never in that of the browser of whoever started the run.
	 *
	 * @param array<string, string> $users
	 */
	#[DataProvider('timeZonesOfADeletion')]
	public function testTrashFolderIsTheDayOfTheDeletionWhereTheTargetUserLives(array $users, string $default, string $day, string $session = ''): void {
		$this->exportDocument();
		$this->timeZones->users = $users;
		$this->timeZones->default = $default;
		$this->timeZones->session = $session;
		$this->paperless->trash = [$this->paperless->documents[0] + ['deleted_at' => '2026-08-26T22:30:00+00:00']];
		$this->paperless->documents = [];

		$this->service->run();

		self::assertSame([self::ARCHIVE . "/_Gelöscht/{$day}/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf" => '%PDF-content'], $this->storage->files);
	}

	public function testMissingDocumentWaitsForTheConfiguredRuns(): void {
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['WAIT P123: missing run 1/3'], $report->actions);
		self::assertSame([['type' => 'wait', 'document' => 123, 'path' => self::EXPORTED_PATH, 'values' => ['scans' => 1, 'limit' => 3]]], $report->entries);
		self::assertSame(1, $this->state->exports[123]['missing_runs']);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testEveryMissingDocumentCountsItsMissingRuns(): void {
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['WAIT P124: missing run 1/3', 'WAIT P123: missing run 1/3'], $report->actions);
		self::assertSame([1, 1], [$this->state->exports[123]['missing_runs'], $this->state->exports[124]['missing_runs']]);
	}

	public function testDocumentWithTheFirstIdCanGoMissing(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument($this->document(1));
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['MISSING P1: ' . $this->deletedToday(1)], $report->actions);
	}

	public function testUnchangedDocumentListedAgainStartsItsCountOfMissingScansAnew(): void {
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->service->run();
		$this->paperless->documents = [$this->document()];

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	public function testDryRunDoesNotCountMissingRuns(): void {
		$this->exportDocument();
		$this->paperless->documents = [];

		$this->service->run(true);

		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	public function testDocumentThatIsListedAgainStartsItsCountOfMissingScansAnew(): void {
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->service->run();
		$this->paperless->documents = [$this->document()];
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');

		$this->service->run(true);
		self::assertSame(1, $this->state->exports[123]['missing_runs'], 'A dry run changes no state.');

		$report = $this->service->run();

		self::assertSame(1, $report->errors);
		self::assertSame(0, $this->state->exports[123]['missing_runs'], 'Only scans in a row without the document count, also when its export fails.');
	}

	public function testIncompleteScanStillStartsTheCountOfAListedDocumentAnew(): void {
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->paperless->documents = [];
		$this->service->run();
		$this->paperless->documents = [$this->document()];
		$this->paperless->documentsListed = 2;
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');

		$this->service->run();

		self::assertSame(1, $this->state->exports[124]['missing_runs'], 'The scan was incomplete, so P124 counts no missing run.');
		self::assertSame(0, $this->state->exports[123]['missing_runs'], 'P123 was listed, also when its export failed.');
	}

	/** @return array<string, array{string, string, array<string, int|string>}> */
	public static function incompleteScans(): array {
		return [
			'documents' => ['documentsListed', "SCAN INCOMPLETE: Paperless's list of documents came back incomplete or changed while the run read it (1 of 2 received); no document counts as missing in this run.", ['list' => 'documents', 'received' => 1, 'listed' => 2]],
			'trash' => ['trashListed', "SCAN INCOMPLETE: Paperless's list of trashed documents came back incomplete or changed while the run read it (0 of 2 received); no document counts as missing in this run.", ['list' => 'trashed documents', 'received' => 0, 'listed' => 2]],
		];
	}

	/** @param array<string, int|string> $context */
	#[DataProvider('incompleteScans')]
	public function testIncompleteScanCountsNoDocumentAsMissing(string $listed, string $action, array $context): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$files = $this->storage->files;
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->{$listed} = 2;
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless\'s list of {list} came back incomplete or changed while the run read it ({received} of {listed} received), so no document counts as missing in this run.',
			$context,
		);

		$report = $this->service->run();

		self::assertSame([$action], $report->actions);
		self::assertSame([['type' => 'scan-incomplete', 'values' => $context]], $report->entries);
		self::assertSame($files, $this->storage->files);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(0, $report->permanentlyDeleted);
	}

	public function testCompleteScanAfterAnIncompleteOneCountsTheMissingDocument(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->documentsListed = 2;
		$this->service->run();
		$this->paperless->documentsListed = null;

		$report = $this->service->run();

		self::assertSame(['MISSING P123: ' . $this->deletedToday()], $report->actions);
		self::assertSame('missing', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsAnIncompleteScan(): void {
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->paperless->documentsListed = 1;

		$report = $this->service->run(true);

		self::assertSame(["SCAN INCOMPLETE: Paperless's list of documents came back incomplete or changed while the run read it (0 of 1 received); no document counts as missing in this run."], $report->actions);
	}

	public function testMissingDocumentMovesToTheDeletedFolderAfterTheGraceRuns(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		$deletedPath = $this->deletedToday();
		self::assertSame(1, $report->movedToTrash);
		self::assertSame(['MISSING P123: ' . $deletedPath], $report->actions);
		self::assertSame([$deletedPath => '%PDF-content'], $this->storage->files);
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame($deletedPath, $this->state->exports[123]['path']);

		$again = $this->service->run();
		self::assertSame(0, $again->movedToTrash);
		self::assertSame([$deletedPath => '%PDF-content'], $this->storage->files);
		self::assertSame(2, $this->state->exports[123]['missing_runs']);
	}

	/** The clock of Nextcloud names the day of the deleted folder, also in the last second of a day. */
	public function testMoveAtTheEndOfADayLandsInTheFolderOfThatDay(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->clock->time = (int)strtotime('2026-08-28T23:59:59Z');

		$report = $this->service->run();

		$path = self::ARCHIVE . '/_Gelöscht/2026-08-28/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(['MISSING P123: ' . $path], $report->actions);
		self::assertSame([$path => '%PDF-content'], $this->storage->files);
	}

	public function testMovesOfMissingDocumentsTakeTheirPlacesInTheBatch(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments($this->documents(4));
		$this->configService->save(['batch_size' => 2]);
		$this->paperless->documents = [];
		unset($this->storage->files[$this->archivePath(1)]);

		$report = $this->service->run();

		self::assertSame(['MISSING P4: ' . $this->deletedToday(4), 'MISSING P3: ' . $this->deletedToday(3)], $report->actions);
		self::assertSame(1, $report->skipped);
		self::assertSame('active', $this->state->exports[2]['state'], 'P2 waits for the next run.');
		self::assertSame('missing', $this->state->exports[1]['state'], 'P1 has no copy to move, so it takes no place in the batch.');
	}

	public function testMissingDocumentWithoutAFileIsOnlyMarked(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->files = [];

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertSame([], $this->storage->prunes);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'Nothing was moved, so the state keeps the path.');
	}

	public function testMissingDocumentWithoutAFileTakesNoPlaceInTheBatch(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->skipped);
		self::assertSame([$this->deletedToday(124) => '%PDF-content'], $this->storage->files);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testMissingDocumentThatWasNeverExportedIsOnlyMarked(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertSame(0, $report->errors);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertArrayNotHasKey('path', $this->state->exports[123], 'A folder of the deleted folder must not become the path of the document.');
	}

	public function testDryRunReportsNoMoveOfAMissingDocumentWithoutAFile(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->files = [];

		$report = $this->service->run(true);

		self::assertSame([self::trashEmpty(1, 1)], $report->actions);
		self::assertSame(0, $report->movedToTrash);
	}

	public function testMoveOfAMissingDocumentWaitsWhenTheBatchIsFull(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();

		self::assertSame(1, $first->exported);
		self::assertSame(1, $first->skipped);
		self::assertSame(0, $first->movedToTrash);
		self::assertSame('%PDF-content', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);

		$second = $this->service->run();

		self::assertSame(1, $second->movedToTrash);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday()]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame($this->deletedToday(), $this->state->exports[123]['path']);
	}

	public function testFailedMoveOfAMissingDocumentIsReportedAndRetried(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'conflict_mode' => 'skip']);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->storage->files[$this->deletedToday()] = 'a file of the user';
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['MISSING P124: ' . $this->deletedToday(124), 'ERROR: Missing P123: conflict'], $report->actions);
		self::assertSame(1, $report->movedToTrash, 'The other missing document still moves.');
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday(124)]);
		self::assertSame('%PDF-content', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('a file of the user', $this->storage->files[$this->deletedToday()]);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame('conflict', $this->state->exports[123]['last_error']);
		self::assertSame('completed-with-errors', $this->settings['status_last_state']);

		unset($this->storage->files[$this->deletedToday()]);
		$retried = $this->service->run();

		self::assertSame(1, $retried->movedToTrash);
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday()]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testDryRunReportsTheMoveOfAMissingDocument(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		self::assertSame(['MISSING P123: ' . $this->deletedToday(), self::trashEmpty(1, 1)], $report->actions);
		self::assertSame(1, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	/**
	 * Decision 0004: permanent deletion is off by default, so a document that Paperless purged
	 * from its trash keeps its copy in the deleted folder, run after run.
	 */
	public function testDocumentPurgedFromTheTrashKeepsItsCopyWhilePermanentDeletionIsOff(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->trashDocument();
		$this->service->run();
		$this->paperless->trash = [];

		$first = $this->service->run();
		$second = $this->service->run();

		foreach ([$first, $second] as $report) {
			self::assertSame([], $report->actions);
			self::assertSame(0, $report->permanentlyDeleted);
		}
		self::assertSame([$this->trashFolderPath() => '%PDF-content'], $this->storage->files);
		self::assertNotContains('delete', $this->storage->methods());
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(2, $this->state->exports[123]['missing_runs']);
	}

	public function testDryRunReportsNoDeletionOfAPurgedDocumentWhilePermanentDeletionIsOff(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->trashDocument();
		$this->service->run();
		$this->paperless->trash = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame('trash', $this->state->exports[123]['state']);
	}

	/**
	 * Decision 0004: without direct deletion, a document that vanished without the trash of
	 * Paperless moves to the deleted folder and stays there, even with permanent deletion on.
	 */
	public function testVanishedDocumentMovesToTheDeletedFolderWithoutDirectDeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => false]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$first = $this->service->run();
		$second = $this->service->run();

		self::assertSame(['MISSING P123: ' . $this->deletedToday()], $first->actions);
		self::assertSame([], $second->actions);
		self::assertSame(0, $first->permanentlyDeleted + $second->permanentlyDeleted);
		self::assertSame([$this->deletedToday() => '%PDF-content'], $this->storage->files);
		self::assertNotContains('delete', $this->storage->methods());
		self::assertSame('missing', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsTheMoveNotTheDeletionWithoutDirectDeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => false]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		self::assertSame(['MISSING P123: ' . $this->deletedToday(), self::trashEmpty(1, 1)], $report->actions);
		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content'], $this->storage->files);
	}

	public function testPermanentDeletionsTakeTheirPlacesInTheBatch(): void {
		$this->moveToTheTrashFolder($this->documents(4));
		$this->configService->save(['permanent_delete' => true, 'missing_grace_runs' => 1, 'batch_size' => 2]);
		$this->paperless->trash = [];
		unset($this->storage->files[$this->trashFolderPath(1)]);
		$this->storage->foldersPerPrune = 2;

		$report = $this->service->run();

		self::assertSame(['DELETE P4: ' . $this->trashFolderPath(4), 'DELETE P3: ' . $this->trashFolderPath(3)], $report->actions);
		self::assertSame(2, $report->permanentlyDeleted);
		self::assertSame(1, $report->skipped);
		self::assertSame(4, $report->foldersPruned, 'Each deletion prunes its empty folders.');
		self::assertSame([$this->trashFolderPath(2) => '%PDF-content'], $this->storage->files);
		self::assertSame([2], array_keys($this->state->exports), 'P1 has no copy to delete, so its state goes without a place in the batch.');
	}

	/** Permanent deletion alone deletes only what the trash showed, so the notice of a missing document still says that it moves. */
	public function testDryRunWithPermanentDeletionAloneSaysThatMissingCopiesMove(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => false]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		self::assertSame(['MISSING P123: ' . $this->deletedToday(), self::trashEmpty(1, 1)], $report->actions);
		self::assertSame(0, $report->permanentlyDeleted);
	}

	/**
	 * Paperless lists in its trash only the documents that the account of the app owns, so a
	 * document of another owner in the trash looks gone without the trash. A dry-run, which an
	 * administrator reads before turning on the schedule, says so when exported documents go
	 * missing while the trash lists none.
	 */
	public function testDryRunExplainsMissingDocumentsWhileTheTrashListsNone(): void {
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		self::assertSame(['WAIT P124: missing run 1/3', 'WAIT P123: missing run 1/3', self::trashEmpty(2, 3)], $report->actions);
		self::assertSame(['type' => 'trash-empty', 'values' => ['missing' => 2, 'scans' => 3, 'deletes' => 0]], $report->entries[2], 'The page translates the notice with its numbers.');
	}

	/** @return array<string, array{array<string, mixed>, bool, string, bool}> the settings, whether the trash lists a document, the state of the missing one, and whether the run is a dry-run */
	public static function missingDocumentsThatNeedNoExplanation(): array {
		return [
			'a run, not a dry-run' => [[], false, 'active', false],
			'a trash that lists a document' => [[], true, 'active', true],
			'copies that stay in place' => [['trash_mode' => 'keep'], false, 'active', true],
			'a document that the run saw in the trash before' => [[], false, 'trash', true],
		];
	}

	/** @param array<string, mixed> $settings */
	#[DataProvider('missingDocumentsThatNeedNoExplanation')]
	public function testMissingDocumentsNeedNoExplanationOtherwise(array $settings, bool $trashListsOne, string $state, bool $dryRun): void {
		$this->configService->save($settings);
		$this->exportDocument();
		$this->state->exports[123]['state'] = $state;
		$this->paperless->documents = [];
		if ($trashListsOne) {
			$this->paperless->trash = [$this->document(124)];
		}

		$report = $this->service->run($dryRun);

		self::assertSame([], array_values(array_filter($report->actions, static fn (string $action): bool => str_starts_with($action, 'TRASH EMPTY:'))));
		self::assertNotSame([], $report->actions, 'The missing document has its line.');
	}

	public function testMissingDocumentIsDeletedDirectlyWhenAllowed(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->permanentlyDeleted);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame([[self::EXPORTED_PATH, self::ARCHIVE]], $this->storage->prunes);
	}

	public function testDryRunReportsThePermanentDeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		// The notice says that the copy is deleted, although the document may be in a trash that the account doesn't see.
		self::assertSame(['DELETE P123: ' . self::EXPORTED_PATH, self::trashEmpty(1, 1, true)], $report->actions);
		self::assertSame([
			['type' => 'delete', 'document' => 123, 'path' => self::EXPORTED_PATH],
			['type' => 'trash-empty', 'values' => ['missing' => 1, 'scans' => 1, 'deletes' => 1]],
		], $report->entries);
		self::assertSame(1, $report->permanentlyDeleted);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testFailedPermanentDeletionIsReported(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->deleteException = new RuntimeException('File is locked');
		$this->expectDocumentWarning(self::EXPORTED_PATH, 'File is locked');

		$report = $this->service->run();
		$this->service->run();

		self::assertSame(['ERROR: Missing P123: File is locked'], $report->actions);
		self::assertSame(0, $report->permanentlyDeleted);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('File is locked', $this->state->exports[123]['last_error']);
	}

	public function testMissingDocumentWithoutAFileIsForgottenWithoutADeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame(0, $report->errors);
		self::assertSame([], $this->state->exports);
	}

	public function testPermanentDeletionWaitsWhenTheBatchIsFull(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$report = $this->service->run();

		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame(1, $report->skipped);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	/** Every change that leaves a folder empty prunes it, and the report counts every folder. */
	public function testEveryPrunedFolderCounts(): void {
		$inbox = 'Dokumente/Paperless/Eingang/Ordner/';
		$this->configService->save(['missing_grace_runs' => 1]);
		foreach (['a', 'b', 'c'] as $name) {
			$this->storage->files["{$inbox}{$name}.pdf"] = "%PDF-{$name}";
		}
		$this->exportDocuments([$this->document(100), $this->document(110), $this->document(120), $this->document(130)]);
		$this->paperless->tasks = [
			'task-1' => ['status' => 'SUCCESS', 'message' => ''],
			'task-2' => ['status' => 'SUCCESS', 'message' => ''],
			'task-3' => ['status' => 'FAILURE', 'message' => 'Unsupported mime type'],
		];
		$excluded = $this->document(130);
		$excluded['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$renamed = $this->document(120);
		$renamed['title'] = 'Strom September';
		$renamed['modified'] = '2026-08-26T11:00:00+02:00';
		// P110 moves to the trash of Paperless, and P100 is gone from both lists.
		$this->paperless->documents = [$excluded, $renamed];
		$this->paperless->trash = [$this->document(110) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->storage->foldersPerPrune = 2;

		$report = $this->service->run();

		self::assertSame([
			"IMPORT SUCCESS: {$inbox}a.pdf",
			"IMPORT SUCCESS: {$inbox}b.pdf",
			"IMPORT ERROR: {$inbox}c.pdf -> Dokumente/Paperless/Fehler/Ordner/c.pdf",
			'REMOVE EXCLUDED P130: ' . $this->archivePath(130),
			'MOVE P120: ' . self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom September [P120].pdf',
			'TRASH P110: ' . $this->trashFolderPath(110),
			'MISSING P100: ' . $this->deletedToday(100),
		], $report->actions);
		self::assertSame(14, $report->foldersPruned, 'Each of the seven changes pruned two folders.');
	}

	/** A dry-run counts its changes against the batch as a run does, so it shows what the next run does. */
	public function testDryRunCountsItsChangesAgainstTheBatch(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 2]);
		$this->trashDocument();
		$this->paperless->documents = [$this->document(125), $this->document(130)];
		$this->paperless->contents += [125 => '%PDF-125', 130 => '%PDF-130'];

		$report = $this->service->run(true);

		self::assertSame(['EXPORT P130: ' . $this->archivePath(130), 'EXPORT P125: ' . $this->archivePath(125)], $report->actions);
		self::assertSame(1, $report->skipped, 'The move to the trash folder waits for the next run.');
	}

	/** A document that failed waits behind every document without an error, also behind one that a later run saw. */
	public function testDocumentsWithoutAnErrorComeFirstWhateverTheirLastRun(): void {
		$this->storage->writeFailures[$this->archivePath(5)] = new RuntimeException('The file name is not allowed');
		$this->exportDocuments([$this->document(5), $this->document(9)]);
		$this->state->exports[5]['last_seen'] = TestClock::START - 3600;
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents[1]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[9] = '%PDF-9-corrected';

		$report = $this->service->run();

		self::assertSame(['EXPORT P9: ' . $this->archivePath(9)], $report->actions);
		self::assertSame(1, $report->skipped);
	}

	/** @return array<string, array{bool, string, bool}> */
	public static function preferredVersions(): array {
		return [
			'the archived version by default' => [true, '%PDF-archive', false],
			'the original when the archived version is not preferred' => [false, '%PDF-original', true],
		];
	}

	#[DataProvider('preferredVersions')]
	public function testExportsThePreferredVersionOfADocument(bool $preferArchive, string $content, bool $original): void {
		$this->configService->save(['prefer_archive' => $preferArchive]);
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-archive';
		$this->paperless->originalContents[123] = '%PDF-original';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$this->service->run();

		self::assertSame([self::EXPORTED_PATH => $content], $this->storage->files);
		self::assertSame([[123, $original]], $this->paperless->downloadRequests);
		self::assertSame(($original ? 'original:' : 'archive:') . '2026-08-26T10:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	public function testFoldersAreKeptWhenPruningIsOff(): void {
		$this->configService->save(['prune_empty_folders' => false, 'missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$excluded = $this->paperless->documents[0];
		$excluded['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$this->paperless->documents = [$excluded];

		$report = $this->service->run();

		self::assertSame(1, $report->removedExcluded);
		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->foldersPruned);
		self::assertSame([], $this->storage->prunes);
	}

	public function testDryRunDoesNotMutateFilesOrState(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$report = $this->service->run(true);
		self::assertSame(1, $report->exported);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame(0, $this->paperless->downloads);
	}

	public function testDryRunReportsAMoveAsMove(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$report = $this->service->run(true);

		self::assertSame(['MOVE P123: ' . self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf'], $report->actions);
		self::assertSame(1, $report->moved);
		self::assertSame('dry-run-completed', $this->settings['status_last_state']);
	}

	public function testDisabledDirectionsAreNotSynchronized(): void {
		$this->configService->save(['inbox_enabled' => false, 'export_enabled' => false]);
		$this->paperless->documents = [$this->document()];
		$this->storage->files['Dokumente/Paperless/Eingang/police.pdf'] = '%PDF-inbox';

		$report = $this->service->run();

		self::assertSame(0, $report->activeDocuments);
		self::assertSame(0, $report->importsSubmitted);
		self::assertSame([], $this->state->exports);
	}

	public function testScheduleFlagAvoidsNextcloudReservedEnabledKey(): void {
		$this->configService->save(['enabled' => true]);

		self::assertTrue($this->settings['sync_enabled']);
		self::assertArrayNotHasKey('enabled', $this->settings);
	}

	public function testActivityStreamShowsWhatTheRunChangedWithTheFilesThatItWrote(): void {
		$this->exportDocument();

		self::assertCount(1, $this->activities);
		$event = $this->activities[0];
		self::assertSame(ActivityPublisher::SUBJECT_RUN, $event->getSubject());
		self::assertSame('paperless', $event->getAffectedUser());
		$parameters = $event->getSubjectParameters();
		self::assertSame(1, $parameters['exported']);
		self::assertSame([['id' => $this->storage->fileId(self::EXPORTED_PATH), 'path' => self::EXPORTED_PATH]], $parameters['files']);
		self::assertSame(0, $parameters['more']);
	}

	public function testRunWithoutChangesAndDryRunLeaveTheActivityStreamAlone(): void {
		$this->exportDocument();
		$this->activities = [];

		$unchanged = $this->service->run();
		$this->paperless->documents[0]['title'] = 'Strom September';
		$dryRun = $this->service->run(true);

		self::assertSame(1, $unchanged->unchanged);
		self::assertSame(1, $dryRun->moved);
		self::assertSame([], $this->activities);
	}

	public function testMoveToTheDeletedFolderLinksTheSameFile(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->trashDocument();
		$this->activities = [];

		$this->service->run();

		$parameters = $this->activities[0]->getSubjectParameters();
		self::assertSame(1, $parameters['trashed']);
		self::assertSame([['id' => $fileId, 'path' => $this->trashFolderPath()]], $parameters['files'], 'The link of the file leads to it in the deleted folder.');
		self::assertSame(self::ARCHIVE . '/_Gelöscht', $parameters['trash']['path']);
	}

	public function testDocumentThatVanishedMovesToTheDeletedFolderInTheActivityStreamToo(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->activities = [];

		$this->service->run();

		$parameters = $this->activities[0]->getSubjectParameters();
		self::assertSame(1, $parameters['trashed']);
		self::assertSame($this->deletedToday(), $parameters['files'][0]['path']);
	}

	public function testEveryImportResultReachesTheActivityStream(): void {
		$scan = 'Dokumente/Paperless/Eingang/scan.pdf';
		$broken = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$scan] = '%PDF-scan';
		$this->storage->files[$broken] = 'broken';

		$this->service->run();
		self::assertSame([ActivityPublisher::SUBJECT_RUN], $this->subjects());
		self::assertSame(2, $this->activities[0]->getSubjectParameters()['submitted']);

		$this->activities = [];
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => 'Success. New document id 456 created', 'documentId' => 456];
		$this->paperless->tasks['task-2'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];
		$this->service->run();

		self::assertSame([ActivityPublisher::SUBJECT_IMPORTED, ActivityPublisher::SUBJECT_IMPORT_FAILED], $this->subjects(), 'A run whose only news are import results has no entry of its own.');
		self::assertSame(['file' => ['id' => 0, 'path' => $scan], 'document' => 456], $this->activities[0]->getSubjectParameters(), 'The imported file is gone from the inbox.');
		$errorPath = 'Dokumente/Paperless/Fehler/broken.pdf';
		self::assertSame(['file' => ['id' => $this->storage->fileId($errorPath), 'path' => $errorPath], 'reason' => 'Unsupported mime type'], $this->activities[1]->getSubjectParameters());
	}

	public function testImportOfAFileThatChangedSinceItsUploadReachesTheActivityStream(): void {
		$path = 'Dokumente/Paperless/Eingang/scan.pdf';
		$this->storage->files[$path] = '%PDF-scan';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-scan, signed';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$this->activities = [];

		$this->service->run();

		self::assertSame(ActivityPublisher::SUBJECT_IMPORTED, $this->activities[0]->getSubject());
		self::assertSame(['file' => ['id' => $this->storage->fileId($path), 'path' => $path], 'document' => null], $this->activities[0]->getSubjectParameters());
	}

	public function testFileThatPaperlessRefusedReachesTheActivityStreamOnce(): void {
		$path = 'Dokumente/Paperless/Eingang/notes.bin';
		$this->storage->files[$path] = 'notes';
		$reason = 'Paperless answered the upload with HTTP 400: document: File type application/octet-stream not supported';
		$this->paperless->uploadException = new PaperlessUploadException($reason, true, 400);

		$this->service->run();
		self::assertSame([ActivityPublisher::SUBJECT_IMPORT_REJECTED], $this->subjects());
		self::assertSame(['file' => ['id' => $this->storage->fileId($path), 'path' => $path], 'reason' => $reason], $this->activities[0]->getSubjectParameters());

		$this->activities = [];
		$this->service->run();
		self::assertSame([], $this->activities, 'The runs after it skip the file quietly.');
	}

	public function testImportThatPaperlessCancelledReachesTheActivityStreamWithItsReason(): void {
		$path = 'Dokumente/Paperless/Eingang/scan.pdf';
		$this->storage->files[$path] = '%PDF-scan';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'REVOKED', 'message' => ''];
		$this->activities = [];

		$this->service->run();

		$errorPath = 'Dokumente/Paperless/Fehler/scan.pdf';
		self::assertSame([ActivityPublisher::SUBJECT_IMPORT_FAILED], $this->subjects());
		self::assertSame(
			['file' => ['id' => $this->storage->fileId($errorPath), 'path' => $errorPath], 'reason' => 'Paperless cancelled the task before it finished (REVOKED), so it created no document.'],
			$this->activities[0]->getSubjectParameters(),
		);
	}

	public function testImportThatPaperlessHasNotFinishedADayAfterTheUploadReachesTheActivityStream(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'STARTED', 'message' => ''];
		$this->clock->advance(86400);
		$this->activities = [];

		$this->service->run();

		$errorPath = 'Dokumente/Paperless/Fehler/police.pdf';
		self::assertSame([ActivityPublisher::SUBJECT_IMPORT_FAILED], $this->subjects());
		self::assertSame(
			['file' => ['id' => $this->storage->fileId($errorPath), 'path' => $errorPath], 'reason' => 'Paperless has not finished the import a day after the upload (STARTED), so it may still create the document.'],
			$this->activities[0]->getSubjectParameters(),
		);
	}

	public function testFileThatPaperlessHasADayAfterTheUploadReachesTheActivityStreamAsImported(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false];
		$this->paperless->originals[hash('sha256', '%PDF-inbox')] = 456;
		$this->clock->advance(86400);
		$this->activities = [];

		$this->service->run();

		self::assertSame([ActivityPublisher::SUBJECT_IMPORTED], $this->subjects());
		self::assertSame(['file' => ['id' => 0, 'path' => $path], 'document' => 456], $this->activities[0]->getSubjectParameters(), 'The imported file is gone from the inbox.');
	}

	public function testRunThatFailsAfterItsFirstChangesStillShowsThem(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$this->paperless->documents = [['title' => 'A document without an ID']];

		try {
			$this->service->run();
			self::fail('The run should fail.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless returned a document without a valid ID.', $exception->getMessage());
		}

		self::assertSame([ActivityPublisher::SUBJECT_RUN], $this->subjects());
		self::assertSame(1, $this->activities[0]->getSubjectParameters()['submitted']);
	}

	public function testActivityStreamThatRefusesTheEntryLeavesTheRunAlone(): void {
		$this->activityFailure = new RuntimeException('The database is full.');
		$this->logger->expects(self::once())->method('warning')->with('Paperless Sync could not add the changes of its run to the activity stream: {error}', self::anything());

		$report = $this->exportDocument();

		self::assertSame(1, $report->exported);
		self::assertSame(0, $report->errors);
		self::assertSame('completed', $this->settings['status_last_state']);
	}

	/** @return list<string> the subjects of the entries of the activity stream */
	private function subjects(): array {
		return array_map(static fn ($event): string => $event->getSubject(), $this->activities);
	}

	/** Expects the warning about the error of the document P123, once in every run that follows. */
	private function expectDocumentWarning(string $path, string $error): void {
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not synchronize the document P{documentId}: {error}',
			['documentId' => 123, 'path' => $path, 'error' => $error],
		);
	}

	/** @param array<string, mixed>|null $document */
	private function exportDocument(?array $document = null): SyncReport {
		return $this->exportDocuments([$document ?? $this->document()]);
	}

	/**
	 * A document that an earlier version exported, with the SHA-256 alone as its fingerprint, on a
	 * Paperless-ngx 2 that reports MD5 checksums, after a new revision of the document.
	 */
	private function exportWithFingerprintOfAnEarlierVersionOnPaperless2(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocument();
		$this->state->exports[123]['fingerprint'] = hash('sha256', '%PDF-content');
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
	}

	/** @param list<array<string, mixed>> $documents */
	private function exportDocuments(array $documents): SyncReport {
		$this->paperless->documents = $documents;
		foreach ($documents as $document) {
			$this->paperless->contents[(int)$document['id']] = '%PDF-content';
		}
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		return $this->service->run();
	}

	/** @return list<array<string, mixed>> the documents with the IDs 1 to $count */
	private function documents(int $count): array {
		return array_map(fn (int $id): array => $this->document($id), range(1, $count));
	}

	/** The path of the copy of a document in the archive. */
	private function archivePath(int $id): string {
		return self::ARCHIVE . "/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/**
	 * The notice of a dry-run about documents that go missing while the trash of Paperless lists
	 * none, with $deleted when permanent and direct deletion are both on.
	 */
	private static function trashEmpty(int $missing, int $scans, bool $deleted = false): string {
		$fate = $deleted
			? "their copies are deleted after the required missing scans ({$scans} in a row), although the documents can still be restored in Paperless"
			: "their copies move to the deleted folder only after the required missing scans ({$scans} in a row), as those of documents deleted without the trash do";

		return "TRASH EMPTY: documents of the archive are gone from Paperless ({$missing}), and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner. Documents in the trash of other owners count as missing: {$fate}. Make the account of the app their owner.";
	}

	/** The path of the copy of a document that a run of today, the day of the clock, moves to the deleted folder. */
	private function deletedToday(int $id = 123): string {
		return self::ARCHIVE . '/_Gelöscht/' . $this->clock->today() . "/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/** The path of the copy of a document that Paperless moved to its trash on 2026-08-27. */
	private function trashFolderPath(int $id = 123): string {
		return self::ARCHIVE . "/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/**
	 * Exports the documents, moves them to the trash of Paperless and their copies to the trash
	 * folder, and gives each an error and missing runs that the next run forgets.
	 *
	 * @param list<array<string, mixed>> $documents
	 */
	private function moveToTheTrashFolder(array $documents): void {
		$this->exportDocuments($documents);
		$this->paperless->documents = [];
		$this->paperless->trash = array_map(static fn (array $document): array => $document + ['deleted_at' => '2026-08-27T08:00:00+02:00'], $documents);
		$this->service->run();
		foreach (array_keys($this->state->exports) as $id) {
			$this->state->exports[$id]['last_error'] = 'The file was locked';
			$this->state->exports[$id]['missing_runs'] = 2;
		}
	}

	private function trashDocument(): void {
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];
	}

	/**
	 * The run of one document, which the webhook of Paperless asks for, takes the way of a run
	 * through the export: the same path, the same fingerprints, a move without a download, with a
	 * batch of one. It reads that document alone, neither the list of the documents nor the trash.
	 */
	public function testTheRunOfOneDocumentFollowsTheRulesOfARun(): void {
		$this->configService->save(['enabled' => true] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$exported = $this->service->syncDocument(123);

		self::assertSame([1, 0, 1], [$exported->exported, $exported->moved, $exported->activeDocuments]);
		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $exported->actions);
		self::assertSame('%PDF-content-v1', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame([123], $this->paperless->documentReads);
		self::assertSame($this->clock->time, $exported->completedAt);

		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$moved = $this->service->syncDocument(123);

		$renamedPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(['MOVE P123: ' . $renamedPath], $moved->actions);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('%PDF-content-v1', $this->storage->files[$renamedPath]);
		self::assertSame(1, $this->paperless->downloads, 'A new title moves the copy without a download, as in a run.');

		// A run afterwards finds the copy where the run of the document put it.
		self::assertSame(1, $this->service->run()->unchanged);
		self::assertSame(1, $this->paperless->downloads);
	}

	/**
	 * The metadata next to the archive follows the copy of its document: a run writes its sidecar
	 * and its row in the index, a run without a change writes neither, the row follows the copy
	 * into the deleted folder, and the sidecar goes with the copy, under the rules of deletion
	 * safety alone. A dry-run writes no metadata.
	 */
	public function testTheMetadataNextToTheArchiveFollowsTheCopyOfItsDocument(): void {
		$this->configService->save(['write_metadata' => true]);
		$this->paperless->documents = [[
			'tags' => [9],
			'custom_fields' => [['field' => 1, 'value' => 'INV-7']],
			'notes' => [['id' => 1, 'note' => 'Bezahlt.', 'created' => '2026-09-01T09:00:00+02:00', 'user' => ['id' => 3, 'username' => 'dennis']]],
		] + $this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['9' => 'Strom'], 'inbox' => []];
		$this->paperless->customFields = ['1' => ['name' => 'Rechnungsnummer', 'type' => 'string', 'options' => []]];
		$sidecar = self::ARCHIVE . '/.paperless/P123.json';
		$index = self::ARCHIVE . '/.paperless/index.csv';

		$this->service->run(true);
		self::assertSame([], $this->storage->files, 'A dry-run writes no metadata.');
		self::assertSame([], $this->paperless->listsRead, 'Nor does it need the custom fields for it.');
		self::assertSame([false], $this->paperless->notesAsked, 'Nor the notes.');

		self::assertSame(1, $this->service->run()->exported);
		$metadata = json_decode($this->storage->files[$sidecar], true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(['Strom August', 'Energie GmbH', 'Rechnung', ['Strom']], [$metadata['title'], $metadata['correspondent'], $metadata['document_type'], $metadata['tags']]);
		self::assertSame([['note' => 'Bezahlt.', 'created' => '2026-09-01T09:00:00+02:00', 'user' => 'dennis']], $metadata['notes']);
		self::assertSame([['name' => 'Rechnungsnummer', 'type' => 'string', 'value' => 'INV-7']], $metadata['custom_fields']);
		self::assertSame(['version' => 'archive', 'sha256' => hash('sha256', '%PDF-content-v1')], $metadata['file']);
		self::assertStringContainsString(',Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf,active,' . hash('sha256', '%PDF-content-v1') . ',', $this->storage->files[$index]);
		self::assertSame(['custom fields'], $this->paperless->listsRead);
		self::assertSame([false, true], $this->paperless->notesAsked);

		$this->storage->writes = [];
		self::assertSame(1, $this->service->run()->unchanged);
		self::assertSame([], $this->storage->writes, 'A run without a change writes no metadata.');

		$this->paperless->trash = [$this->paperless->documents[0] + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->paperless->documents = [];
		self::assertSame(1, $this->service->run()->movedToTrash);
		self::assertArrayHasKey($sidecar, $this->storage->files, 'A copy in the deleted folder keeps its sidecar.');
		self::assertStringContainsString(',_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf,trash,', $this->storage->files[$index]);

		// Deleted for good in Paperless, while permanent deletion is off: the copy and its sidecar stay.
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->paperless->trash = [];
		$this->service->run();
		self::assertArrayHasKey($sidecar, $this->storage->files);
		self::assertStringContainsString(',missing,', $this->storage->files[$index]);

		$this->configService->save(['permanent_delete' => true, 'allow_direct_delete' => true]);
		self::assertSame(1, $this->service->run()->permanentlyDeleted);
		self::assertSame([$index], array_keys($this->storage->files), 'The sidecar went with the copy.');
		self::assertSame("\u{FEFF}" . implode(',', MetadataSidecars::COLUMNS) . "\r\n", $this->storage->files[$index]);
	}

	/** A call of the webhook writes the metadata of its document at once, as a run does. */
	public function testTheRunOfOneDocumentWritesItsMetadata(): void {
		$this->configService->save(['enabled' => true, 'write_metadata' => true] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';

		self::assertSame(1, $this->service->syncDocument(123)->exported);

		self::assertArrayHasKey(self::ARCHIVE . '/.paperless/P123.json', $this->storage->files);
		self::assertStringContainsString("\r\n123,Strom August,", $this->storage->files[self::ARCHIVE . '/.paperless/index.csv']);
		self::assertSame([true], $this->paperless->notesAsked, 'The document comes with its notes.');
	}

	/** An index that Nextcloud can't read is an error of the run, which still exports its documents and leaves the metadata as it is. */
	public function testAnIndexThatNextcloudCannotReadLeavesTheMetadataAsItIs(): void {
		$this->configService->save(['write_metadata' => true]);
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$index = self::ARCHIVE . '/.paperless/index.csv';
		$this->storage->files[$index] = 'id';
		$this->storage->readException = new RuntimeException('The storage is not available');

		$report = $this->service->run();

		self::assertSame([1, 1], [$report->exported, $report->errors]);
		self::assertSame(['Metadata index: The storage is not available'], $report->errorMessages);
		self::assertContains(['type' => 'error', 'path' => $index, 'detail' => 'Metadata index: The storage is not available'], $report->entries);
		self::assertArrayNotHasKey(self::ARCHIVE . '/.paperless/P123.json', $this->storage->files);
		self::assertSame('id', $this->storage->files[$index]);
	}

	/**
	 * The metadata names the custom fields: without the permission to view them, the run exports
	 * its documents and writes no metadata, which is an error of the run that names the permission.
	 */
	public function testTheMetadataNeedsThePermissionToViewCustomFields(): void {
		$this->configService->save(['write_metadata' => true]);
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->customFieldsException = new RuntimeException('HTTP 403.');

		$report = $this->service->run();

		self::assertSame(1, $report->exported, 'The copies need no custom fields.');
		self::assertSame(['Metadata: Paperless did not list its custom fields, which the metadata next to the archive names, so the run writes no metadata; the user of the API token needs the permission to view custom fields: HTTP 403.'], $report->errorMessages);
		self::assertSame([], array_filter(array_keys($this->storage->files), static fn (string $path): bool => str_contains($path, '/.paperless/')));
	}

	/** A full quota stops the metadata of the run, once reported, and the run still exports its copies. */
	public function testAFullArchiveStopsTheMetadataOfTheRun(): void {
		$this->configService->save(['write_metadata' => true]);
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->storage->writeFailures[self::ARCHIVE . '/.paperless/P123.json'] = new NotEnoughSpaceException('Insufficient space in Documents');

		$report = $this->service->run();

		self::assertSame([1, ['Metadata P123: Insufficient space in Documents']], [$report->exported, $report->errorMessages]);
		self::assertArrayNotHasKey(self::ARCHIVE . '/.paperless/index.csv', $this->storage->files);
	}

	/** A document that the settings exclude loses its copy, as in a run. */
	public function testTheRunOfOneDocumentRemovesTheCopyOfAnExcludedDocument(): void {
		$this->configService->save(['enabled' => true, 'excluded_tags' => 'Privat'] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['2' => 'Privat'], 'inbox' => []];
		$this->service->syncDocument(123);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		$this->paperless->documents[0]['tags'] = [2];

		$report = $this->service->syncDocument(123);

		self::assertSame([1, 1], [$report->removedExcluded, $report->skipped]);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	/**
	 * A document that Paperless doesn't show, in its trash or deleted, waits for a scheduled run,
	 * which counts its missing scans: the run of one document counts none, and moves nothing.
	 */
	public function testTheRunOfOneDocumentLeavesTrashedAndDeletedDocumentsToTheScheduledRuns(): void {
		$this->configService->save(['enabled' => true] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->service->run();
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];

		$report = $this->service->syncDocument(123);

		self::assertSame([0, 0, 0], [$report->movedToTrash, $report->trashedDocuments, $report->activeDocuments]);
		self::assertSame([], $report->actions);
		self::assertSame('missing', WebhookService::result($report));
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame([0, 'active'], [(int)($this->state->exports[123]['missing_runs'] ?? 0), $this->state->exports[123]['state'] ?? null]);
	}

	/**
	 * The run of one document is no run of the status: the schedule counts from the last run, so
	 * calls of the webhook never hold back the scheduled run that finds trashed and deleted
	 * documents, and it gives no sign of life, which would make an interrupted run look active.
	 */
	public function testTheRunOfOneDocumentLeavesTheStatusOfTheLastRunAlone(): void {
		$this->configService->save(['enabled' => true] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->settings['status_last_state'] = 'running';
		$this->settings['status_last_started'] = $this->clock->time - 7200;
		$this->settings['status_last_heartbeat'] = $this->clock->time - StatusService::HEARTBEAT_GRACE - 1;
		$keys = array_flip(['status_last_state', 'status_last_started', 'status_last_heartbeat', 'status_last_sync_started', 'status_last_ended', 'status_last_report']);
		$before = array_intersect_key($this->settings, $keys);
		$this->locking->expects(self::once())->method('acquireLock')->with('paperless_sync::synchronization', ILockingProvider::LOCK_EXCLUSIVE, 'Paperless Sync');
		$this->locking->expects(self::once())->method('releaseLock')->with('paperless_sync::synchronization', ILockingProvider::LOCK_EXCLUSIVE);

		self::assertSame(1, $this->service->syncDocument(123)->exported);

		self::assertSame($before, array_intersect_key($this->settings, $keys));
		self::assertSame('interrupted', $this->status->get()['state']);
		self::assertSame([], $this->paperless->connections, 'The read of the document is the test of the connection.');
		self::assertEquals([new PaperlessConnection('https://paperless.example.test', 'test-token', false)], $this->paperless->connects, 'It reads the document and its lists through one connection.');
		self::assertCount(1, $this->activities, 'The Activity stream of the target user shows the export.');
	}

	/** While another run is active, the run of one document waits, for the background job that the webhook queues. */
	public function testTheRunOfOneDocumentWaitsForAnActiveRun(): void {
		$this->configService->save(['enabled' => true] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		try {
			$this->service->syncDocument(123);
			self::fail('A run of one document must not start beside an active run.');
		} catch (LockedException) {
		}

		self::assertSame([], $this->paperless->documentReads);
	}

	/** @return array<string, array{array<string, mixed>, string, string}> the settings that stop the runs of one document, the reason and the setting to change */
	public static function settingsWithoutDocumentRuns(): array {
		return [
			'scheduled synchronization off' => [['enabled' => false], 'Scheduled synchronization is off, so changes from Paperless wait until it is on again.', 'enabled'],
			'the export off' => [['enabled' => true, 'export_enabled' => false], 'The archive export is off, so changes from Paperless reach no copy.', 'export_enabled'],
		];
	}

	/** @param array<string, mixed> $settings */
	#[DataProvider('settingsWithoutDocumentRuns')]
	public function testTheRunOfOneDocumentFollowsTheSwitchesOfTheRuns(array $settings, string $message, string $setting): void {
		$this->configService->save($settings + self::validSettings());
		$this->paperless->documents = [$this->document()];
		// An expected state, no failure: no lock, and no error in the log.
		$this->locking->expects(self::never())->method('acquireLock');
		$this->logger->expects(self::never())->method('error');

		try {
			$this->service->syncDocument(123);
			self::fail('A run of one document must not start while the runs are off.');
		} catch (InvalidSettingException $exception) {
			self::assertSame([$message, $setting], [$exception->getMessage(), $exception->setting]);
		}

		self::assertSame([], $this->paperless->documentReads);
		self::assertSame([], $this->activities);
	}

	public function testTheRunOfOneDocumentNeedsTheConnectionToPaperless(): void {
		$this->configService->reset();

		$this->expectException(InvalidSettingException::class);
		$this->expectExceptionMessage('Paperless Sync is not completely configured.');
		$this->service->syncDocument(123);
	}

	/** A failure is logged with its trace, and the lock is free again for the next run. */
	public function testAFailedRunOfOneDocumentIsLoggedAndReleasesTheLock(): void {
		$this->configService->save(['enabled' => true] + self::validSettings());
		$failure = new PaperlessRequestException('cURL error 7: Failed to connect to paperless.example.test');
		$this->paperless->documentException = $failure;
		$this->locking->expects(self::once())->method('releaseLock');
		$this->logger->expects(self::once())->method('error')->with('Paperless synchronization of document {document} failed', ['document' => 123, 'exception' => $failure]);

		$this->expectExceptionObject($failure);
		$this->service->syncDocument(123);
	}

	public function testACompletedRunOfOneDocumentIsLogged(): void {
		$this->configService->save(['enabled' => true] + self::validSettings());
		$this->paperless->documents = [$this->document()];
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization of document {document} completed', self::callback(
			static fn (array $context): bool => $context['document'] === 123 && $context['exported'] === 1,
		));

		$this->service->syncDocument(123);
	}

	/** @return array<string, mixed> */
	private function document(int $id = 123): array {
		return [
			'id' => $id,
			'title' => 'Strom August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'tags' => [],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];
	}
}
