<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\SyncReport;
use PHPUnit\Framework\TestCase;

final class SyncReportTest extends TestCase {
	/**
	 * Per-user ownership: the report of the run takes in the report of each user, its counts, also
	 * those for the user, its lines and its errors with the user, and its notices as notices; the
	 * files that it changed and its imports stay with it, for the activity stream of that user.
	 */
	public function testTakesInTheReportOfEachUser(): void {
		$run = new SyncReport(false, 100);
		$run->notice(SyncReport::TYPE_SCAN_INCOMPLETE, 'SCAN INCOMPLETE: of the run');
		$alice = new SyncReport(false, 100);
		$alice->change(SyncReport::TYPE_EXPORT, 1, 'Dokumente/Paperless/Archiv/a.pdf');
		$alice->changedFile('Dokumente/Paperless/Archiv/a.pdf');
		++$alice->exported;
		$alice->importResult(SyncReport::IMPORTED, 'Dokumente/Paperless/Eingang/scan.pdf', 7);
		$bob = new SyncReport(false, 100);
		$bob->change(SyncReport::TYPE_REMOVE_REASSIGNED, 1, 'Dokumente/Paperless/Archiv/a.pdf');
		++$bob->removedReassigned;
		$bob->error('P2: the quota is full', 2, 'Dokumente/Paperless/Archiv/b.pdf');
		$bob->notice(SyncReport::TYPE_EXPORT_STOPPED, 'EXPORT STOPPED: Nextcloud cannot store files in the archive');
		++$bob->unchanged;
		$bob->fileSetAside('Dokumente/Paperless/Eingang/film.mp4', 'video');

		$run->absorb($alice, 'alice');
		$run->absorb($bob, 'bob');

		self::assertSame([1, 1, 1, 1, 1], [$run->exported, $run->removedReassigned, $run->errors, $run->unchanged, $run->setAside]);
		self::assertSame([0, 1], [$run->users['alice']['setAside'], $run->users['bob']['setAside']]);
		self::assertSame(['exported' => 1, 'removedReassigned' => 0, 'errors' => 0], array_intersect_key($run->users['alice'], ['exported' => 0, 'removedReassigned' => 0, 'errors' => 0]));
		self::assertSame(['exported' => 0, 'removedReassigned' => 1, 'unchanged' => 1, 'errors' => 1], array_intersect_key($run->users['bob'], ['exported' => 0, 'removedReassigned' => 0, 'unchanged' => 0, 'errors' => 0]));
		self::assertSame([
			'SCAN INCOMPLETE: of the run',
			'[alice] EXPORT P1: Dokumente/Paperless/Archiv/a.pdf',
			'[bob] REMOVE REASSIGNED P1: Dokumente/Paperless/Archiv/a.pdf',
			'[bob] ERROR: P2: the quota is full',
			'[bob] EXPORT STOPPED: Nextcloud cannot store files in the archive',
			'[bob] IMPORT SET ASIDE: Dokumente/Paperless/Eingang/film.mp4: video',
		], $run->actions);
		self::assertSame(['alice', 'bob', 'bob', 'bob', 'bob'], array_column(array_slice($run->entries, 1), 'user'));
		self::assertSame(['[bob] P2: the quota is full'], $run->errorMessages);
		self::assertSame([[], 0, []], [$run->changedFiles(), $run->changedFileCount(), $run->importResults()], 'The files and the imports of a user stay in their report.');
		self::assertSame(['alice' => $alice->counts(), 'bob' => $bob->counts()], $run->jsonSerialize()['users']);
		self::assertArrayNotHasKey('users', $run->summary());
	}

	/** A notice of a user keeps its place past the limit of the lines, as one of the run does. */
	public function testKeepsTheNoticesOfAUserPastTheLimit(): void {
		$run = new SyncReport(true);
		$user = new SyncReport(true);
		for ($document = 1; $document <= SyncReport::ACTION_LIMIT; ++$document) {
			$user->change(SyncReport::TYPE_EXPORT, $document, "a{$document}.pdf");
		}
		$user->notice(SyncReport::TYPE_EXPORT_STOPPED, 'EXPORT STOPPED');
		$user->change(SyncReport::TYPE_DELETE, 999, 'gone.pdf');
		$user->errorMessages = array_fill(0, SyncReport::ERROR_LIMIT, 'an error');

		$run->absorb($user, 'alice');

		self::assertContains('[alice] EXPORT STOPPED', $run->actions);
		self::assertContains('[alice] DELETE P999: gone.pdf', $run->actions, 'A deletion takes the place of an export.');
		self::assertSame(1, $run->omittedActions, 'The run counts the line that the report of the user left out.');
		self::assertCount(SyncReport::ERROR_LIMIT, $run->errorMessages);
	}

