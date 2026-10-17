<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Command;

use OCA\PaperlessSync\Service\SyncService;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_sync:run: a run or a dry-run now, in the shell, as long as it takes. Its exit code
 * says how it ended, for scripts and for cron: 0 completed, 1 completed with errors, 2 failed, and
 * 3 when another run is active.
 */
final class Run extends Base {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private SyncService $syncService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_sync:run')
			->setDescription('Synchronize Paperless and Nextcloud now, or list the changes of a run with --dry-run')
			->setHelp(<<<'HELP'
				A run makes at most as many changes as the batch size of the settings, as a scheduled
				run does; --batch-size or --unlimited change that for this run, such as for the first
				import of a large archive. A run without a limit can take hours; while it works, it
				gives a sign of life every minute, which keeps scheduled runs and those of
				"Synchronize now" from starting beside it, also after Nextcloud let its lock expire.

				Exit codes: 0 the run completed, 1 it completed with errors of single documents or
				files, 2 it failed or did not start, 3 another run is active.
				HELP)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the changes of a run without making them')
			->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'The most changes of this run, in place of the batch size of the settings')
			->addOption('unlimited', null, InputOption::VALUE_NONE, 'Make every change that is due, without a batch size');
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = $this->outputFormat($input, $output);
		if ($format === null) {
			return self::FAILED;
		}
		$dryRun = $input->getOption('dry-run') === true;
		$batchSize = $this->batchSize($input, $output);
		if ($batchSize === 0) {
			return self::FAILED;
		}
		try {
			$report = $this->syncService->run($dryRun, batchSize: $batchSize);
		} catch (LockedException) {
			$this->error($output, 'Another synchronization run is already active. Try again when it has ended: occ paperless_sync:status');
			return self::LOCKED;
		} catch (\Throwable $exception) {
			$this->error($output, 'The synchronization failed: ' . $exception->getMessage());
			return self::FAILED;
		}

		if ($format !== 'plain') {
			$this->writeJson($output, $report->jsonSerialize(), $format);
		} else {
			$this->line($output, match (true) {
				$report->dryRun && $report->errors > 0 => "Dry-run completed with {$report->errors} error(s). No files were changed.",
				$report->dryRun => 'Dry-run completed. No files were changed.',
				$report->errors > 0 => "Synchronization completed with {$report->errors} error(s).",
				default => 'Synchronization completed.',
			});
			$this->writeReport($output, $report->jsonSerialize());
		}

		return $report->errors > 0 ? self::ERRORS : self::OK;
	}

	/**
	 * The batch size of this run: null for that of the settings, PHP_INT_MAX without a limit, and 0
	 * after it said what is wrong with the options.
	 */
	private function batchSize(InputInterface $input, OutputInterface $output): ?int {
		/** @var string|null $value an option with a required value, without a default */
		$value = $input->getOption('batch-size');
		$unlimited = $input->getOption('unlimited') === true;
		if ($value !== null && $unlimited) {
			$this->error($output, 'Use either --batch-size or --unlimited.');
			return 0;
		}
		if ($unlimited) {
			return PHP_INT_MAX;
		}
		if ($value === null) {
			return null;
		}
		$batchSize = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($batchSize === false) {
			$this->error($output, 'The batch size must be a whole number of at least 1.');
			return 0;
		}

		return $batchSize;
	}
}
