<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\HealthController;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\InMemoryWebhook;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The health check that a monitor calls, with its token in the header Authorization, as Nextcloud
 * dispatches a call, and the token, which administrators create on the settings page.
 */
#[AllowMockObjectsWithoutExpectations]
final class HealthControllerTest extends TestCase {
	use InMemoryConfiguration;
	use InMemoryWebhook;

	private TestClock $clock;
	private StatusService $status;
	/** @var IThrottler&MockObject */
	private IThrottler $throttler;
	/** The header Authorization of the call. */
	private string $authorization = '';

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->status = $this->statusService($this->clock);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->configService()->save(['enabled' => true, 'sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
	}

	/**
	 * A monitor has no session of Nextcloud, so the health check is a public page without the token
	 * of a form; Nextcloud counts and delays its calls with a wrong token and limits all of them.
	 */
	public function testTheHealthCheckIsPublicButThrottledAndLimited(): void {
		$method = new ReflectionMethod(HealthController::class, 'check');

		self::assertCount(1, $method->getAttributes(PublicPage::class));
		self::assertCount(1, $method->getAttributes(NoCSRFRequired::class));
		$route = $method->getAttributes(FrontpageRoute::class)[0]->newInstance();
		self::assertSame(['GET', '/api/v1/health'], [$route->getVerb(), $route->getUrl()]);
		$protection = $method->getAttributes(BruteForceProtection::class)[0]->newInstance();
		self::assertSame(HealthController::THROTTLE_ACTION, $protection->getAction());
		$limit = $method->getAttributes(AnonRateLimit::class)[0]->newInstance();
		self::assertSame([HealthController::RATE_LIMIT, HealthController::RATE_PERIOD], [$limit->getLimit(), $limit->getPeriod()]);
	}

	/** Only administrators see and change the token; a change asks for their password. */
	public function testTheTokenBelongsToAdministrators(): void {
		foreach (['state' => ['GET', false], 'create' => ['POST', true], 'remove' => ['DELETE', true]] as $name => [$verb, $confirmed]) {
			$method = new ReflectionMethod(HealthController::class, $name);
			$route = $method->getAttributes(FrontpageRoute::class)[0]->newInstance();
			self::assertSame([$verb, '/settings/health-token'], [$route->getVerb(), $route->getUrl()]);
			self::assertSame([], $method->getAttributes(PublicPage::class));
			self::assertSame([], $method->getAttributes(NoAdminRequired::class));
			self::assertCount($confirmed ? 1 : 0, $method->getAttributes(PasswordConfirmationRequired::class), $name);
		}
	}

	/** @return array<string, array{string}> the header Authorization of calls without the token */
	public static function callsWithoutTheToken(): array {
		return [
			'no header' => [''],
			'a wrong token' => ['Bearer ' . self::OTHER_SECRET],
			'the token without its scheme' => [self::SECRET],
			'basic authentication' => ['Basic ' . base64_encode('admin:' . self::SECRET)],
		];
	}

	#[DataProvider('callsWithoutTheToken')]
	public function testACallWithoutTheTokenIsRefusedAndThrottled(string $authorization): void {
		$this->healthToken($this->clock)->create();
		$this->authorization = $authorization;
		$this->throttler->expects(self::never())->method('resetDelay');

		$response = $this->controller()->check();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
		self::assertSame(['action' => HealthController::THROTTLE_ACTION], $response->getThrottleMetadata());
		self::assertArrayNotHasKey('health', $response->getData());
	}

	/** Before an administrator creates the token, the health check refuses every call. */
	public function testWithoutATokenEveryCallIsRefused(): void {
		$this->authorization = 'Bearer ' . self::SECRET;

		$response = $this->controller()->check();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	/** The secret of the webhook opens the webhook alone, and the token the health check alone. */
	public function testTheSecretOfTheWebhookIsNoTokenOfTheHealthCheck(): void {
		$secret = $this->webhookService($this->clock)->create();
		$this->authorization = 'Bearer ' . $secret;

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->check()->getStatus());

		$token = $this->healthToken($this->clock)->create();
		self::assertNotSame($secret, $token);
		self::assertFalse($this->webhookService($this->clock)->verify('Bearer ' . $token));
		self::assertTrue($this->webhookService($this->clock)->verify('Bearer ' . $secret));
	}

	/**
	 * With its token, the health check answers with the health alone, and ends the delay of the
	 * address of the monitor, as after an administrator fixed a wrong token in it.
	 */
	public function testAWorkingMirrorIsHealthy(): void {
		$report = new SyncReport(false, $this->clock->time - 60, $this->clock->time);
		$report->exported = 3;
		$this->status->started(false);
		$this->status->completed($report);
		$this->authorization = 'Bearer ' . $this->healthToken($this->clock)->create();
		$this->throttler->expects(self::once())->method('resetDelay')->with('192.0.2.10', HealthController::THROTTLE_ACTION, ['action' => HealthController::THROTTLE_ACTION]);

		$response = $this->controller()->check();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->isThrottled());
		self::assertSame([
			'healthy' => true,
			'health' => 'ok',
			'state' => 'completed',
			'lastSuccess' => TestClock::START,
			'failedRuns' => 0,
			'failingSince' => 0,
		], $response->getData());
	}