	/** Single ownership has no counts of users, so its report stays as it was. */
	public function testAReportWithoutUsersHasNoCountsOfUsers(): void {
		self::assertArrayNotHasKey('users', (new SyncReport(false))->jsonSerialize());
	}

	public function testListsTheChangesInTheirOrder(): void {
		$report = new SyncReport(true);

		$report->upload('Eingang/a.pdf');
		$report->change(SyncReport::TYPE_TRASH, 2, 'Archiv/_Gelöscht/b.pdf');
		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/a.pdf');

		self::assertSame(['IMPORT: Eingang/a.pdf', 'TRASH P2: Archiv/_Gelöscht/b.pdf', 'EXPORT P1: Archiv/a.pdf'], $report->actions);
		self::assertSame(0, $report->omittedActions);
		self::assertSame([0, 0], [$report->startedAt, $report->completedAt], 'A report without times has none.');
	}

	/** Every line in English for the log and the command line, and its entry for the settings page. */
	public function testRecordsAnEntryNextToEveryLine(): void {
		$report = new SyncReport(false);

		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/a.pdf');
		$report->change(SyncReport::TYPE_MOVE, 2, 'Archiv/b.pdf');
		$report->change(SyncReport::TYPE_REMOVE_EXCLUDED, 3, 'Archiv/c.pdf');
		$report->change(SyncReport::TYPE_TRASH, 4, 'Archiv/_Gelöscht/d.pdf');
		$report->change(SyncReport::TYPE_MISSING, 5, 'Archiv/_Gelöscht/e.pdf');
		$report->change(SyncReport::TYPE_DELETE, 6, 'Archiv/_Gelöscht/f.pdf');
		$report->waiting(7, 'Archiv/g.pdf', 1, 3);
		$report->tags(1, 'Archiv/a.pdf', ['Steuer 2026', 'Bezahlt'], ['Eingang']);
		$report->upload('Eingang/h.pdf');
		$report->newTag('Steuer');
		$report->upload('Eingang/Steuer/n.pdf', ['scanned', 'Steuer']);
		$report->uploadSucceeded('Eingang/i.pdf', 456);
		$report->uploadSucceeded('Eingang/j.pdf', null);
		$report->uploadFailed('Eingang/k.pdf', 'Fehler/k.pdf', 'Unsupported mime type');
		$report->uploadRejected('Eingang/l.bin', 'File type not supported');
		$report->notice(SyncReport::TYPE_EXPORT_STOPPED, 'EXPORT STOPPED: Nextcloud cannot store files in the archive.');
		$report->notice(SyncReport::TYPE_SCAN_INCOMPLETE, 'SCAN INCOMPLETE: 3 of 5 received.', ['list' => 'documents', 'received' => 3, 'listed' => 5]);
		$report->notice(SyncReport::TYPE_TAGS_UNKNOWN, 'TAGS UNKNOWN: no tags named 2026, Rechnungen.', ['names' => '2026, Rechnungen', 'count' => 2]);
		$report->error('P8: Paperless is unavailable', 8);
		$report->error('Import task Eingang/m.pdf: HTTP 500', null, 'Eingang/m.pdf');

		self::assertSame([
			'EXPORT P1: Archiv/a.pdf',
			'MOVE P2: Archiv/b.pdf',
			'REMOVE EXCLUDED P3: Archiv/c.pdf',
			'TRASH P4: Archiv/_Gelöscht/d.pdf',
			'MISSING P5: Archiv/_Gelöscht/e.pdf',
			'DELETE P6: Archiv/_Gelöscht/f.pdf',
			'WAIT P7: missing run 1/3',
			'TAGS P1: +Steuer 2026 +Bezahlt -Eingang',
			'IMPORT: Eingang/h.pdf',
			'NEW TAG: Steuer',
			'IMPORT: Eingang/Steuer/n.pdf (tags: scanned, Steuer)',
			'IMPORT SUCCESS: Eingang/i.pdf -> P456',
			'IMPORT SUCCESS: Eingang/j.pdf',
			'IMPORT ERROR: Eingang/k.pdf -> Fehler/k.pdf',
			'IMPORT REJECTED: Eingang/l.bin: File type not supported',
			'EXPORT STOPPED: Nextcloud cannot store files in the archive.',
			'SCAN INCOMPLETE: 3 of 5 received.',
			'TAGS UNKNOWN: no tags named 2026, Rechnungen.',
			'ERROR: P8: Paperless is unavailable',
			'ERROR: Import task Eingang/m.pdf: HTTP 500',
		], $report->actions, 'The lines stay as the log and the command line know them.');
		self::assertSame([
			['type' => 'export', 'document' => 1, 'path' => 'Archiv/a.pdf'],
			['type' => 'move', 'document' => 2, 'path' => 'Archiv/b.pdf'],
			['type' => 'remove-excluded', 'document' => 3, 'path' => 'Archiv/c.pdf'],
			['type' => 'trash', 'document' => 4, 'path' => 'Archiv/_Gelöscht/d.pdf'],
			['type' => 'missing', 'document' => 5, 'path' => 'Archiv/_Gelöscht/e.pdf'],
			['type' => 'delete', 'document' => 6, 'path' => 'Archiv/_Gelöscht/f.pdf'],
			['type' => 'wait', 'document' => 7, 'path' => 'Archiv/g.pdf', 'values' => ['scans' => 1, 'limit' => 3]],
			['type' => 'tags', 'document' => 1, 'path' => 'Archiv/a.pdf', 'values' => ['added' => 'Steuer 2026, Bezahlt', 'removed' => 'Eingang']],
			['type' => 'import', 'path' => 'Eingang/h.pdf'],
			['type' => 'new-tag', 'detail' => 'Steuer'],
			['type' => 'import', 'path' => 'Eingang/Steuer/n.pdf', 'values' => ['tags' => 'scanned, Steuer']],
			['type' => 'import-success', 'path' => 'Eingang/i.pdf', 'document' => 456],
			['type' => 'import-success', 'path' => 'Eingang/j.pdf'],
			['type' => 'import-error', 'path' => 'Eingang/k.pdf', 'target' => 'Fehler/k.pdf', 'detail' => 'Unsupported mime type'],
			['type' => 'import-rejected', 'path' => 'Eingang/l.bin', 'detail' => 'File type not supported'],
			['type' => 'export-stopped'],
			['type' => 'scan-incomplete', 'values' => ['list' => 'documents', 'received' => 3, 'listed' => 5]],
			['type' => 'tags-unknown', 'values' => ['names' => '2026, Rechnungen', 'count' => 2]],
			['type' => 'error', 'document' => 8, 'detail' => 'P8: Paperless is unavailable'],
			['type' => 'error', 'path' => 'Eingang/m.pdf', 'detail' => 'Import task Eingang/m.pdf: HTTP 500'],
		], $report->entries);
		self::assertSame($report->entries, $report->jsonSerialize()['entries']);
		self::assertArrayNotHasKey('entries', $report->summary(), 'The summary has the counts alone.');
		self::assertSame(1, $report->summary()['tagged'], 'It counts the copies whose tags changed.');
	}

