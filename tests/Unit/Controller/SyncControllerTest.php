<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Controller\SyncController;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncControllerTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;
	use RecordedActivities;

	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	private TestPaperlessClient $paperless;
	private SyncController $controller;

	protected function setUp(): void {
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'secret-token');
		$this->locking = $this->createMock(ILockingProvider::class);
		$status = new StatusService($this->appConfig());
		$this->paperless = new TestPaperlessClient();
		$sync = new SyncService(
			$configService,
			$this->paperless,
			new TestStorage(),
			new TestStateRepository(),
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
			$this->activityPublisher(new TestStorage(), $this->createMock(LoggerInterface::class)),
		);
		$this->controller = new SyncController(AppConstants::APP_ID, $this->createMock(IRequest::class), $sync, $status, $this->germanL10n(), $configService);
	}

	public function testRunReturnsTheReport(): void {
		$response = $this->controller->run();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$report = $response->getData();
		self::assertInstanceOf(SyncReport::class, $report);
		self::assertTrue($report->dryRun, 'A run from the settings page is a dry run unless asked otherwise.');

		$report = $this->controller->run(false)->getData();
		self::assertInstanceOf(SyncReport::class, $report);
		self::assertFalse($report->dryRun);
	}

	public function testRunWhileAnotherRunIsActiveIsAConflict(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Another synchronization run is already active.'], $response->getData());
	}

	public function testFailedRunReturnsItsError(): void {
		$this->settings = [];

		$response = $this->controller->run(false);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Paperless Sync is not completely configured.'], $response->getData());
	}

	/** A run that Nextcloud doesn't let reach Paperless in the local network says what to do. */
	public function testRunRefusedInTheLocalNetworkSaysWhatToDo(): void {
		$this->paperless->connectionException = new PaperlessLocalNetworkException('paperless');

		$response = $this->controller->run(false);

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
		self::assertSame(['EXPORT P5: Dokumente/Paperless/Archiv/2026/Strom [P5].pdf'], $report->actions);
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
			'message' => 'Ein Testlauf ungespeicherter Einstellungen verwendet die gespeicherte Verbindung zu Paperless und den gespeicherten Zielbenutzer. Speichere die Einstellungen, um eine andere Verbindung oder einen anderen Zielbenutzer auszuprobieren.',
			'setting' => $setting,
		], $response->getData());
		self::assertSame([], $this->paperless->connections);
	}

	public function testADryRunOfInvalidSettingsSaysWhyAsASaveWould(): void {
		$response = $this->controller->run(true, ['sync_interval_minutes' => 3]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Synchronization interval must be between 5 and 1440.'], $response->getData());
	}

	public function testStatusOfTheLastRun(): void {
		$this->controller->run(false);

		$status = $this->controller->status()->getData();

		self::assertIsArray($status);
		self::assertSame('completed', $status['state']);
		self::assertSame(0, $status['summary']['errors']);
		self::assertSame([], $status['report']['errorMessages']);
		self::assertNull($status['nextRun'], 'Scheduled runs are off until the settings turn them on.');
	}
}
