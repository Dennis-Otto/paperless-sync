<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @psalm-type ExportRead = array{documents: list<array<string, mixed>>, trash: list<array<string, mixed>>|null, complete: bool, pathOf: \Closure(array<string, mixed>): string, filter: ExportFilter, sidecars: (\Closure(SyncConfig, SyncReport, string): ?MetadataSidecars)|null, tagNames: array<string, string>}
 */
final class SyncService {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessClientFactoryInterface $clients,
		private NextcloudStorageInterface $storage,
		private SyncStateRepositoryInterface $state,
		private PathTemplateService $pathTemplate,
		private StatusService $status,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private ActivityPublisher $activity,
		private UserTimeZones $timeZones,
		private NotificationPublisher $notifications,
		private SystemTagService $systemTags,
		private OwnerMapping $ownerMapping,
	) {
	}

	/**
	 * A run with the saved settings, or a dry-run with $settings, such as those of the settings page
	 * before they are saved, which ConfigService::validate() checked: a dry-run changes nothing, so
	 * it may show what settings would do before anything uses them.
	 *
	 * A run makes at most as many changes as the batch size of the settings, or as $batchSize, such
	 * as a run on the command line that imports a large archive at once. $author is the user who
	 * started a run by hand in a background job, whom its entries in the Activity stream name.
	 *
	 * A run reaches Paperless through one connection, which it takes with its settings at its
	 * start: settings that an administrator saves during the run reach the next run, not the rest
	 * of this one, so that no run records the IDs of one Paperless as those of another.
	 *
	 * @throws InvalidArgumentException when a run that is no dry-run comes with settings, or for a batch size below 1
	 * @throws LockedException while another run is active
	 */
	public function run(bool $dryRun = false, ?SyncConfig $settings = null, ?int $batchSize = null, string $author = ''): SyncReport {
		if ($settings !== null && !$dryRun) {
			throw new InvalidArgumentException('Only a dry-run can use settings that are not saved.');
		}
		if ($batchSize !== null && $batchSize < 1) {
			throw new InvalidArgumentException('The batch size must be at least 1.');
		}
		$this->lock();
		$report = new SyncReport($dryRun, $this->now(), unsavedSettings: $settings !== null);
		$this->status->started($dryRun);
		$config = null;
		try {
			[$config, $connection] = $settings === null
				? $this->configService->snapshot()
				: [$settings, $this->configService->connection($settings)];
			if (!$config->complete()) {
				throw new InvalidSettingException('Paperless Sync is not completely configured.');
			}
			if ($batchSize !== null) {
				$config = $config->withBatchSize($batchSize);
			}
			$paperless = $this->clients->connect($connection);
			$this->status->connected($paperless->testConnection());
			$this->storage->test($config->targetUser, $config->basePath);
			if (!$dryRun) {
				$this->storage->prepare($config);
			}
			if ($config->perUser()) {
				$this->syncUsers($paperless, $config, $report, $author);
			} else {
				if ($config->inboxEnabled) {
					$this->syncInbox($paperless, $config, $report);
				}
				if ($config->exportEnabled) {
					$this->syncExports($paperless, $config, $report);
				}
			}
			$report->completedAt = $this->now();
			$this->status->completed($report);
			$this->logger->info('Paperless synchronization completed', $report->summary());

			return $report;
		} catch (\Throwable $exception) {
			$this->status->failed($exception, $report);
			$this->logger->error('Paperless synchronization failed', ['exception' => $exception]);
			throw $exception;
		} finally {
			// The activity stream of the target user shows what the run changed, also when it failed
			// after its first changes; in per-user ownership, each user's shows what changed for them.
			if ($config !== null && !$config->perUser()) {
				$this->activity->publish($config, $report, $author);
			}
			// The administrators hear of a mirror that keeps failing, and the target user of the
			// imports that failed, once the status has kept how the run ended.
			$this->notifications->publish($config, $report);
			$this->lockingProvider->releaseLock(AppConstants::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * The run of one document, which the webhook of a Paperless workflow asks for when the document
	 * was added or changed: it exports, moves or removes the copy of that document by the rules of
	 * a run, with a batch of one, so that the change reaches Nextcloud within seconds instead of
	 * with the next scheduled run. It reads that document alone, and leaves the trash, the missing
	 * documents and the inbox to the scheduled runs, which see the whole of Paperless.
	 *
	 * It holds the lock of the runs but is no run of the status: the settings page keeps the last
	 * run, and the schedule counts from it, so that calls of the webhook never hold back the
	 * scheduled run that finds the trashed and deleted documents. Nor does it write signs of life,
	 * which would make an interrupted run look active. The Activity stream of the target user shows
	 * its changes, as it does those of a run, and it keeps its connection to Paperless from its
	 * start to its end, as a run does.
	 *
	 * @throws LockedException while another run is active
	 * @throws RunsOffException while the app is not completely configured, or scheduled synchronization or the export is off
	 */
	public function syncDocument(int $documentId): SyncReport {
		// Runs that are off are no failure: a document that was queued before an administrator
		// turned them off ends without an error in the log, and without taking the lock.
		[$config, $connection] = $this->configService->snapshot();
		$this->requireDocumentRuns($config);
		$this->lock();
		$report = new SyncReport(false, $this->now());
		try {
			$this->storage->test($config->targetUser, $config->basePath);
			$this->storage->prepare($config);
			// Paperless shows no document in its trash, deleted or of another owner: the scheduled
			// runs move or delete its copy, after the missing scans that the settings require, and
			// the report without a change says so (WebhookService::result()).
			$paperless = $this->clients->connect($connection);
			$document = $paperless->document($documentId, $config->writeMetadata);
			if ($document !== null && $config->perUser()) {
				$this->syncUsers($paperless, $config->withBatchSize(1), $report, '', $document);
			} elseif ($document !== null) {
				$this->syncExports($paperless, $config->withBatchSize(1), $report, $document);
			}
			$report->completedAt = $this->now();
			$this->logger->info('Paperless synchronization of document {document} completed', ['document' => $documentId] + $report->summary());

			return $report;
		} catch (\Throwable $exception) {
			$this->logger->error('Paperless synchronization of document {document} failed', ['document' => $documentId, 'exception' => $exception]);
			throw $exception;
		} finally {
			if (!$config->perUser()) {
				$this->activity->publish($config, $report);
			}
			$this->lockingProvider->releaseLock(AppConstants::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * The runs of one document need what a scheduled run needs, and they follow its switches: while
	 * an administrator keeps scheduled synchronization off, such as before the first dry-run was
	 * reviewed or while Paperless is down, or turns the export off, a call of the webhook changes
	 * nothing either.
	 *
	 * @throws RunsOffException with the setting that stops the runs of one document
	 */
	public function requireDocumentRuns(SyncConfig $config): void {
		if (!$config->complete()) {
			throw new RunsOffException('Paperless Sync is not completely configured.');
		}
		if (!$config->enabled) {
			throw new RunsOffException('Scheduled synchronization is off, so changes from Paperless wait until it is on again.', [], 'enabled');
		}
		if (!$config->exportEnabled) {
			throw new RunsOffException('The archive export is off, so changes from Paperless reach no copy.', [], 'export_enabled');
		}
	}

	/**
	 * Forgets the content of the copies of documents, of all or of those with the IDs, so that the
	 * next runs download them again and write each in place of its copy. The copies keep their
	 * paths and stay the app's own: none is left behind, none counts as a file of the user, and the
	 * versions of Nextcloud keep their content before. Only the copy of a document that Paperless
	 * has, not in its trash and not missing, is forgotten: the copies in the deleted folder keep
	 * the fingerprint that tells whether someone changed them before the app deletes them. It waits
	 * for no run, but holds the lock, so that no run saves what it forgets at the same time.
	 *
	 * @param list<int>|null $documentIds null for every document
	 * @return list<int> the IDs of the documents whose copies the next runs write again
	 * @throws LockedException while a run is active
	 * @throws InvalidSettingException without a target user
	 */
	public function forget(?array $documentIds = null): array {
		$this->lock();
		try {
			$config = $this->configService->get();
			if ($config->targetUser === '') {
				throw new InvalidSettingException('Paperless Sync is not completely configured.');
			}
			// In per-user ownership, the copies of every user whose archive has any.
			$users = $config->perUser() ? array_values(array_unique([$config->targetUser, ...$this->state->exportOwners()])) : [$config->targetUser];
			$forgotten = [];
			foreach ($users as $user) {
				$entries = $documentIds === null
					? $this->state->allExports($user)
					: array_values(array_filter(array_map(fn (int $documentId): ?array => $this->state->findExport($user, $documentId), $documentIds)));
				foreach ($entries as $entry) {
					$documentId = (int)($entry['document_id'] ?? 0);
					// A document without a copy, such as one that was never exported, has nothing to forget.
					if ((string)($entry['path'] ?? '') === '' || ($entry['state'] ?? 'active') !== 'active') {
						continue;
					}
					// A pending fingerprint is that of a copy that the app was writing: the next run takes the
					// copy as its own and writes it again, see isOwnCopy().
					$this->state->saveExport($user, $documentId, ['fingerprint' => DocumentFingerprint::PENDING], true);
					$forgotten[$documentId] = $documentId;
				}
			}

			return array_values($forgotten);
		} finally {
			$this->lockingProvider->releaseLock(AppConstants::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Takes the lock of the runs. Nextcloud lets a lock expire after filelocking.ttl, an hour by
	 * default, also while its run is still active, such as the first import of a large archive:
	 * the signs of life of that run keep another from starting beside it.
	 *
	 * @throws LockedException while another run is active
	 */
	private function lock(): void {
		$this->lockingProvider->acquireLock(AppConstants::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, AppConstants::APP_NAME);
		if ($this->status->alive()) {
			$this->lockingProvider->releaseLock(AppConstants::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
			throw new LockedException(AppConstants::LOCK_PATH);
		}
	}

	/**
	 * The export of a run, from the Paperless of the run: the active documents, then the trash, then
	 * the documents that Paperless no longer lists. With $document, the run of that document alone
	 * takes its way through the export, and only that.
	 *
	 * ExportPlanner plans what the run does with each document, and ExportExecutor applies it, or
	 * reports it in a dry-run, so that a dry-run shows the changes of a run with the same settings.
	 *
	 * @param array<string, mixed>|null $document
	 */
	private function syncExports(PaperlessClientInterface $paperless, SyncConfig $config, SyncReport $report, ?array $document = null): void {
		$read = $this->readForExport($paperless, $config, $report, $document);
		$missing = $this->exportArchive($paperless, $config, $report, $read, $read['documents']);
		if ($read['trash'] !== null) {
			$this->checkInvisibleTrash($config, $report, $missing, count($read['trash']));
		}
	}

	/**
	 * What the export of a run reads from Paperless, once for the archives of all users: the
	 * documents, the trash unless it is the run of one document, whether both lists are complete,
	 * the path of each document, which documents the settings leave out, the names of the tags,
	 * and the metadata next to the archive, if the run writes it. A tag to mirror that Paperless
	 * doesn't have fails the run here, before a copy moves.
	 *
	 * @param array<string, mixed>|null $document
	 * @param array<string, string>|null $users the users of Paperless, when the run has them already
	 * @return ExportRead
	 */
	private function readForExport(PaperlessClientInterface $paperless, SyncConfig $config, SyncReport $report, ?array $document, ?array $users = null): array {
		$single = $document !== null;
		// The metadata next to the archive, which a dry-run leaves as it is, holds the notes and names the custom fields.
		$writesMetadata = $config->writeMetadata && !$report->dryRun;
		$documentScan = $single ? new PaperlessScan([$document], true, 1) : $paperless->documents($writesMetadata);
		$trashScan = $single ? new PaperlessScan([], true, 0) : $paperless->trash();
		$documentsComplete = $this->checkScan($report, 'documents', $documentScan);
		$trashComplete = $this->checkScan($report, 'trashed documents', $trashScan);
		$correspondents = $paperless->correspondents();
		$documentTypes = $paperless->documentTypes();
		$storagePaths = $paperless->storagePaths();
		$tags = $paperless->tags();
		[$users, $customFields, $writesMetadata] = $this->templateLists($paperless, $config, $writesMetadata ? $report : null, $users);
		$filter = new ExportFilter($config, $tags);
		$report->activeDocuments = count($documentScan->documents);
		$report->trashedDocuments = count($trashScan->documents);
		$pathOf = /** @param array<string, mixed> $document */ fn (array $document): string => $this->pathTemplate->render($config, $document, $correspondents, $documentTypes, $storagePaths, $tags['names'], $users, $customFields);

		return [
			'documents' => $documentScan->documents,
			'trash' => $single ? null : $trashScan->documents,
			'complete' => $documentsComplete && $trashComplete,
			'pathOf' => $pathOf,
			'filter' => $filter,
			'sidecars' => $writesMetadata
				? fn (SyncConfig $archive, SyncReport $archiveReport, string $archiveRoot): ?MetadataSidecars => $this->sidecars($archive, $archiveReport, $archiveRoot, $correspondents, $documentTypes, $storagePaths, $tags['names'], $customFields)
				: null,
			'tagNames' => $tags['names'],
		];
	}

	/**
	 * The export into the archive of the target user of $config, with what readForExport() read: the
	 * plan of $documents, of the documents of other users in $others, of the trash and of the
	 * documents that Paperless no longer lists, which ExportExecutor applies. The answer is the
	 * number of exported documents that the plan counted as missing without seeing them in the
	 * trash.
	 *
	 * @param ExportRead $read
	 * @param list<array<string, mixed>> $documents
	 * @param array<int, bool> $others as ExportPlanner::plan() takes them
	 */
	private function exportArchive(PaperlessClientInterface $paperless, SyncConfig $config, SyncReport $report, array $read, array $documents, array $others = []): int {
		$single = $read['trash'] === null;
		$archiveRoot = Paths::join($config->basePath, $config->archiveFolder);
		// The run reads the state of every export once, and writes only the values that change.
		$states = new ExportStates($this->state, $config->targetUser);
		$batch = new SyncBatch($config->batchSize);
		$sidecars = $read['sidecars'] !== null ? ($read['sidecars'])($config, $report, $archiveRoot) : null;
		$planner = new ExportPlanner(
			$config,
			$states,
			$batch,
			new TargetFiles($this->storage, $config->targetUser),
			$paperless,
			$read['pathOf'],
			$read['filter'],
			$this->timeZones,
			$this->timeFactory,
			$archiveRoot,
			// The run of one document gives no sign of life, which would make an interrupted run look active.
			$single ? static function (): void {
			} : $this->status->heartbeat(...),
		);
		$archiveTags = $config->nextcloudTags ? $this->systemTags->forRun($config, $report, $read['tagNames']) : null;
		$executor = new ExportExecutor($config, $report, $states, $batch, $this->storage, $paperless, $this->logger, $this->timeFactory, $archiveRoot, $sidecars, $archiveTags);
		foreach ($planner->plan($documents, $read['trash'], $read['complete'], $others) as $action) {
			$executor->apply($action);
		}
		// The metadata of a run of one document ends as well: the state of the export knows every document.
		$sidecars?->finish($states);
		// The tags of Nextcloud follow those of Paperless; only a run of the whole archive sees every tag
		// that a file of the app still has.
		$archiveTags?->finish(!$single);

		return $planner->missingActive();
	}

	/**
	 * Per-user ownership: the inbox and the archive of every user of Nextcloud who gets documents,
	 * below the same base folder of each, each with a batch, a report and entries in the activity
	 * stream and the notifications of their own; the report of the run takes in the report of
	 * each user. A document goes to the archive of the user whose user of Paperless owns it, or
	 * else to that of the target user, and its copy leaves every other archive, also one of a user
	 * who gets no documents now, such as after they left the group of the setting. The run reads
	 * Paperless once for all of them.
	 *
	 * An archive that Nextcloud can't reach, such as that of a user without a writable base
	 * folder, is an error of the run, and the documents that it would get stay where their copies
	 * are until a run reaches it. So does a document whose owner Paperless didn't list.
	 *
	 * @param array<string, mixed>|null $document the document of a run of one document, which reaches only the archives that get it or have a copy of it
	 */
	private function syncUsers(PaperlessClientInterface $paperless, SyncConfig $config, SyncReport $report, string $author, ?array $document = null): void {
		[$owners, $users] = $this->owners($paperless, $config);
		$getting = $owners->users();
		$documentId = $document !== null && is_numeric($document['id'] ?? null) ? (int)$document['id'] : 0;
		/** @var array<string, SyncConfig> $archives the archives that the run reaches, by the IDs of their users */
		$archives = [];
		/** @var array<string, SyncReport> $reports */
		$reports = [];
		try {
			foreach ([...$getting, ...array_diff($this->state->exportOwners(), $getting)] as $user) {
				$gets = in_array($user, $getting, true);
				// A user who is gone took their files along, and a run of one document reaches only
				// the archives that get it or have a copy of it.
				if ((!$gets && !$this->ownerMapping->exists($user))
					|| ($document !== null && self::ownerOf($owners, $document) !== $user && $this->state->findExport($user, $documentId) === null)) {
					continue;
				}
				$reports[$user] = new SyncReport($report->dryRun, $report->startedAt);
				$archive = $config->withTargetUser($user);
				try {
					// The run tested and prepared the folders of the target user already.
					if ($user !== $config->targetUser) {
						$this->storage->test($user, $config->basePath);
						if ($gets && !$report->dryRun) {
							$this->storage->prepare($archive);
						}
					}
					$archives[$user] = $archive;
				} catch (\Throwable $exception) {
					$reports[$user]->error(new RunError('Archive of %1$s: %2$s Its documents wait until Nextcloud can store them.', [$user, RunError::of($exception)]));
				}
			}
			if ($document === null && $config->inboxEnabled) {
				foreach (array_intersect_key($archives, array_flip($getting)) as $user => $archive) {
					$this->syncInbox($paperless, $archive, $reports[$user], $owners->paperlessUserOf($user));
				}
			}
			if ($config->exportEnabled) {
				$held = $this->heldOwner($paperless, $config, $owners);
				if ($held === false) {
					$report->error(new RunError('Paperless did not name its account, which per-user ownership needs while an upload waits for its owner, so the export waits until it has it: in Paperless, under Users & Groups, give the account the permission View for UI Settings.'));
				} else {
					$this->exportArchives($paperless, $config, $report, $this->readForExport($paperless, $config, $report, $document, $users), $owners, $archives, $reports, $held);
				}
			}
		} finally {
			foreach ($reports as $user => $userReport) {
				$archive = $config->withTargetUser($user);
				$this->activity->publish($archive, $userReport, $author);
				$this->notifications->publishImports($archive, $userReport);
				$report->absorb($userReport, $user);
			}
		}
	}

	/**
	 * The export of per-user ownership into each archive that the run reaches: the documents that
	 * the user gets, and the others, of which those that another archive takes leave it.
	 *
	 * The documents of the user of Paperless $held, the account of the app while an upload waits
	 * for its owner, stay where their copies are, as heldOwner() explains.
	 *
	 * @param ExportRead $read
	 * @param array<string, SyncConfig> $archives
	 * @param array<string, SyncReport> $reports
	 */
	private function exportArchives(PaperlessClientInterface $paperless, SyncConfig $config, SyncReport $report, array $read, Owners $owners, array $archives, array $reports, ?int $held): void {
		/** @var array<int, string|null> $routes the user of each document, or null for one that the run holds */
		$routes = [];
		$mine = array_fill_keys(array_keys($archives), []);
		foreach ($read['documents'] as $document) {
			$documentId = is_numeric($document['id'] ?? null) ? (int)$document['id'] : 0;
			// A document without a valid ID fails the export of the target user, as in single ownership.
			// Without a held owner ($held null), no owner equals it.
			$user = match (true) {
				$documentId <= 0 => $config->targetUser,
				is_numeric($document['owner'] ?? null) && (int)$document['owner'] === $held => null,
				default => self::ownerOf($owners, $document),
			};
			if ($documentId > 0) {
				$routes[$documentId] = $user;
			}
			if ($user !== null && isset($archives[$user])) {
				$mine[$user][] = $document;
			}
		}
		$missing = 0;
		foreach ($archives as $user => $archive) {
			$others = [];
			foreach ($routes as $documentId => $to) {
				if ($to !== $user) {
					$others[$documentId] = $to !== null && isset($archives[$to]);
				}
			}
			$missing += $this->exportArchive($paperless, $archive, $reports[$user], $read, $mine[$user], $others);
		}
		if ($read['trash'] !== null) {
			$this->checkInvisibleTrash($config, $report, $missing, count($read['trash']));
		}
	}

	/**
	 * The user of Paperless whose documents a run of per-user ownership holds: the account of the
	 * app, while an upload of the inbox or of Send to Paperless waits for its owner. Paperless
	 * makes the account that uploads a file the owner of its document, and the app gives the
	 * document the owner of its file only once the task of the upload has ended, or in a later run
	 * when Paperless refused the change. Until then, the document of Alice's upload looks like a
	 * document of the account, which would go to the target user, or to the user of the account,
	 * so the runs hold every document of the account while an upload waits, and their copies stay
	 * where they are. Null holds nothing, as when no upload waits or each would keep the account as
	 * its owner; false holds every document, when Paperless doesn't name its account.
	 */
	private function heldOwner(PaperlessClientInterface $paperless, SyncConfig $config, Owners $owners): int|false|null {
		$waiting = [];
		if ($config->inboxEnabled) {
			foreach ($owners->users() as $user) {
				$owner = $owners->paperlessUserOf($user);
				if ($owner !== null && in_array('pending', array_map(static fn (array $import): string => (string)($import['status'] ?? ''), $this->state->allImports($user)), true)) {
					$waiting[$owner] = true;
				}
			}
		}
		foreach ($this->state->allSends() as $send) {
			$owner = $owners->paperlessUserOf((string)($send['user_id'] ?? ''));
			if ($owner !== null && ($send['status'] ?? '') === SendService::PENDING) {
				$waiting[$owner] = true;
			}
		}
		if ($waiting === []) {
			return null;
		}
		$account = $paperless->accountId();
		if ($account === null) {
			return false;
		}

		return array_diff_key($waiting, [$account => true]) === [] ? null : $account;
	}

	/**
	 * The owners of per-user ownership, from the users of Paperless, which a run without them would
	 * give to the wrong users, so it fails, with the permission that its token may lack.
	 *
	 * @return array{Owners, array<string, string>} the owners and the names of the users of Paperless by their IDs
	 */
	private function owners(PaperlessClientInterface $paperless, SyncConfig $config): array {
		try {
			$users = $paperless->users();
		} catch (RuntimeException $exception) {
			throw new RunException('Paperless did not list its users, which per-user ownership needs to give each document to the user who owns it; the user of the API token needs the permission to view users: %s', [RunError::of($exception)], $exception);
		}

		return [$this->ownerMapping->owners($config, $users), $users];
	}

	/**
	 * The user of Nextcloud whose archive gets a document, as Owners::userOf() says, or null when
	 * Paperless named no owner field at all, so that the run holds the document rather than give it
	 * to the target user.
	 *
	 * @param array<string, mixed> $document
	 */
	private static function ownerOf(Owners $owners, array $document): ?string {
		return array_key_exists('owner', $document) ? $owners->userOf($document['owner']) : null;
	}

	/**
	 * The metadata next to the archive for a run. An index that Nextcloud can't read is an error of
	 * the run, which then leaves the metadata as it is: the next run tries again.
	 *
	 * @param array<string, string> $correspondents
	 * @param array<string, string> $documentTypes
	 * @param array<string, string> $storagePaths
	 * @param array<string, string> $tagNames
	 * @param array<string, array{name: string, type: string, options: array<string, string>}> $customFields
	 */
	private function sidecars(SyncConfig $config, SyncReport $report, string $archiveRoot, array $correspondents, array $documentTypes, array $storagePaths, array $tagNames, array $customFields): ?MetadataSidecars {
		try {
			return new MetadataSidecars(
				$this->storage, $config, $report, $archiveRoot, $correspondents, $documentTypes, $storagePaths, $tagNames, $customFields,
				fn (\Throwable $exception): bool => SyncErrors::archiveFailed($exception, $this->storage, $config->targetUser, $archiveRoot),
			);
		} catch (\Throwable $exception) {
			$report->error(new RunError('Metadata index: %s', [RunError::of($exception)]), null, Paths::join($archiveRoot, MetadataSidecars::FOLDER, MetadataSidecars::INDEX));

			return null;
		}
	}

	/**
	 * Paperless lists in its trash only the documents that the account of the app owns and those
	 * without an owner, unless it is a superuser. So documents of other owners that someone moved
	 * to the trash look gone without the trash: they reach the deleted folder only after the
	 * missing scans, or, with permanent and direct deletion both on, are deleted then, although
	 * they can still be restored in Paperless. A dry-run, which an administrator reads before
	 * turning the schedule on, says so when documents go missing while the trash lists none.
	 */
	private function checkInvisibleTrash(SyncConfig $config, SyncReport $report, int $missing, int $trashed): void {
		if (!$report->dryRun || $missing === 0 || $trashed > 0 || $config->trashMode !== 'move') {
			return;
		}
		$deletes = $config->permanentDelete && $config->allowDirectDelete;
		$fate = $deletes
			? "their copies are deleted after the required missing scans ({$config->missingGraceRuns} in a row), although the documents can still be restored in Paperless"
			: "their copies move to the deleted folder only after the required missing scans ({$config->missingGraceRuns} in a row), as those of documents deleted without the trash do";
		$report->notice(SyncReport::TYPE_TRASH_EMPTY, "TRASH EMPTY: documents of the archive are gone from Paperless ({$missing}), and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner. Documents in the trash of other owners count as missing: {$fate}. Optional: make the account of the app their owner, and deletions arrive with the next run. This does not go together with per-user ownership.", [
			'missing' => $missing,
			'scans' => $config->missingGraceRuns,
			'deletes' => $deletes ? 1 : 0,
		]);
	}

	/**
	 * The paths that the newest documents of Paperless would have in the archive with $config, such
	 * as the settings of the page before they are saved, relative to the archive folder, for the
	 * preview of the path template; skipped is whether a run leaves the document out, as one of
	 * the inbox, with an excluded tag or without a tag to mirror. It reads Paperless, through the
	 * connection of $config, and nothing else, and changes nothing.
	 *
	 * @return list<array{id: int, title: string, path: string, skipped: bool}>
	 * @throws InvalidArgumentException when Paperless lacks a custom field of the template or a tag to mirror
	 */
	public function previewPaths(SyncConfig $config, int $count): array {
		$paperless = $this->clients->connect($this->configService->connection($config));
		$documents = $paperless->newestDocuments($count);
		$correspondents = $paperless->correspondents();
		$documentTypes = $paperless->documentTypes();
		$storagePaths = $paperless->storagePaths();
		$tags = $paperless->tags();
		[$users, $customFields] = $this->templateLists($paperless, $config);
		$filter = new ExportFilter($config, $tags);
		$paths = [];
		foreach ($documents as $document) {
			// A document without a valid ID has no path; a run reports it as an error.
			$documentId = is_numeric($document['id'] ?? null) ? (int)$document['id'] : 0;
			if ($documentId < 1) {
				continue;
			}
			$paths[] = [
				'id' => $documentId,
				'title' => is_string($document['title'] ?? null) ? (string)$document['title'] : '',
				'path' => $this->pathTemplate->render($config, $document, $correspondents, $documentTypes, $storagePaths, $tags['names'], $users, $customFields),
				'skipped' => $filter->excludes($document),
			];
		}

		return $paths;
	}

	/**
	 * The lists of Paperless that only some templates need, so that a run reads them only then: the
	 * users for {{ owner_username }} and the custom fields for {{ custom_fields.<name> }}, which the
	 * metadata next to the archive names as well, when the run writes it (with the report of the
	 * run in $metadata). A list that the template needs and Paperless refuses fails the run, with
	 * the permission that its token may lack, as does a custom field that Paperless doesn't have,
	 * before a path changes: the paths of every document would change without them. The metadata
	 * alone doesn't fail the run: it is an error of the run, which exports its documents and
	 * leaves the metadata to a run with the permission. The third value says whether the run
	 * writes the metadata. A run of per-user ownership has read the users already, in $users.
	 *
	 * @param array<string, string>|null $users
	 * @return array{array<string, string>, array<string, array{name: string, type: string, options: array<string, string>}>, bool}
	 */
	private function templateLists(PaperlessClientInterface $paperless, SyncConfig $config, ?SyncReport $metadata = null, ?array $users = null): array {
		$customFields = [];
		try {
			if ($users === null) {
				$users = $this->pathTemplate->usesOwner($config->pathTemplate) ? $paperless->users() : [];
			}
		} catch (RuntimeException $exception) {
			throw new RunException('Paperless did not list its users, which the archive path template needs for {{ owner_username }}; the user of the API token needs the permission to view users: %s', [RunError::of($exception)], $exception);
		}
		$template = $this->pathTemplate->customFieldNames($config->pathTemplate) !== [];
		$writesMetadata = $metadata !== null;
		try {
			if ($template || $writesMetadata) {
				$customFields = $paperless->customFields();
			}
		} catch (RuntimeException $exception) {
			if ($template || $metadata === null) {
				throw new RunException('Paperless did not list its custom fields, which the archive path template uses; the user of the API token needs the permission to view custom fields: %s', [RunError::of($exception)], $exception);
			}
			$metadata->error(new RunError('Metadata: Paperless did not list its custom fields, which the metadata next to the archive names, so the run writes no metadata; the user of the API token needs the permission to view custom fields: %s', [RunError::of($exception)]));
			$writesMetadata = false;
		}
		$this->pathTemplate->requireCustomFields($config->pathTemplate, $customFields);

		return [$users, $customFields, $writesMetadata];
	}

	/** Reports and logs a scan that may have left documents out; returns whether the scan was complete. */
	private function checkScan(SyncReport $report, string $list, PaperlessScan $scan): bool {
		if ($scan->complete) {
			return true;
		}
		$received = count($scan->documents);
		$report->notice(SyncReport::TYPE_SCAN_INCOMPLETE, "SCAN INCOMPLETE: Paperless's list of {$list} came back incomplete or changed while the run read it ({$received} of {$scan->listed} received); no document counts as missing in this run.", [
			'list' => $list,
			'received' => $received,
			'listed' => $scan->listed,
		]);
		$this->logger->warning("Paperless's list of {list} came back incomplete or changed while the run read it ({received} of {listed} received), so no document counts as missing in this run.", [
			'list' => $list,
			'received' => $received,
			'listed' => $scan->listed,
		]);

		return false;
	}

	/**
	 * The inbox of a run: how the uploads of earlier runs ended, then the uploads of its new and
	 * changed files. ImportPlanner plans them, and ImportExecutor applies them, or reports them in
	 * a dry-run. In per-user ownership, $owner is the user of Paperless who gets the documents of
	 * its uploads, the one of the user of Nextcloud whose inbox it is.
	 */
	private function syncInbox(PaperlessClientInterface $paperless, SyncConfig $config, SyncReport $report, ?int $owner = null): void {
		$inboxRoot = Paths::join($config->basePath, $config->inboxFolder);
		$batch = new SyncBatch($config->batchSize);
		$planner = new ImportPlanner(
			$config,
			$this->state,
			$batch,
			new TargetFiles($this->storage, $config->targetUser),
			$paperless,
			$this->timeFactory,
			$inboxRoot,
			Paths::join($config->basePath, $config->errorFolder),
			$this->status->heartbeat(...),
		);
		$executor = new ImportExecutor($config, $report, $this->state, $batch, $this->storage, $paperless, $this->logger, $this->timeFactory, $inboxRoot, $owner);
		foreach ($planner->plan() as $action) {
			$executor->apply($action);
		}
		$executor->finish();
	}

	/** The current time, from the clock of Nextcloud, which the tests set. */
	private function now(): int {
		return $this->timeFactory->getTime();
	}
}