	/** A full report gives up a line and its entry together, so that each line keeps its entry. */
	public function testEveryLineKeepsItsEntryPastTheLimit(): void {
		$report = new SyncReport(true);
		foreach (range(1, SyncReport::ACTION_LIMIT) as $number) {
			$report->upload("Eingang/scan{$number}.pdf");
		}

		$report->change(SyncReport::TYPE_DELETE, 5, 'Archiv/5.pdf');
		$report->notice(SyncReport::TYPE_TRASH_EMPTY, 'TRASH EMPTY: 2 missing.', ['missing' => 2, 'scans' => 3, 'deletes' => 0]);

		self::assertCount(count($report->actions), $report->entries);
		self::assertSame(['type' => 'import', 'path' => 'Eingang/scan99.pdf'], $report->entries[98]);
		self::assertSame(['type' => 'delete', 'document' => 5, 'path' => 'Archiv/5.pdf'], $report->entries[99], 'The deletion takes the place of the last import.');
		self::assertSame(['type' => 'trash-empty', 'values' => ['missing' => 2, 'scans' => 3, 'deletes' => 0]], $report->entries[100]);
		foreach ($report->actions as $index => $line) {
			$path = $report->entries[$index]['path'] ?? '';
			self::assertStringContainsString($path, $line, "Line {$index} and its entry concern the same file.");
		}
	}

