<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Service\SyncBatch;
use PHPUnit\Framework\TestCase;

final class SyncBatchTest extends TestCase {
	public function testAnExportIsAnAttemptAndAChangeOnceItSucceeded(): void {
		$batch = new SyncBatch(2);

		$batch->take(SyncBatch::EXPORT);
		$batch->succeeded();

		self::assertSame([true, true, true], self::room($batch), 'One of two places is taken.');

		$batch->take(SyncBatch::EXPORT);
		$batch->succeeded();

		self::assertSame([false, false, false], self::room($batch));
	}

	/** Documents whose export keeps failing leave the places of the trashed and the missing documents alone. */
	public function testAFailedExportLeavesItsChangeToTheOtherActions(): void {
		$batch = new SyncBatch(2);

		$batch->take(SyncBatch::EXPORT);
		$batch->take(SyncBatch::EXPORT);

		self::assertSame([false, true, true], self::room($batch), 'A run that can\'t write downloads no more documents than its batch size.');

		$batch->take(SyncBatch::WRITE);
		$batch->take(SyncBatch::DELETE);

		self::assertSame([false, false, false], self::room($batch));
	}

	/** After Nextcloud refused a write for the whole archive, the exports and moves wait, and the deletions, which need no space, go on. */
	public function testStoppedWritesLeaveTheDeletions(): void {
		$batch = new SyncBatch(5);

		$batch->stop();

		self::assertTrue($batch->stopped());
		self::assertSame([false, false, true], self::room($batch));
	}

	public function testAnActionThatDoesNotFitIsRefused(): void {
		$batch = new SyncBatch(1);
		$batch->take(SyncBatch::DELETE);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('The batch of 1 has no place for another write.');

		$batch->take(SyncBatch::WRITE);
	}

	/** @return list<bool> whether an export, a write and a deletion fit */
	private static function room(SyncBatch $batch): array {
		return [$batch->fits(SyncBatch::EXPORT), $batch->fits(SyncBatch::WRITE), $batch->fits(SyncBatch::DELETE)];
	}
}
