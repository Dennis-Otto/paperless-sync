<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\InboxTags;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The tags of the files of the inbox in one run, which the tests of the synchronization use
 * through whole runs.
 */
final class InboxTagsTest extends TestCase {
	use InMemoryConfiguration;

	private TestPaperlessClient $paperless;

	protected function setUp(): void {
		$this->paperless = new TestPaperlessClient();
	}

	/** @return array<string, array{string, list<string>}> a list as an administrator writes it, and its names */
	public static function lists(): array {
		return [
			'empty' => ['', []],
			'commas and lines' => ["Scanned,Steuer\nPost\r\n", ['Scanned', 'Steuer', 'Post']],
			'spaces and empty names' => ['  Scanned ,, , Steuer ', ['Scanned', 'Steuer']],
			'a name twice, in another case' => ['Scanned, scanned, SCANNED, Steuer', ['Scanned', 'Steuer']],
			'names of digits alone' => ['2026, 2025, 2026', ['2026', '2025']],
		];
	}

	/** @param list<string> $names */
	#[DataProvider('lists')]
	public function testTheNamesOfAListAreEachThereOnce(string $list, array $names): void {
		self::assertSame($names, InboxTags::names($list));
	}

	/** @return array<string, array{array<string, mixed>, bool}> */
	public static function settings(): array {
		return [
			'neither' => [['inbox_create_tags' => true], false],
			'subfolders become tags' => [['inbox_folder_tags' => true], true],
			'tags for every import' => [['inbox_tags' => 'Scanned'], true],
		];
	}

	/** @param array<string, mixed> $settings */
	#[DataProvider('settings')]
	public function testTheFilesGetTagsOnlyWithASettingThatGivesThem(array $settings, bool $enabled): void {
		self::assertSame($enabled, $this->tags($settings)->enabled());
	}

	public function testAFileInTheInboxItselfNeedsNoListOfTheTags(): void {
		$tags = $this->tags(['inbox_folder_tags' => true]);

		self::assertSame(['ids' => [], 'names' => []], $tags->forFolder(''));
		self::assertSame(0, $this->paperless->tagReads);
	}

	/** A list of the tags that Paperless didn't give fails every file that needs it, with one request. */
	public function testAListOfTheTagsThatFailedFailsEveryFileThatNeedsIt(): void {
		$failure = new RuntimeException('Paperless could not read /api/tags/: HTTP 403.');
		$this->paperless->tagsException = $failure;
		$tags = $this->tags(['inbox_folder_tags' => true]);
		self::assertFalse($tags->unavailable());

		foreach (['Steuer', 'Post'] as $folder) {
			try {
				$tags->forFolder($folder);
				self::fail('A file that needs a tag must wait for it.');
			} catch (RuntimeException $exception) {
				self::assertSame($failure, $exception);
			}
		}

		self::assertSame(1, $this->paperless->tagReads);
		self::assertTrue($tags->unavailable());
	}

	public function testANameWithoutATagIsReportedOnceAsItWasWrittenFirst(): void {
		$tags = $this->tags(['inbox_folder_tags' => true]);

		self::assertSame(['ids' => [], 'names' => []], $tags->forFolder('Steuer/2026'));
		self::assertSame(['ids' => [], 'names' => []], $tags->forFolder('steuer'));

		self::assertSame(['Steuer', '2026'], $tags->unknown());
	}

	/** @param array<string, mixed> $settings */
	private function tags(array $settings): InboxTags {
		$config = $this->configService()->validate($settings + self::validSettings(), 'secret-token');

		return new InboxTags($this->paperless, $config, new SyncReport(false));
	}
}
