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
	public function testListsTheChangesInTheirOrder(): void {
		$report = new SyncReport(true);

		$report->action('IMPORT: Eingang/a.pdf');
		$report->action('TRASH P2: Archiv/_Gelöscht/b.pdf');
		$report->action('EXPORT P1: Archiv/a.pdf');

		self::assertSame(['IMPORT: Eingang/a.pdf', 'TRASH P2: Archiv/_Gelöscht/b.pdf', 'EXPORT P1: Archiv/a.pdf'], $report->actions);
		self::assertSame(0, $report->omittedActions);
	}

	public function testFullReportCountsTheChangesThatItLeavesOut(): void {
		$report = new SyncReport(true);

		foreach (range(1, SyncReport::ACTION_LIMIT + 5) as $id) {
			$report->action("EXPORT P{$id}: Archiv/{$id}.pdf");
		}

		self::assertCount(SyncReport::ACTION_LIMIT, $report->actions);
		self::assertSame('EXPORT P100: Archiv/100.pdf', $report->actions[99], 'The first changes stay.');
		self::assertSame(5, $report->omittedActions);
	}

	public function testErrorsAndDeletionsNeverHideBehindImports(): void {
		$report = new SyncReport(true);
		foreach (range(1, SyncReport::ACTION_LIMIT) as $number) {
			$report->action("IMPORT: Eingang/scan{$number}.pdf");
		}

		$report->action('EXPORT P1: Archiv/1.pdf');
		$report->action('WAIT P2: missing run 1/3');
		$report->action('TRASH P3: Archiv/_Gelöscht/3.pdf');
		$report->action('MISSING P4: Archiv/_Gelöscht/4.pdf');
		$report->action('DELETE P5: Archiv/_Gelöscht/5.pdf');
		$report->action('REMOVE EXCLUDED P6: Archiv/6.pdf');
		$report->action('IMPORT REJECTED: Eingang/notes.bin: File type not supported');
		$report->action('IMPORT ERROR: Eingang/broken.pdf -> Fehler/broken.pdf');
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
		$report->action('IMPORT: Eingang/a.pdf');
		foreach (range(1, SyncReport::ACTION_LIMIT - 2) as $id) {
			$report->action("TRASH P{$id}: Archiv/_Gelöscht/{$id}.pdf");
		}
		$report->action('IMPORT: Eingang/b.pdf');

		$report->action('DELETE P200: Archiv/200.pdf');
		self::assertSame('IMPORT: Eingang/a.pdf', $report->actions[0]);
		self::assertNotContains('IMPORT: Eingang/b.pdf', $report->actions);

		$report->action('DELETE P201: Archiv/201.pdf');
		self::assertNotContains('IMPORT: Eingang/a.pdf', $report->actions);

		$report->action('DELETE P202: Archiv/202.pdf');
		self::assertNotContains('TRASH P98: Archiv/_Gelöscht/98.pdf', $report->actions);
		self::assertSame(['TRASH P97: Archiv/_Gelöscht/97.pdf', 'DELETE P200: Archiv/200.pdf', 'DELETE P201: Archiv/201.pdf', 'DELETE P202: Archiv/202.pdf'], array_slice($report->actions, -4));
		self::assertSame(3, $report->omittedActions);
	}

	public function testLineOfALowerPriorityNeverTakesThePlaceOfAnother(): void {
		$report = new SyncReport(false);
		foreach (range(1, SyncReport::ACTION_LIMIT) as $id) {
			$report->error("P{$id}: Paperless is unavailable");
		}

		$report->action('DELETE P200: Archiv/200.pdf');
		$report->error('P201: Paperless is unavailable');

		self::assertCount(SyncReport::ACTION_LIMIT, $report->actions);
		self::assertNotContains('DELETE P200: Archiv/200.pdf', $report->actions);
		self::assertSame('ERROR: P100: Paperless is unavailable', $report->actions[99]);
		self::assertSame(2, $report->omittedActions);
		self::assertSame(101, $report->errors);
	}

	public function testNoticesStayPastTheLimitAndKeepTheirPlaces(): void {
		$report = new SyncReport(true);
		$report->notice('SCAN INCOMPLETE: Paperless answered incompletely.');
		foreach (range(1, SyncReport::ACTION_LIMIT) as $number) {
			$report->action("IMPORT: Eingang/scan{$number}.pdf");
		}
		$report->notice('EXPORT STOPPED: Nextcloud cannot store files in the archive.');

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
			$report->action("EXPORT P{$id}: Archiv/{$id}.pdf");
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
}
