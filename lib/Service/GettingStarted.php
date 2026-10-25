<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IL10N;

/**
 * The getting-started checklist of the settings page: the five steps that lead a new installation
 * to its first successful run, in their safe order, each done or not as the server stores it, and
 * how long the first synchronization takes. The page shows it until a run, not a dry-run, has
 * succeeded once, and on request after that.
 *
 * Each step is done, next, the first one that is not done, or to do. A step counts on its own, so
 * a step that is done stays done when an earlier one is not, such as the dry-run after Disconnect.
 *
 * @psalm-type Step = array{id: string, state: 'done'|'next'|'todo'}
 * @psalm-type Progress = array{complete: bool, steps: list<Step>, estimate: string}
 */
final class GettingStarted {
	/** The URL of Paperless and its token are saved, and the connection checklist passed with them. */
	public const CONNECT = 'connect';
	/** The target user is saved, who gets the documents, or with per-user ownership those without a user. */
	public const OWNER = 'owner';
	/** A dry-run completed, whose report the page keeps. */
	public const DRY_RUN = 'dry-run';
	/** Scheduled synchronization is on. */
	public const SCHEDULE = 'schedule';
	/** A run, not a dry-run, succeeded. */
	public const FIRST_RUN = 'first-run';

	/** The minutes up to which the estimate counts in minutes, and the hours up to which in hours. */
	private const MINUTES_UP_TO = 120;
	private const HOURS_UP_TO = 48;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private StatusService $status,
		private IL10N $l10n,
	) {
	}

	/**
	 * The steps for the settings and the status, as StatusService::overview() gives it.
	 *
	 * @param array{state: string, lastCompleted: int, lastSuccess: int, summary: array<string, mixed>, ...<string, mixed>} $status
	 * @return Progress
	 */
	public function progress(SyncConfig $config, array $status): array {
		$dryRun = $this->status->lastDryRun();
		if ($dryRun['ended'] === 0 && $status['state'] === 'dry-run-completed') {
			// A dry-run of an earlier version, which kept no mark of its own: the last run was one.
			$documents = isset($status['summary']['activeDocuments']) && is_int($status['summary']['activeDocuments']) ? $status['summary']['activeDocuments'] : 0;
			$dryRun = ['ended' => $status['lastCompleted'], 'documents' => $documents];
		}
		$done = [
			// SettingsController::save() saves a token whose checklist failed only for one module,
			// such as a missing permission for the tags, so the step needs the mark of a checklist in
			// which no line failed, with the saved URL: a save, a test or the setup of the account
			// keeps it.
			self::CONNECT => $config->paperlessUrl !== '' && $config->tokenConfigured && $this->status->connectionPassed($config->paperlessUrl),
			self::OWNER => $config->targetUser !== '',
			self::DRY_RUN => $dryRun['ended'] > 0,
			self::SCHEDULE => $config->enabled,
			self::FIRST_RUN => $status['lastSuccess'] > 0,
		];
		$steps = [];
		$found = false;
		foreach ($done as $id => $isDone) {
			$state = $isDone ? 'done' : ($found ? 'todo' : 'next');
			$found = $found || !$isDone;
			$steps[] = ['id' => $id, 'state' => $state];
		}

		return [
			'complete' => $done[self::FIRST_RUN],
			'steps' => $steps,
			'estimate' => $this->estimate($config, $dryRun),
		];
	}

	/**
	 * How long the first synchronization takes, from the documents that the last dry-run found: a
	 * run takes up to the batch size of them, one run each interval, as docs/faq.md explains. A
	 * batch size below 1, which occ can store, counts as 1.
	 *
	 * @param array{ended: int, documents: int} $dryRun
	 */
	private function estimate(SyncConfig $config, array $dryRun): string {
		$documents = $dryRun['documents'];
		if ($documents <= 0) {
			return $dryRun['ended'] > 0
				// Paperless is empty, or the export module is off, so the dry-run counted nothing.
				? $this->l10n->t('The last dry-run found no document to mirror; the first run takes about one interval.')
				: $this->l10n->t('A dry-run counts the documents in Paperless; then this step says how long the first synchronization takes.');
		}
		$batchSize = max(1, $config->batchSize);
		$runs = intdiv($documents + $batchSize - 1, $batchSize);
		if ($runs === 1) {
			return $this->l10n->n('Paperless has %n document, which the first run mirrors.', 'Paperless has %n documents, which the first run mirrors at once.', $documents);
		}

		return $this->l10n->t('Paperless has %1$s documents: with %2$s in each run and a run every %3$s minutes, the first synchronization takes about %4$s runs, %5$s.', [
			(string)$documents,
			(string)$batchSize,
			(string)$config->syncIntervalMinutes,
			(string)$runs,
			$this->duration($runs * $config->syncIntervalMinutes),
		]);
	}

	/** About as long as these minutes, in minutes, hours or days. */
	private function duration(int $minutes): string {
		if ($minutes < self::MINUTES_UP_TO) {
			return $this->l10n->n('%n minute', '%n minutes', $minutes);
		}
		$hours = (int)round($minutes / 60);
		if ($hours < self::HOURS_UP_TO) {
			return $this->l10n->n('%n hour', '%n hours', $hours);
		}

		return $this->l10n->n('%n day', '%n days', (int)round($hours / 24));
	}
}
