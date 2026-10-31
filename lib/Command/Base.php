<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the occ commands of the app share: the option --output of Nextcloud's own commands, plain
 * for people and json or json_pretty for scripts, and the report of a run as both read it.
 */
abstract class Base extends Command {
	/** The run completed, or the command did what it was asked. */
	public const OK = 0;
	/** The run completed with errors of single documents or files. */
	public const ERRORS = 1;
	/** The run failed or did not start, such as for an invalid option or an incomplete configuration. */
	public const FAILED = 2;
	/** Another run is active and holds the lock. */
	public const LOCKED = 3;

	/** The counts of a report, by their key, with their labels on the settings page. */
	private const COUNTS = [
		'exported' => 'Exported',
		'moved' => 'Moved',
		'movedToTrash' => 'Moved to trash',
		'permanentlyDeleted' => 'Deleted',
		'removedExcluded' => 'Excluded copies removed',
		'removedReassigned' => 'Copies of another user now',
		'importsSubmitted' => 'Imports submitted',
		'importsSucceeded' => 'Imports succeeded',
		'importsFailed' => 'Imports failed',
		'setAside' => 'Set aside',
		'foldersPruned' => 'Folders removed',
		'tagged' => 'Tags changed',
		'unchanged' => 'Unchanged',
		'skipped' => 'Skipped',
		'errors' => 'Errors',
	];

	protected function addOutputOption(): void {
		$this->addOption('output', null, InputOption::VALUE_REQUIRED, 'The format of the output: plain, json or json_pretty', 'plain');
	}

	/**
	 * The format that --output asks for, or null after it said that the format is unknown.
	 *
	 * @return 'plain'|'json'|'json_pretty'|null
	 */
	protected function outputFormat(InputInterface $input, OutputInterface $output): ?string {
		/** @var string $format an option with a required value and a default */
		$format = $input->getOption('output');
		if ($format === 'plain' || $format === 'json' || $format === 'json_pretty') {
			return $format;
		}
		$this->error($output, 'The output format must be plain, json or json_pretty.');

		return null;
	}

	/** @param array<array-key, mixed> $data */
	protected function writeJson(OutputInterface $output, array $data, string $format): void {
		$flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
		$output->writeln(json_encode($data, $format === 'json_pretty' ? $flags | JSON_PRETTY_PRINT : $flags), OutputInterface::OUTPUT_RAW);
	}

	/**
	 * The counts of a report, its lines of changes with the count of those that it leaves out, and
	 * its errors with the count of those that it doesn't keep, as text.
	 *
	 * @param array<array-key, mixed> $report
	 */
	protected function writeReport(OutputInterface $output, array $report): void {
		$width = max(array_map('mb_strlen', self::COUNTS));
		foreach (self::COUNTS as $key => $label) {
			$this->line($output, sprintf('  %s  %d', str_pad($label, $width), self::integer($report[$key] ?? 0)));
		}
		/** @psalm-suppress MixedAssignment the report as JSON, whose counts of each user are checked one by one */
		$users = $report['users'] ?? null;
		if (is_array($users) && $users !== []) {
			$this->line($output, 'By user:');
			/** @psalm-suppress MixedAssignment */
			foreach ($users as $user => $counts) {
				$changes = [];
				foreach (self::COUNTS as $key => $label) {
					$count = is_array($counts) ? self::integer($counts[$key] ?? 0) : 0;
					if ($count > 0) {
						$changes[] = "{$label} {$count}";
					}
				}
				$this->line($output, sprintf('  %s  %s', (string)$user, $changes !== [] ? implode(', ', $changes) : 'No changes'));
			}
		}
		$actions = self::lines($report['actions'] ?? []);
		$omitted = self::integer($report['omittedActions'] ?? 0);
		if ($actions !== []) {
			$this->line($output, 'Changes:');
			foreach ($actions as $action) {
				$this->line($output, '  ' . $action);
			}
			if ($omitted > 0) {
				$this->line($output, sprintf('  … and %d more, which the list leaves out', $omitted));
			}
		}
		$errors = self::lines($report['errorMessages'] ?? []);
		if ($errors !== []) {
			$this->line($output, 'Errors:');
			foreach ($errors as $error) {
				$this->line($output, '  ' . $error);
			}
			$more = self::integer($report['errors'] ?? 0) - count($errors);
			if ($more > 0) {
				$this->line($output, sprintf('  … and %d more', $more));
			}
		}
	}

	/** A line as it is: a name of a file may have characters that Symfony takes for its tags. */
	protected function line(OutputInterface $output, string $text): void {
		$output->writeln($text, OutputInterface::OUTPUT_RAW);
	}

	/** A message about a failure, on standard error, where a script that reads the output doesn't take it for data. */
	protected function error(OutputInterface $output, string $message): void {
		$target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
		$target->writeln($message, OutputInterface::OUTPUT_RAW);
	}

	private static function integer(mixed $value): int {
		return is_int($value) ? $value : 0;
	}

	/** @return list<string> */
	private static function lines(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
