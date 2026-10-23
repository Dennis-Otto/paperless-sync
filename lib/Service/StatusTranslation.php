<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\RunError;
use OCP\IL10N;

/**
 * The errors of the status and of the report of a run in the language of the administrator who
 * reads them on the settings page. The run keeps each error in English, with its record, its
 * RunError, which this translates; an error without one, such as one of an earlier version, which
 * kept the English text alone, or one of Paperless, stays as it is. The records leave the answer,
 * which says each error once.
 */
final class StatusTranslation {
	/**
	 * The status, as StatusService::overview() gives it, with its error and its report translated.
	 *
	 * @param array{error: string, errorRecord: array<string, mixed>, report: array<string, mixed>, ...<string, mixed>} $status
	 * @return array<string, mixed>
	 */
	public static function status(array $status, IL10N $l10n): array {
		$error = RunError::stored($status['errorRecord']);
		unset($status['errorRecord']);
		if ($error !== null) {
			$status['error'] = $error->translated($l10n);
		}
		$status['report'] = self::report($status['report'], $l10n);

		return $status;
	}

	/**
	 * A report, as the status keeps it or SyncReport::jsonSerialize() gives it, with its errors and
	 * the details of its error entries translated.
	 *
	 * @param array<array-key, mixed> $report
	 * @return array<array-key, mixed>
	 */
	public static function report(array $report, IL10N $l10n): array {
		$records = isset($report['errorRecords']) && is_array($report['errorRecords']) ? array_values($report['errorRecords']) : [];
		unset($report['errorRecords']);
		if (isset($report['errorMessages']) && is_array($report['errorMessages'])) {
			$messages = [];
			/** @psalm-suppress MixedAssignment */
			foreach (array_values($report['errorMessages']) as $index => $message) {
				$error = RunError::stored($records[$index] ?? null);
				$messages[] = $error === null ? $message : $error->translated($l10n);
			}
			$report['errorMessages'] = $messages;
		}
		if (isset($report['entries']) && is_array($report['entries'])) {
			$report['entries'] = array_map(static function (mixed $entry) use ($l10n): mixed {
				if (!is_array($entry) || !array_key_exists('error', $entry)) {
					return $entry;
				}
				$error = RunError::stored($entry['error']);
				unset($entry['error']);
				if ($error !== null) {
					$entry['detail'] = $error->translated($l10n);
				}

				return $entry;
			}, $report['entries']);
		}

		return $report;
	}
}
