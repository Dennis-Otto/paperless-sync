<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Command;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\StatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_sync:status: the status of the last run, as the settings page shows it, for people
 * and, with --output=json, for scripts and monitoring; --exit-code tells how it ended by the code.
 */
final class Status extends Base {
	/** The labels of the states, as the settings page shows them. */
	private const STATES = [
		'never-run' => 'Never run',
		'queued' => 'Queued',
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
		$status = $this->statusService->overview($this->configService->get());
		if ($format !== 'plain') {
			$this->writeJson($output, $status, $format);
		} else {
			$this->field($output, 'State', self::STATES[$status['state']] ?? $status['state']);
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

	private function field(OutputInterface $output, string $label, string $value): void {
		$this->line($output, str_pad($label . ':', 21) . $value);
	}

	private static function time(int $timestamp): string {
		return $timestamp > 0 ? date(DATE_ATOM, $timestamp) : '—';
	}
}
