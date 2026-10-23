<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ArchiveTags;
use OCA\PaperlessSync\Service\SystemTagService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestSystemTags;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The tags of Paperless as tags of Nextcloud on the copies of a run (ArchiveTags), with the tags of
 * the app that SystemTagService keeps in the config of the app.
 */
final class SystemTagServiceTest extends TestCase {
	use InMemoryConfiguration;

	private const FIRST = TestConfig::ARCHIVE . '/Rechnung [P1].pdf';
	private const SECOND = TestConfig::ARCHIVE . '/Brief [P2].pdf';
	/** The tags of Paperless by their IDs. */
	private const NAMES = ['1' => 'Steuer 2026', '2' => 'Eingang', '3' => 'Bezahlt'];

	private TestSystemTags $tags;
	private TestStorage $storage;
	private SystemTagService $service;

	protected function setUp(): void {
		$this->tags = new TestSystemTags();
		$this->storage = new TestStorage();
		$this->storage->files[self::FIRST] = '%PDF-1';
		$this->storage->files[self::SECOND] = '%PDF-2';
		$this->service = new SystemTagService($this->tags, $this->tags, $this->appConfig(), $this->storage);
	}

	/**
	 * A copy gets a tag of Nextcloud for each tag of its document, which the app makes: visible to
	 * every user, so that Files filters and searches by it, and assignable by administrators alone.
	 * The config of the app keeps the tags that the app made, with their names in Paperless.
	 */
	public function testACopyGetsTheTagsOfItsDocument(): void {
		$report = $this->runTags([[1, self::FIRST, [1, 2]]]);

		self::assertSame(['Steuer 2026', 'Eingang'], $this->tags->created);
		self::assertSame(['Eingang', 'Steuer 2026'], $this->tags->namesOf($this->fileId(self::FIRST)));
		foreach ($this->tags->tags as $tag) {
			self::assertTrue($tag->isUserVisible(), 'Every user sees the tags of the copies.');
			self::assertFalse($tag->isUserAssignable(), 'Only administrators assign them, so that they stay in step with Paperless.');
		}
		self::assertSame(['TAGS P1: +Steuer 2026 +Eingang'], $report->actions);
		self::assertSame([['type' => 'tags', 'document' => 1, 'path' => self::FIRST, 'values' => ['added' => 'Steuer 2026, Eingang']]], $report->entries);
		self::assertSame(1, $report->tagged);
		self::assertSame('{"1":"Steuer 2026","2":"Eingang"}', $this->settings[SystemTagService::MANAGED]);
		self::assertSame([1 => 'Steuer 2026', 2 => 'Eingang'], $this->service->managed());
	}

	/** The tags of a copy follow its document: a run adds the new ones and removes those it lost, and changes nothing when they are the same. */
	public function testTheTagsOfACopyFollowItsDocument(): void {
		$this->runTags([[1, self::FIRST, [1, 2]]]);
		$this->tags->assigned = [];

		$same = $this->runTags([[1, self::FIRST, [2, 1]]]);
		self::assertSame([[], [], 0], [$same->actions, $this->tags->assigned, $same->tagged], 'The same tags change nothing.');

		$report = $this->runTags([[1, self::FIRST, [1, 3]]]);

		self::assertSame(['TAGS P1: +Bezahlt -Eingang'], $report->actions);
		self::assertSame([['type' => 'tags', 'document' => 1, 'path' => self::FIRST, 'values' => ['added' => 'Bezahlt', 'removed' => 'Eingang']]], $report->entries);
		self::assertSame(['Bezahlt', 'Steuer 2026'], $this->tags->namesOf($this->fileId(self::FIRST)));
		self::assertNotNull($this->tags->idOf('Eingang'), 'Paperless still has the tag, so the tag of the app stays for the next copy with it.');

		$removed = $this->runTags([[1, self::FIRST, []]]);
		self::assertSame(['TAGS P1: -Steuer 2026 -Bezahlt'], $removed->actions);
		self::assertSame([['type' => 'tags', 'document' => 1, 'path' => self::FIRST, 'values' => ['removed' => 'Steuer 2026, Bezahlt']]], $removed->entries);
		self::assertSame([], $this->tags->namesOf($this->fileId(self::FIRST)));
	}

