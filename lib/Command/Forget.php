<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Command;

use OCA\PaperlessSync\Service\SyncService;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_sync:forget: the next runs download the documents again and write each in place of
 * its copy, such as after a copy was damaged, or after an incident of the storage for all of them.
 */
final class Forget extends Base {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private SyncService $syncService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_sync:forget')
			->setDescription('Forget the content of copies, so that the next runs export the documents again')
			->setHelp(<<<'HELP'
				The next runs download the documents again, batch by batch, and write each in place
				of its copy: the copy keeps its path, its file ID, its shares and its versions, and
				the version before keeps its content. A copy that someone edited in Nextcloud gets the
				content of Paperless again.

				Exit codes: 0 every document was forgotten, 1 some of them have no copy, 2 the
				command failed, 3 a run is active.
				HELP)
			->addArgument('documents', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The IDs of the documents in Paperless, such as 123 or P123')
			->addOption('all', null, InputOption::VALUE_NONE, 'Forget the content of every copy');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		/** @var list<string> $arguments */
		$arguments = $input->getArgument('documents');
		$all = $input->getOption('all') === true;
		if ($all === ($arguments !== [])) {
			$this->error($output, 'Name the IDs of the documents, or use --all.');
			return self::FAILED;
		}
		$documentIds = [];
		foreach ($arguments as $argument) {
			$documentId = filter_var(preg_replace('/^P/i', '', $argument), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($documentId === false) {
				$this->error($output, "{$argument} is no ID of a document, such as 123 or P123.");
				return self::FAILED;
			}
			$documentIds[] = $documentId;
		}
		$documentIds = array_values(array_unique($documentIds));

		try {
			$forgotten = $this->syncService->forget($all ? null : $documentIds);
		} catch (LockedException) {
			$this->error($output, 'A synchronization run is active. Try again when it has ended: occ paperless_sync:status');
			return self::LOCKED;
		} catch (\Throwable $exception) {
			$this->error($output, 'Forgetting failed: ' . $exception->getMessage());
			return self::FAILED;
		}

		$names = static fn (array $ids): string => implode(', ', array_map(static fn (int $id): string => "P{$id}", $ids));
		$this->line($output, match (true) {
			$forgotten === [] => 'No copy to forget.',
			// Every copy may be many thousands.
			$all => sprintf('The next runs export %d document(s) again.', count($forgotten)),
			default => sprintf('The next runs export %d document(s) again: %s.', count($forgotten), $names($forgotten)),
		});
		$unknown = array_values(array_diff($documentIds, $forgotten));
		if ($unknown !== []) {
			$this->error($output, 'Paperless Sync has no copy of ' . $names($unknown) . '.');
			return self::ERRORS;
		}

		return self::OK;
	}
}
