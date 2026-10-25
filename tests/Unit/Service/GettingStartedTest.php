<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\GettingStarted;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GettingStartedTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private const UNKNOWN = 'Ein Testlauf zählt die Dokumente in Paperless; dann sagt dieser Schritt, wie lange die erste Synchronisation dauert.';

	private TestClock $clock;
	private StatusService $status;
	private GettingStarted $gettingStarted;

	protected function setUp(): void {
		$this->clock = new TestClock();
		$this->status = $this->statusService($this->clock);
		$this->gettingStarted = new GettingStarted($this->status, $this->germanL10n());
	}

	/**
	 * A new installation walks through the steps in their order: each step that it completes is
	 * done, and the first one that is not is next. The first successful run completes the list.
	 */
	public function testANewInstallationWalksThroughEveryStep(): void {
		self::assertSame(['next', 'todo', 'todo', 'todo', 'todo'], $this->states(), 'A new installation starts with the connection.');
		self::assertFalse($this->progress()['complete']);
		self::assertSame(self::UNKNOWN, $this->progress()['estimate'], 'Before a dry-run, the documents are unknown.');

		$this->configService()->save(['enabled' => false] + self::validSettings(), 'secret-token');
		self::assertSame(['done', 'done', 'next', 'todo', 'todo'], $this->states(), 'Saving the token and the target user completes the first two steps at once.');

		$this->status->completed($this->dryRun(5000));
		self::assertSame(['done', 'done', 'done', 'next', 'todo'], $this->states());

		$this->configService()->save(['enabled' => true] + self::validSettings());
		self::assertSame(['done', 'done', 'done', 'done', 'next'], $this->states());
		self::assertFalse($this->progress()['complete']);

		$this->status->completed(new SyncReport(false, $this->clock->time, $this->clock->time + 60));
		self::assertSame(['done', 'done', 'done', 'done', 'done'], $this->states());
		self::assertTrue($this->progress()['complete'], 'The first successful run completes the list.');
	}

	/** The steps come in their order, each with its ID, which the page finds its line by. */
	public function testTheStepsComeInTheirSafeOrder(): void {
		self::assertSame(
			[GettingStarted::CONNECT, GettingStarted::OWNER, GettingStarted::DRY_RUN, GettingStarted::SCHEDULE, GettingStarted::FIRST_RUN],
			array_column($this->progress()['steps'], 'id'),
		);
		self::assertSame(['connect', 'owner', 'dry-run', 'schedule', 'first-run'], array_column($this->progress()['steps'], 'id'), 'templates/settings.php and js/settings.js know the steps by these IDs.');
	}

	/**
	 * A step counts on its own: after Disconnect, which deletes the token and turns the schedule off,
	 * the connection is next again, while the target user and the dry-run stay done.
	 */
	public function testAStepThatIsDoneStaysDoneWhenAnEarlierOneIsNot(): void {
		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
		$this->status->completed($this->dryRun(40));

		$this->configService()->reset();

		self::assertSame(['next', 'done', 'done', 'todo', 'todo'], $this->states());
	}

	/** Runs that failed or completed with errors are no success, so the last step stays next. */
	public function testOnlyASuccessfulRunCompletesTheList(): void {
		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
		$this->status->completed($this->dryRun(40));
		$this->status->failed(new RuntimeException('Paperless is down.'), new SyncReport(false, $this->clock->time));
		$withErrors = new SyncReport(false, $this->clock->time, $this->clock->time + 60);
		$withErrors->error('P123: the download failed');
		$this->status->completed($withErrors);

		self::assertSame(['done', 'done', 'done', 'done', 'next'], $this->states());
		self::assertFalse($this->progress()['complete']);
	}

	/** A dry-run with errors completed as well: its report says what to fix. One that failed did not. */
	public function testADryRunWithErrorsCountsAndOneThatFailedDoesNot(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->status->failed(new RuntimeException('Paperless is down.'), new SyncReport(true, $this->clock->time));
		self::assertSame('next', $this->states()[2]);

		$dryRun = $this->dryRun(40);
		$dryRun->error('P123: the download failed');
		$this->status->completed($dryRun);
		self::assertSame('done', $this->states()[2]);
	}

	/**
	 * The last step estimates the first synchronization from the documents of the last dry-run, as
	 * docs/faq.md does: the documents divided by the batch size are the runs, one each interval.
	 *
	 * @param array<string, int> $settings
	 */
	#[DataProvider('estimates')]
	public function testTheLastStepEstimatesTheFirstSynchronization(int $documents, array $settings, string $estimate): void {
		$this->configService()->save($settings + self::validSettings(), 'secret-token');
		$this->status->completed($this->dryRun($documents));

		self::assertSame($estimate, $this->progress()['estimate']);
	}

	/** @return iterable<string, array{int, array<string, int>, string}> */
	public static function estimates(): iterable {
		yield 'one document' => [1, [], 'Paperless hat 1 Dokument, das der erste Lauf spiegelt.'];
		yield 'one batch' => [100, ['batch_size' => 100], 'Paperless hat 100 Dokumente, die der erste Lauf auf einmal spiegelt.'];
		yield 'minutes' => [250, ['batch_size' => 100, 'sync_interval_minutes' => 5], 'Paperless hat 250 Dokumente: Mit 100 je Lauf und einem Lauf alle 5 Minuten dauert die erste Synchronisation etwa 3 Läufe, 15 Minuten.'];
		yield 'the example of the FAQ' => [5000, ['batch_size' => 100, 'sync_interval_minutes' => 5], 'Paperless hat 5000 Dokumente: Mit 100 je Lauf und einem Lauf alle 5 Minuten dauert die erste Synchronisation etwa 50 Läufe, 4 Stunden.'];
		yield 'two hours from 120 minutes' => [2400, ['batch_size' => 100, 'sync_interval_minutes' => 5], 'Paperless hat 2400 Dokumente: Mit 100 je Lauf und einem Lauf alle 5 Minuten dauert die erste Synchronisation etwa 24 Läufe, 2 Stunden.'];
		yield 'days from 48 hours' => [30000, ['batch_size' => 100, 'sync_interval_minutes' => 10], 'Paperless hat 30000 Dokumente: Mit 100 je Lauf und einem Lauf alle 10 Minuten dauert die erste Synchronisation etwa 300 Läufe, 2 Tage.'];
		yield 'a last batch that is not full' => [2001, ['batch_size' => 1000, 'sync_interval_minutes' => 60], 'Paperless hat 2001 Dokumente: Mit 1000 je Lauf und einem Lauf alle 60 Minuten dauert die erste Synchronisation etwa 3 Läufe, 3 Stunden.'];
	}

	/** A dry-run that found no document, such as of an empty Paperless, gives no estimate. */
	public function testADryRunWithoutDocumentsGivesNoEstimate(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->status->completed($this->dryRun(0));

		self::assertSame('done', $this->states()[2]);
		self::assertSame(self::UNKNOWN, $this->progress()['estimate']);
	}

	/**
	 * A dry-run of an earlier version kept no mark of its own: while the last run is a dry-run that
	 * completed, it counts, with the documents of its summary, which an unreadable one lacks.
	 */
	public function testADryRunOfAnEarlierVersionCounts(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->settings['status_last_state'] = 'dry-run-completed';
		$this->settings['status_last_completed'] = TestClock::START;
		$this->settings['status_last_summary'] = '{"dryRun":true,"activeDocuments":300}';

		self::assertSame('done', $this->states()[2]);
		self::assertSame('Paperless hat 300 Dokumente: Mit 100 je Lauf und einem Lauf alle 5 Minuten dauert die erste Synchronisation etwa 3 Läufe, 15 Minuten.', $this->progress()['estimate']);

		$this->settings['status_last_summary'] = '{"dryRun":true,"activeDocuments":"300"}';
		self::assertSame('done', $this->states()[2]);
		self::assertSame(self::UNKNOWN, $this->progress()['estimate']);

		$this->settings['status_last_state'] = 'completed';
		self::assertSame('next', $this->states()[2], 'Another kind of run tells nothing about a dry-run of an earlier version.');
	}

	private function dryRun(int $documents): SyncReport {
		$report = new SyncReport(true, $this->clock->time, $this->clock->time + 30);
		$report->activeDocuments = $documents;

		return $report;
	}

	/** @return array{complete: bool, steps: list<array{id: string, state: 'done'|'next'|'todo'}>, estimate: string} */
	private function progress(): array {
		$config = $this->configService()->get();

		return $this->gettingStarted->progress($config, $this->status->overview($config));
	}

	/** @return list<string> */
	private function states(): array {
		return array_column($this->progress()['steps'], 'state');
	}
}