	/**
	 * A tag that someone made in Nextcloud stays theirs: a run never assigns or removes it, also one
	 * with the name of a tag of Paperless in another case, whose copies go without it, which the
	 * report says once for the run.
	 */
	public function testATagThatSomeoneMadeIsNeverTouched(): void {
		$holiday = $this->tags->add('Urlaub');
		$taken = $this->tags->add('eingang', false, false);
		$fileId = (string)$this->fileId(self::FIRST);
		$this->tags->objects['files'][$fileId] = [$holiday, $taken];

		$report = $this->runTags([[1, self::FIRST, [1, 2]], [2, self::SECOND, [2]]]);

		self::assertSame(['Steuer 2026'], $this->tags->created, 'Nextcloud keeps one tag of a name, whatever its case.');
		self::assertSame(['Steuer 2026', 'Urlaub', 'eingang'], $this->tags->namesOf((int)$fileId));
		self::assertSame([], $this->tags->namesOf($this->fileId(self::SECOND)));
		self::assertSame([
			'TAGS P1: +Steuer 2026',
			'TAGS TAKEN: Nextcloud has tags named Eingang that Paperless Sync did not make, so the copies go without them. Rename the tag in Nextcloud or in Paperless.',
		], $report->actions);
		self::assertSame(['type' => 'tags-taken', 'values' => ['names' => 'Eingang', 'count' => 1]], $report->entries[1]);

		// Without the tag in Paperless, the copy keeps the tags of others as well.
		$this->runTags([[1, self::FIRST, []]]);
		self::assertSame(['Urlaub', 'eingang'], $this->tags->namesOf((int)$fileId));
		foreach ($this->tags->unassigned as [, $ids]) {
			self::assertSame([], array_intersect($ids, [$holiday, $taken]));
		}
	}

	/** Someone may make a tag of the name between the question of the run and its creation: it stays theirs. */
	public function testATagThatSomeoneMakesDuringTheRunStaysTheirs(): void {
		$this->tags->createdMeanwhile = ['Eingang'];

		$report = $this->runTags([[1, self::FIRST, [2]]]);

		self::assertSame([], $this->tags->namesOf($this->fileId(self::FIRST)));
		self::assertSame([['type' => 'tags-taken', 'values' => ['names' => 'Eingang', 'count' => 1]]], $report->entries);
		self::assertSame([], $this->service->managed());
	}

	/**
	 * Nextcloud lets only users and the command line create tags: a run without either, such as one
	 * of the webhook, gives the copies the tags that the app made already, and says which wait.
	 */
	public function testARunThatMayCreateNoTagGivesTheCopiesTheTagsThatExist(): void {
		$this->runTags([[1, self::FIRST, [1]]]);
		$this->tags->createForbidden = true;

		$report = $this->runTags([[2, self::SECOND, [1, 2, 3]]]);

		self::assertSame(['Steuer 2026'], $this->tags->namesOf($this->fileId(self::SECOND)));
		self::assertSame([
			'TAGS P2: +Steuer 2026',
			'TAGS NOT CREATED: Nextcloud lets only users and the command line create tags, so the copies get the tags named Eingang, Bezahlt with the next run of the background jobs by cron or of occ paperless_sync:run.',
		], $report->actions);
		self::assertSame(['type' => 'tags-not-created', 'values' => ['names' => 'Eingang, Bezahlt', 'count' => 2]], $report->entries[1]);
		self::assertSame(0, $report->errors);
	}

	/** A dry-run reports the changes of a run, those with tags that it would create too, and changes nothing. */
	public function testADryRunReportsTheChangesAndChangesNothing(): void {
		$this->runTags([[1, self::FIRST, [2]]]);
		$this->tags->assigned = [];
		$managed = $this->settings[SystemTagService::MANAGED];

		$report = $this->runTags([[1, self::FIRST, [1, 3]], [3, TestConfig::ARCHIVE . '/Neu [P3].pdf', [1]]], dryRun: true);

		self::assertSame(['TAGS P1: +Steuer 2026 +Bezahlt -Eingang', 'TAGS P3: +Steuer 2026'], $report->actions);
		self::assertSame(['Eingang'], $this->tags->created, 'A dry-run creates no tag.');
		self::assertSame([[], []], [$this->tags->assigned, $this->tags->unassigned]);
		self::assertSame(['Eingang'], $this->tags->namesOf($this->fileId(self::FIRST)));
		self::assertSame($managed, $this->settings[SystemTagService::MANAGED]);
	}

