<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Command;

use OCA\PaperlessSync\Command\Status;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ paperless_sync:status, as an administrator or a monitoring script asks for it.
 */
final class StatusTest extends TestCase {
	use InMemoryConfiguration;

	private TestClock $clock;
	private ConfigService $configService;
	private StatusService $statusService;
	private Status $status;
	private CommandTester $command;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(['sync_interval_minutes' => 15] + self::validSettings(), 'secret-token');
		$this->clock = new TestClock();
		$this->statusService = $this->statusService($this->clock);
		$this->status = new Status($this->statusService, $this->configService, $this->clock);
		$this->command = new CommandTester($this->status);
	}

	public function testCommandIsNamedAfterTheApp(): void {
		self::assertSame('paperless_sync:status', $this->status->getName());
	}

	public function testNothingRanYet(): void {
		self::assertSame(Status::OK, $this->execute([]));

		self::assertSame(
			"State:               Never run\n"
			. "Last started:        —\n"
			. "Last finished:       —\n"
			. "Last successful run: —\n"
			. "Next scheduled run:  off\n",
			$this->command->getDisplay(),
		);
	}

	public function testStatusOfARunWithItsReport(): void {
		$this->configService->save(['enabled' => true]);
		$report = new SyncReport(false, $this->clock->time - 60, $this->clock->time);
		$report->exported = 1;
		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/<P1>.pdf');
		$report->error('P2: Paperless timed out');
		$this->statusService->started(false);
		$this->statusService->completed($report);

		self::assertSame(Status::OK, $this->execute([]));

		$output = $this->command->getDisplay();
		self::assertStringStartsWith("State:               Completed with errors\n", $output);
		self::assertStringContainsString('Last finished:       ' . date(DATE_ATOM, $report->completedAt) . "\n", $output);
		self::assertStringContainsString('Last successful run: —' . "\n", $output, 'A run with errors is no success.');
		self::assertStringContainsString('Next scheduled run:  ' . date(DATE_ATOM, $report->completedAt + 15 * 60) . "\n", $output);
		self::assertStringContainsString("Report of the last run:\n", $output);
		self::assertMatchesRegularExpression('/^  Exported +1$/m', $output);
		self::assertStringContainsString("Changes:\n  EXPORT P1: Archiv/<P1>.pdf\n  ERROR: P2: Paperless timed out\n", $output, 'A name is printed as it is.');
		self::assertStringContainsString("Errors:\n  P2: Paperless timed out\n", $output);
	}

	/** The kept report counts the lines and the errors that it leaves out. */
	public function testReportSaysWhatItLeavesOut(): void {
		$this->settings['status_last_state'] = 'dry-run-completed';
		$this->settings['status_last_report'] = json_encode([
			'dryRun' => true,
			'errors' => 52,
			'omittedActions' => 7,
			'actions' => ['EXPORT P1: Archiv/P1.pdf', 7],
			'errorMessages' => ['P2: Paperless timed out', 'P3: Paperless timed out'],
		], JSON_THROW_ON_ERROR);

		$this->execute([]);

		$output = $this->command->getDisplay();
		self::assertStringContainsString("Report of the last dry-run:\n", $output);
		self::assertMatchesRegularExpression('/^  Exported +0$/m', $output);
		self::assertStringContainsString("Changes:\n  EXPORT P1: Archiv/P1.pdf\n  … and 7 more, which the list leaves out\n", $output);
		self::assertStringContainsString("  P3: Paperless timed out\n  … and 50 more\n", $output);
	}

	public function testFailedRunShowsItsError(): void {
		$this->statusService->failed(new RuntimeException('Paperless is not reachable.'));

		self::assertSame(Status::OK, $this->execute([]), 'Without --exit-code, the command did what it was asked.');

		self::assertStringContainsString("State:               Failed\n", $this->command->getDisplay());
		self::assertStringContainsString("Error:               Paperless is not reachable.\n", $this->command->getDisplay());
	}

	public function testNextRunThatIsDueStartsWithTheNextBackgroundJob(): void {
		$this->configService->save(['enabled' => true]);
		$this->settings['status_last_started'] = $this->clock->time - 3600;

		$this->execute([]);

		self::assertStringContainsString("Next scheduled run:  with the next background job\n", $this->command->getDisplay());
	}

	/** A script and a monitoring check read the same status as the settings page. */
	public function testStatusAsJson(): void {
		$this->statusService->completed(new SyncReport(true, 1000, 1060));

		self::assertSame(Status::OK, $this->execute(['--output' => 'json']));

		$status = json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($this->statusService->overview($this->configService->get()), $status);
	}

	/** @return iterable<string, array{string, int}> */
	public static function exitCodes(): iterable {
		yield 'never run' => ['never-run', Status::OK];
		yield 'queued' => ['queued', Status::OK];
		yield 'running' => ['running', Status::OK];
		yield 'completed' => ['completed', Status::OK];
		yield 'completed with errors' => ['completed-with-errors', Status::ERRORS];
		yield 'failed' => ['failed', Status::FAILED];
	}

	/** Like occ status --exit-code of Nextcloud, for a check of a monitoring. */
	#[DataProvider('exitCodes')]
	public function testExitCodeTellsHowTheLastRunEnded(string $state, int $code): void {
		$this->settings['status_last_state'] = $state;
		$this->settings['status_last_started'] = $this->clock->time;

		self::assertSame($code, $this->execute(['--exit-code' => true, '--output' => 'json']));
	}

	public function testInterruptedRunExitsWithTwo(): void {
		$this->settings['status_last_state'] = 'running';
		$this->settings['status_last_started'] = $this->clock->time - 7200;

		self::assertSame(Status::FAILED, $this->execute(['--exit-code' => true]));
		self::assertStringStartsWith("State:               Interrupted\n", $this->command->getDisplay());
	}

	public function testUnknownFormatIsRefused(): void {
		self::assertSame(Status::FAILED, $this->execute(['--output' => 'yaml']));
		self::assertSame("The output format must be plain, json or json_pretty.\n", $this->command->getErrorOutput());
		self::assertSame('', $this->command->getDisplay());
	}

	/** @param array<string, bool|string> $input */
	private function execute(array $input): int {
		return $this->command->execute($input, ['capture_stderr_separately' => true]);
	}
}
