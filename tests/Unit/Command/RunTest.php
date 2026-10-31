<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Command;

use OCA\PaperlessSync\Command\Run;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedActivities;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\Config\IUserConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ paperless_sync:run, as an administrator or a script starts it.
 */
final class RunTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	private const PATH = 'Documents/Paperless/Archive/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P%d].pdf';

	private ConfigService $configService;
	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	private Run $run;
	private CommandTester $command;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(['batch_size' => 1] + self::validSettings(), 'secret-token');
		$this->paperless = new TestPaperlessClient();
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->storage = new TestStorage();
		$this->locking = $this->createStub(ILockingProvider::class);
		$clock = new TestClock();
		$status = $this->statusService($clock, $this->locking);
		$sync = new SyncService(
			$this->configService,
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
		$this->run = new Run($sync);
		$this->command = new CommandTester($this->run);
	}

	public function testCommandIsNamedAfterTheApp(): void {
		self::assertSame('paperless_sync:run', $this->run->getName());
		self::assertStringContainsString('Exit codes: 0 the run completed', $this->run->getHelp());
	}

	public function testRunPrintsWhatItChanged(): void {
		$this->documents(1);

		$code = $this->execute([]);

		self::assertSame(Run::OK, $code);
		$output = $this->command->getDisplay();
		self::assertStringStartsWith("Synchronization completed.\n", $output);
		self::assertMatchesRegularExpression('/^  Exported +1$/m', $output);
		self::assertMatchesRegularExpression('/^  Set aside +0$/m', $output);
		self::assertMatchesRegularExpression('/^  Errors +0$/m', $output);
		self::assertStringContainsString("Changes:\n  EXPORT P1: " . sprintf(self::PATH, 1) . "\n", $output);
		self::assertStringNotContainsString('Errors:', $output);
		self::assertSame('completed', $this->settings['status_last_state'], 'The settings page shows the run as any other.');
		self::assertSame('', $this->command->getErrorOutput());
	}

	/** In per-user ownership, the report counts the changes of each user, as the settings page shows them. */
	public function testRunPrintsTheChangesOfEachUser(): void {
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$this->configService->save(['batch_size' => 1, 'ownership' => 'per-user'] + self::validSettings());
		$this->paperless->users = ['1' => 'alice'];
		$this->documents(2);
		$this->paperless->documents[0]['owner'] = 1;
		$this->paperless->documents[1]['owner'] = null;

		self::assertSame(Run::OK, $this->execute([]));

		self::assertStringContainsString("By user:\n  paperless  Exported 1\n  alice  Exported 1\n", $this->command->getDisplay());
		self::assertStringContainsString('  [alice] EXPORT P1: ' . sprintf(self::PATH, 1) . "\n", $this->command->getDisplay());
	}

	/** A script reads the report as the settings page does, from the same JSON. */
	public function testDryRunPrintsItsReportAsJson(): void {
		$this->documents(1);

		$code = $this->execute(['--dry-run' => true, '--output' => 'json']);

		self::assertSame(Run::OK, $code);
		$report = json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($report);
		self::assertTrue($report['dryRun']);
		self::assertSame(1, $report['exported']);
		self::assertSame(['EXPORT P1: ' . sprintf(self::PATH, 1)], $report['actions'], 'Slashes and umlauts stay readable.');
		self::assertSame([], $this->storage->files, 'A dry-run changes nothing.');
		self::assertStringNotContainsString("\n    ", $this->command->getDisplay(), 'One line for a script.');
	}

	public function testDryRunSaysThatItChangedNothing(): void {
		$this->documents(1);

		$this->execute(['--dry-run' => true, '--output' => 'json_pretty']);
		self::assertStringContainsString("\n    \"dryRun\": true,\n", $this->command->getDisplay());

		$this->execute(['--dry-run' => true]);
		self::assertStringStartsWith("Dry-run completed. No files were changed.\n", $this->command->getDisplay());
	}

	public function testRunWithErrorsOfDocumentsExitsWithOne(): void {
		$this->documents(1);
		$this->paperless->downloadException = new RuntimeException('Paperless timed out');

		self::assertSame(Run::ERRORS, $this->execute([]));
		$output = $this->command->getDisplay();
		self::assertStringStartsWith("Synchronization completed with 1 error(s).\n", $output);
		self::assertStringContainsString("Errors:\n  P1: Paperless timed out\n", $output);
	}

	public function testDryRunWithErrorsSaysSo(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->documents(1);
		$this->storage->files[sprintf(self::PATH, 1)] = '%PDF-of the user';

		self::assertSame(Run::ERRORS, $this->execute(['--dry-run' => true]));
		self::assertStringStartsWith("Dry-run completed with 1 error(s). No files were changed.\n", $this->command->getDisplay());
	}

	public function testFailedRunExitsWithTwoAndSaysWhyOnStandardError(): void {
		$this->settings['paperless_url'] = '';

		self::assertSame(Run::FAILED, $this->execute(['--output' => 'json']));
		self::assertSame('', $this->command->getDisplay(), 'A script finds no report to read.');
		self::assertSame("The synchronization failed: Paperless Sync is not completely configured.\n", $this->command->getErrorOutput());
		self::assertSame('failed', $this->settings['status_last_state']);
	}

	public function testRunWhileAnotherIsActiveExitsWithThree(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		self::assertSame(Run::LOCKED, $this->execute([]));
		self::assertStringStartsWith('Another synchronization run is already active.', $this->command->getErrorOutput());
		self::assertArrayNotHasKey('status_last_state', $this->settings, 'The status stays that of the active run.');
	}

	/** The first import of a large archive needs no wait of an interval for every batch. */
	public function testBatchSizeOfTheRunTakesThePlaceOfThatOfTheSettings(): void {
		$this->documents(3);

		self::assertSame(Run::OK, $this->execute(['--dry-run' => true, '--batch-size' => '2', '--output' => 'json']));
		self::assertSame(2, $this->report()['exported']);

		self::assertSame(Run::OK, $this->execute(['--unlimited' => true, '--output' => 'json']));
		self::assertSame(3, $this->report()['exported']);
		self::assertSame('', $this->command->getErrorOutput());
		self::assertSame(1, $this->configService->get()->batchSize);
	}

	/**
	 * Scheduled runs may stay on during a long run without a limit: its signs of life keep them
	 * from starting beside it, also after Nextcloud let its lock expire.
	 */
	public function testRunWithoutALimitNeedsNoPauseOfScheduledRuns(): void {
		$this->configService->save(['enabled' => true]);
		$this->documents(1);

		self::assertSame(Run::OK, $this->execute(['--unlimited' => true]));
		self::assertSame('', $this->command->getErrorOutput());
	}

	/** @return iterable<string, array{array<string, bool|string>, string}> */
	public static function invalidOptions(): iterable {
		yield 'batch size of 0' => [['--batch-size' => '0'], 'The batch size must be a whole number of at least 1.'];
		yield 'batch size that is no number' => [['--batch-size' => 'many'], 'The batch size must be a whole number of at least 1.'];
		yield 'batch size and no limit' => [['--batch-size' => '5', '--unlimited' => true], 'Use either --batch-size or --unlimited.'];
		yield 'unknown format' => [['--output' => 'xml'], 'The output format must be plain, json or json_pretty.'];
	}

	/** @param array<string, bool|string> $options */
	#[DataProvider('invalidOptions')]
	public function testInvalidOptionsStartNoRun(array $options, string $message): void {
		self::assertSame(Run::FAILED, $this->execute($options));
		self::assertSame($message . "\n", $this->command->getErrorOutput());
		self::assertArrayNotHasKey('status_last_state', $this->settings);
	}

	/** @param array<string, bool|string> $input */
	private function execute(array $input): int {
		return $this->command->execute($input, ['capture_stderr_separately' => true]);
	}

	/** @return array<string, mixed> */
	private function report(): array {
		$report = json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($report);

		return $report;
	}

	private function documents(int $count): void {
		foreach (range(1, $count) as $id) {
			$this->paperless->documents[] = [
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
			$this->paperless->contents[$id] = "%PDF-{$id}";
		}
	}
}
