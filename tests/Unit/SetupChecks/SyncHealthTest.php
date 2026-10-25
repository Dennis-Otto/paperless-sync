<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\SetupChecks;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\IDateTimeFormatter;
use OCP\IURLGenerator;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncHealthTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private const GUIDE = AppConstants::GERMAN_GUIDE_URL . '#being-notified';
	/** The settings of the app, as Nextcloud links to a section of its administration settings. */
	private const SETTINGS = 'https://cloud.example.test/index.php/settings/admin/paperless_sync';

	private TestClock $clock;
	private StatusService $status;
	private SyncHealth $check;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->status = $this->statusService($this->clock);
		$formatter = $this->createStub(IDateTimeFormatter::class);
		$formatter->method('formatTimeSpan')->willReturnCallback(fn (int $timestamp): string => 'vor ' . intdiv($this->clock->time - $timestamp, 60) . ' Minuten');
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $parameters = []): string => $route === 'settings.AdminSettings.index' && $parameters === ['section' => AppConstants::APP_ID] ? self::SETTINGS : 'https://cloud.example.test/elsewhere');
		$this->check = new SyncHealth($this->status, $this->configService(), $this->germanL10n(), $formatter, $urlGenerator);
		$this->configService()->save(['enabled' => true, 'sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
	}

	public function testTheCheckBelongsToTheSystem(): void {
		self::assertSame('system', $this->check->getCategory());
		self::assertSame('Paperless Sync', $this->check->getName());
	}

	/**
	 * An installation without the URL of Paperless, its token or the target user, which no run can
	 * use, says so and links to its settings, which lead through the first steps: a new one, and
	 * one whose token Disconnect deleted, also after runs that failed.
	 */
	public function testAnInstallationThatIsNotSetUpLeadsToItsSettings(): void {
		$this->settings = [];
		self::assertResult(SetupResult::INFO, 'Paperless Sync ist installiert, aber noch nicht eingerichtet. Seine Einstellungen führen durch die ersten Schritte.', self::SETTINGS, $this->check->run());

		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
		$this->status->failed(new RuntimeException('Paperless is down.'));
		$this->configService()->reset();
		self::assertResult(SetupResult::INFO, 'Paperless Sync ist installiert, aber noch nicht eingerichtet. Seine Einstellungen führen durch die ersten Schritte.', self::SETTINGS, $this->check->run());
	}

	public function testScheduledRunsThatAreOffAreFine(): void {
		$this->configService()->save(['enabled' => false] + self::validSettings(), 'secret-token');

		self::assertResult(SetupResult::SUCCESS, 'Die geplanten Läufe von Paperless Sync sind ausgeschaltet.', null, $this->check->run());
	}

	public function testBeforeTheFirstSuccessTheCheckWaitsForOne(): void {
		self::assertResult(SetupResult::INFO, 'Paperless Sync hat noch keinen Lauf abgeschlossen; der nächste Hintergrundjob startet einen.', null, $this->check->run());
	}

	public function testASuccessInTimeIsFine(): void {
		$this->status->completed(new SyncReport(false, $this->clock->time - 660, $this->clock->time - 600));

		self::assertResult(SetupResult::SUCCESS, 'Der letzte Lauf von Paperless Sync war vor 10 Minuten erfolgreich.', null, $this->check->run());
	}

	public function testAMirrorWithoutASuccessForLongWarns(): void {
		$this->status->completed(new SyncReport(false, $this->clock->time - 60, $this->clock->time));
		$this->clock->advance(StatusService::STALE_MINIMUM + 60);

		self::assertResult(SetupResult::WARNING, 'Der letzte erfolgreiche Lauf von Paperless Sync war vor 61 Minuten. Prüfe, ob die Hintergrundjobs von Nextcloud laufen.', self::GUIDE, $this->check->run());
	}

	public function testRunsWithErrorsWarnWithTheErrorOfTheLast(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->error('P123: ' . str_repeat('x', 300));
		$this->status->completed($report);

		$result = $this->check->run();
		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertSame('Der letzte Lauf von Paperless Sync endete mit Fehlern: P123: ' . str_repeat('x', 194), $result->getDescription(), 'The check names the first 200 characters of the error.');
		self::assertSame(self::GUIDE, $result->getLinkToDoc());

		$this->status->completed($report);
		self::assertStringStartsWith('Die letzten 2 Läufe von Paperless Sync endeten mit Fehlern, der letzte mit: P123: ', (string)$this->check->run()->getDescription());
	}

	public function testARunThatFailedIsAnError(): void {
		$this->status->failed(new RuntimeException('Paperless is down.'));

		self::assertResult(SetupResult::ERROR, 'Der letzte Lauf von Paperless Sync ist fehlgeschlagen: Paperless is down.', self::GUIDE, $this->check->run());

		$this->status->failed(new RuntimeException('Paperless answered 401.'));
		self::assertResult(SetupResult::ERROR, 'Die letzten 2 Läufe von Paperless Sync sind fehlgeschlagen, der letzte mit: Paperless answered 401.', self::GUIDE, $this->check->run());
	}

	/** The guide that the results link to has the part that they name. */
	public function testTheGuideExplainsTheChecks(): void {
		$guide = (string)file_get_contents(__DIR__ . '/../../../docs/guide.md');

		self::assertMatchesRegularExpression('/^### Being notified$/m', $guide);
	}

	private static function assertResult(string $severity, string $description, ?string $link, SetupResult $result): void {
		self::assertSame([$severity, $description, $link], [$result->getSeverity(), $result->getDescription(), $result->getLinkToDoc()]);
	}
}