	public function testFullReportCountsTheChangesThatItLeavesOut(): void {
		$report = new SyncReport(true);

		foreach (range(1, 150) as $id) {
			$report->change(SyncReport::TYPE_EXPORT, $id, "Archiv/{$id}.pdf");
		}

		self::assertCount(100, $report->actions);
		self::assertSame('EXPORT P100: Archiv/100.pdf', $report->actions[99], 'The first changes stay.');
		self::assertSame(50, $report->omittedActions);
	}

	public function testErrorsAndDeletionsNeverHideBehindImports(): void {
		$report = new SyncReport(true);
		foreach (range(1, SyncReport::ACTION_LIMIT) as $number) {
			$report->upload("Eingang/scan{$number}.pdf");
		}

		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/1.pdf');
		$report->waiting(2, 'Archiv/2.pdf', 1, 3);
		$report->change(SyncReport::TYPE_TRASH, 3, 'Archiv/_Gelöscht/3.pdf');
		$report->change(SyncReport::TYPE_MISSING, 4, 'Archiv/_Gelöscht/4.pdf');
		$report->change(SyncReport::TYPE_DELETE, 5, 'Archiv/_Gelöscht/5.pdf');
		$report->change(SyncReport::TYPE_REMOVE_EXCLUDED, 6, 'Archiv/6.pdf');
		$report->uploadRejected('Eingang/notes.bin', 'File type not supported');
		$report->uploadFailed('Eingang/broken.pdf', 'Fehler/broken.pdf', 'Unsupported mime type');
		$report->error('P7: Paperless is unavailable');

		self::assertCount(SyncReport::ACTION_LIMIT, $report->actions);
		self::assertSame(9, $report->omittedActions, 'The export, the wait and the seven imports that gave up their places.');
		self::assertSame([
			'TRASH P3: Archiv/_Gelöscht/3.pdf',
			'MISSING P4: Archiv/_Gelöscht/4.pdf',
			'DELETE P5: Archiv/_Gelöscht/5.pdf',
			'REMOVE EXCLUDED P6: Archiv/6.pdf',
			'IMPORT REJECTED: Eingang/notes.bin: File type not supported',
			'IMPORT ERROR: Eingang/broken.pdf -> Fehler/broken.pdf',
			'ERROR: P7: Paperless is unavailable',
		], array_slice($report->actions, 93), 'The lines stay in the order of the run.');
		self::assertSame('IMPORT: Eingang/scan93.pdf', $report->actions[92], 'The last imports give up their places.');
	}

	public function testLineGivesUpTheLastLineOfTheLowestPriority(): void {
		$report = new SyncReport(false);
		$report->upload('Eingang/a.pdf');
		foreach (range(1, SyncReport::ACTION_LIMIT - 2) as $id) {
			$report->change(SyncReport::TYPE_TRASH, $id, "Archiv/_Gelöscht/{$id}.pdf");
		}
		$report->upload('Eingang/b.pdf');

		$report->change(SyncReport::TYPE_DELETE, 200, 'Archiv/200.pdf');
		self::assertSame('IMPORT: Eingang/a.pdf', $report->actions[0]);
		self::assertNotContains('IMPORT: Eingang/b.pdf', $report->actions);

		$report->change(SyncReport::TYPE_DELETE, 201, 'Archiv/201.pdf');
		self::assertNotContains('IMPORT: Eingang/a.pdf', $report->actions);

		$report->change(SyncReport::TYPE_DELETE, 202, 'Archiv/202.pdf');
		self::assertNotContains('TRASH P98: Archiv/_Gelöscht/98.pdf', $report->actions);
		self::assertSame(['TRASH P97: Archiv/_Gelöscht/97.pdf', 'DELETE P200: Archiv/200.pdf', 'DELETE P201: Archiv/201.pdf', 'DELETE P202: Archiv/202.pdf'], array_slice($report->actions, -4));
		self::assertSame(3, $report->omittedActions);
	}

