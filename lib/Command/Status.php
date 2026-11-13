<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Command;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\TagShares;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_sync:status: the status of the last run, as the settings page shows it, for people
 * and, with --output=json, for scripts and monitoring; --exit-code tells how it ended by the code.
 * It also names each share of the target user that shows a user with tags more than their tags,
 * or may, which the settings page removes on request (decision 0038).
 *
 * @psalm-import-type TagShare from TagShares
 */
final class Status extends Base {
	/** The labels of the states, as the settings page shows them. */
	private const STATES = [
		'never-run' => 'Never run',
		'queued' => 'Queued',
		'stalled' => 'Not started',
		'running' => 'Running',
		'dry-run-running' => 'Dry-run running',
		'completed' => 'Completed',
		'dry-run-completed' => 'Dry-run completed',
		'completed-with-errors' => 'Completed with errors',
		'failed' => 'Failed',
		'interrupted' => 'Interrupted',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private StatusService $statusService,
		private ConfigService $configService,
		private ITimeFactory $timeFactory,
		private TagShares $tagShares,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_sync:status')
			->setDescription('Show the status of the last synchronization run')
			->addOption('exit-code', null, InputOption::VALUE_NONE, 'Exit with 1 when the last run completed with errors and with 2 when it failed or was interrupted');
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = $this->outputFormat($input, $output);
		if ($format === null) {
			return self::FAILED;
		}
		$config = $this->configService->get();
		$status = $this->statusService->overview($config);
		$shares = $this->tagShares->find($config);
		// A token that the credentials store can't decrypt, as after a restore with another secret.
		$tokenUnreadable = $this->configService->tokenUnreadable();
		if ($format !== 'plain') {
			$this->writeJson($output, $status + ['tagShares' => $shares, 'tokenUnreadable' => $tokenUnreadable], $format);
		} else {
			if ($tokenUnreadable) {
				$this->line($output, 'The stored API token of Paperless can not be read, as after a restore or a move of Nextcloud with another secret: enter it again in the settings.');
			}
			$this->field($output, 'State', self::STATES[$status['state']] ?? $status['state']);
			if ($status['state'] === 'stalled') {
				$this->line($output, 'The run of "Synchronize now" has waited for the background jobs of Nextcloud since ' . self::time($status['lastQueued']) . '. Check that they run: ' . ($status['lastBackgroundJobs'] > 0 ? 'Nextcloud last ran them at ' . self::time($status['lastBackgroundJobs']) : 'Nextcloud has never run them') . '. Or start the run with occ paperless_sync:run.');
			}
			$this->field($output, 'Last started', self::time($status['lastStarted']));
			$this->field($output, 'Last finished', self::time($status['lastEnded']));
			$this->field($output, 'Last successful run', self::time($status['lastSuccess']));
			$this->field($output, 'Next scheduled run', match (true) {
				$status['nextRun'] === null => 'off',
				$status['nextRun'] <= $this->timeFactory->getTime() => 'with the next background job',
				default => self::time($status['nextRun']),
			});
			if ($status['error'] !== '') {
				$this->field($output, 'Error', $status['error']);
			}
			if ($status['report'] !== []) {
				$this->line($output, ($status['report']['dryRun'] ?? false) === true ? 'Report of the last dry-run:' : 'Report of the last run:');
				$this->writeReport($output, $status['report']);
			}
			$this->writeShares($output, array_filter($shares, static fn (array $share): bool => $share['known']), 'These shares of ' . $config->targetUser . ' show a user with tags every document in them; remove them under "Tags that a user may see" of the settings, or in Files:');
			$this->writeShares($output, array_filter($shares, static fn (array $share): bool => !$share['known']), 'These shares of ' . $config->targetUser . ' may show a user with tags every document in them, as Nextcloud does not name who is in them; check who is, and remove those that reach a user with tags under "Tags that a user may see" of the settings, or in Files:');
		}
		if ($input->getOption('exit-code') !== true) {
			return self::OK;
		}

		return match ($status['state']) {
			'completed-with-errors' => self::ERRORS,
			'failed', 'interrupted' => self::FAILED,
			default => self::OK,
		};
	}

	/**
	 * The shares under $heading, each with its ID, or nothing without one.
	 *
	 * @param array<array-key, TagShare> $shares
	 */
	private function writeShares(OutputInterface $output, array $shares, string $heading): void {
		if ($shares === []) {
			return;
		}
		$this->line($output, $heading);
		foreach ($shares as $share) {
			$this->line($output, '  ' . TagShares::describe($share) . ' (share ' . $share['id'] . ')');
		}
	}

	private function field(OutputInterface $output, string $label, string $value): void {
		$this->line($output, str_pad($label . ':', 21) . $value);
	}

	private static function time(int $timestamp): string {
		return $timestamp > 0 ? date(DATE_ATOM, $timestamp) : '—';
	}
}
