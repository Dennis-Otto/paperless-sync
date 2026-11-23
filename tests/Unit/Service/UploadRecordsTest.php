<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\PerUserScope;
use OCA\PaperlessSync\Service\ImportPlanner;
use OCA\PaperlessSync\Service\UploadRecords;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The records of the uploads of per-user ownership: which uploads get one, and how long one holds and follows its upload. */
final class UploadRecordsTest extends TestCase {
	private const START = TestClock::START;
	private const DAY = 86400;

	/** The two times are 30 days and the day that the app gives Paperless to finish a task. */
	public function testTheTimesOfARecord(): void {
		self::assertSame([30 * self::DAY, ImportPlanner::TASK_TIMEOUT], [UploadRecords::FOLLOW, UploadRecords::LOST_ANSWER]);
	}

	/**
	 * A record holds while its task may still create the document, up to 30 days after its start,
	 * and without a task for a day after its last attempt; it ends 30 days after its start.
	 *
	 * @return array<string, array{array<string, int|string|null>, int, bool, bool}>
	 */
	public static function records(): array {
		$task = ['task_id' => 'task-1', 'created_at' => self::START, 'last_attempt_at' => self::START];
		$lost = ['task_id' => '', 'created_at' => self::START - 3 * self::DAY, 'last_attempt_at' => self::START];

		return [
			'a task, right after the upload' => [$task, self::START, true, false],
			'a task, a second before 30 days' => [$task, self::START + 30 * self::DAY - 1, true, false],
			'a task, 30 days after the upload' => [$task, self::START + 30 * self::DAY, false, true],
			'a lost answer, a second before a day' => [$lost, self::START + self::DAY - 1, true, false],
			'a lost answer, a day after the last attempt' => [$lost, self::START + self::DAY, false, false],
			'a lost answer, 30 days after the first attempt' => [$lost, self::START + 27 * self::DAY, false, true],
			'a record without its times' => [[], self::START, false, true],
		];
	}

	/** @param array<string, int|string|null> $record */
	#[DataProvider('records')]
	public function testHowLongARecordHoldsAndFollowsItsUpload(array $record, int $now, bool $holds, bool $ended): void {
		self::assertSame([$holds, $ended], [UploadRecords::holds($record, $now), UploadRecords::ended($record, $now)]);
	}

	/** The checksums of a record are those of the content that it follows. */
	public function testTheContentOfARecord(): void {
		$content = UploadRecords::content(['sha256' => 'abc', 'md5' => 'def']);

		self::assertSame(['abc', 'def'], [$content->sha256, $content->md5]);
		self::assertSame(['', ''], [UploadRecords::content([])->sha256, UploadRecords::content([])->md5]);
	}

	/**
	 * Only an upload of a user whose user of Paperless is another than the account gets a record;
	 * while Paperless doesn't name its account, every upload of a user of Paperless does.
	 *
	 * @return array<string, array{string|null, int|null, int|null}>
	 */
	public static function owners(): array {
		return [
			'single ownership' => [null, 9, null],
			'a user of Paperless' => ['alice', 9, 1],
			'the user of the account' => ['paperless-sync', 9, null],
			'a user without a user of Paperless' => ['paperless', 9, null],
			'an account that Paperless does not name' => ['alice', null, 1],
		];
	}

	#[DataProvider('owners')]
	public function testWhoseUploadsGetARecord(?string $user, ?int $account, ?int $owner): void {
		$paperless = new TestPaperlessClient();
		$paperless->accountId = $account;
		$route = static fn (int $id, string $name, ?string $user): array => ['id' => $id, 'name' => $name, 'user' => $user, 'reason' => $user !== null ? Owners::NAME : Owners::NONE, 'detail' => ''];
		$owners = new Owners('paperless', [1 => $route(1, 'alice', 'alice'), 9 => $route(9, 'paperless-sync', 'paperless-sync')]);

		self::assertSame($owner, UploadRecords::owner($user !== null ? new PerUserScope($owners, $user) : null, $paperless));
	}

	/** A further upload of the content takes the record over: it keeps its first ID and its start, and follows the new task. */
	public function testAFurtherUploadKeepsTheFirstIdAndTheStart(): void {
		$state = new TestStateRepository();
		$content = new DocumentFingerprint('sha', 'md5');

		self::assertTrue(UploadRecords::begin($state, 'alice', UploadRecords::INBOX, 'Eingang/a.pdf', $content, 12, self::START));
		UploadRecords::started($state, 'alice', $content, 'task-1');
		self::assertFalse(UploadRecords::begin($state, 'alice', UploadRecords::SEND, 'Scans/a.pdf', $content, 77, self::START + 60));

		self::assertSame([
			'user_id' => 'alice',
			'sha256' => 'sha',
			'source' => UploadRecords::SEND,
			'path' => 'Scans/a.pdf',
			'md5' => 'md5',
			'known_id' => 12,
			'task_id' => '',
			'last_attempt_at' => self::START + 60,
			'created_at' => self::START,
		], $state->findUpload('alice', 'sha'));
	}
}