	/** A run changes the tags of at most as many copies as its batch size; the others follow with the next runs. */
	public function testARunChangesTheTagsOfAtMostItsBatchOfCopies(): void {
		$copies = [[1, self::FIRST, [1]], [2, self::SECOND, [1]]];

		$first = $this->runTags($copies, ['batchSize' => 1]);
		self::assertSame(['TAGS P1: +Steuer 2026'], $first->actions);
		self::assertSame([], $this->tags->namesOf($this->fileId(self::SECOND)));

		$second = $this->runTags($copies, ['batchSize' => 1]);
		self::assertSame(['TAGS P2: +Steuer 2026'], $second->actions);
		self::assertSame(['Steuer 2026'], $this->tags->namesOf($this->fileId(self::SECOND)));
	}

	/**
	 * A tag of the app whose tag of Paperless is gone, such as one renamed there, goes at the end of a
	 * run of the whole archive once no file has it, also not a copy in the deleted folder. A run of
	 * one document leaves it.
	 */
	public function testATagWhoseTagOfPaperlessIsGoneGoesOnceNoFileHasIt(): void {
		$deleted = TestConfig::ARCHIVE . '/_Gelöscht/Alt [P9].pdf';
		$this->storage->files[$deleted] = '%PDF-9';
		$this->runTags([[1, self::FIRST, [2]], [9, $deleted, [3]]]);
		$renamed = ['1' => 'Steuer 2026', '2' => 'Posteingang', '3' => 'Bezahlt'];

		$single = $this->runTags([[1, self::FIRST, [2]]], names: $renamed, complete: false);
		self::assertSame(['TAGS P1: +Posteingang -Eingang'], $single->actions);
		self::assertNotNull($this->tags->idOf('Eingang'), 'A run of one document deletes no tag.');

		$this->runTags([[1, self::FIRST, [2]]], names: ['2' => 'Posteingang']);
		self::assertNull($this->tags->idOf('Eingang'), 'No file has the tag, and Paperless has none of its name.');
		self::assertNotNull($this->tags->idOf('Bezahlt'), 'The copy in the deleted folder still has the tag.');
		self::assertSame([2 => 'Bezahlt', 3 => 'Posteingang'], $this->service->managed());
	}

	/** The app forgets a tag that an administrator deleted, and the next copy with its name gets a new one. */
	public function testATagThatAnAdministratorDeletedIsMadeAgain(): void {
		$this->runTags([[1, self::FIRST, [1, 2]]]);
		$this->tags->deleteTags((string)$this->tags->idOf('Eingang'));

		self::assertSame([1 => 'Steuer 2026'], $this->service->managed());
		self::assertSame('{"1":"Steuer 2026"}', $this->settings[SystemTagService::MANAGED]);

		$report = $this->runTags([[1, self::FIRST, [1, 2]]]);
		self::assertSame(['TAGS P1: +Eingang'], $report->actions);
		self::assertSame(['Steuer 2026', 'Eingang', 'Eingang'], $this->tags->created);
	}

	/** A value of the config of the app that is no list of tags, such as one that someone changed by hand, counts as none. */
	public function testATagListThatIsNoneCountsAsNone(): void {
		foreach (['', 'not json', '[1, 2]', '{"0": "Null", "x": "Name", "5": 7, "6": ""}'] as $value) {
			$this->settings[SystemTagService::MANAGED] = $value;
			self::assertSame([], $this->service->managed(), $value);
		}
	}

	/** A name has the form that Nextcloud keeps: one tag of each name, without control characters, with single spaces and at most 64 bytes. */
	public function testTheNamesTakeTheFormThatNextcloudKeeps(): void {
		$long = str_repeat('ä', 40);
		$names = ['1' => " Steuer \t 2026\u{0007} ", '2' => 'STEUER 2026', '3' => $long, '4' => "\u{200B}"];

		$this->runTags([[1, self::FIRST, [1, '2', 3, 4, 99, null, [1]]]], names: $names);

		self::assertSame(['Steuer 2026', str_repeat('ä', 32)], $this->tags->created, 'A tag without a name, one that Paperless shows the account by its ID alone, and an ID that is none have no tag.');
		self::assertSame('Steuer 2026', ArchiveTags::normalize("Steuer\u{00A0}\u{00A0}2026"));
		self::assertSame(ArchiveTags::NAME_BYTES, strlen(ArchiveTags::normalize(str_repeat('a', 70))));
		self::assertSame(63, strlen(ArchiveTags::normalize(str_repeat('a', 63) . 'ä')), 'A character is never cut in half.');
	}