	/** Every other line takes the place of a file set aside, so that a folder of videos hides no change; the count stays. */
	public function testFilesSetAsideGiveWayToEveryOtherLine(): void {
		$report = new SyncReport(false);
		$report->fileSetAside('Eingang/first.mp4', 'video');
		for ($file = 1; $file < SyncReport::ACTION_LIMIT; ++$file) {
			$report->fileSetAside("Eingang/{$file}.mp4", 'video');
		}

		$report->upload('Eingang/scan.pdf');
		$report->change(SyncReport::TYPE_EXPORT, 1, 'Archiv/a.pdf');

		self::assertCount(SyncReport::ACTION_LIMIT, $report->actions);
		self::assertSame('IMPORT: Eingang/scan.pdf', $report->actions[98]);
		self::assertSame('EXPORT P1: Archiv/a.pdf', $report->actions[99]);
		self::assertSame('IMPORT SET ASIDE: Eingang/first.mp4: video', $report->actions[0]);
		self::assertSame(SyncReport::ACTION_LIMIT, $report->setAside);
		self::assertSame(2, $report->omittedActions);
	}

	/** A full report leaves out a file set aside, and counts it. */
	public function testAFullReportLeavesOutAFileSetAside(): void {
		$report = new SyncReport(false);
		for ($document = 1; $document <= SyncReport::ACTION_LIMIT; ++$document) {
			$report->change(SyncReport::TYPE_EXPORT, $document, "Archiv/{$document}.pdf");
		}

		$report->fileSetAside('Eingang/film.mp4', 'video');

		self::assertNotContains('IMPORT SET ASIDE: Eingang/film.mp4: video', $report->actions);
		self::assertSame(1, $report->setAside);
		self::assertSame(1, $report->omittedActions);
	}

	public function testLineOfALowerPriorityNeverTakesThePlaceOfAnother(): void {
		$report = new SyncReport(false);
		foreach (range(1, SyncReport::ACTION_LIMIT) as $id) {
			$report->error("P{$id}: Paperless is unavailable");
		}

		$report->change(SyncReport::TYPE_DELETE, 200, 'Archiv/200.pdf');
		$report->error('P201: Paperless is unavailable');

		self::assertCount(SyncReport::ACTION_LIMIT, $report->actions);
		self::assertNotContains('DELETE P200: Archiv/200.pdf', $report->actions);
		self::assertSame('ERROR: P100: Paperless is unavailable', $report->actions[99]);
		self::assertSame(2, $report->omittedActions);
		self::assertSame(101, $report->errors);
	}

	public function testNoticesStayPastTheLimitAndKeepTheirPlaces(): void {
		$report = new SyncReport(true);
		$report->notice(SyncReport::TYPE_SCAN_INCOMPLETE, 'SCAN INCOMPLETE: Paperless answered incompletely.');
		foreach (range(1, SyncReport::ACTION_LIMIT) as $number) {
			$report->upload("Eingang/scan{$number}.pdf");
		}
		$report->notice(SyncReport::TYPE_EXPORT_STOPPED, 'EXPORT STOPPED: Nextcloud cannot store files in the archive.');

		$report->error('P1: Not enough free space');

		self::assertCount(SyncReport::ACTION_LIMIT + 2, $report->actions, 'Notices take no place of a change.');
		self::assertSame('SCAN INCOMPLETE: Paperless answered incompletely.', $report->actions[0]);
		self::assertSame(['EXPORT STOPPED: Nextcloud cannot store files in the archive.', 'ERROR: P1: Not enough free space'], array_slice($report->actions, -2));
		self::assertNotContains('IMPORT: Eingang/scan100.pdf', $report->actions);
		self::assertSame(1, $report->omittedActions);
	}

