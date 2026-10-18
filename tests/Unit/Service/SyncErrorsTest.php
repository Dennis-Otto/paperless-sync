<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\SyncErrors;
use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncErrorsTest extends TestCase {
	public function testAnErrorIsTheMessageOfItsException(): void {
		self::assertSame('Broken', SyncErrors::message(new RuntimeException('  Broken ')));
		self::assertSame(RuntimeException::class, SyncErrors::message(new RuntimeException('')), 'An exception without a message names its class.');
	}

	public function testAStateKeepsAnErrorOnceUpToItsLength(): void {
		$long = str_repeat('ä', SyncErrors::LENGTH + 1);

		self::assertSame(SyncErrors::LENGTH, mb_strlen(SyncErrors::stored($long)));
		self::assertFalse(SyncErrors::changed(['last_error' => SyncErrors::stored($long)], $long), 'The state keeps the error as it stores it.');
		self::assertTrue(SyncErrors::changed(['last_error' => 'Other'], 'Broken'));
		self::assertTrue(SyncErrors::changed(null, 'Broken'), 'A document without a state has no error yet.');
	}

	/** A full quota fails every write of the archive; a refused path only its file, unless the whole archive is read-only. */
	public function testTellsAFailureOfTheWholeArchiveFromThatOfOneFile(): void {
		$storage = new TestStorage();
		$failed = static fn (\Throwable $exception): bool => SyncErrors::archiveFailed($exception, $storage, TestStorage::USER, TestConfig::ARCHIVE);

		self::assertTrue($failed(new NotEnoughSpaceException('Not enough free space')));
		self::assertFalse($failed(new RuntimeException('The connection broke')));
		self::assertFalse($failed(new NotPermittedException('Forbidden name')), 'The archive is still writable.');
		self::assertSame([TestConfig::ARCHIVE], $storage->writableChecks);

		$storage->writable = false;
		self::assertTrue($failed(new NotPermittedException('Read-only')));

		$storage->writable = new RuntimeException('The storage is gone');
		self::assertTrue($failed(new NotPermittedException('Read-only')), 'An archive whose check fails counts as read-only.');
	}
}