	/** A copy that a run finds no file of, such as one that someone deleted meanwhile, keeps nothing to tag. */
	public function testACopyWithoutAFileHasNoTags(): void {
		$report = $this->runTags([[3, TestConfig::ARCHIVE . '/Weg [P3].pdf', [1]]]);

		self::assertSame([[], []], [$report->actions, $this->tags->created]);
	}

	/** A failure for one copy is an error of its document, and the run goes on with the others. */
	public function testAFailureForOneCopyIsAnErrorOfItsDocument(): void {
		$this->tags->assignFailures[(string)$this->fileId(self::FIRST)] = new RuntimeException('The file is locked');

		$report = $this->runTags([[1, self::FIRST, [1]], [2, self::SECOND, [1]]]);

		self::assertSame(['ERROR: Tags P1: The file is locked', 'TAGS P2: +Steuer 2026'], $report->actions);
		self::assertEquals(['type' => 'error', 'document' => 1, 'path' => self::FIRST, 'detail' => 'Tags P1: The file is locked', 'error' => new RunError('Tags P%1$s: %2$s', ['1', RunError::plain('The file is locked')])], $report->entries[0]);
		self::assertSame([1, 1], [$report->errors, $report->tagged]);
	}

	/**
	 * A tag that Nextcloud fails to create, or a file that it fails to find, is an error of the
	 * document of that copy, once per name in a run: the other copies get their tags.
	 */
	public function testAFailureForOneTagOrFileIsAnErrorOfItsDocument(): void {
		$this->tags->createFailures['Eingang'] = new RuntimeException('Incorrect string value');

		$report = $this->runTags([[1, self::FIRST, [1, 2]], [2, self::SECOND, [2, 3]], [4, '', [1]]]);

		self::assertSame([
			'ERROR: Tags P4: A relative folder path is required.',
			'ERROR: Tags P1: Incorrect string value',
			'TAGS P1: +Steuer 2026',
			'TAGS P2: +Bezahlt',
		], $report->actions);
		self::assertEquals(['type' => 'error', 'document' => 1, 'path' => self::FIRST, 'detail' => 'Tags P1: Incorrect string value', 'error' => new RunError('Tags P%1$s: %2$s', ['1', RunError::plain('Incorrect string value')])], $report->entries[1]);
		self::assertSame([2, 2], [$report->errors, $report->tagged]);
		self::assertSame(['Steuer 2026', 'Bezahlt'], $this->tags->created);
	}

	/** A failure for every copy, such as of the database of Nextcloud, is an error of the run, which keeps the tags that it made. */
	public function testAFailureForEveryCopyIsAnErrorOfTheRun(): void {
		$this->tags->readException = new RuntimeException('The database is not available');

		$report = $this->runTags([[1, self::FIRST, [1]]]);

		self::assertSame(['Tags: The database is not available'], $report->errorMessages);
		self::assertSame([], $this->tags->namesOf($this->fileId(self::FIRST)));
		$this->tags->readException = null;
		self::assertSame(['TAGS P1: +Steuer 2026'], $this->runTags([[1, self::FIRST, [1]]])->actions);

		// Nextcloud names no missing tag: the run can't tell which of its tags are gone.
		$this->tags->lookupException = new TagNotFoundException('Tag id(s) not found');
		self::assertSame(['Tags: Tag id(s) not found'], $this->runTags([[1, self::FIRST, []]])->errorMessages);
		self::assertSame(['Steuer 2026'], $this->tags->namesOf($this->fileId(self::FIRST)));
	}

	/**
	 * One run: each copy with its document, the path where it is, and the IDs of the tags of its
	 * document, and finish() at its end.
	 *
	 * @param list<array{int, string, list<mixed>}> $copies
	 * @param array<string, bool|int|string> $settings
	 * @param array<string, string> $names
	 */
	private function runTags(array $copies, array $settings = [], bool $dryRun = false, array $names = self::NAMES, bool $complete = true): SyncReport {
		$report = new SyncReport($dryRun);
		$tags = $this->service->forRun(TestConfig::of(['nextcloudTags' => true] + $settings), $report, $names);
		foreach ($copies as [$documentId, $path, $tagIds]) {
			$tags->copy($documentId, ['id' => $documentId, 'tags' => $tagIds], $path, $path);
		}
		$tags->finish($complete);

		return $report;
	}

	private function fileId(string $path): int {
		return $this->storage->findFileId(TestStorage::USER, $path) ?? 0;
	}
}
