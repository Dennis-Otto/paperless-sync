<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\StatusTranslation;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use PHPUnit\Framework\TestCase;

/**
 * The errors of the status and of the report in the language of the administrator who reads the
 * settings page, from the records that the run kept with its English errors.
 */
final class StatusTranslationTest extends TestCase {
	use GermanTranslations;

	/** The error of a failed run and the errors of its report, from the JSON that the status kept. */
	public function testTheStatusInGerman(): void {
		$report = new SyncReport(false);
		$report->error(new RunError('Excluded P%1$s: %2$s', ['7', RunError::plain('The file is locked')]), 7, 'Archiv/a.pdf');
		$report->error('Paperless timed out');
		$kept = self::kept($report);
		$status = [
			'state' => 'failed',
			'error' => 'Nextcloud user paperless does not exist.',
			'errorRecord' => self::kept(new RunError('Nextcloud user %s does not exist.', ['paperless'], 'target_user')),
			'report' => $kept,
		];

		$translated = StatusTranslation::status($status, $this->germanL10n());

		self::assertSame(['state', 'error', 'report'], array_keys($translated), 'The answer says each error once, without its record.');
		self::assertSame('Den Nextcloud-Benutzer paperless gibt es nicht.', $translated['error']);
		$translatedReport = $translated['report'];
		self::assertIsArray($translatedReport);
		self::assertSame(['Ausgeschlossen P7: The file is locked', 'Paperless timed out'], $translatedReport['errorMessages']);
		self::assertSame([
			['type' => 'error', 'document' => 7, 'path' => 'Archiv/a.pdf', 'detail' => 'Ausgeschlossen P7: The file is locked'],
			['type' => 'error', 'detail' => 'Paperless timed out'],
		], $translatedReport['entries']);
		unset($kept['errorRecords'], $kept['errorMessages'], $kept['entries'], $translatedReport['errorMessages'], $translatedReport['entries']);
		self::assertSame($kept, $translatedReport, 'The rest of the report stays as the run kept it.');
		self::assertSame(['Excluded P7: The file is locked', 'Paperless timed out'], $report->errorMessages, 'The run keeps its errors in English.');
	}

	/**
	 * A status of an earlier version kept its errors in English alone, and so did a run that a
	 * record is missing for: they still show, as they are.
	 */
	public function testErrorsWithoutTheirRecordsStayAsTheyAre(): void {
		$status = [
			'state' => 'completed-with-errors',
			'error' => '',
			'errorRecord' => [],
			'report' => [
				'errorMessages' => ['[alice] Archive of alice: gone', 'P8: Paperless timed out'],
				'errorRecords' => [self::kept(new RunError('[%1$s] %2$s', ['alice', RunError::plain('Archive of alice: gone')]))],
				'entries' => [
					['type' => 'error', 'document' => 8, 'detail' => 'P8: Paperless timed out'],
					['type' => 'error', 'detail' => 'P9: lost', 'error' => 'not a record'],
					'not an entry',
				],
			],
		];

		$translated = StatusTranslation::status($status, $this->germanL10n());

		self::assertSame([
			'state' => 'completed-with-errors',
			'error' => '',
			'report' => [
				'errorMessages' => ['[alice] Archive of alice: gone', 'P8: Paperless timed out'],
				'entries' => [
					['type' => 'error', 'document' => 8, 'detail' => 'P8: Paperless timed out'],
					['type' => 'error', 'detail' => 'P9: lost'],
					'not an entry',
				],
			],
		], $translated);
		self::assertSame(['actions' => []], StatusTranslation::report(['actions' => []], $this->germanL10n()), 'A report without errors stays as it is.');
	}

	/** The answer of a dry-run, as SyncReport gives it, with the errors of each user of per-user ownership. */
	public function testAReportInGerman(): void {
		$user = new SyncReport(true);
		$user->error(new RunError('Import %1$s: %2$s', ['Eingang/a.pdf', new RunError('Nextcloud user %s does not exist.', ['alice'])]), null, 'Eingang/a.pdf');
		$run = new SyncReport(true);
		$run->absorb($user, 'alice');

		$translated = StatusTranslation::report($run->jsonSerialize(), $this->germanL10n());

		self::assertSame(['[alice] Import Eingang/a.pdf: Den Nextcloud-Benutzer alice gibt es nicht.'], $translated['errorMessages']);
		self::assertSame([['type' => 'error', 'path' => 'Eingang/a.pdf', 'detail' => 'Import Eingang/a.pdf: Den Nextcloud-Benutzer alice gibt es nicht.', 'user' => 'alice']], $translated['entries']);
		self::assertArrayNotHasKey('errorRecords', $translated);
		self::assertSame(['[alice] Import Eingang/a.pdf: Nextcloud user alice does not exist.'], $run->errorMessages, 'The report itself stays English, as the log and the command line show it.');
	}

	/** @return array<string, mixed> the value as the status keeps it, in JSON */
	private static function kept(\JsonSerializable $value): array {
		/** @var array<string, mixed> */
		return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}
}
