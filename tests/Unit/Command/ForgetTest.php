<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Command;

use OCA\PaperlessSync\Command\Forget;
use OCA\PaperlessSync\Model\DocumentFingerprint;
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
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ paperless_sync:forget, as an administrator uses it after a copy or the storage was damaged.
 */
final class ForgetTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedActivities;
	use RecordedNotifications;

	private const FINGERPRINT = 'sha256 md5';

	private TestStateRepository $state;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	private Forget $forget;
	private CommandTester $command;

	protected function setUp(): void {
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'secret-token');
		$this->state = new TestStateRepository();
		foreach ([123, 124, 125] as $documentId) {
			$this->state->saveExport('paperless', $documentId, ['path' => "Archiv/P{$documentId}.pdf", 'fingerprint' => self::FINGERPRINT]);
		}
		$this->locking = $this->createMock(ILockingProvider::class);
		$clock = new TestClock();
		$status = $this->statusService($clock, $this->locking);
		$sync = new SyncService(
			$configService,
			new TestPaperlessClient(),
			new TestStorage(),
			$this->state,
			new PathTemplateService(),
			$status,
			$this->locking,
			$this->createMock(LoggerInterface::class),
			$clock,
			$this->activityPublisher(new TestStorage(), $this->createMock(LoggerInterface::class)),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->notificationPublisher($status, new TestStorage(), $clock, $this->createMock(LoggerInterface::class)),
			$this->systemTagService(new TestStorage()),
		);
		$this->forget = new Forget($sync);
		$this->command = new CommandTester($this->forget);
	}

	public function testCommandIsNamedAfterTheApp(): void {
		self::assertSame('paperless_sync:forget', $this->forget->getName());
		self::assertStringContainsString('in place', $this->forget->getHelp());
	}

	public function testForgetsTheDocumentsOfTheIds(): void {
		self::assertSame(Forget::OK, $this->execute(['documents' => ['123', 'P124', 'p124']]));

		self::assertSame("The next runs export 2 document(s) again: P123, P124.\n", $this->command->getDisplay());
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[123]['fingerprint']);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[124]['fingerprint']);
		self::assertSame(self::FINGERPRINT, $this->state->exports[125]['fingerprint']);
	}

	public function testDocumentWithoutACopyExitsWithOne(): void {
		self::assertSame(Forget::ERRORS, $this->execute(['documents' => ['123', '999']]));

		self::assertSame("The next runs export 1 document(s) again: P123.\n", $this->command->getDisplay());
		self::assertSame("Paperless Sync has no copy of P999.\n", $this->command->getErrorOutput());
	}

	public function testForgetsEveryCopy(): void {
		self::assertSame(Forget::OK, $this->execute(['--all' => true]));

		self::assertSame("The next runs export 3 document(s) again.\n", $this->command->getDisplay());
		foreach ($this->state->exports as $entry) {
			self::assertSame(DocumentFingerprint::PENDING, $entry['fingerprint']);
		}
	}

	public function testNothingToForget(): void {
		$this->state->exports = [];

		self::assertSame(Forget::OK, $this->execute(['--all' => true]));
		self::assertSame("No copy to forget.\n", $this->command->getDisplay());
	}

	/** @return iterable<string, array{array<string, bool|list<string>>, string}> */
	public static function invalidInput(): iterable {
		yield 'nothing' => [[], 'Name the IDs of the documents, or use --all.'];
		yield 'IDs and --all' => [['documents' => ['123'], '--all' => true], 'Name the IDs of the documents, or use --all.'];
		yield 'no ID' => [['documents' => ['123', 'invoice']], 'invoice is no ID of a document, such as 123 or P123.'];
		yield 'ID 0' => [['documents' => ['P0']], 'P0 is no ID of a document, such as 123 or P123.'];
	}

	/** @param array<string, bool|list<string>> $input */
	#[DataProvider('invalidInput')]
	public function testInvalidInputForgetsNothing(array $input, string $message): void {
		self::assertSame(Forget::FAILED, $this->execute($input));

		self::assertSame($message . "\n", $this->command->getErrorOutput());
		self::assertSame(self::FINGERPRINT, $this->state->exports[123]['fingerprint']);
	}

	public function testRunThatIsActiveExitsWithThree(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('paperless_sync::synchronization'));

		self::assertSame(Forget::LOCKED, $this->execute(['--all' => true]));
		self::assertStringStartsWith('A synchronization run is active.', $this->command->getErrorOutput());
	}

	public function testFailureExitsWithTwo(): void {
		$this->settings['target_user'] = '';

		self::assertSame(Forget::FAILED, $this->execute(['--all' => true]));
		self::assertSame("Forgetting failed: Paperless Sync is not completely configured.\n", $this->command->getErrorOutput());
	}

	/** @param array<string, bool|list<string>> $input */
	private function execute(array $input): int {
		return $this->command->execute($input, ['capture_stderr_separately' => true]);
	}
}
