<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\WebhookController;
use OCA\PaperlessSync\Cron\DocumentSyncJob;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\InMemoryWebhook;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The webhook that a workflow of Paperless calls, with the run of one document behind it, as
 * Nextcloud dispatches a call: the secret in the header Authorization, the document as a parameter.
 */
final class WebhookControllerTest extends TestCase {
	use InMemoryConfiguration;
	use InMemoryWebhook;
	use RecordedActivities;
	use RecordedNotifications;

	private const ARCHIVE = 'Documents/Paperless/Archive';
	private const PATH = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
	private const RENAMED_PATH = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom September [P123].pdf';

	private TestClock $clock;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var IJobList&MockObject */
	private IJobList $jobList;
	/** @var IThrottler&MockObject */
	private IThrottler $throttler;
	/** The header Authorization of the call. */
	private string $authorization = '';

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->paperless = new TestPaperlessClient();
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->documents = [self::document('Strom August')];
		$this->paperless->contents[123] = '%PDF-123';
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
	}

	/**
	 * Paperless has no session of Nextcloud, so the webhook is a public page without the token of
	 * a form; Nextcloud counts and delays its calls with a wrong secret and limits all of them.
	 */
	public function testTheWebhookIsPublicButThrottledAndLimited(): void {
		$method = new ReflectionMethod(WebhookController::class, 'receive');

		self::assertCount(1, $method->getAttributes(PublicPage::class));
		self::assertCount(1, $method->getAttributes(NoCSRFRequired::class));
		$route = $method->getAttributes(FrontpageRoute::class)[0]->newInstance();
		self::assertSame(['POST', '/api/v1/webhook'], [$route->getVerb(), $route->getUrl()]);
		$protection = $method->getAttributes(BruteForceProtection::class)[0]->newInstance();
		self::assertSame(WebhookController::THROTTLE_ACTION, $protection->getAction());
		$limit = $method->getAttributes(AnonRateLimit::class)[0]->newInstance();
		self::assertSame([WebhookController::RATE_LIMIT, WebhookController::RATE_PERIOD], [$limit->getLimit(), $limit->getPeriod()]);
	}

	/** Only administrators see and change the secret; a change asks for their password. */
	public function testTheSecretBelongsToAdministrators(): void {
		foreach (['state' => ['GET', false], 'create' => ['POST', true], 'remove' => ['DELETE', true]] as $name => [$verb, $confirmed]) {
			$method = new ReflectionMethod(WebhookController::class, $name);
			$route = $method->getAttributes(FrontpageRoute::class)[0]->newInstance();
			self::assertSame([$verb, '/settings/webhook'], [$route->getVerb(), $route->getUrl()]);
			self::assertSame([], $method->getAttributes(PublicPage::class));
			self::assertSame([], $method->getAttributes(NoAdminRequired::class));
			self::assertCount($confirmed ? 1 : 0, $method->getAttributes(PasswordConfirmationRequired::class), $name);
		}
	}

	/** @return array<string, array{string}> the header Authorization of calls without the secret */
	public static function callsWithoutTheSecret(): array {
		return [
			'no header' => [''],
			'a wrong secret' => ['Bearer ' . self::OTHER_SECRET],
			'the token of Paperless' => ['Token secret-token'],
			'the secret without its scheme' => [self::SECRET],
		];
	}

	#[DataProvider('callsWithoutTheSecret')]
	public function testACallWithoutTheSecretIsRefusedAndThrottled(string $authorization): void {
		$webhook = $this->webhookService($this->clock);
		$webhook->create();
		$this->authorization = $authorization;
		$this->throttler->expects(self::never())->method('resetDelay');
		$this->jobList->expects(self::never())->method('add');

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
		self::assertSame(['action' => WebhookController::THROTTLE_ACTION], $response->getThrottleMetadata());
		self::assertSame([], $this->paperless->documentReads);
		self::assertNull($webhook->state()['lastCall'], 'A refused call is no call of Paperless.');
	}

	/** Before an administrator creates the secret, the webhook refuses every call. */
	public function testWithoutASecretEveryCallIsRefused(): void {
		$this->authorization = 'Bearer ' . self::SECRET;

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	/** The run starts during the call: the copy changes before Paperless has its answer. */
	public function testACallMirrorsTheChangeOfTheDocumentAtOnce(): void {
		$webhook = $this->webhookService($this->clock);
		$webhook->create();
		$this->authorization = 'Bearer ' . self::SECRET;
		$controller = $this->controller();
		self::assertSame(['document' => 123, 'result' => 'exported'], $controller->receive(123)->getData());
		self::assertSame('%PDF-123', $this->storage->files[self::PATH]);
		$this->paperless->documents = [self::document('Strom September')];
		$this->clock->advance(60);
		$this->throttler->expects(self::once())->method('resetDelay')->with('192.0.2.10', WebhookController::THROTTLE_ACTION, ['action' => WebhookController::THROTTLE_ACTION]);
		$this->jobList->expects(self::never())->method('add');

		$response = $controller->receive('123');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['document' => 123, 'result' => 'moved'], $response->getData());
		self::assertArrayNotHasKey(self::PATH, $this->storage->files);
		self::assertSame('%PDF-123', $this->storage->files[self::RENAMED_PATH]);
		self::assertSame(1, $this->paperless->downloads, 'A new title moves the copy without a download, as in a run.');
		self::assertSame(['at' => TestClock::START + 60, 'document' => 123, 'result' => 'moved', 'message' => ''], $webhook->state()['lastCall']);
		self::assertSame([], $this->paperless->connections, 'The run of one document reads that document, without the test of a run.');
		self::assertArrayNotHasKey('status_last_started', $this->settings, 'The status keeps the last run, from which the schedule counts.');
		self::assertCount(2, $this->activities, 'The Activity stream of the target user shows the export and the move.');
	}

	/** @return array<string, array{mixed}> the document of a call, as JSON, a form or the placeholder {{ doc_url }} of Paperless-ngx 2.x send it */
	public static function documents(): array {
		return [
			'a number' => [123],
			'text' => ['123'],
			'text with spaces' => [" 123\n"],
			'the address of the document' => ['https://paperless.example.test/documents/123/'],
			'the address without a slash at its end' => ['https://paperless.example.test/documents/123'],
			'the path alone, without PAPERLESS_URL' => ['/documents/123/'],
			'Paperless under a path' => ['https://example.test/paperless/documents/123/'],
		];
	}

	#[DataProvider('documents')]
	public function testTheDocumentIsItsIdOrItsAddressInPaperless(mixed $document): void {
		$this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . self::SECRET;

		self::assertSame(['document' => 123, 'result' => 'exported'], $this->controller()->receive($document)->getData());
		self::assertSame([123], $this->paperless->documentReads);
	}

	/** @return array<string, array{mixed}> */
	public static function invalidDocuments(): array {
		return [
			'none' => [null],
			'zero' => [0],
			'below zero' => [-5],
			'a word' => ['abc'],
			'a number and more' => ['123abc'],
			'a list' => [[123]],
			'a page of the document' => ['https://paperless.example.test/documents/123/details'],
			'an address of something else' => ['https://paperless.example.test/tags/123/'],
			'too large a number' => [str_repeat('9', 19)],
			'a number with a sign' => ['+123'],
		];
	}

	#[DataProvider('invalidDocuments')]
	public function testACallWithoutADocumentIsRefused(mixed $document): void {
		$this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . self::SECRET;

		$response = $this->controller()->receive($document);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertFalse($response->isThrottled(), 'Paperless knows the secret; its call names no document.');
		self::assertSame([], $this->paperless->documentReads);
	}

	/** While another run holds the lock, the document waits for the background jobs: once, however often Paperless calls. */
	public function testACallDuringAnotherRunQueuesTheDocument(): void {
		$webhook = $this->webhookService($this->clock);
		$webhook->create();
		$this->authorization = 'Bearer ' . self::SECRET;
		$this->locking->method('acquireLock')->willThrowException(new LockedException(AppConstants::LOCK_PATH));
		$this->jobList->expects(self::once())->method('add')->with(DocumentSyncJob::class, ['document' => 123]);

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		self::assertSame(['document' => 123, 'result' => 'queued'], $response->getData());
		self::assertSame([], $this->paperless->documentReads);
		self::assertSame('queued', $webhook->state()['lastCall']['result'] ?? null);
	}

	/** With "Mirror the document during the call" off, every document waits for the background jobs. */
	public function testACallLeavesTheDocumentToTheBackgroundJobsWhenTheSettingsSaySo(): void {
		$this->configService()->save(['enabled' => true, 'webhook_immediate' => false] + self::validSettings());
		$this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . self::SECRET;
		$this->locking->expects(self::never())->method('acquireLock');
		$this->jobList->expects(self::once())->method('add')->with(DocumentSyncJob::class, ['document' => 123]);

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		self::assertSame([], $this->storage->files);
	}

	/** @return array<string, array{array<string, mixed>, string}> settings that stop the runs of one document, and the reason */
	public static function settingsThatStopTheWebhook(): array {
		return [
			'scheduled synchronization off' => [['enabled' => false], 'Scheduled synchronization is off, so changes from Paperless wait until it is on again.'],
			'the export off' => [['export_enabled' => false], 'The archive export is off, so changes from Paperless reach no copy.'],
		];
	}

	/**
	 * Scheduled synchronization is the switch of every run without an administrator: before the
	 * first dry-run was reviewed, or while Paperless is down, a call changes nothing either.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider('settingsThatStopTheWebhook')]
	public function testACallChangesNothingWhileSynchronizationIsOff(array $settings, string $message): void {
		$this->configService()->save($settings + ['enabled' => true] + self::validSettings());
		$webhook = $this->webhookService($this->clock);
		$webhook->create();
		$this->authorization = 'Bearer ' . self::SECRET;
		$this->jobList->expects(self::never())->method('add');

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['document' => 123, 'result' => 'off', 'message' => $message], $response->getData());
		self::assertSame([], $this->storage->files);
		self::assertSame('off', $webhook->state()['lastCall']['result'] ?? null);
	}

	public function testACallChangesNothingWithoutTheConnectionToPaperless(): void {
		$this->configService()->reset();
		$this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . self::SECRET;

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('Paperless Sync is not completely configured.', $response->getData()['message']);
	}

	/** A run that fails answers 500, so that Paperless retries the call and logs the reason. */
	public function testAFailedRunAnswersWithItsReason(): void {
		$webhook = $this->webhookService($this->clock);
		$webhook->create();
		$this->authorization = 'Bearer ' . self::SECRET;
		$this->paperless->documentException = new PaperlessRequestException('cURL error 7: Failed to connect to paperless.example.test');

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['document' => 123, 'result' => 'failed', 'message' => 'cURL error 7: Failed to connect to paperless.example.test'], $response->getData());
		self::assertSame('failed', $webhook->state()['lastCall']['result'] ?? null);
	}

	/** The copy of the document failed, such as for a file in the way: the run reports it, and so does the answer. */
	public function testAFailedCopyAnswersWithItsError(): void {
		$this->configService()->save(['enabled' => true, 'conflict_mode' => 'skip'] + self::validSettings());
		$this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . self::SECRET;
		$this->storage->files[self::PATH] = 'A file of the user';

		$response = $this->controller()->receive(123);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['document' => 123, 'result' => 'failed', 'message' => 'P123: Nextcloud file conflict at ' . self::PATH . '.'], $response->getData());
		self::assertSame('A file of the user', $this->storage->files[self::PATH]);
	}

	/** A document that Paperless doesn't show, such as one in its trash, waits for a scheduled run. */
	public function testACallForADocumentThatPaperlessDoesNotShowChangesNothing(): void {
		$this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . self::SECRET;

		$response = $this->controller()->receive(999);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['document' => 999, 'result' => 'missing'], $response->getData());
		self::assertSame([], $this->storage->files);
	}

	/** The secret appears once, in the answer to its creation; the state of the webhook never has it. */
	public function testAdministratorsCreateAndRemoveTheSecret(): void {
		$controller = $this->controller();
		self::assertSame(['configured' => false, 'created' => 0, 'url' => self::WEBHOOK_URL, 'lastCall' => null], $controller->state()->getData());

		$created = $controller->create()->getData();

		self::assertSame(['secret' => self::SECRET, 'configured' => true, 'created' => TestClock::START, 'url' => self::WEBHOOK_URL, 'lastCall' => null], $created);
		self::assertStringNotContainsString(self::SECRET, json_encode($controller->state()->getData(), JSON_THROW_ON_ERROR));
		$this->authorization = 'Bearer ' . self::SECRET;
		self::assertSame(Http::STATUS_OK, $controller->receive(123)->getStatus());

		self::assertFalse($controller->remove()->getData()['configured']);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->receive(123)->getStatus());
	}

	private function controller(): WebhookController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'Authorization' ? $this->authorization : '');
		$request->method('getRemoteAddress')->willReturn('192.0.2.10');
		$status = $this->statusService($this->clock, $this->locking);
		$sync = new SyncService(
			$this->configService(),
			$this->paperless,
			$this->storage,
			$this->state,
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
			$this->clock,
			$this->activityPublisher($this->storage, $this->createMock(LoggerInterface::class)),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($status, $this->storage, $this->clock, $this->createMock(LoggerInterface::class)),
		);

		return new WebhookController(AppConstants::APP_ID, $request, $this->webhookService($this->clock), $this->configService(), $sync, $this->jobList, $this->throttler);
	}

	/** @return array<string, mixed> P123 with the title, as Paperless lists it */
	private static function document(string $title): array {
		return [
			'id' => 123,
			'title' => $title,
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'tags' => [],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => $title === 'Strom August' ? '2026-08-26T10:00:00+02:00' : '2026-09-01T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];
	}

}
