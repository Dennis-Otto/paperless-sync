<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Activity;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCP\Activity\IManager;
use Psr\Log\LoggerInterface;

/**
 * Tells the target user in the activity stream of Nextcloud what a run did with the files: one entry
 * for the changes of the run, which names its first files, and one for each import that Paperless
 * finished, failed or refused. The entries store the IDs and paths of the files, so that their links
 * lead to the files also after later moves; Provider puts them into words in the language of the
 * reader.
 */
final class ActivityPublisher {
	/** The type of the entries, which the activity settings and the filter of the app name. */
	public const TYPE = AppConstants::APP_ID;
	public const SUBJECT_RUN = 'run';
	public const SUBJECT_IMPORTED = 'imported';
	public const SUBJECT_IMPORT_FAILED = 'import_failed';
	public const SUBJECT_IMPORT_REJECTED = 'import_rejected';
	/** The longest reason of a failed import that an entry keeps, in characters. */
	public const REASON_LIMIT = 1000;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IManager $activityManager,
		private NextcloudStorageInterface $storage,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The entries of a run. A dry-run and a run that changed nothing add none, and an entry that
	 * Nextcloud refuses, such as for a full database, is logged without failing the run, whose
	 * changes are done.
	 *
	 * @param string $author the user who started the run by hand, whom the entries name, such as the
	 *                       administrator of "Synchronize now", whose background job has no session
	 *                       that Nextcloud could take the name from; empty for a scheduled run
	 */
	public function publish(SyncConfig $config, SyncReport $report, string $author = ''): void {
		if ($report->dryRun) {
			return;
		}
		try {
			$this->publishRun($config, $report, $author);
			foreach ($report->importResults() as $result) {
				$this->publishImport($config, $result, $author);
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Paperless Sync could not add the changes of its run to the activity stream: {error}', [
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	private function publishRun(SyncConfig $config, SyncReport $report, string $author): void {
		$counts = [
			'exported' => $report->exported,
			'moved' => $report->moved,
			'trashed' => $report->movedToTrash,
			'deleted' => $report->permanentlyDeleted,
			'removed' => $report->removedExcluded,
			'reassigned' => $report->removedReassigned,
			'submitted' => $report->importsSubmitted,
		];
		if (array_sum($counts) === 0) {
			return;
		}
		$archive = $this->join($config->basePath, $config->archiveFolder);
		$files = [];
		foreach ($report->changedFiles() as $path) {
			$id = $this->storage->findFileId($config->targetUser, $path);
			// A file that is gone again, such as one that a later step of the run removed, has no link.
			if ($id !== null) {
				$files[] = ['id' => $id, 'path' => $path];
			}
		}
		$parameters = $counts + [
			'files' => $files,
			'more' => $report->changedFileCount() - count($files),
		];
		if ($report->movedToTrash > 0) {
			$parameters['trash'] = $this->reference($config, $this->join($archive, $config->deletedFolder));
		}
		// The entry belongs to the folder whose files changed: the archive, or the inbox when the
		// run only sent files to Paperless.
		$folder = $report->importsSubmitted === array_sum($counts)
			? $this->join($config->basePath, $config->inboxFolder)
			: $archive;
		$parameters['folder'] = $this->reference($config, $folder);
		$this->send($config, self::SUBJECT_RUN, $parameters, $parameters['folder'], $author);
	}

	/** @param array{outcome: SyncReport::IMPORT*, path: string, document: int|null, reason: string} $result */
	private function publishImport(SyncConfig $config, array $result, string $author): void {
		$file = $this->reference($config, $result['path']);
		if ($result['outcome'] === SyncReport::IMPORTED) {
			$this->send($config, self::SUBJECT_IMPORTED, ['file' => $file, 'document' => $result['document']], $file, $author);
			return;
		}
		$subject = $result['outcome'] === SyncReport::IMPORT_FAILED ? self::SUBJECT_IMPORT_FAILED : self::SUBJECT_IMPORT_REJECTED;
		$this->send($config, $subject, ['file' => $file, 'reason' => mb_substr($result['reason'], 0, self::REASON_LIMIT)], $file, $author);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @param array{id: int, path: string} $object the file or folder of the entry, whose sidebar in Files shows it; ID 0 for one that is gone
	 */
	private function send(SyncConfig $config, string $subject, array $parameters, array $object, string $author): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp(AppConstants::APP_ID)
			->setType(self::TYPE)
			->setAffectedUser($config->targetUser)
			->setSubject($subject, $parameters);
		// Without an author, Nextcloud names the user of the session, if there is one.
		if ($author !== '') {
			$event->setAuthor($author);
		}
		if ($object['id'] > 0) {
			$event->setObject('files', $object['id'], $object['path']);
		}
		$this->activityManager->publish($event);
	}

	/** @return array{id: int, path: string} the file or folder at the path, with ID 0 when it is gone */
	private function reference(SyncConfig $config, string $path): array {
		return ['id' => $this->storage->findFileId($config->targetUser, $path) ?? 0, 'path' => $path];
	}

	private function join(string ...$parts): string {
		return implode('/', array_filter(array_map(static fn (string $part): string => trim($part, '/'), $parts), static fn (string $part): bool => $part !== ''));
	}
}