	public function testKeepsTheFirstErrorMessagesAndCountsThemAll(): void {
		$report = new SyncReport(false);

		foreach (range(1, SyncReport::ERROR_LIMIT + 10) as $id) {
			$report->error("P{$id}: Paperless is unavailable");
		}

		self::assertSame(SyncReport::ERROR_LIMIT + 10, $report->errors);
		self::assertCount(SyncReport::ERROR_LIMIT, $report->errorMessages);
		self::assertSame('P1: Paperless is unavailable', $report->errorMessages[0]);
		self::assertSame('P50: Paperless is unavailable', $report->errorMessages[49]);
		self::assertSame('ERROR: P60: Paperless is unavailable', $report->actions[59], 'The list of changes has every error that fits.');
	}

	public function testJsonHasTheErrorsAndTheCountOfOmittedChangesButNoInternals(): void {
		$report = new SyncReport(true, 1000, 1060);
		$report->error('P1: Paperless is unavailable');
		foreach (range(1, SyncReport::ACTION_LIMIT) as $id) {
			$report->change(SyncReport::TYPE_EXPORT, $id, "Archiv/{$id}.pdf");
		}

		$data = $report->jsonSerialize();
		$summary = $report->summary();

		self::assertSame(['P1: Paperless is unavailable'], $data['errorMessages']);
		self::assertSame(1, $data['omittedActions']);
		self::assertTrue($data['dryRun']);
		self::assertSame(1060, $data['completedAt']);
		self::assertArrayNotHasKey('priorities', $data);
		self::assertArrayNotHasKey('notices', $data);
		self::assertSame(1, $summary['omittedActions']);
		self::assertSame(1, $summary['errors']);
		self::assertArrayNotHasKey('actions', $summary);
		self::assertArrayNotHasKey('errorMessages', $summary);
		self::assertSame(json_encode($data, JSON_THROW_ON_ERROR), json_encode($report, JSON_THROW_ON_ERROR));
	}

	public function testActivityNamesTheFirstChangedFilesAndCountsThemAll(): void {
		$report = new SyncReport(false);

		foreach (range(1, SyncReport::ACTIVITY_FILE_LIMIT + 2) as $id) {
			$report->changedFile("Archiv/{$id}.pdf");
		}

		self::assertSame(['Archiv/1.pdf', 'Archiv/2.pdf', 'Archiv/3.pdf', 'Archiv/4.pdf', 'Archiv/5.pdf'], $report->changedFiles());
		self::assertSame(SyncReport::ACTIVITY_FILE_LIMIT + 2, $report->changedFileCount());
	}

	public function testActivityKeepsEveryImportResult(): void {
		$report = new SyncReport(false);

		$report->importResult(SyncReport::IMPORTED, 'Eingang/scan.pdf', 123);
		$report->importResult(SyncReport::IMPORT_FAILED, 'Fehler/broken.pdf', null, 'Unsupported mime type');
		$report->importResult(SyncReport::IMPORT_REJECTED, 'Eingang/notes.bin', null, 'File type not supported');

		self::assertSame([
			['outcome' => 'imported', 'path' => 'Eingang/scan.pdf', 'document' => 123, 'reason' => ''],
			['outcome' => 'failed', 'path' => 'Fehler/broken.pdf', 'document' => null, 'reason' => 'Unsupported mime type'],
			['outcome' => 'rejected', 'path' => 'Eingang/notes.bin', 'document' => null, 'reason' => 'File type not supported'],
		], $report->importResults());
	}

	public function testDryRunChangesNoFileForTheActivity(): void {
		$report = new SyncReport(true);

		$report->changedFile('Archiv/1.pdf');
		$report->importResult(SyncReport::IMPORTED, 'Eingang/scan.pdf', 123);

		self::assertSame([], $report->changedFiles());
		self::assertSame(0, $report->changedFileCount());
		self::assertSame([], $report->importResults());
	}

	public function testWhatTheActivityShowsStaysOutOfTheStatus(): void {
		$report = new SyncReport(false);
		$report->changedFile('Archiv/1.pdf');
		$report->importResult(SyncReport::IMPORTED, 'Eingang/scan.pdf', 123);

		$data = $report->jsonSerialize();

		self::assertArrayNotHasKey('changedFiles', $data);
		self::assertArrayNotHasKey('changedFileCount', $data);
		self::assertArrayNotHasKey('importResults', $data);
	}
}