	/**
	 * A failing mirror is an alarm, with the number of runs that failed, but without the error of the
	 * run, which may name paths and documents of the archive: those stay with the status API.
	 */
	public function testAFailingMirrorIsAnAlarmWithoutItsError(): void {
		$this->status->failed(new RuntimeException('Documents/Paperless/Archive/Energie GmbH: the file is locked.'));
		$this->clock->advance(900);
		$this->status->failed(new RuntimeException('Documents/Paperless/Archive/Energie GmbH: the file is locked again.'));
		$this->authorization = 'Bearer ' . $this->healthToken($this->clock)->create();

		$response = $this->controller()->check();

		self::assertSame(Http::STATUS_OK, $response->getStatus(), 'A monitor tells a failing mirror from a health check that it cannot reach.');
		self::assertSame([
			'healthy' => false,
			'health' => 'failed',
			'state' => 'failed',
			'lastSuccess' => 0,
			'failedRuns' => 2,
			'failingSince' => TestClock::START,
		], $response->getData());
		self::assertStringNotContainsString('Energie GmbH', json_encode($response->getData(), JSON_THROW_ON_ERROR));
	}

	/** Runs that stopped, as when the background jobs no longer run, are an alarm too. */
	public function testAMirrorWhoseRunsStoppedIsAnAlarm(): void {
		$this->status->started(false);
		$this->status->completed(new SyncReport(false, $this->clock->time - 60, $this->clock->time));
		$this->clock->advance(2 * 86400);
		$this->authorization = 'Bearer ' . $this->healthToken($this->clock)->create();

		$data = $this->controller()->check()->getData();

		self::assertSame([false, 'stale', TestClock::START], [$data['healthy'], $data['health'], $data['lastSuccess']]);
	}

	/** The token appears once, in the answer to its creation; the state of the health check never has it. */
	public function testAdministratorsCreateAndRevokeTheToken(): void {
		$controller = $this->controller();
		self::assertSame(['configured' => false, 'created' => 0, 'url' => self::HEALTH_URL], $controller->state()->getData());

		$created = $controller->create()->getData();

		self::assertSame(['token' => self::SECRET, 'configured' => true, 'created' => TestClock::START, 'url' => self::HEALTH_URL], $created);
		self::assertStringNotContainsString(self::SECRET, json_encode($controller->state()->getData(), JSON_THROW_ON_ERROR));
		$this->authorization = 'Bearer ' . self::SECRET;
		self::assertSame(Http::STATUS_OK, $controller->check()->getStatus());

		self::assertFalse($controller->remove()->getData()['configured']);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->check()->getStatus());
	}

	private function controller(): HealthController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'Authorization' ? $this->authorization : '');
		$request->method('getRemoteAddress')->willReturn('192.0.2.10');

		return new HealthController(AppConstants::APP_ID, $request, $this->healthToken($this->clock), $this->status, $this->configService(), $this->throttler);
	}
}
