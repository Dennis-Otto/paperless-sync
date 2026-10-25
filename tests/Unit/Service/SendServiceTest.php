<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessRequestException;
use OCA\PaperlessSync\Service\PaperlessUploadException;
use OCA\PaperlessSync\Service\SendService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\RecordedNotifications;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestNotification;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class SendServiceTest extends TestCase {
	use InMemoryConfiguration;
	use RecordedNotifications;

	private const SENDER = 'alice';

	private ConfigService $configService;
	private TestClock $clock;
	private TestStorage $storage;
	private TestStateRepository $state;
	private TestPaperlessClient $paperless;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SendService $service;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(['send_group' => 'scanners'] + self::validSettings(), 'secret-token');
		$this->clock = new TestClock();
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->paperless = new TestPaperlessClient();
		$this->logger = $this->createMock(LoggerInterface::class);
		$publisher = $this->notificationPublisher($this->statusService($this->clock), $this->storage, $this->clock, $this->createStub(LoggerInterface::class));
		$this->service = new SendService($this->configService, $this->paperless, $this->state, $this->storage, $publisher, $this->clock, $this->logger, $this->ownerMapping());
	}

	public function testNothingSentNeedsNoPaperless(): void {
		$this->service->process();

		self::assertSame([], $this->paperless->connects);
	}

	/** Without the token, the files wait until the app is connected again. */
	public function testTheFilesWaitWhileTheAppIsNotConnected(): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->configService->reset();

		$this->service->process();

		self::assertSame([], $this->paperless->connects);
		self::assertSame(SendService::QUEUED, $this->send($id)['status']);
	}

	public function testAFileGoesToPaperlessWithItsNameAndStaysWhereItIs(): void {
		$id = $this->queue('Scans/receipt.pdf');

		$this->service->process();

		self::assertSame(['receipt.pdf' => 'Synthetic content of Scans/receipt.pdf'], $this->paperless->uploads);
		self::assertSame(['task_id' => 'task-1', 'status' => SendService::PENDING, 'submitted_at' => TestClock::START], array_intersect_key($this->send($id), ['status' => 0, 'task_id' => 0, 'submitted_at' => 0]));
		self::assertArrayHasKey('Scans/receipt.pdf', $this->storage->filesOfOthers[self::SENDER]);
		self::assertSame([], $this->notifications);
		self::assertCount(2, $this->storage->openedStreams, 'One read for the checksums, one for the upload.');
		foreach ($this->storage->openedStreams as $stream) {
			self::assertFalse(is_resource($stream), 'Every stream is closed.');
		}
	}

	/** A sent file follows the tags of the inbox: those for every import, and those of its subfolders below the sent folder. */
	public function testAFileGetsTheTagsForEveryImportAndOfItsSubfolders(): void {
		$this->configService->save(['inbox_tags' => 'Scanned', 'inbox_folder_tags' => true, 'send_group' => 'scanners'] + self::validSettings());
		$this->paperless->tagInfo = ['names' => ['3' => 'Scanned', '7' => '2026'], 'inbox' => []];
		$this->queue('Taxes/2026/receipt.pdf', '2026');

		$this->service->process();

		self::assertSame([3, 7], $this->paperless->uploadTags['receipt.pdf']);
	}

	/**
	 * A file of a rule of Flow gets the tags of the rule besides those for every import, each once:
	 * with "Create missing tags", Paperless gets a tag that it lacks, and else the file goes without it.
	 */
	public function testAFileOfARuleGetsTheTagsOfTheRule(): void {
		$this->configService->save(['inbox_tags' => 'Scanned', 'inbox_create_tags' => true, 'send_group' => 'scanners'] + self::validSettings());
		$this->paperless->tagInfo = ['names' => ['3' => 'Scanned', '5' => 'Family'], 'inbox' => []];
		$this->queueByRule('Scans/receipt.pdf', 'family,Scanned,Taxes');
		$this->queueByRule('Scans/letter.pdf', '');

		$this->service->process();

		self::assertSame([3, 5, 100], $this->paperless->uploadTags['receipt.pdf']);
		self::assertSame([3], $this->paperless->uploadTags['letter.pdf']);
		self::assertSame(['Taxes'], $this->paperless->createdTags);
	}

	public function testATagOfARuleThatPaperlessLacksIsLeftOutWithoutCreatingTags(): void {
		$this->paperless->tagInfo = ['names' => ['5' => 'Family'], 'inbox' => []];
		$this->queueByRule('Scans/receipt.pdf', 'Family,Taxes');

		$this->service->process();

		self::assertSame([5], $this->paperless->uploadTags['receipt.pdf']);
		self::assertSame([], $this->paperless->createdTags);
	}

	/** A file moved after the click still goes, from its new place. */
	public function testAMovedFileGoesFromItsNewPlace(): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->storage->move(self::SENDER, 'Scans/receipt.pdf', 'Archive/receipt.pdf', 'replace');

		$this->service->process();

		self::assertSame('Archive/receipt.pdf', $this->send($id)['path']);
		self::assertSame(['receipt.pdf'], array_keys($this->paperless->uploads));
	}

	/** A moved file whose upload fails waits for its next attempt at its new place. */
	public function testAMovedFileWhoseUploadFailsWaitsAtItsNewPlace(): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->storage->move(self::SENDER, 'Scans/receipt.pdf', 'Archive/receipt.pdf', 'replace');
		$this->paperless->uploadFailures['receipt.pdf'] = new PaperlessUploadException('Paperless answered HTTP 503.', false, 503);

		$this->service->process();

		self::assertSame(['path' => 'Archive/receipt.pdf', 'status' => SendService::RETRY], array_intersect_key($this->send($id), ['path' => 0, 'status' => 0]));
	}

	/** Paperless-ngx 3.x would import the same content a second time: the sender learns the document that has it. */
	public function testAFileThatPaperlessHasIsNotUploadedAgain(): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->paperless->originals[hash('sha256', 'Synthetic content of Scans/receipt.pdf')] = 77;

		$this->service->process();

		self::assertSame([], $this->paperless->uploads);
		self::assertNull($this->state->findSend(self::SENDER, $id));
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_KNOWN, $id, 'Scans/receipt.pdf', 77);
	}

	/** @return array<string, array{\Closure(TestStorage, int): void}> */
	public static function goneFiles(): array {
		return [
			'deleted' => [static function (TestStorage $storage): void {
				$storage->delete(self::SENDER, 'Scans/receipt.pdf');
			}],
			'no longer readable' => [static function (TestStorage $storage): void {
				$storage->unreadable[] = 'Scans/receipt.pdf';
			}],
		];
	}

	/** @param \Closure(TestStorage, int): void $change */
	#[DataProvider('goneFiles')]
	public function testAFileThatIsGoneBeforeItsUploadEndsItsImport(\Closure $change): void {
		$id = $this->queue('Scans/receipt.pdf');
		$change($this->storage, $id);

		$this->service->process();

		self::assertSame([], $this->paperless->uploadAttempts);
		self::assertNull($this->state->findSend(self::SENDER, $id));
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_GONE, $id, 'Scans/receipt.pdf');
	}

	/** The ID of a file that became a folder, as after a restore of another backup, is no file to upload. */
	public function testAFolderIsNoFileToUpload(): void {
		$id = $this->storage->folderId('Scans', self::SENDER);
		$this->state->saveSend(self::SENDER, $id, ['path' => 'Scans', 'status' => SendService::QUEUED]);

		$this->service->process();

		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_GONE, $id, 'Scans');
	}

	/** A sender whose account was deleted has no files left. */
	public function testTheFilesOfAUserThatIsGoneAreGone(): void {
		$storage = $this->createStub(NextcloudStorageInterface::class);
		$storage->method('nodeById')->willThrowException(new RuntimeException('Backends provided no user object for alice'));
		$publisher = $this->notificationPublisher($this->statusService($this->clock), $this->storage, $this->clock, $this->createStub(LoggerInterface::class));
		$this->state->saveSend(self::SENDER, 5, ['path' => 'Scans/receipt.pdf', 'status' => SendService::QUEUED]);

		(new SendService($this->configService, $this->paperless, $this->state, $storage, $publisher, $this->clock, $this->logger, $this->ownerMapping()))->process();

		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_GONE, 5, 'Scans/receipt.pdf');
	}

	public function testAFileThatPaperlessRefusesIsNotUploadedAgain(): void {
		$id = $this->queue('Scans/notes.bin');
		$this->paperless->uploadFailures['notes.bin'] = new PaperlessUploadException('File type application/octet-stream not supported', true, 400);

		$this->service->process();

		self::assertNull($this->state->findSend(self::SENDER, $id));
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_REFUSED, $id, 'Scans/notes.bin', null, 'File type application/octet-stream not supported');
	}

	/**
	 * A file whose import ends before or with its upload, as it is gone, Paperless has its content
	 * or refuses it, says nothing about Paperless: the next file of the round goes.
	 *
	 * @return array<string, array{\Closure(self): void}>
	 */
	public static function endsOfAnUpload(): array {
		return [
			'a file that is gone' => [static function (self $test): void {
				$test->storage->delete(self::SENDER, 'Scans/a.pdf');
			}],
			'a content that Paperless has' => [static function (self $test): void {
				$test->paperless->originals[hash('sha256', 'Synthetic content of Scans/a.pdf')] = 77;
			}],
			'a file that Paperless refuses' => [static function (self $test): void {
				$test->paperless->uploadFailures['a.pdf'] = new PaperlessUploadException('File type application/octet-stream not supported', true, 400);
			}],
		];
	}

	/** @param \Closure(self): void $end */
	#[DataProvider('endsOfAnUpload')]
	public function testAFileWhoseImportEndsLetsTheNextFileGo(\Closure $end): void {
		$first = $this->queue('Scans/a.pdf');
		$next = $this->queue('Scans/b.pdf');
		$end($this);

		$this->service->process();

		self::assertNull($this->state->findSend(self::SENDER, $first));
		self::assertSame(SendService::PENDING, $this->send($next)['status']);
	}

	/** An upload that fails closes the file all the same. */
	public function testAFailedUploadClosesTheFile(): void {
		$this->queue('Scans/a.pdf');
		$this->paperless->uploadFailures['a.pdf'] = new RuntimeException('The storage is not available.');

		$this->service->process();

		self::assertCount(2, $this->storage->openedStreams, 'One read for the checksums, one for the upload.');
		foreach ($this->storage->openedStreams as $stream) {
			self::assertFalse(is_resource($stream), 'Every stream is closed.');
		}
	}

	/** After a failure that may pass, the file waits, and so do the other files of the round, as they would meet it as well. */
	public function testAFailedUploadWaitsLongerEachTimeAndHoldsBackTheOthers(): void {
		$first = $this->queue('Scans/a.pdf');
		$second = $this->queue('Scans/b.pdf');
		$this->paperless->uploadFailures['a.pdf'] = new PaperlessUploadException('Paperless answered HTTP 503.', false, 503);
		$this->logger->expects(self::once())->method('log')->with(LogLevel::WARNING, self::stringContains('the next attempt follows in {minutes} minutes'), self::callback(
			static fn (array $context): bool => $context === ['path' => 'Scans/a.pdf', 'user' => self::SENDER, 'attempts' => 1, 'minutes' => 15, 'error' => 'Paperless answered HTTP 503.'],
		));

		$this->service->process();

		self::assertSame(['status' => SendService::RETRY, 'attempts' => 1, 'retry_at' => TestClock::START + 900, 'last_error' => 'Paperless answered HTTP 503.'], array_intersect_key($this->send($first), ['status' => 0, 'attempts' => 0, 'retry_at' => 0, 'last_error' => 0]));
		self::assertSame(SendService::QUEUED, $this->send($second)['status']);
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
	}

	public function testAFileWaitsForItsNextAttempt(): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->state->saveSend(self::SENDER, $id, ['status' => SendService::RETRY, 'attempts' => 2, 'retry_at' => TestClock::START + 60]);

		$this->service->process();
		self::assertSame([], $this->paperless->uploadAttempts);

		$this->clock->advance(60);
		$this->service->process();
		self::assertSame(SendService::PENDING, $this->send($id)['status']);
	}

	/** @return array<string, array{int, int}> the failed uploads of a file before, and the wait after the next one in minutes */
	public static function waits(): array {
		return [
			'after the first failure' => [0, 15],
			'after the third' => [2, 60],
			'after the fifth' => [4, 240],
		];
	}

	/** The wait for the next attempt doubles with each failure. */
	#[DataProvider('waits')]
	public function testTheWaitDoublesWithEachFailure(int $attempts, int $minutes): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->state->saveSend(self::SENDER, $id, ['status' => SendService::RETRY, 'attempts' => $attempts, 'retry_at' => 0]);
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered HTTP 503.', false, 503);
		$this->logger->expects(self::once())->method('log')->with(self::anything(), self::anything(), self::callback(static fn (array $context): bool => $context['minutes'] === $minutes));

		$this->service->process();

		self::assertSame(['attempts' => $attempts + 1, 'retry_at' => TestClock::START + 60 * $minutes], array_intersect_key($this->send($id), ['attempts' => 0, 'retry_at' => 0]));
	}

	/** @return array<string, array{\Closure(self, string): int}> a failure with the error, and the file that meets it */
	public static function longErrors(): array {
		return [
			'of an upload' => [static function (self $test, string $error): int {
				$test->paperless->uploadFailures['receipt.pdf'] = new PaperlessUploadException($error, false, 503);

				return $test->queue('Scans/receipt.pdf');
			}],
			'of the task of an upload' => [static function (self $test, string $error): int {
				$test->paperless->taskException = new PaperlessRequestException($error);

				return $test->pending('Scans/receipt.pdf');
			}],
			'of the owner of a document' => [static function (self $test, string $error): int {
				$test->usersOfNextcloud = ['paperless', 'alice'];
				$test->configService->save(['send_group' => 'scanners', 'ownership' => 'per-user'] + self::validSettings());
				$test->paperless->users = ['5' => 'alice'];
				$test->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];
				$test->paperless->ownerException = new RuntimeException($error);

				return $test->pending('Scans/receipt.pdf');
			}],
		];
	}

	/** The state keeps the first 4,000 characters of a long error, also of one beyond ASCII. */
	#[DataProvider('longErrors')]
	public function testTheStateKeepsTheBeginningOfALongError(\Closure $failure): void {
		$id = $failure($this, str_repeat('ü', 4001));

		$this->service->process();

		self::assertSame(str_repeat('ü', 4000), $this->send($id)['last_error']);
	}

	/** The attempts after the first are information in the log, and the last ends the import. */
	public function testTheLastFailedAttemptEndsTheImport(): void {
		$id = $this->queue('Scans/receipt.pdf');
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered HTTP 502.', false, 502);
		$this->state->saveSend(self::SENDER, $id, ['status' => SendService::RETRY, 'attempts' => 1]);
		$this->logger->expects(self::once())->method('log')->with(LogLevel::INFO, self::anything(), self::callback(static fn (array $context): bool => $context['attempts'] === 2 && $context['minutes'] === 30));
		$this->service->process();

		$this->state->saveSend(self::SENDER, $id, ['status' => SendService::RETRY, 'attempts' => SendService::MAX_ATTEMPTS - 1, 'retry_at' => 0]);
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('gave up sending {path}'), self::identicalTo(['path' => 'Scans/receipt.pdf', 'user' => self::SENDER, 'attempts' => SendService::MAX_ATTEMPTS, 'error' => 'Paperless answered HTTP 502.']));
		$this->service->process();

		self::assertNull($this->state->findSend(self::SENDER, $id));
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_FAILED, $id, 'Scans/receipt.pdf', null, 'Paperless answered HTTP 502.');
	}

	/** A failure of one file alone, such as of its storage, leaves the other files of the round alone. */
	public function testAFailureOfOneFileLetsTheOthersGo(): void {
		$first = $this->queue('Scans/a.pdf');
		$this->queue('Scans/b.pdf');
		$this->paperless->uploadFailures['a.pdf'] = new RuntimeException('The storage is not available.');

		$this->service->process();

		self::assertSame(SendService::RETRY, $this->send($first)['status']);
		self::assertSame(['a.pdf', 'b.pdf'], $this->paperless->uploadAttempts);
	}

	/** @return array<string, array{\Closure(TestPaperlessClient): void}> */
	public static function failuresOfPaperless(): array {
		return [
			'the tags can\'t be listed' => [static function (TestPaperlessClient $paperless): void {
				$paperless->tagsException = new RuntimeException('Paperless answered HTTP 500.');
			}],
			'Paperless can\'t be reached' => [static function (TestPaperlessClient $paperless): void {
				$paperless->findException = new PaperlessRequestException('cURL error 7: Failed to connect to paperless.example.test');
			}],
		];
	}

	/** @param \Closure(TestPaperlessClient): void $failure */
	#[DataProvider('failuresOfPaperless')]
	public function testAFailureOfPaperlessHoldsBackTheOtherFiles(\Closure $failure): void {
		$this->configService->save(['inbox_tags' => 'Scanned', 'send_group' => 'scanners'] + self::validSettings());
		$first = $this->queue('Scans/a.pdf');
		$second = $this->queue('Scans/b.pdf');
		$failure($this->paperless);

		$this->service->process();

		self::assertSame(SendService::RETRY, $this->send($first)['status']);
		self::assertSame(SendService::QUEUED, $this->send($second)['status']);
	}

	/** A round follows the task of an earlier upload and uploads the files after it. */
	public function testARoundFollowsATaskAndUploadsTheFilesAfterIt(): void {
		$pending = $this->pending('Scans/a.pdf');
		$queued = $this->queue('Scans/b.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];

		$this->service->process();

		self::assertNull($this->state->findSend(self::SENDER, $pending));
		self::assertSame(['b.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(SendService::PENDING, $this->send($queued)['status']);
	}

	/**
	 * A file that waits for its next attempt, or for the next round once the batch is full, leaves
	 * the round to the files after it, and the round follows the tasks of the earlier uploads all the same.
	 */
	public function testARoundGoesPastTheFilesThatWait(): void {
		$this->configService->save(['batch_size' => 1, 'send_group' => 'scanners'] + self::validSettings());
		$waiting = $this->queue('Scans/a.pdf');
		$this->state->saveSend(self::SENDER, $waiting, ['status' => SendService::RETRY, 'attempts' => 1, 'retry_at' => TestClock::START + 60]);
		$first = $this->queue('Scans/b.pdf');
		$second = $this->queue('Scans/c.pdf');
		$pending = $this->queue('Scans/d.pdf');
		$this->state->saveSend(self::SENDER, $pending, ['status' => SendService::PENDING, 'task_id' => 'task-9', 'submitted_at' => TestClock::START]);
		$this->paperless->tasks['task-9'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];

		$this->service->process();

		self::assertSame(['b.pdf'], $this->paperless->uploadAttempts);
		self::assertSame([SendService::RETRY, SendService::PENDING, SendService::QUEUED], [$this->send($waiting)['status'], $this->send($first)['status'], $this->send($second)['status']]);
		self::assertNull($this->state->findSend(self::SENDER, $pending), 'The import of the earlier upload ended.');
	}

	public function testARoundUploadsAtMostABatch(): void {
		$this->configService->save(['batch_size' => 1, 'send_group' => 'scanners'] + self::validSettings());
		$first = $this->queue('Scans/a.pdf');
		$second = $this->queue('Scans/b.pdf');

		$this->service->process();

		self::assertSame([SendService::PENDING, SendService::QUEUED], [$this->send($first)['status'], $this->send($second)['status']]);
	}

	public function testAnImportedFileNotifiesItsSenderWithItsDocument(): void {
		$id = $this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => 'Success. New document id 123 created', 'documentId' => 123];

		$this->service->process();

		self::assertNull($this->state->findSend(self::SENDER, $id));
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_IMPORTED, $id, 'Scans/receipt.pdf', 123);
	}

	/** In per-user ownership, the document of a sent file becomes that of the user of Paperless of its sender. */
	public function testTheDocumentOfASentFileGetsTheUserOfPaperlessOfItsSender(): void {
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$this->configService->save(['send_group' => 'scanners', 'ownership' => 'per-user'] + self::validSettings());
		$this->paperless->users = ['5' => 'alice'];
		$first = $this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => 'Success. New document id 123 created', 'documentId' => 123];

		$this->service->process();

		self::assertSame([[123, 5]], $this->paperless->ownerChanges);
		self::assertNull($this->state->findSend(self::SENDER, $first));
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_IMPORTED, $first, 'Scans/receipt.pdf', 123);
	}

	/** The round reads the users of Paperless once, for every document that gets its owner. */
	public function testARoundReadsTheUsersOfPaperlessOnce(): void {
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$this->configService->save(['send_group' => 'scanners', 'ownership' => 'per-user'] + self::validSettings());
		$this->paperless->users = ['5' => 'alice'];
		$this->pending('Scans/a.pdf');
		$second = $this->queue('Scans/b.pdf');
		$this->state->saveSend(self::SENDER, $second, ['status' => SendService::PENDING, 'task_id' => 'task-2', 'submitted_at' => TestClock::START]);
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];
		$this->paperless->tasks['task-2'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 124];

		$this->service->process();

		self::assertSame([[123, 5], [124, 5]], $this->paperless->ownerChanges);
		self::assertSame(['users'], array_values(array_filter($this->paperless->listsRead, static fn (string $list): bool => $list === 'users')));
	}

	/** With one target user for every document, the document of a sent file keeps its owner, and the round reads no users of Paperless. */
	public function testTheDocumentOfASentFileKeepsItsOwnerWithOneTargetUser(): void {
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$this->paperless->users = ['5' => 'alice'];
		$id = $this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];

		$this->service->process();

		self::assertSame([], $this->paperless->ownerChanges);
		self::assertNotContains('users', $this->paperless->listsRead);
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_IMPORTED, $id, 'Scans/receipt.pdf', 123);
	}

	/** A sender without a user of Paperless leaves the document to the account of the app. */
	public function testTheDocumentOfASenderWithoutAUserOfPaperlessKeepsItsOwner(): void {
		$this->configService->save(['send_group' => 'scanners', 'ownership' => 'per-user'] + self::validSettings());
		$this->paperless->users = ['5' => 'bob'];
		$this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];

		$this->service->process();

		self::assertSame([], $this->paperless->ownerChanges);
	}

	/** While Paperless refuses the owner, the import stays pending, the log says so once, and the next round asks again. */
	public function testAnOwnerThatPaperlessRefusesKeepsTheImportPending(): void {
		$this->usersOfNextcloud = ['paperless', 'alice'];
		$this->configService->save(['send_group' => 'scanners', 'ownership' => 'per-user'] + self::validSettings());
		$this->paperless->users = ['5' => 'alice'];
		$id = $this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => '', 'documentId' => 123];
		$this->paperless->ownerException = new RuntimeException('Paperless did not make the user 5 the owner of the document 123: HTTP 403.');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('its owner'), self::identicalTo(['document' => 123, 'path' => 'Scans/receipt.pdf', 'user' => self::SENDER, 'error' => 'Paperless did not make the user 5 the owner of the document 123: HTTP 403.']));

		$this->service->process();
		$this->service->process();

		self::assertSame(SendService::PENDING, $this->send($id)['status']);
		self::assertSame('Paperless did not make the user 5 the owner of the document 123: HTTP 403.', $this->send($id)['last_error']);
		self::assertCount(2, $this->paperless->ownerChanges);
	}

	/**
	 * A rule may send many files, so its owner doesn't hear of every import that worked, nor of a
	 * file that they deleted themselves before its upload; the log keeps them.
	 *
	 * @return array<string, array{\Closure(self, int): void, string}>
	 */
	public static function quietEnds(): array {
		return [
			'an import' => [static function (self $test): void {
				$test->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => 'Success. New document id 123 created', 'documentId' => 123];
			}, NotificationPublisher::SUBJECT_SEND_IMPORTED],
			'a content that Paperless has' => [static function (self $test, int $id): void {
				$test->state->saveSend(self::SENDER, $id, ['status' => SendService::QUEUED]);
				$test->paperless->originals[hash('sha256', 'Synthetic content of Scans/receipt.pdf')] = 77;
			}, NotificationPublisher::SUBJECT_SEND_KNOWN],
			'a file that is gone' => [static function (self $test, int $id): void {
				$test->state->saveSend(self::SENDER, $id, ['status' => SendService::QUEUED]);
				$test->storage->delete(self::SENDER, 'Scans/receipt.pdf');
			}, NotificationPublisher::SUBJECT_SEND_GONE],
		];
	}

	/** @param \Closure(self, int): void $end */
	#[DataProvider('quietEnds')]
	public function testTheOwnerOfAFileOfARuleHearsOfNoImportThatWorked(\Closure $end, string $subject): void {
		$id = $this->queueByRule('Scans/receipt.pdf', '');
		$this->state->saveSend(self::SENDER, $id, ['status' => SendService::PENDING, 'task_id' => 'task-1', 'submitted_at' => TestClock::START]);
		$end($this, $id);
		$this->logger->expects(self::once())->method('info')->with(
			'The import of {path} of {user}, which the rule {rule} of Flow sent to Paperless, ended: {subject}, document {document}.',
			self::callback(static fn (array $context): bool => $context['path'] === 'Scans/receipt.pdf' && $context['user'] === self::SENDER && $context['rule'] === 7 && $context['subject'] === $subject),
		);

		$this->service->process();

		self::assertNull($this->state->findSend(self::SENDER, $id));
		self::assertSame([], $this->notifications);
	}

	/** An import that Paperless refused or that failed needs its owner, whoever sent it. */
	public function testTheOwnerOfAFileOfARuleHearsOfAFailedImport(): void {
		$id = $this->queueByRule('Scans/notes.bin', '');
		$this->paperless->uploadFailures['notes.bin'] = new PaperlessUploadException('File type application/octet-stream not supported', true, 400);

		$this->service->process();

		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_REFUSED, $id, 'Scans/notes.bin', null, 'File type application/octet-stream not supported');
	}

	/** @return array<string, array{array{status: string, message: string}, string}> */
	public static function failedTasks(): array {
		return [
			'a failure with its reason' => [['status' => 'FAILURE', 'message' => 'Not consuming receipt.pdf: It is a duplicate of Receipt (#12).'], 'Not consuming receipt.pdf: It is a duplicate of Receipt (#12).'],
			'a cancelled task' => [['status' => 'REVOKED', 'message' => ''], 'Paperless cancelled the task before it finished (REVOKED), so it created no document.'],
		];
	}

	/** @param array{status: string, message: string} $task */
	#[DataProvider('failedTasks')]
	public function testAFailedImportNotifiesItsSenderWithTheReason(array $task, string $reason): void {
		$id = $this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = $task;

		$this->service->process();

		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_FAILED, $id, 'Scans/receipt.pdf', null, $reason);
	}

	/** Paperless may still create the document, so the file is not uploaded again. */
	public function testATaskThatIsNotFinishedADayAfterTheUploadEndsTheImport(): void {
		$id = $this->pending('Scans/receipt.pdf');
		$this->paperless->tasks['task-1'] = ['status' => 'STARTED', 'message' => 'Consuming'];

		$this->clock->advance(86399);
		$this->service->process();
		self::assertSame(SendService::PENDING, $this->send($id)['status']);

		$this->clock->advance(1);
		$this->service->process();
		self::assertSame([], $this->paperless->uploadAttempts);
		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_FAILED, $id, 'Scans/receipt.pdf', null, 'Paperless has not finished the import a day after the upload (STARTED: Consuming), so it may still create the document.');
	}

	public function testATaskWithoutAMessageNamesItsStatus(): void {
		$id = $this->pending('Scans/receipt.pdf');
		$this->clock->advance(86400);

		$this->service->process();

		$this->assertNotified(NotificationPublisher::SUBJECT_SEND_FAILED, $id, 'Scans/receipt.pdf', null, 'Paperless has not finished the import a day after the upload (PENDING), so it may still create the document.');
	}

	/** While Paperless can't answer, the task waits, and the log tells of each new error once. */
	public function testATaskThatPaperlessCannotReportWaits(): void {
		$id = $this->pending('Scans/receipt.pdf');
		$this->paperless->taskException = new PaperlessRequestException('cURL error 7: Failed to connect to paperless.example.test');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('could not check the import of {path}'), self::callback(
			static fn (array $context): bool => $context === ['path' => 'Scans/receipt.pdf', 'user' => self::SENDER, 'error' => 'cURL error 7: Failed to connect to paperless.example.test'],
		));

		$this->service->process();
		$this->service->process();

		self::assertSame(['status' => SendService::PENDING, 'last_error' => 'cURL error 7: Failed to connect to paperless.example.test'], array_intersect_key($this->send($id), ['status' => 0, 'last_error' => 0]));
		self::assertSame([], $this->notifications);
	}

	/** A file of the sender, queued as a click queues it, and its ID. */
	private function queue(string $path, string $folder = ''): int {
		$this->storage->filesOfOthers[self::SENDER][$path] = 'Synthetic content of ' . $path;
		$id = $this->storage->fileId($path, self::SENDER);
		$this->state->saveSend(self::SENDER, $id, ['path' => $path, 'folder' => $folder, 'status' => SendService::QUEUED]);

		return $id;
	}

	/** A file whose upload is the task task-1 of Paperless. */
	/** A file that the rule 7 of Flow queued, with the names of its tags. */
	private function queueByRule(string $path, string $tags): int {
		$id = $this->queue($path);
		$this->state->saveSend(self::SENDER, $id, ['tags' => $tags, 'rule_id' => 7]);

		return $id;
	}

	private function pending(string $path): int {
		$id = $this->queue($path);
		$this->state->saveSend(self::SENDER, $id, ['status' => SendService::PENDING, 'task_id' => 'task-1', 'submitted_at' => TestClock::START]);

		return $id;
	}

	/** @return array<string, int|string|null> */
	private function send(int $id): array {
		$send = $this->state->findSend(self::SENDER, $id);
		self::assertNotNull($send, "The file {$id} is no longer sent.");

		return $send;
	}

	private function assertNotified(string $subject, int $id, string $path, ?int $document = null, string $reason = ''): void {
		self::assertCount(1, $this->notifications);
		$notification = $this->notifications[0];
		self::assertInstanceOf(TestNotification::class, $notification);
		self::assertSame([self::SENDER, $subject, NotificationPublisher::OBJECT_SEND, (string)$id], [$notification->getUser(), $notification->getSubject(), $notification->getObjectType(), $notification->getObjectId()]);
		self::assertSame([
			'file' => ['id' => $id, 'path' => $path],
			'document' => $document,
			'url' => 'https://paperless.example.test',
			'reason' => $reason,
		], $notification->getSubjectParameters());
	}
}
