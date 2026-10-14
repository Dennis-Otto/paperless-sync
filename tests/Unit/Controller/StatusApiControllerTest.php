<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\StatusApiController;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class StatusApiControllerTest extends TestCase {
	use InMemoryConfiguration;

	private TestClock $clock;
	private StatusService $status;
	private StatusApiController $controller;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->status = new StatusService($this->appConfig(), $this->clock);
		$this->configService()->save(['enabled' => true, 'sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
		$this->controller = new StatusApiController(AppConstants::APP_ID, $this->createMock(IRequest::class), $this->status, $this->configService());
	}

	/** A monitor reads the route of the OCS API, which only administrators may read. */
	public function testTheRouteIsOneOfTheOcsApiForAdministrators(): void {
		$method = new ReflectionMethod(StatusApiController::class, 'status');
		$routes = $method->getAttributes(ApiRoute::class);

		self::assertCount(1, $routes);
		$route = $routes[0]->newInstance();
		self::assertSame(['GET', '/api/v1/status'], [$route->getVerb(), $route->getUrl()]);
		self::assertSame([], $method->getAttributes(NoAdminRequired::class), 'The error of a run may name paths and documents of the target user.');
		self::assertSame([], $method->getAttributes(PublicPage::class));
	}

	public function testAWorkingMirrorIsHealthy(): void {
		$report = new SyncReport(false, $this->clock->time - 60, $this->clock->time);
		$report->exported = 3;
		$this->status->started(false);
		$this->status->connected(['version' => '2.20.15', 'apiVersion' => '9']);
		$this->status->completed($report);

		$response = $this->controller->status();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame([true, 'ok', true, 'completed'], [$data['healthy'], $data['health'], $data['enabled'], $data['state']]);
		self::assertSame([TestClock::START, TestClock::START, TestClock::START], [$data['lastStarted'], $data['lastEnded'], $data['lastSuccess']]);
		self::assertSame(TestClock::START + 15 * 60, $data['nextRun']);
		self::assertSame(['', 0, 0], [$data['error'], $data['failedRuns'], $data['failingSince']]);
		self::assertSame(3, $data['summary']['exported']);
		self::assertSame(['2.20.15', '9'], [$data['paperlessVersion'], $data['paperlessApiVersion']]);
	}

	public function testAFailingMirrorIsNotHealthy(): void {
		$this->status->failed(new RuntimeException('Paperless answered 401.'));
		$this->clock->advance(900);
		$this->status->failed(new RuntimeException('Paperless answered 401 again.'));
		// A dry-run that failed afterwards changes neither the row nor its error.
		$this->status->failed(new RuntimeException('The dry-run failed.'), new SyncReport(true, $this->clock->time));

		$data = $this->controller->status()->getData();

		self::assertSame([false, 'failed'], [$data['healthy'], $data['health']]);
		self::assertSame(['Paperless answered 401 again.', 2, TestClock::START], [$data['error'], $data['failedRuns'], $data['failingSince']]);
	}

	public function testScheduledRunsThatAreOffAreNoAlarm(): void {
		$this->configService()->save(['enabled' => false] + self::validSettings(), 'secret-token');

		$data = $this->controller->status()->getData();

		self::assertSame([true, 'off', false, null], [$data['healthy'], $data['health'], $data['enabled'], $data['nextRun']]);

		$this->status->failed(new RuntimeException('Paperless answered 401.'));
		self::assertSame([false, 'failed'], [$this->controller->status()->getData()['healthy'], $this->controller->status()->getData()['health']], 'A run by hand that failed is an alarm while scheduled runs are off, too.');
	}
}
