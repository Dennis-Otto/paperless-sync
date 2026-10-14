<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use LogicException;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PaperlessUploadException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

final class SyncServiceTest extends TestCase {
	use InMemoryConfiguration;

	private const ARCHIVE = 'Dokumente/Paperless/Archiv';
	private const EXPORTED_PATH = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';

	private TestPaperlessClient $paperless;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ConfigService $configService;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private SyncService $service;

	protected function setUp(): void {
		$this->paperless = new TestPaperlessClient();
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$paths = new PathTemplateService();
		$this->configService = $this->configService($paths);
		$this->configService->save(self::validSettings(), 'test-token');
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SyncService(
			$this->configService,
			$this->paperless,
			$this->storage,
			$this->state,
			$paths,
			new StatusService($this->appConfig()),
			$this->locking,
			$this->logger,
		);
	}

	public function testExportsMovesTrashesRestoresAndPermanentlyDeletes(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content-v1';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$first = $this->service->run();
		$initialPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(1, $first->exported);
		self::assertSame(['EXPORT P123: ' . $initialPath], $first->actions, 'A run lists its changes as a dry-run does.');
		self::assertSame('%PDF-content-v1', $this->storage->files[$initialPath]);
		self::assertSame(1, $this->paperless->downloads);

		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$moved = $this->service->run();
		$renamedPath = 'Dokumente/Paperless/Archiv/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(1, $moved->moved);
		self::assertSame(['MOVE P123: ' . $renamedPath], $moved->actions);
		self::assertArrayNotHasKey($initialPath, $this->storage->files);
		self::assertArrayHasKey($renamedPath, $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'Metadata-only changes must not download the PDF again.');
		self::assertSame(1, $this->paperless->checksumRequests);

		$trashedDocument = $this->paperless->documents[0];
		$trashedDocument['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument];
		$trashed = $this->service->run();
		self::assertSame(1, $trashed->movedToTrash);
		$trashPath = 'Dokumente/Paperless/Archiv/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(['TRASH P123: ' . $trashPath], $trashed->actions);
		self::assertArrayHasKey($trashPath, $this->storage->files);

		unset($trashedDocument['deleted_at']);
		$this->paperless->documents = [$trashedDocument];
		$this->paperless->trash = [];
		$restored = $this->service->run();
		self::assertSame(1, $restored->moved);
		self::assertSame(['MOVE P123: ' . $renamedPath], $restored->actions);
		self::assertArrayHasKey($renamedPath, $this->storage->files);

		$this->paperless->documents = [];
		$this->paperless->trash = [$trashedDocument + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->service->run();
		$this->configService->save(['permanent_delete' => true, 'missing_grace_runs' => 1]);
		$this->paperless->trash = [];
		$deleted = $this->service->run();
		self::assertSame(1, $deleted->permanentlyDeleted);
		self::assertSame(['DELETE P123: ' . $trashPath], $deleted->actions);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testRefusesToRunBeforeTheAppIsConfigured(): void {
		$this->configService->reset();
		$this->locking->expects(self::once())->method('acquireLock');
		$this->locking->expects(self::once())->method('releaseLock');
		$this->logger->expects(self::once())->method('error')->with('Paperless synchronization failed');

		try {
			$this->service->run();
			self::fail('An unconfigured app must not synchronize.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless Sync is not completely configured.', $exception->getMessage());
		}

		self::assertSame('failed', $this->settings['status_last_state']);
		self::assertSame('Paperless Sync is not completely configured.', $this->settings['status_last_error']);
	}

	public function testFailedRunKeepsTheReportOfWhatItDidInTheStatus(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/scan.pdf'] = '%PDF-scan';
		$this->paperless->documents = [['id' => 'abc', 'title' => 'Broken']];

		try {
			$this->service->run();
			self::fail('A document without a valid ID fails the run.');
		} catch (RuntimeException) {
		}

		$report = json_decode((string)$this->settings['status_last_report'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['IMPORT: Dokumente/Paperless/Eingang/scan.pdf'], $report['actions'], 'The import before the failure stays in the report.');
		self::assertSame(0, $report['completedAt']);
		self::assertSame('Paperless returned a document without a valid ID.', $this->settings['status_last_error']);
		self::assertArrayNotHasKey('status_last_completed', $this->settings, 'A run that failed completed nothing.');
	}

	/**
	 * The provisioning API of Nextcloud may change the URL in the app configuration without the
	 * settings page, which asks for the token again. The next run must not send the token there.
	 */
	public function testAUrlChangedElsewhereNeverGetsTheToken(): void {
		$this->exportDocument();
		self::assertSame([['https://paperless.example.test', 'test-token', false]], $this->paperless->connections);
		$this->settings['paperless_url'] = 'https://attacker.example.test';

		try {
			$this->service->run();
			self::fail('A URL that the token was not saved with must not get it.');
		} catch (RuntimeException $exception) {
			self::assertSame('Paperless Sync is not completely configured.', $exception->getMessage());
		}

		self::assertCount(1, $this->paperless->connections);
		self::assertSame(1, $this->paperless->downloads);
	}

	public function testARunReachesPaperlessInTheLocalNetworkWhenTheSettingsAllowIt(): void {
		$this->configService->save(['paperless_url' => 'http://paperless:8000', 'paperless_local_network' => true] + self::validSettings(), 'test-token');

		$this->service->run(true);

		self::assertSame([['http://paperless:8000', 'test-token', true]], $this->paperless->connections);
	}

	/**
	 * A run that Nextcloud doesn't let reach Paperless in the local network, after an update that
	 * turned allow_local_remote_servers off for example, records what to do in the status.
	 */
	public function testARunRefusedInTheLocalNetworkRecordsWhatToDo(): void {
		$this->paperless->connectionException = new PaperlessLocalNetworkException('paperless');

		try {
			$this->service->run();
			self::fail('A refused connection must fail the run.');
		} catch (PaperlessLocalNetworkException) {
		}

		self::assertSame('failed', $this->settings['status_last_state']);
		self::assertSame(
			'Nextcloud blocks connections to local addresses such as paperless. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
			$this->settings['status_last_error'],
		);
	}

	public function testCompletedRunIsLoggedAndRecordedInTheStatus(): void {
		$this->exportDocument();
		$this->logger->expects(self::once())->method('info')->with('Paperless synchronization completed', self::callback(
			static fn (array $summary): bool => $summary['unchanged'] === 1 && !array_key_exists('actions', $summary),
		));

		$report = $this->service->run();

		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertSame($report->completedAt, $this->settings['status_last_completed']);
	}

	public function testUnchangedDocumentIsNeitherDownloadedNorRewritten(): void {
		$this->exportDocument();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $report->exported);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(0, $this->paperless->checksumRequests, 'An unchanged revision needs no checksum.');
	}

	public function testNewRevisionWithTheSameContentOnlyRecordsTheRevision(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->checksumRequests);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	public function testOriginalIsExportedWhenPaperlessHasNoArchiveVersion(): void {
		$document = $this->document();
		$document['archived_file_name'] = null;
		$this->exportDocument($document);

		self::assertSame('original:2026-08-26T10:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * A new content replaces the content of the file in place, so that it keeps its file ID in
	 * Nextcloud, with its shares, comments, tags, favorites and versions. Before, the file was
	 * deleted and written anew, which lost all of these and left the old copy in the trash bin.
	 */
	public function testChangedContentUpdatesTheFileInPlace(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->exported);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH));
		self::assertSame([[self::EXPORTED_PATH, 'created'], [self::EXPORTED_PATH, 'updated']], $this->storage->writes);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	/** A change of the content and of the path moves the file first, which keeps its ID, and then updates it in place. */
	public function testChangedContentAndPathMoveTheFileAndUpdateItInPlace(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		self::assertSame(['EXPORT P123: ' . $newPath], $report->actions);
		self::assertSame(1, $report->exported);
		self::assertSame(0, $report->moved);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId($newPath));
		self::assertSame([[self::EXPORTED_PATH, 'created'], [$newPath, 'updated']], $this->storage->writes);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0]);
		self::assertSame(1, $report->foldersPruned);
		self::assertSame($newPath, $this->state->exports[123]['path']);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	/** When the download fails after the move, the state knows the new path of the file, and the next run updates it there. */
	public function testFileMovedBeforeAFailedDownloadIsUpdatedAtItsNewPathInTheNextRun(): void {
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';

		$failed = $this->service->run();

		self::assertSame(['ERROR: P123: Paperless is unavailable'], $failed->actions);
		self::assertSame([$newPath => '%PDF-content'], $this->storage->files);
		self::assertSame($newPath, $this->state->exports[123]['path']);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint'], 'The fingerprint still names the content of the file.');
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0], 'The folder that the file left is pruned at once.');

		$this->paperless->downloadException = null;
		$retried = $this->service->run();

		self::assertSame(['EXPORT P123: ' . $newPath], $retried->actions);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId($newPath));
		self::assertCount(1, $this->storage->prunes, 'The file stayed where it is.');
	}

	/**
	 * Paperless-ngx reports MD5 checksums up to 2.x and SHA-256 since 3.0. Before, a change of the
	 * title on 2.x downloaded the document again and replaced the file, which lost its shares,
	 * comments and versions in Nextcloud.
	 */
	public function testMetadataChangeOnPaperless2MovesTheFileWithoutADownload(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		$renamedPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf';
		self::assertSame(1, $report->moved);
		self::assertSame(0, $report->exported);
		self::assertSame([$renamedPath => '%PDF-content'], $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'A change of the metadata alone must not download the document again.');
		self::assertSame(1, $this->paperless->checksumRequests);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint'], 'A move keeps the checksums of the file.');
	}

	/** An earlier version stored the SHA-256 alone, which Paperless-ngx 3 still recognizes. */
	public function testFingerprintOfAnEarlierVersionStillMovesTheFileOnPaperless3(): void {
		$this->exportDocument();
		$this->state->exports[123]['fingerprint'] = hash('sha256', '%PDF-content');
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->moved);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame([], $this->storage->reads, 'A SHA-256 of Paperless needs no read of the mirrored file.');
		self::assertSame(hash('sha256', '%PDF-content'), $this->state->exports[123]['fingerprint']);
	}

	/**
	 * On Paperless-ngx 2, the SHA-256 alone of an earlier version can't tell an unchanged document.
	 * Before, the first change of the metadata after the update downloaded every such document once
	 * more and replaced its file; now the run reads the mirrored file instead, whose SHA-256 shows
	 * that it still has the content of that download, and keeps its MD5 for the changes after it.
	 */
	public function testFingerprintOfAnEarlierVersionGainsTheMd5OfTheMirroredFileOnPaperless2(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$first = $this->service->run();
		$this->paperless->documents[0]['title'] = 'Strom August final';
		$this->paperless->documents[0]['modified'] = '2026-08-26T12:00:00+02:00';
		$second = $this->service->run();

		self::assertSame([1, 0], [$first->moved, $first->exported]);
		self::assertSame([1, 0], [$second->moved, $second->exported]);
		self::assertSame(1, $this->paperless->downloads, 'A change of the metadata alone must not download the document again.');
		self::assertSame([self::EXPORTED_PATH], $this->storage->reads, 'Only the first change reads the mirrored file.');
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint']);
		self::assertSame([self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August final [P123].pdf' => '%PDF-content'], $this->storage->files);
	}

	public function testFingerprintOfAnEarlierVersionGainsTheMd5AlsoWhenThePathStaysOnPaperless2(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint']);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * A move that waits for the next run, because the batch is full or Nextcloud refused a write,
	 * keeps the MD5 that the read of its mirrored file found, so every mirrored file is read once,
	 * also when the moves of a large archive take many runs. A dry-run saves nothing.
	 */
	public function testFingerprintOfAnEarlierVersionReadsTheMirroredFileOnceWhenTheMoveWaitsOnPaperless2(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocuments([$this->document(123), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		foreach ($this->paperless->documents as $index => $document) {
			$this->state->exports[(int)$document['id']]['fingerprint'] = hash('sha256', '%PDF-content');
			$this->paperless->documents[$index]['title'] = 'Strom August korrigiert';
			$this->paperless->documents[$index]['modified'] = '2026-08-26T11:00:00+02:00';
		}

		$dryRun = $this->service->run(true);
		self::assertSame(1, $dryRun->moved);
		self::assertSame([hash('sha256', '%PDF-content'), hash('sha256', '%PDF-content')], [$this->state->exports[123]['fingerprint'], $this->state->exports[124]['fingerprint']], 'A dry-run saves nothing.');
		$this->storage->reads = [];
		$first = $this->service->run();
		$second = $this->service->run();

		self::assertSame([1, 1, 0], [$first->moved, $first->skipped, $first->exported]);
		self::assertSame([1, 1, 0], [$second->moved, $second->unchanged, $second->exported]);
		self::assertSame(2, $this->paperless->downloads, 'A change of the metadata alone must not download the documents again.');
		self::assertEqualsCanonicalizing([$this->archivePath(123), $this->archivePath(124)], $this->storage->reads, 'Each mirrored file is read once.');
		foreach ([123, 124] as $documentId) {
			self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[$documentId]['fingerprint']);
		}
	}

	/**
	 * A mirrored file with other content than the download that the earlier version saved, or one
	 * that Nextcloud can't read, can't vouch for the document, so the document is downloaded again:
	 * the file moves to its new path and gets the content of the download there.
	 */
	#[DataProvider('mirroredFilesThatCantVouchForTheDocument')]
	public function testFingerprintOfAnEarlierVersionDownloadsWhenTheMirroredFileCantVouchForIt(string $content, ?\Throwable $readFailure): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		$this->storage->files[self::EXPORTED_PATH] = $content;
		$this->storage->readException = $readFailure;
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$report = $this->service->run();

		self::assertSame([0, 1], [$report->moved, $report->exported]);
		self::assertSame(0, $report->errors);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH], $this->storage->reads);
		self::assertSame([self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf' => '%PDF-content'], $this->storage->files);
		self::assertSame(hash('sha256', '%PDF-content') . ' ' . hash('md5', '%PDF-content'), $this->state->exports[123]['fingerprint']);
	}

	/** @return array<string, array{string, ?\Throwable}> */
	public static function mirroredFilesThatCantVouchForTheDocument(): array {
		return [
			'changed in Nextcloud' => ['%PDF-changed-in-nextcloud', null],
			'not readable' => ['%PDF-content', new RuntimeException('Storage is not available.')],
		];
	}

	public function testFingerprintOfAnEarlierVersionDownloadsWhenTheMirroredFileIsGone(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		unset($this->storage->files[self::EXPORTED_PATH]);

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([], $this->storage->reads, 'A file that is gone is not read.');
		self::assertSame([self::EXPORTED_PATH => '%PDF-content'], $this->storage->files);
	}

	/** New content in Paperless downloads the document, also when the mirrored file still has the old one. */
	public function testFingerprintOfAnEarlierVersionDownloadsNewContentOnPaperless2(): void {
		$this->exportWithFingerprintOfAnEarlierVersionOnPaperless2();
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(2, $this->paperless->downloads);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	public function testNewRevisionOnPaperless2WithTheSameContentOnlyRecordsTheRevision(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame('archive:2026-08-26T11:00:00+02:00', $this->state->exports[123]['source_revision']);
	}

	/**
	 * Before, an export read the whole document into a string to compute its checksum, so that a
	 * document larger than the free memory of PHP ended the run with a fatal error, before it saved
	 * its state, and every run after it died on the same document.
	 */
	public function testExportPassesTheDocumentToNextcloudWithoutHoldingItInMemory(): void {
		$this->configService->save(['inbox_enabled' => false]);
		$size = 16 * 1024 * 1024;
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = str_repeat('%PDF-large', intdiv($size, 10));
		$written = hash_init('sha256');
		$storage = $this->createMock(NextcloudStorageInterface::class);
		$storage->expects(self::once())->method('writeStream')->willReturnCallback(
			/** @param resource $source */
			static function (string $userId, string $path, $source) use ($written): void {
				hash_update_stream($written, $source);
			},
		);
		$service = new SyncService(
			$this->configService,
			$this->paperless,
			$storage,
			$this->state,
			new PathTemplateService(),
			new StatusService($this->appConfig()),
			$this->locking,
			$this->logger,
		);
		memory_reset_peak_usage();
		$before = memory_get_usage();

		$report = $service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(hash('sha256', $this->paperless->contents[123]), hash_final($written), 'Nextcloud receives the whole document.');
		self::assertLessThan($size / 2, memory_get_peak_usage() - $before, 'The document must reach Nextcloud in chunks.');
	}

	public function testDocumentsThatShareAFileAreDownloadedAgainInsteadOfMoved(): void {
		$this->paperless->documents = [$this->document(123), $this->document(456)];
		$this->paperless->contents = [123 => '%PDF-content-123', 456 => '%PDF-content-456'];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->service->run();
		// An older version rendered both documents to one path without their markers, so the
		// second write replaced the first, and the file holds the content of P456 only.
		$shared = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August.pdf';
		$this->storage->files = [$shared => '%PDF-content-456'];
		$this->state->exports[123]['path'] = $shared;
		$this->state->exports[456]['path'] = $shared;

		$report = $this->service->run();

		self::assertSame(0, $report->moved, 'Moving the shared file would give P123 the content of P456.');
		self::assertSame(2, $report->exported);
		self::assertSame([
			self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P456].pdf' => '%PDF-content-456',
			self::EXPORTED_PATH => '%PDF-content-123',
		], $this->storage->files, 'The most recently added document comes first.');
		self::assertSame(4, $this->paperless->downloads);
	}

	public function testBatchSizeLimitsTheChangesOfOneRun(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(), $this->document(124)];
		$this->paperless->contents = [123 => '%PDF-123', 124 => '%PDF-124'];

		$first = $this->service->run();
		self::assertSame(1, $first->exported);
		self::assertSame(1, $first->skipped);
		self::assertArrayNotHasKey(123, $this->state->exports);

		$second = $this->service->run();
		self::assertSame(1, $second->unchanged);
		self::assertSame(1, $second->exported);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testMostRecentlyAddedDocumentsComeFirst(): void {
		$this->configService->save(['batch_size' => 2]);

		$report = $this->exportDocuments([$this->document(5), $this->document(9), $this->document(7)]);

		self::assertSame(['EXPORT P9: ' . $this->archivePath(9), 'EXPORT P7: ' . $this->archivePath(7)], $report->actions, 'A new document does not wait behind a backlog.');
	}

	public function testFailedExportsTakeTheirPlaceInTheBatch(): void {
		$this->configService->save(['batch_size' => 5]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');

		$first = $this->exportDocuments($this->documents(50));

		self::assertSame(5, $this->paperless->downloads, 'A run that cannot write downloads no more documents than the batch size allows.');
		self::assertSame(5, $first->errors);
		self::assertSame(45, $first->skipped);
		self::assertSame('The file name is not allowed', $this->state->exports[46]['last_error']);
		self::assertArrayNotHasKey(45, $this->state->exports);

		$second = $this->service->run();

		self::assertSame(10, $this->paperless->downloads);
		self::assertSame(45, $second->skipped);
		self::assertSame(array_map(static fn (int $id): string => "ERROR: P{$id}: The file name is not allowed", range(45, 41)), $second->actions, 'The documents that failed wait behind the others.');
	}

	public function testExportsThatKeepFailingLeaveTheBatchToTrashedDocuments(): void {
		$this->exportDocuments([$this->document(123), $this->document(124)]);
		$this->configService->save(['batch_size' => 1, 'conflict_mode' => 'skip']);
		$this->paperless->documents = [$this->document(122), $this->document(123)];
		$this->storage->files[$this->archivePath(122)] = 'a file of the user';
		$this->paperless->trash = [$this->document(124) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$trashPath = self::ARCHIVE . '/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P124].pdf';

		$report = $this->service->run();

		self::assertSame([
			'ERROR: P122: Nextcloud file conflict at ' . $this->archivePath(122) . '.',
			'TRASH P124: ' . $trashPath,
		], $report->actions, 'A failed export is no change, so the trashed document still moves.');
		self::assertSame(1, $report->movedToTrash);
	}

	public function testExportsThatKeepFailingLeaveTheBatchToMissingDocuments(): void {
		$this->exportDocuments([$this->document(123)]);
		$this->configService->save(['batch_size' => 1, 'conflict_mode' => 'skip', 'missing_grace_runs' => 1]);
		$this->paperless->documents = [$this->document(122)];
		$this->storage->files[$this->archivePath(122)] = 'a file of the user';

		$report = $this->service->run();

		self::assertSame([
			'ERROR: P122: Nextcloud file conflict at ' . $this->archivePath(122) . '.',
			'MISSING P123: ' . $this->deletedToday(),
		], $report->actions);
		self::assertSame('missing', $this->state->exports[123]['state']);
	}

	public function testFailedMovesToTheDeletedFolderTakeTheirPlaceInTheBatch(): void {
		$documents = $this->documents(5);
		$this->exportDocuments($documents);
		$this->configService->save(['batch_size' => 2]);
		$this->paperless->documents = [];
		$this->paperless->trash = array_map(static fn (array $document): array => $document + ['deleted_at' => '2026-08-27T08:00:00+02:00'], $documents);
		$this->storage->moveException = new RuntimeException('The file is locked');

		$report = $this->service->run();

		self::assertSame(['ERROR: Trash P1: The file is locked', 'ERROR: Trash P2: The file is locked'], $report->actions, 'A run that cannot move tries no more moves than the batch size allows.');
		self::assertSame(3, $report->skipped);
	}

	public function testDocumentWhoseExportFailedWaitsBehindTheOthers(): void {
		$this->storage->writeException = new RuntimeException('The file name is not allowed');
		$this->exportDocument();
		$this->storage->writeException = null;
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents[] = $this->document(124);
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();

		self::assertSame(['EXPORT P124: ' . $this->archivePath(124)], $first->actions);
		self::assertSame(1, $first->skipped);

		$second = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $second->actions);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testDocumentsThatKeepFailingTakeTurns(): void {
		$this->configService->save(['batch_size' => 2]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');
		$this->exportDocuments($this->documents(4));
		$this->service->run();
		// The first two failed in an earlier run than the other two.
		foreach ([1 => 100, 2 => 100, 3 => 200, 4 => 200] as $id => $lastSeen) {
			$this->state->exports[$id]['last_seen'] = $lastSeen;
		}

		$third = $this->service->run();

		self::assertSame(['ERROR: P2: The file name is not allowed', 'ERROR: P1: The file name is not allowed'], $third->actions);

		$fourth = $this->service->run();

		self::assertSame(['ERROR: P4: The file name is not allowed', 'ERROR: P3: The file name is not allowed'], $fourth->actions);
	}

	/** @return array<string, array{\Throwable}> */
	public static function storageFailures(): array {
		return [
			'full quota' => [new NotEnoughSpaceException('Not enough free space')],
			'read-only storage' => [new NotPermittedException('Could not create path')],
			'unavailable external storage' => [new class('The storage is not available') extends StorageNotAvailableException {
				// Nextcloud's constructor translates a hint, which needs a running server.
				public function __construct(string $message) {
					\Exception::__construct($message);
				}
			}],
		];
	}

	#[DataProvider('storageFailures')]
	public function testStorageThatCannotBeWrittenEndsTheExportsOfTheRun(\Throwable $failure): void {
		$this->storage->writeException = $failure;
		$this->storage->writable = false;
		$this->logger->expects(self::once())->method('warning')->with(
			'Nextcloud cannot store files in the archive, so this run exports and moves no further documents: {error}',
			['error' => $failure->getMessage(), 'exception' => $failure],
		);

		$report = $this->exportDocuments($this->documents(3));

		$error = $failure->getMessage();
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame(1, $report->errors);
		self::assertSame(2, $report->skipped);
		self::assertSame([
			"ERROR: P3: {$error}",
			'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.',
		], $report->actions);
		self::assertSame($error, $this->state->exports[3]['last_error']);
		self::assertArrayNotHasKey(2, $this->state->exports);
	}

	/** @return array<string, array{bool|\Throwable}> */
	public static function archiveChecks(): array {
		return [
			'an archive that can still be written' => [true],
			'a check that fails' => [new RuntimeException('The storage is not available')],
		];
	}

	#[DataProvider('archiveChecks')]
	public function testPathThatNextcloudRefusesConcernsOnlyItsDocument(bool|\Throwable $writable): void {
		$this->storage->writeFailures[$this->archivePath(3)] = new NotPermittedException('Could not create folder');
		$this->storage->writable = $writable;

		$report = $this->exportDocuments($this->documents(3));

		$stopped = $writable instanceof \Throwable;
		self::assertSame(['ERROR: P3: Could not create folder', ...($stopped
			? ['EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.']
			: ['EXPORT P2: ' . $this->archivePath(2), 'EXPORT P1: ' . $this->archivePath(1)])], $report->actions);
		self::assertSame([self::ARCHIVE], $this->storage->writableChecks, 'Only the archive folder tells whether the whole archive refuses files.');
	}

	public function testStorageThatCannotBeWrittenStillRemovesExcludedCopies(): void {
		$this->exportDocuments([$this->document(122), $this->document()]);
		$this->configService->save(['excluded_tags' => 'Privat']);
		$this->paperless->tagInfo = ['names' => ['9' => 'Privat'], 'inbox' => []];
		$this->paperless->documents = [$this->document(122), $this->document(123), $this->document(124)];
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->contents[124] = '%PDF-124';
		$this->storage->writeException = new NotEnoughSpaceException('Not enough free space');

		$report = $this->service->run();

		self::assertSame(3, $this->paperless->downloads, 'Only the first document of this run is downloaded.');
		self::assertSame(['ERROR: P124: Not enough free space', 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.', 'REMOVE EXCLUDED P122: ' . $this->archivePath(122)], $report->actions, 'Removing a copy frees space, so it goes on.');
		self::assertArrayNotHasKey($this->archivePath(122), $this->storage->files);
		self::assertSame(1, $report->unchanged);
	}

	public function testStorageThatCannotBeWrittenMovesNoDocumentToTheDeletedFolder(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(122), $this->document(123)]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';
		$this->paperless->trash = [$this->document(122) + ['deleted_at' => '2026-08-27T08:00:00+02:00']];
		$this->storage->writeException = new NotEnoughSpaceException('Not enough free space');

		$report = $this->service->run();

		self::assertSame(['ERROR: P124: Not enough free space', 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.'], $report->actions);
		self::assertSame(2, $report->skipped, 'The trashed and the missing document wait for the next run.');
		self::assertSame('active', $this->state->exports[122]['state']);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	/** @return array<string, array{bool, string}> */
	public static function phasesThatMove(): array {
		return [
			// The trash in the order of Paperless, the missing documents in the order of their state.
			'trash' => [true, 'Trash P122'],
			'missing' => [false, 'Missing P123'],
		];
	}

	#[DataProvider('phasesThatMove')]
	public function testStorageThatCannotBeWrittenEndsTheMovesToTheDeletedFolder(bool $trashed, string $error): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(122), $this->document(123)]);
		$this->paperless->documents = [];
		if ($trashed) {
			$this->paperless->trash = array_map(fn (int $id): array => $this->document($id) + ['deleted_at' => '2026-08-27T08:00:00+02:00'], [122, 123]);
		}
		$this->storage->moveException = new NotPermittedException('Could not create folder');
		$this->storage->writable = false;

		$report = $this->service->run();

		self::assertSame(["ERROR: {$error}: Could not create folder", 'EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.'], $report->actions);
		self::assertSame(1, $report->skipped, 'The other document waits for the next run.');
	}

	public function testNoticesOfTheRunAreKeptPastTheLimitOfTheReport(): void {
		$this->configService->save(['batch_size' => 100]);
		foreach (range(1, 100) as $number) {
			$this->storage->files["Dokumente/Paperless/Eingang/scan{$number}.pdf"] = "%PDF-{$number}";
		}
		$this->paperless->documentsListed = 1;

		$report = $this->service->run();

		self::assertCount(101, $report->actions);
		self::assertStringStartsWith('SCAN INCOMPLETE:', $report->actions[100], 'A hundred imports do not hide that the run counted no document as missing.');
	}

	public function testDryRunShowsTheTrashAlsoBehindAHundredImports(): void {
		$this->exportDocument();
		$this->trashDocument();
		foreach (range(1, 100) as $number) {
			$this->storage->files["Dokumente/Paperless/Eingang/scan{$number}.pdf"] = "%PDF-{$number}";
		}

		$report = $this->service->run(true);

		self::assertSame(100, $report->importsSubmitted);
		self::assertCount(100, $report->actions);
		self::assertSame('TRASH P123: ' . $this->trashFolderPath(), $report->actions[99], 'The move to the deleted folder takes the place of the last import.');
		self::assertSame(1, $report->omittedActions);
	}

	public function testErrorOfADocumentIsLoggedOnceUntilItChanges(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});
		$unavailable = new RuntimeException('Paperless is unavailable');
		$this->paperless->downloadException = $unavailable;

		$this->exportDocument();
		$this->service->run();
		$timedOut = new RuntimeException('Paperless timed out');
		$this->paperless->downloadException = $timedOut;
		$this->service->run();

		$message = 'Paperless Sync could not synchronize the document P{documentId}: {error}';
		self::assertSame([
			[$message, ['documentId' => 123, 'path' => self::EXPORTED_PATH, 'error' => 'Paperless is unavailable']],
			[$message, ['documentId' => 123, 'path' => self::EXPORTED_PATH, 'error' => 'Paperless timed out']],
		], $warnings, 'The second run fails as the first did, so only the first and the third are logged, without the trace of the exception.');
	}

	public function testDryRunLogsTheErrorOfADocumentThatNoRunRecorded(): void {
		$this->paperless->documents = [['id' => 12.5] + $this->document()];
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not synchronize the document P{documentId}: {error}',
			self::callback(static fn (array $context): bool => $context['documentId'] === 12 && $context['path'] === ''),
		);

		$this->service->run(true);
	}

	public function testDocumentWithoutAValidIdFailsTheRun(): void {
		$this->paperless->documents = [['id' => 'abc', 'title' => 'Broken']];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Paperless returned a document without a valid ID.');
		$this->service->run();
	}

	public function testFailedDownloadIsReportedAndRetriedInTheNextRun(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');

		$failed = $this->exportDocument();

		self::assertSame(1, $failed->errors);
		self::assertSame(['ERROR: P123: Paperless is unavailable'], $failed->actions);
		self::assertSame('Paperless is unavailable', $this->state->exports[123]['last_error']);
		self::assertSame([], $this->storage->files);
		self::assertSame('completed-with-errors', $this->settings['status_last_state']);

		$this->paperless->downloadException = null;
		$retried = $this->service->run();
		self::assertSame(1, $retried->exported);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testErrorWithoutMessageNamesTheExceptionClass(): void {
		$this->paperless->downloadException = new LogicException();

		$report = $this->exportDocument();

		self::assertSame(['ERROR: P123: LogicException'], $report->actions);
	}

	public function testDryRunReportsAnUnusableDocumentWithoutRecordingIt(): void {
		$document = $this->document();
		$document['id'] = 12.5;

		$this->paperless->documents = [$document];
		$report = $this->service->run(true);

		self::assertSame(['ERROR: P12: Paperless returned a document without a numeric ID.'], $report->actions);
		self::assertSame([], $this->state->exports);
	}

	public function testExistingFileIsKeptWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->storage->files[self::EXPORTED_PATH] = 'a file of the user';

		$report = $this->exportDocument();

		self::assertSame(1, $report->errors);
		self::assertSame('a file of the user', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('Nextcloud file conflict at ' . self::EXPORTED_PATH . '.', $this->state->exports[123]['last_error']);
		self::assertSame(0, $this->paperless->downloads, 'The file stays, so the download would be in vain.');
	}

	/** The skip policy protects the files of the user, not the copy that the app wrote itself (#98). */
	public function testOwnCopyIsUpdatedInPlaceWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH));
		self::assertNull($this->state->exports[123]['last_error']);
	}

	/** A copy whose content Paperless changed while the document was missing is its own as well. */
	public function testReturningDocumentUpdatesItsOwnCopyWhenConflictsAreSkipped(): void {
		$this->configService->save(['trash_mode' => 'keep', 'missing_grace_runs' => 1, 'conflict_mode' => 'skip']);
		$this->exportDocument();
		$listed = $this->paperless->documents;
		$this->paperless->documents = [];
		$this->service->run();
		$listed[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->documents = $listed;
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	/** A copy that the user changed in Nextcloud is no longer the app's own, and the skip policy leaves it alone. */
	public function testCopyThatTheUserChangedIsAConflictWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-annotated by the user';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.'], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-annotated by the user'], $this->storage->files);
		self::assertSame(1, $this->paperless->downloads, 'The file stays, so the download would be in vain.');
	}

	public function testCopyThatCantBeReadIsAConflictWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->storage->readException = new RuntimeException('The storage is not available');
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		self::assertSame(['ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.'], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content'], $this->storage->files);
	}

	/** The move takes the own copy to a free path, so the update in place that follows is no conflict. */
	public function testChangedContentAndPathMoveTheOwnCopyWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$report = $this->service->run();

		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		self::assertSame(['EXPORT P123: ' . $newPath], $report->actions);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
		self::assertSame($fileId, $this->storage->fileId($newPath));
	}

	/** The move would take a copy that someone changed along, and the new content would replace their edit. */
	public function testChangedCopyStaysWhenContentAndPathChangeAndConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-annotated by the user';
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';

		$dryRun = $this->service->run(true);
		$report = $this->service->run();

		$conflict = 'ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.';
		self::assertSame([$conflict], $dryRun->actions);
		self::assertSame([$conflict], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-annotated by the user'], $this->storage->files);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame(1, $this->paperless->downloads, 'The copy stays, so the download would be in vain.');
	}

	/** A write in place that fails halfway leaves the own copy incomplete, which the next run writes again. */
	public function testOwnCopyThatAFailedUpdateLeftIncompleteIsWrittenAgainWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';
		$this->storage->writeFailures[self::EXPORTED_PATH] = new RuntimeException('The disk is full');

		$failed = $this->service->run();
		self::assertSame(['ERROR: P123: The disk is full'], $failed->actions);
		self::assertSame(DocumentFingerprint::PENDING, $this->state->exports[123]['fingerprint']);
		$this->storage->files[self::EXPORTED_PATH] = '%PDF-cont';
		$this->storage->writeFailures = [];

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content-v2'], $this->storage->files);
		self::assertSame(hash('sha256', '%PDF-content-v2') . ' ' . hash('md5', '%PDF-content-v2'), $this->state->exports[123]['fingerprint']);
	}

	/** The state records the new path after the move and the write in place before it starts, so a failed write leaves the moved copy the app's own. */
	public function testMovedCopyThatAFailedUpdateLeftIncompleteIsWrittenAgainWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$this->paperless->contents[123] = '%PDF-content-v2';
		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		$this->storage->writeFailures[$newPath] = new RuntimeException('The disk is full');

		$this->service->run();
		self::assertSame(['path' => $newPath, 'fingerprint' => DocumentFingerprint::PENDING], array_intersect_key($this->state->exports[123], ['path' => true, 'fingerprint' => true]));
		$this->storage->files[$newPath] = '%PDF-cont';
		$this->storage->writeFailures = [];

		$report = $this->service->run();

		self::assertSame(['EXPORT P123: ' . $newPath], $report->actions);
		self::assertSame([$newPath => '%PDF-content-v2'], $this->storage->files);
	}

	public function testMoveOntoAnExistingFileIsAConflictWhenConflictsAreSkipped(): void {
		$this->exportDocument();
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->paperless->documents[0]['title'] = 'Strom August neu';
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
		$newPath = self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August neu [P123].pdf';
		$this->storage->files[$newPath] = 'a file of the user';

		$report = $this->service->run();

		self::assertSame(["ERROR: P123: Nextcloud file conflict at {$newPath}."], $report->actions);
		self::assertSame([self::EXPORTED_PATH => '%PDF-content', $newPath => 'a file of the user'], $this->storage->files);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testDryRunReportsAConflictWhenConflictsAreSkipped(): void {
		$this->configService->save(['conflict_mode' => 'skip']);
		$this->storage->files[self::EXPORTED_PATH] = 'a file of the user';
		$this->paperless->documents = [$this->document()];
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$report = $this->service->run(true);

		self::assertSame(['ERROR: P123: Nextcloud file conflict at ' . self::EXPORTED_PATH . '.'], $report->actions);
		self::assertSame(0, $report->exported);
		self::assertSame([], $this->state->exports);
	}

	public function testImportsInboxAndDeletesSourceAfterSuccessfulTask(): void {
		$path = 'Dokumente/Paperless/Eingang/Versicherung/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';

		$submitted = $this->service->run();
		self::assertSame(1, $submitted->importsSubmitted);
		self::assertSame(['IMPORT: ' . $path], $submitted->actions);
		self::assertSame(['police.pdf' => '%PDF-inbox'], $this->paperless->uploads);
		self::assertArrayHasKey($path, $this->storage->files);

		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];
		$completed = $this->service->run();
		self::assertSame(1, $completed->importsSucceeded);
		self::assertSame(['IMPORT SUCCESS: ' . $path], $completed->actions);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame([], $this->state->imports);
	}

	public function testFailedImportMovesFileAndWritesDiagnostic(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];

		$report = $this->service->run();
		$errorPath = 'Dokumente/Paperless/Fehler/broken.pdf';
		self::assertSame(1, $report->importsFailed);
		self::assertSame(['IMPORT ERROR: ' . $path . ' -> ' . $errorPath], $report->actions);
		self::assertSame('broken', $this->storage->files[$errorPath]);
		self::assertStringContainsString('Unsupported mime type', $this->storage->files[$errorPath . '.error.txt']);
	}

	public function testSuccessfulImportNamesTheDocumentThatPaperlessCreated(): void {
		$this->configService->save(['delete_inbox_after_success' => false]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => 'Success. New document id 456 created', 'documentId' => 456];

		$report = $this->service->run();

		self::assertSame(['IMPORT SUCCESS: ' . $path . ' -> P456'], $report->actions);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame('success', $this->state->imports[$path]['status']);
	}

	/**
	 * Every end of a task that created no document moves the file to the error folder, with what
	 * Paperless said and the task to look it up by: a failure, a duplicate, which Paperless-ngx 3.x
	 * also reports as a failure, and a task that Paperless revoked, which ends no other way.
	 *
	 * @return array<string, array{array{status: string, message: string}, string}>
	 */
	public static function failedTasks(): array {
		return [
			'a failure' => [
				['status' => 'FAILURE', 'message' => 'police.pdf: Unsupported mime type application/octet-stream'],
				"Paperless import failed\n\npolice.pdf: Unsupported mime type application/octet-stream\n\nPaperless task: task-1\n",
			],
			'a duplicate' => [
				['status' => 'FAILURE', 'message' => 'Not consuming: It is a duplicate of document #123'],
				"Paperless import failed\n\nNot consuming: It is a duplicate of document #123\n\nPaperless task: task-1\n",
			],
			'a revoked task' => [
				['status' => 'REVOKED', 'message' => ''],
				"Paperless import failed\n\nPaperless cancelled the task before it finished (REVOKED), so it created no document.\n\nPaperless task: task-1\n",
			],
			'a revoked task with a reason' => [
				['status' => 'REVOKED', 'message' => 'Terminated'],
				"Paperless import failed\n\nPaperless cancelled the task before it finished (REVOKED), so it created no document: Terminated\n\nPaperless task: task-1\n",
			],
			'a failure without a reason' => [
				['status' => 'FAILURE', 'message' => ''],
				"Paperless import failed\n\nPaperless gave no reason; its log may have one.\n\nPaperless task: task-1\n",
			],
		];
	}

	/** @param array{status: string, message: string} $task */
	#[DataProvider('failedTasks')]
	public function testFailedTaskMovesTheFileWithWhatPaperlessSaid(array $task, string $diagnostic): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;

		$report = $this->service->run();

		$errorPath = 'Dokumente/Paperless/Fehler/police.pdf';
		self::assertSame(['IMPORT ERROR: ' . $path . ' -> ' . $errorPath], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(0, $report->errors);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame($diagnostic, $this->storage->files[$errorPath . '.error.txt']);
		self::assertSame([], $this->state->imports);
	}

	/**
	 * A task that Paperless doesn't list a day after the upload: its queue lost it, as after a
	 * restart of Redis. Without an end, the file would wait in the inbox for good, so it is uploaded
	 * again, as after a failed upload. So is a file that changed since its upload, also while
	 * Paperless still lists the task of its old content.
	 *
	 * @return array<string, array{array{status: string, message: string, listed: bool}, string, bool}>
	 */
	public static function lostTasks(): array {
		return [
			'not listed' => [['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false], 'PENDING: Paperless does not list the task.', false],
			'listed, of a file that changed since' => [['status' => 'STARTED', 'message' => '', 'listed' => true], 'STARTED', true],
		];
	}

	/** @param array{status: string, message: string, listed: bool} $task */
	#[DataProvider('lostTasks')]
	public function testTaskThatPaperlessLostIsUploadedAgainADayAfterTheUpload(array $task, string $state, bool $changed): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->state->imports[$path]['submitted_at'] = time() - 86400;
		if ($changed) {
			$this->storage->files[$path] = '%PDF-inbox, saved anew';
		}
		$error = "Paperless has not finished the import task task-1 a day after the upload ({$state})";
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless has not finished the import of the inbox file {path} a day after its upload; it is uploaded again in {minutes} minutes: {error}',
			['path' => $path, 'error' => $error, 'task' => 'task-1', 'minutes' => 15],
		);

		$lost = $this->service->run();

		self::assertSame([hash('sha256', $this->storage->files[$path])], $this->paperless->searches, 'Paperless may have the file after all.');
		self::assertSame(['ERROR: Import ' . $path . ': ' . $error . ' (attempt 1, the next in 15 minutes)'], $lost->actions);
		self::assertSame(1, $lost->errors);
		self::assertSame(0, $lost->importsFailed);
		self::assertSame('retry', $this->state->imports[$path]['status']);
		self::assertSame(1, $this->state->imports[$path]['attempts']);
		self::assertSame(900, $this->state->imports[$path]['retry_at'] - $this->state->imports[$path]['submitted_at']);
		self::assertSame($error, $this->state->imports[$path]['last_error']);
		self::assertArrayHasKey($path, $this->storage->files, 'The file stays in the inbox.');
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts);

		$this->state->imports[$path]['retry_at'] = time();
		$again = $this->service->run();
		self::assertSame(1, $again->importsSubmitted);
		self::assertSame(['police.pdf', 'police.pdf'], $this->paperless->uploadAttempts);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	/**
	 * A task that Paperless still lists a day after the upload, unfinished: its worker may be down,
	 * or the task may wait in a long queue, after a large import on a small machine. Paperless-ngx
	 * 3.x imports a duplicate as a second document unless PAPERLESS_CONSUMER_DELETE_DUPLICATES is
	 * on, so a new upload could put the document into Paperless twice. The file moves to the error
	 * folder instead, with a note that the document may still appear.
	 *
	 * @return array<string, array{array{status: string, message: string}, string}>
	 */
	public static function unfinishedTasks(): array {
		return [
			'pending' => [['status' => 'PENDING', 'message' => ''], 'PENDING'],
			'started' => [['status' => 'STARTED', 'message' => ''], 'STARTED'],
			'a status of the future, with a text' => [['status' => 'RETRYING', 'message' => 'Waiting for OCR'], 'RETRYING: Waiting for OCR'],
		];
	}

	/** @param array{status: string, message: string} $task */
	#[DataProvider('unfinishedTasks')]
	public function testTaskThatPaperlessHasNotFinishedADayAfterTheUploadMovesTheFileWithoutUploadingItAgain(array $task, string $state): void {
		$path = 'Dokumente/Paperless/Eingang/Versicherung/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->state->imports[$path]['submitted_at'] = time() - 86400;
		$error = "Paperless has not finished the import task task-1 a day after the upload ({$state})";
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless has not finished the import of the inbox file {path} a day after its upload; it moves to the error folder, as Paperless may still import it: {error}',
			['path' => $path, 'error' => $error, 'task' => 'task-1'],
		);

		$report = $this->service->run();

		$errorPath = 'Dokumente/Paperless/Fehler/Versicherung/police.pdf';
		self::assertSame(['IMPORT ERROR: ' . $path . ' -> ' . $errorPath], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(0, $report->errors);
		self::assertArrayNotHasKey($path, $this->storage->files);
		self::assertSame('%PDF-inbox', $this->storage->files[$errorPath]);
		self::assertSame(
			"Paperless import not finished\n\n"
			. "Paperless has not finished the import task a day after the upload ({$state}). Its worker may be down, or the task may still wait in its queue, so Paperless may still create the document. The file was not uploaded again, because Paperless-ngx 3.x imports a duplicate as a second document.\n\n"
			. "Should the document not appear in Paperless, check that the worker of Paperless runs, then move the file back into the inbox.\n\n"
			. "Paperless task: task-1\n",
			$this->storage->files[$errorPath . '.error.txt'],
		);
		self::assertSame([], $this->state->imports);
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts, 'No second upload, which could make a duplicate.');
	}

	/**
	 * Paperless has the content of the file a day after the upload, though its task doesn't say
	 * so: someone removed the finished task, or Paperless no longer lists it. The checksum of its
	 * original is the SHA-256 of the file since Paperless-ngx 3.0 and its MD5 before.
	 *
	 * @return array<string, array{array{status: string, message: string, listed: bool}, string, bool}>
	 */
	public static function importedFiles(): array {
		return [
			'3.x, not listed' => [['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false], 'sha256', true],
			'2.x, still pending' => [['status' => 'PENDING', 'message' => '', 'listed' => true], 'md5', true],
			'3.x, still started, the file kept' => [['status' => 'STARTED', 'message' => '', 'listed' => true], 'sha256', false],
		];
	}

	/** @param array{status: string, message: string, listed: bool} $task */
	#[DataProvider('importedFiles')]
	public function testFileThatPaperlessHasADayAfterTheUploadCountsAsImported(array $task, string $algorithm, bool $deleteAfterSuccess): void {
		$this->configService->save(['delete_inbox_after_success' => $deleteAfterSuccess]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->paperless->originals[hash($algorithm, '%PDF-inbox')] = 456;
		$this->state->imports[$path]['submitted_at'] = time() - 86400;
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run();

		self::assertSame(['IMPORT SUCCESS: ' . $path . ' -> P456'], $report->actions);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame(0, $report->errors);
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts);
		if ($deleteAfterSuccess) {
			self::assertArrayNotHasKey($path, $this->storage->files);
			self::assertSame([], $this->state->imports);
		} else {
			self::assertSame('success', $this->state->imports[$path]['status']);
			self::assertSame(hash('sha256', '%PDF-inbox'), $this->state->imports[$path]['etag']);
			self::assertSame(0, $this->service->run()->importsSubmitted, 'The kept file stays imported.');
		}
	}

	/** A file that can't be read can't be looked for in Paperless; it ends as if Paperless didn't have it. */
	public function testUnreadableFileIsNotLookedForInPaperless(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false];
		$this->state->imports[$path]['submitted_at'] = time() - 86400;
		$this->storage->readException = new RuntimeException('Synthetic read failure');

		$report = $this->service->run();

		self::assertSame([], $this->paperless->searches);
		self::assertSame('retry', $this->state->imports[$path]['status']);
		self::assertSame(1, $report->errors);
	}

	public function testTaskThatPaperlessHasNotFinishedYetWaitsForADay(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'STARTED', 'message' => ''];
		// A minute before the day is over, so that the run doesn't reach it on a slow machine.
		$this->state->imports[$path]['submitted_at'] = time() - 86400 + 60;
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->errors);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertSame([], $this->paperless->searches);
	}

	/** @return array<string, array{string}> */
	public static function unansweredQuestions(): array {
		return [
			'the task' => ['taskException'],
			'the document with the content of the file' => ['findException'],
		];
	}

	#[DataProvider('unansweredQuestions')]
	public function testTaskOfAPaperlessThatDoesNotAnswerIsNotGivenUp(string $failure): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->state->imports[$path]['submitted_at'] = time() - 3 * 86400;
		$this->paperless->{$failure} = new RuntimeException('Paperless timed out');

		$report = $this->service->run();

		self::assertSame('pending', $this->state->imports[$path]['status'], 'Only an answer of Paperless tells that it has not finished the task.');
		self::assertSame(1, $report->errors);
		self::assertArrayHasKey($path, $this->storage->files);
		self::assertCount(1, $this->paperless->uploadAttempts);
	}

	/** @return array<string, array{array{status: string, message: string, listed: bool}, string}> */
	public static function unfinishedTasksInADryRun(): array {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';

		return [
			'not listed' => [
				['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'listed' => false],
				'ERROR: Import ' . $path . ': Paperless has not finished the import task task-1 a day after the upload (PENDING: Paperless does not list the task.); a run uploads it again',
			],
			'still pending' => [['status' => 'PENDING', 'message' => '', 'listed' => true], 'IMPORT ERROR: ' . $path . ' -> Dokumente/Paperless/Fehler/police.pdf'],
		];
	}

	/** @param array{status: string, message: string, listed: bool} $task */
	#[DataProvider('unfinishedTasksInADryRun')]
	public function testDryRunReportsATaskThatPaperlessHasNotFinishedWithoutChangingAnything(array $task, string $action): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = $task;
		$this->state->imports[$path]['submitted_at'] = time() - 86400;
		$imports = $this->state->imports;
		$files = $this->storage->files;
		$this->logger->expects(self::never())->method('warning');

		$report = $this->service->run(true);

		self::assertSame([$action], $report->actions);
		self::assertSame($imports, $this->state->imports);
		self::assertSame($files, $this->storage->files);
		self::assertCount(1, $this->paperless->uploadAttempts);
	}

	public function testDryRunReportsAFileThatPaperlessHasWithoutChangingAnything(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->originals[hash('sha256', '%PDF-inbox')] = 456;
		$this->state->imports[$path]['submitted_at'] = time() - 86400;
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame(['IMPORT SUCCESS: ' . $path . ' -> P456'], $report->actions);
		self::assertSame($imports, $this->state->imports);
		self::assertArrayHasKey($path, $this->storage->files);
	}

	public function testRunThatCannotReachPaperlessNamesNoPaperless(): void {
		$this->paperless->server = ['version' => '3.3.0', 'apiVersion' => '9'];
		$this->service->run(true);
		$this->paperless->connectionException = new RuntimeException('Paperless answered 406');

		try {
			$this->service->run(true);
			self::fail('A failed connection must fail the run.');
		} catch (RuntimeException) {
		}

		self::assertSame('', $this->settings['status_paperless_version'], 'The settings page must not name the Paperless of the run before.');
		self::assertSame('', $this->settings['status_paperless_api_version']);
	}

	public function testRunKeepsTheVersionOfPaperlessForTheStatus(): void {
		$this->paperless->server = ['version' => '3.3.0', 'apiVersion' => '9'];

		$this->service->run(true);

		self::assertSame('3.3.0', $this->settings['status_paperless_version']);
		self::assertSame('9', $this->settings['status_paperless_api_version']);
	}

	public function testFailedTaskIsNotSubmittedAgainWhenErrorHandlingThrows(): void {
		$path = 'Dokumente/Paperless/Eingang/broken.pdf';
		$this->storage->files[$path] = 'broken';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Unsupported mime type'];
		$this->storage->writeTextException = new RuntimeException('Synthetic diagnostic write failure');

		$report = $this->service->run();

		self::assertSame(0, $report->importsSubmitted);
		self::assertSame(1, $report->errors);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testUnreadableTaskStatusIsRetriedInTheNextRun(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$timedOut = new RuntimeException('Paperless timed out');
		$this->paperless->taskException = $timedOut;
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not check the import of the inbox file {path}: {error}',
			['path' => $path, 'error' => 'Paperless timed out'],
		);

		$report = $this->service->run();
		$this->service->run();
		$this->service->run(true);

		self::assertSame(['ERROR: Import task ' . $path . ': Paperless timed out'], $report->actions);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertSame('Paperless timed out', $this->state->imports[$path]['last_error']);
		self::assertCount(1, $this->paperless->uploads);
	}

	public function testImportStateOfAVanishedFileIsForgotten(): void {
		$this->state->imports['Dokumente/Paperless/Eingang/gone.pdf'] = ['path' => 'Dokumente/Paperless/Eingang/gone.pdf', 'status' => 'pending', 'task_id' => 'task-9'];

		$report = $this->service->run();

		self::assertSame([], $this->state->imports);
		self::assertSame(0, $report->importsSubmitted);
	}

	public function testDryRunKeepsTheImportStateOfAVanishedFile(): void {
		$this->state->imports['Dokumente/Paperless/Eingang/gone.pdf'] = ['path' => 'Dokumente/Paperless/Eingang/gone.pdf', 'status' => 'pending', 'task_id' => 'task-9'];

		$this->service->run(true);

		self::assertArrayHasKey('Dokumente/Paperless/Eingang/gone.pdf', $this->state->imports);
	}

	public function testKeptInboxFileIsImportedOnceUntilItChanges(): void {
		$this->configService->save(['delete_inbox_after_success' => false]);
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCEEDED', 'message' => ''];

		$succeeded = $this->service->run();
		self::assertSame(1, $succeeded->importsSucceeded);
		self::assertSame('success', $this->state->imports[$path]['status']);
		self::assertArrayHasKey($path, $this->storage->files);

		$unchanged = $this->service->run();
		self::assertSame(1, $unchanged->unchanged);
		self::assertSame(0, $unchanged->importsSubmitted);

		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$changed = $this->service->run();
		self::assertSame(1, $changed->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox-corrected'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testFileChangedDuringItsImportIsSubmittedAgain(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];

		$succeeded = $this->service->run();
		self::assertSame(1, $succeeded->importsSucceeded);
		self::assertSame(0, $succeeded->importsSubmitted);
		self::assertArrayHasKey($path, $this->storage->files, 'A file that changed after its upload must not be deleted.');
		self::assertSame([], $this->state->imports);

		$resubmitted = $this->service->run();
		self::assertSame(1, $resubmitted->importsSubmitted);
		self::assertSame('%PDF-inbox-corrected', $this->paperless->uploads['police.pdf']);
	}

	public function testDryRunReportsImportsWithoutTouchingTheInbox(): void {
		$succeeded = 'Dokumente/Paperless/Eingang/succeeded.pdf';
		$failed = 'Dokumente/Paperless/Eingang/Ordner/failed.pdf';
		$this->storage->files[$succeeded] = '%PDF-1';
		$this->storage->files[$failed] = '%PDF-2';
		$this->service->run();
		$this->paperless->tasks = [
			'task-1' => ['status' => 'SUCCESS', 'message' => ''],
			'task-2' => ['status' => 'FAILED', 'message' => 'Broken'],
		];
		$new = 'Dokumente/Paperless/Eingang/new.pdf';
		$this->storage->files[$new] = '%PDF-3';
		$files = $this->storage->files;
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame([
			'IMPORT SUCCESS: ' . $succeeded,
			'IMPORT ERROR: ' . $failed . ' -> Dokumente/Paperless/Fehler/Ordner/failed.pdf',
			'IMPORT: ' . $new,
		], $report->actions);
		self::assertSame(1, $report->importsFailed);
		self::assertSame(1, $report->importsSucceeded);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame($files, $this->storage->files);
		self::assertSame($imports, $this->state->imports);
	}

	public function testDryRunKeepsTheStateOfAFileChangedDuringItsImport(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->service->run();
		$this->storage->files[$path] = '%PDF-inbox-corrected';
		$this->paperless->tasks['task-1'] = ['status' => 'SUCCESS', 'message' => ''];

		$this->service->run(true);

		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testImportsBeyondTheBatchSizeWaitForTheNextRun(): void {
		$this->configService->save(['batch_size' => 1]);
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(1, $report->skipped);
		self::assertSame(['a.pdf' => '%PDF-a'], $this->paperless->uploads);
	}

	public function testFailedUploadWaitsAndIsRetriedWhenItsTimeHasCome(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 502: Bad Gateway', false, 502);

		$failed = $this->service->run();
		self::assertSame(['ERROR: Import ' . $path . ': Paperless answered the upload with HTTP 502: Bad Gateway (attempt 1, the next in 15 minutes)'], $failed->actions);
		self::assertSame(1, $failed->errors);
		self::assertSame('retry', $this->state->imports[$path]['status']);
		self::assertSame(1, $this->state->imports[$path]['attempts']);
		self::assertSame(900, $this->state->imports[$path]['retry_at'] - $this->state->imports[$path]['submitted_at']);
		self::assertSame('Paperless answered the upload with HTTP 502: Bad Gateway', $this->state->imports[$path]['last_error']);

		$waiting = $this->service->run();
		self::assertSame([], $waiting->actions);
		self::assertSame(0, $waiting->errors);
		self::assertSame(1, $waiting->skipped);
		self::assertSame(['police.pdf'], $this->paperless->uploadAttempts, 'The file must wait for its next attempt.');

		$this->paperless->uploadException = null;
		$this->state->imports[$path]['retry_at'] = time();
		$retried = $this->service->run();
		self::assertSame(1, $retried->importsSubmitted);
		self::assertSame(['police.pdf' => '%PDF-inbox'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
		self::assertSame(0, $this->state->imports[$path]['attempts']);
		self::assertSame(0, $this->state->imports[$path]['retry_at']);
		self::assertNull($this->state->imports[$path]['last_error']);
	}

	public function testWaitAfterAFailedUploadDoublesUpToADay(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = new RuntimeException('Paperless timed out');
		$levels = [];
		$this->logger->method('log')->willReturnCallback(static function (string $level, string $message, array $context) use (&$levels): void {
			$levels[] = $level;
		});

		$waits = [];
		for ($run = 1; $run <= 10; ++$run) {
			$this->service->run();
			$import = $this->state->imports[$path];
			self::assertSame($run, $import['attempts']);
			$waits[] = intdiv($import['retry_at'] - $import['submitted_at'], 60);
			$this->state->imports[$path]['retry_at'] = time();
		}

		self::assertSame([15, 30, 60, 120, 240, 480, 960, 1440, 1440, 1440], $waits);
		self::assertSame([LogLevel::WARNING, ...array_fill(0, 9, LogLevel::INFO)], $levels, 'Only the first failure is a warning.');
	}

	public function testFileThatPaperlessRefusedWaitsUntilItChanges(): void {
		$path = 'Dokumente/Paperless/Eingang/notes.zip';
		$this->storage->files[$path] = 'PK-archive';
		$reason = 'Paperless answered the upload with HTTP 400: document: File type application/zip not supported';
		$this->paperless->uploadException = new PaperlessUploadException($reason, true, 400);
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless refused the inbox file {path}; it stays in the inbox until it changes: {reason}',
			['path' => $path, 'reason' => $reason, 'status' => 400],
		);

		$refused = $this->service->run();
		self::assertSame(['IMPORT REJECTED: ' . $path . ': ' . $reason], $refused->actions);
		self::assertSame(1, $refused->importsFailed);
		self::assertSame(0, $refused->errors);
		self::assertSame('completed', $this->settings['status_last_state']);
		self::assertSame('rejected', $this->state->imports[$path]['status']);
		self::assertSame($reason, $this->state->imports[$path]['last_error']);
		self::assertArrayHasKey($path, $this->storage->files, 'A refused file stays in the inbox.');

		$this->paperless->uploadException = null;
		for ($run = 0; $run < 3; ++$run) {
			$skipped = $this->service->run();
			self::assertSame([], $skipped->actions);
			self::assertSame(0, $skipped->importsFailed);
			self::assertSame(1, $skipped->skipped);
		}
		self::assertSame(['notes.zip'], $this->paperless->uploadAttempts, 'Paperless would refuse the same file again.');

		$this->storage->files[$path] = '%PDF-replaced';
		$changed = $this->service->run();
		self::assertSame(1, $changed->importsSubmitted);
		self::assertSame(['notes.zip' => '%PDF-replaced'], $this->paperless->uploads);
		self::assertSame('pending', $this->state->imports[$path]['status']);
	}

	public function testRefusedFileIsUploadedAgainUnderANewName(): void {
		$path = 'Dokumente/Paperless/Eingang/notes.zip';
		$this->storage->files[$path] = '%PDF-1';
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 400: document: No file was submitted.', true, 400);
		$this->service->run();
		$this->paperless->uploadException = null;
		$renamed = 'Dokumente/Paperless/Eingang/notes.pdf';
		$this->storage->files[$renamed] = $this->storage->files[$path];
		unset($this->storage->files[$path]);

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame([$renamed], array_keys($this->state->imports));
	}

	public function testChangedFileIsUploadedAtOnceAfterAFailedUpload(): void {
		$path = 'Dokumente/Paperless/Eingang/police.pdf';
		$this->storage->files[$path] = '%PDF-inbox';
		$this->paperless->uploadException = new RuntimeException('Paperless timed out');
		$this->service->run();
		$this->paperless->uploadException = null;
		$this->storage->files[$path] = '%PDF-inbox-corrected';

		$report = $this->service->run();

		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(0, $this->state->imports[$path]['attempts']);
	}

	public function testRefusedFileDoesNotHoldUpTheOthers(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/a.zip'] = 'PK-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';
		$this->paperless->uploadFailures['a.zip'] = new PaperlessUploadException('Paperless answered the upload with HTTP 413.', true, 413);

		$report = $this->service->run();

		self::assertSame(1, $report->importsFailed);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame(['b.pdf' => '%PDF-b'], $this->paperless->uploads);
	}

	public function testFailureOfOneFileDoesNotHoldUpTheOthers(): void {
		$this->storage->files['Dokumente/Paperless/Eingang/a.pdf'] = '%PDF-a';
		$this->storage->files['Dokumente/Paperless/Eingang/b.pdf'] = '%PDF-b';
		$this->paperless->uploadFailures['a.pdf'] = new RuntimeException('Could not copy the file for the Paperless upload.');

		$report = $this->service->run();

		self::assertSame(1, $report->errors);
		self::assertSame(1, $report->importsSubmitted);
		self::assertSame('retry', $this->state->imports['Dokumente/Paperless/Eingang/a.pdf']['status']);
	}

	public function testUnavailablePaperlessEndsTheUploadsOfTheRun(): void {
		foreach (['a', 'b', 'c'] as $name) {
			$this->storage->files["Dokumente/Paperless/Eingang/{$name}.pdf"] = "%PDF-{$name}";
		}
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 503.', false, 503);

		$first = $this->service->run();
		self::assertSame(['a.pdf'], $this->paperless->uploadAttempts);
		self::assertSame(1, $first->errors);
		self::assertSame(2, $first->skipped);
		self::assertSame(['Dokumente/Paperless/Eingang/a.pdf'], array_keys($this->state->imports), 'Files that waited were not attempted.');

		$second = $this->service->run();
		self::assertSame(['a.pdf', 'b.pdf'], $this->paperless->uploadAttempts, 'Each run tries one more file while Paperless fails.');
		self::assertSame(2, $second->skipped);

		$this->paperless->uploadException = null;
		$recovered = $this->service->run();
		self::assertSame(1, $recovered->importsSubmitted);
		self::assertSame(['c.pdf' => '%PDF-c'], $this->paperless->uploads);
	}

	public function testFailedUploadsTakeTheirPlaceInTheBatch(): void {
		$this->configService->save(['batch_size' => 2]);
		foreach (['a', 'b', 'c'] as $name) {
			$this->storage->files["Dokumente/Paperless/Eingang/{$name}.zip"] = "PK-{$name}";
		}
		$this->paperless->uploadException = new PaperlessUploadException('Paperless answered the upload with HTTP 400.', true, 400);

		$report = $this->service->run();

		self::assertSame(['a.zip', 'b.zip'], $this->paperless->uploadAttempts);
		self::assertSame(2, $report->importsFailed);
		self::assertSame(1, $report->skipped);
	}

	public function testDryRunSkipsWaitingFilesAndKeepsTheirState(): void {
		$refused = 'Dokumente/Paperless/Eingang/refused.zip';
		$waiting = 'Dokumente/Paperless/Eingang/waiting.pdf';
		$due = 'Dokumente/Paperless/Eingang/due.pdf';
		$this->storage->files[$refused] = 'PK';
		$this->storage->files[$waiting] = '%PDF-waiting';
		$this->storage->files[$due] = '%PDF-due';
		$this->paperless->uploadFailures['refused.zip'] = new PaperlessUploadException('Paperless answered the upload with HTTP 400.', true, 400);
		$this->paperless->uploadException = new RuntimeException('Paperless timed out');
		$this->service->run();
		$this->service->run();
		$this->state->imports[$due]['retry_at'] = time();
		$imports = $this->state->imports;

		$report = $this->service->run(true);

		self::assertSame(['IMPORT: ' . $due], $report->actions);
		self::assertSame(2, $report->skipped);
		self::assertSame($imports, $this->state->imports);
	}

	public function testExcludedDocumentRemovesMirroredCopyAndCanBeExportedAgain(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$this->service->run();
		$this->paperless->documents[0]['tags'] = [9];
		$excluded = $this->service->run();

		self::assertSame(1, $excluded->removedExcluded);
		self::assertSame(['REMOVE EXCLUDED P123: ' . self::EXPORTED_PATH], $excluded->actions);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);

		$this->paperless->documents[0]['tags'] = [];
		$exportedAgain = $this->service->run();
		self::assertSame(1, $exportedAgain->exported);
		self::assertSame(2, $this->paperless->downloads);
	}

	public function testExcludedDocumentThatWasNeverExportedIsOnlySkipped(): void {
		$document = $this->document();
		$document['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->exportDocument($document);

		self::assertSame(1, $report->skipped);
		self::assertSame(0, $report->removedExcluded);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
	}

	public function testExcludedTagNamesMatchWithoutRegardToCase(): void {
		$this->configService->save(['excluded_tags' => 'Privat, Steuer']);
		$this->paperless->tagInfo = ['names' => ['5' => 'privat', '6' => 'Haushalt'], 'inbox' => []];
		$private = $this->document();
		$private['tags'] = [['id' => 6], ['id' => 5]];
		$household = $this->document(124);
		$household['tags'] = [['id' => 6], ['name' => 'without an id']];

		$report = $this->exportDocuments([$private, $household]);

		self::assertSame(1, $report->skipped);
		self::assertSame(1, $report->exported);
		self::assertArrayNotHasKey(123, $this->state->exports);
		self::assertArrayHasKey(124, $this->state->exports);
	}

	public function testInboxTagDoesNotExcludeWhenTheInboxIsSynchronizedToo(): void {
		$this->configService->save(['skip_inbox' => false]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$document = $this->document();
		$document['tags'] = [9];

		$report = $this->exportDocument($document);

		self::assertSame(1, $report->exported);
	}

	public function testDryRunReportsTheRemovalOfAnExcludedCopy(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];

		$report = $this->service->run(true);

		self::assertSame(['REMOVE EXCLUDED P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->removedExcluded);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testExcludedCopyWaitsWhenTheBatchIsFull(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$excluded = $this->paperless->documents[0];
		$excluded['tags'] = [9];
		$this->paperless->documents = [$this->document(124), $excluded];
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();
		self::assertSame(1, $first->exported);
		self::assertSame(0, $first->removedExcluded);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);

		$second = $this->service->run();
		self::assertSame(1, $second->removedExcluded);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testFailedRemovalOfAnExcludedCopyIsReported(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$this->storage->deleteException = new RuntimeException('File is locked');
		$this->expectDocumentWarning(self::EXPORTED_PATH, 'File is locked');

		$report = $this->service->run();
		$this->service->run();

		self::assertSame(['ERROR: Excluded P123: File is locked'], $report->actions);
		self::assertSame('File is locked', $this->state->exports[123]['last_error']);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testTrashedDocumentThatWasNeverExportedIsIgnored(): void {
		$this->paperless->trash = [$this->document() + ['deleted_at' => '2026-08-27T08:00:00+02:00']];

		$report = $this->service->run();

		self::assertSame(1, $report->trashedDocuments);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame([], $this->state->exports);
	}

	public function testDocumentAlreadyInTheTrashFolderStaysThere(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->service->run();

		$report = $this->service->run();

		self::assertSame(1, $report->unchanged);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('trash', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsTheMoveToTheTrashFolder(): void {
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run(true);

		$trashPath = self::ARCHIVE . '/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(['TRASH P123: ' . $trashPath], $report->actions);
		self::assertSame(1, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testMoveToTheTrashFolderWaitsWhenTheBatchIsFull(): void {
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->trashDocument();
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$report = $this->service->run();

		self::assertSame(1, $report->exported);
		self::assertSame(1, $report->skipped);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testFailedMoveToTheTrashFolderIsReported(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->moveException = new RuntimeException('Storage is full');
		$this->expectDocumentWarning(self::EXPORTED_PATH, 'Storage is full');

		$report = $this->service->run();
		$this->service->run();

		self::assertSame(['ERROR: Trash P123: Storage is full'], $report->actions);
		self::assertSame('Storage is full', $this->state->exports[123]['last_error']);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testKeepPolicyLeavesTheCopyOfATrashedDocumentInPlace(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame(strtotime('2026-08-27T08:00:00+02:00'), $this->state->exports[123]['trash_date']);
	}

	/**
	 * A document restored from the Paperless trash finds its copy where the keep policy left it,
	 * with the same content, so nothing is downloaded or written. Before, the copy was downloaded
	 * and written again, which gave it a new file ID.
	 */
	public function testRestoredDocumentKeepsItsCopyUnderTheKeepPolicy(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$fileId = $this->storage->fileId(self::EXPORTED_PATH);
		$restored = $this->paperless->documents;
		$this->trashDocument();
		$this->service->run();
		$this->paperless->documents = $restored;
		$this->paperless->trash = [];

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame([[self::EXPORTED_PATH, 'created']], $this->storage->writes);
		self::assertSame($fileId, $this->storage->fileId(self::EXPORTED_PATH));
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['trash_date']);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	/** Also with conflicts skipped: the copy at the path of the document is its own. */
	#[DataProvider('conflictModes')]
	public function testDocumentListedAgainKeepsItsCopyUnderTheKeepPolicy(string $conflictMode): void {
		$this->configService->save(['trash_mode' => 'keep', 'missing_grace_runs' => 1, 'conflict_mode' => $conflictMode]);
		$this->exportDocument();
		$listed = $this->paperless->documents;
		$this->paperless->documents = [];
		$this->service->run();
		self::assertSame('missing', $this->state->exports[123]['state']);
		$this->paperless->documents = $listed;

		$report = $this->service->run();

		self::assertSame([], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame(1, $this->paperless->downloads);
		self::assertSame([[self::EXPORTED_PATH, 'created']], $this->storage->writes);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	/** @return array<string, array{string}> */
	public static function conflictModes(): array {
		return ['replace' => ['replace'], 'skip' => ['skip']];
	}

	public function testDryRunKeepsTheStateOfARestoredDocument(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$restored = $this->paperless->documents;
		$this->trashDocument();
		$this->service->run();
		$this->paperless->documents = $restored;
		$this->paperless->trash = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(1, $report->unchanged);
		self::assertSame('trash', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsNothingWhenTheKeepPolicyLeavesTheCopyInPlace(): void {
		$this->configService->save(['trash_mode' => 'keep']);
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testTrashedDocumentWithoutAFileIsOnlyMarked(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->files = [];

		$first = $this->service->run();

		self::assertSame(0, $first->movedToTrash);
		self::assertSame([], $this->storage->prunes);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'Nothing was moved, so the state keeps the path.');

		$second = $this->service->run();

		self::assertSame(0, $second->movedToTrash);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'The path must not grow by a deleted folder in every run.');
	}

	public function testTrashedDocumentWithoutAFileTakesNoPlaceInTheBatch(): void {
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->paperless->trash = array_map(
			static fn (array $document): array => $document + ['deleted_at' => '2026-08-27T08:00:00+02:00'],
			$this->paperless->documents,
		);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->skipped);
		self::assertSame([$this->trashFolderPath(124) => '%PDF-content'], $this->storage->files);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testDryRunReportsNoMoveToTheTrashFolderWithoutAFile(): void {
		$this->exportDocument();
		$this->trashDocument();
		$this->storage->files = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
	}

	public function testTrashedDocumentThatWasNeverExportedIsOnlyMarked(): void {
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->trashDocument();

		$report = $this->service->run();

		self::assertSame(0, $report->errors);
		self::assertSame(0, $report->movedToTrash);
		self::assertSame('trash', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['last_error']);
		self::assertArrayNotHasKey('path', $this->state->exports[123], 'A folder of the deleted folder must not become the path of the document.');
	}

	/** @return array<string, array{mixed}> */
	public static function unreadableDeletionDates(): array {
		return [
			'missing' => [null],
			'empty' => [''],
			'unparsable' => ['not a date'],
		];
	}

	#[DataProvider('unreadableDeletionDates')]
	public function testTrashFolderUsesTheCurrentDateWithoutAReadableDeletionDate(mixed $deletedAt): void {
		$this->exportDocument();
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = $deletedAt;
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];

		$this->service->run();

		$today = gmdate('Y-m-d');
		self::assertArrayHasKey(self::ARCHIVE . "/_Gelöscht/{$today}/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf", $this->storage->files);
	}

	public function testMissingDocumentWaitsForTheConfiguredRuns(): void {
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['WAIT P123: missing run 1/3'], $report->actions);
		self::assertSame(1, $this->state->exports[123]['missing_runs']);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testDryRunDoesNotCountMissingRuns(): void {
		$this->exportDocument();
		$this->paperless->documents = [];

		$this->service->run(true);

		self::assertSame(0, $this->state->exports[123]['missing_runs']);
	}

	public function testDocumentThatIsListedAgainStartsItsCountOfMissingScansAnew(): void {
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->service->run();
		$this->paperless->documents = [$this->document()];
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->storage->writeException = new RuntimeException('The file name is not allowed');

		$this->service->run(true);
		self::assertSame(1, $this->state->exports[123]['missing_runs'], 'A dry run changes no state.');

		$report = $this->service->run();

		self::assertSame(1, $report->errors);
		self::assertSame(0, $this->state->exports[123]['missing_runs'], 'Only scans in a row without the document count, also when its export fails.');
	}

	/** @return array<string, array{string, string}> */
	public static function incompleteScans(): array {
		return [
			'documents' => ['documentsListed', "SCAN INCOMPLETE: Paperless's list of documents came back incomplete or changed while the run read it (1 of 2 received); no document counts as missing in this run."],
			'trash' => ['trashListed', "SCAN INCOMPLETE: Paperless's list of trashed documents came back incomplete or changed while the run read it (0 of 2 received); no document counts as missing in this run."],
		];
	}

	#[DataProvider('incompleteScans')]
	public function testIncompleteScanCountsNoDocumentAsMissing(string $listed, string $action): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$files = $this->storage->files;
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->{$listed} = 2;
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless\'s list of {list} came back incomplete or changed while the run read it ({received} of {listed} received), so no document counts as missing in this run.',
			self::callback(static fn (array $context): bool => $context['listed'] === 2),
		);

		$report = $this->service->run();

		self::assertSame([$action], $report->actions);
		self::assertSame($files, $this->storage->files);
		self::assertSame(0, $this->state->exports[123]['missing_runs']);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(0, $report->permanentlyDeleted);
	}

	public function testCompleteScanAfterAnIncompleteOneCountsTheMissingDocument(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->documentsListed = 2;
		$this->service->run();
		$this->paperless->documentsListed = null;

		$report = $this->service->run();

		self::assertSame(['MISSING P123: ' . $this->deletedToday()], $report->actions);
		self::assertSame('missing', $this->state->exports[123]['state']);
	}

	public function testDryRunReportsAnIncompleteScan(): void {
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->paperless->documentsListed = 1;

		$report = $this->service->run(true);

		self::assertSame(["SCAN INCOMPLETE: Paperless's list of documents came back incomplete or changed while the run read it (0 of 1 received); no document counts as missing in this run."], $report->actions);
	}

	public function testMissingDocumentMovesToTheDeletedFolderAfterTheGraceRuns(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		$deletedPath = self::ARCHIVE . '/_Gelöscht/' . gmdate('Y-m-d') . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(1, $report->movedToTrash);
		self::assertSame(['MISSING P123: ' . $deletedPath], $report->actions);
		self::assertSame([$deletedPath => '%PDF-content'], $this->storage->files);
		self::assertSame([self::EXPORTED_PATH, self::ARCHIVE], $this->storage->prunes[0]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame($deletedPath, $this->state->exports[123]['path']);

		$again = $this->service->run();
		self::assertSame(0, $again->movedToTrash);
		self::assertSame([$deletedPath => '%PDF-content'], $this->storage->files);
		self::assertSame(2, $this->state->exports[123]['missing_runs']);
	}

	public function testMissingDocumentWithoutAFileIsOnlyMarked(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->files = [];

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertSame([], $this->storage->prunes);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path'], 'Nothing was moved, so the state keeps the path.');
	}

	public function testMissingDocumentWithoutAFileTakesNoPlaceInTheBatch(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->configService->save(['batch_size' => 1]);
		unset($this->storage->files[self::EXPORTED_PATH]);
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->skipped);
		self::assertSame([$this->deletedToday(124) => '%PDF-content'], $this->storage->files);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
	}

	public function testMissingDocumentThatWasNeverExportedIsOnlyMarked(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(0, $report->movedToTrash);
		self::assertSame(0, $report->errors);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertArrayNotHasKey('path', $this->state->exports[123], 'A folder of the deleted folder must not become the path of the document.');
	}

	public function testDryRunReportsNoMoveOfAMissingDocumentWithoutAFile(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->files = [];

		$report = $this->service->run(true);

		self::assertSame([], $report->actions);
		self::assertSame(0, $report->movedToTrash);
	}

	public function testMoveOfAMissingDocumentWaitsWhenTheBatchIsFull(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$first = $this->service->run();

		self::assertSame(1, $first->exported);
		self::assertSame(1, $first->skipped);
		self::assertSame(0, $first->movedToTrash);
		self::assertSame('%PDF-content', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);

		$second = $this->service->run();

		self::assertSame(1, $second->movedToTrash);
		self::assertArrayNotHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday()]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertSame($this->deletedToday(), $this->state->exports[123]['path']);
	}

	public function testFailedMoveOfAMissingDocumentIsReportedAndRetried(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'conflict_mode' => 'skip']);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$this->storage->files[$this->deletedToday()] = 'a file of the user';
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(['MISSING P124: ' . $this->deletedToday(124), 'ERROR: Missing P123: conflict'], $report->actions);
		self::assertSame(1, $report->movedToTrash, 'The other missing document still moves.');
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday(124)]);
		self::assertSame('%PDF-content', $this->storage->files[self::EXPORTED_PATH]);
		self::assertSame('a file of the user', $this->storage->files[$this->deletedToday()]);
		self::assertSame('active', $this->state->exports[123]['state']);
		self::assertSame(self::EXPORTED_PATH, $this->state->exports[123]['path']);
		self::assertSame('conflict', $this->state->exports[123]['last_error']);
		self::assertSame('completed-with-errors', $this->settings['status_last_state']);

		unset($this->storage->files[$this->deletedToday()]);
		$retried = $this->service->run();

		self::assertSame(1, $retried->movedToTrash);
		self::assertSame('%PDF-content', $this->storage->files[$this->deletedToday()]);
		self::assertSame('missing', $this->state->exports[123]['state']);
		self::assertNull($this->state->exports[123]['last_error']);
	}

	public function testDryRunReportsTheMoveOfAMissingDocument(): void {
		$this->configService->save(['missing_grace_runs' => 1]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		$deletedPath = self::ARCHIVE . '/_Gelöscht/' . gmdate('Y-m-d') . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P123].pdf';
		self::assertSame(['MISSING P123: ' . $deletedPath], $report->actions);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('active', $this->state->exports[123]['state']);
	}

	public function testMissingDocumentIsDeletedDirectlyWhenAllowed(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(1, $report->permanentlyDeleted);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame([[self::EXPORTED_PATH, self::ARCHIVE]], $this->storage->prunes);
	}

	public function testDryRunReportsThePermanentDeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run(true);

		self::assertSame(['DELETE P123: ' . self::EXPORTED_PATH], $report->actions);
		self::assertSame(1, $report->permanentlyDeleted);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertArrayHasKey(123, $this->state->exports);
	}

	public function testFailedPermanentDeletionIsReported(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->paperless->documents = [];
		$this->storage->deleteException = new RuntimeException('File is locked');
		$this->expectDocumentWarning(self::EXPORTED_PATH, 'File is locked');

		$report = $this->service->run();
		$this->service->run();

		self::assertSame(['ERROR: Missing P123: File is locked'], $report->actions);
		self::assertSame(0, $report->permanentlyDeleted);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
		self::assertSame('File is locked', $this->state->exports[123]['last_error']);
	}

	public function testMissingDocumentWithoutAFileIsForgottenWithoutADeletion(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->paperless->downloadException = new RuntimeException('Paperless is unavailable');
		$this->exportDocument();
		$this->paperless->documents = [];

		$report = $this->service->run();

		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame(0, $report->errors);
		self::assertSame([], $this->state->exports);
	}

	public function testPermanentDeletionWaitsWhenTheBatchIsFull(): void {
		$this->configService->save(['missing_grace_runs' => 1, 'permanent_delete' => true, 'allow_direct_delete' => true]);
		$this->exportDocument();
		$this->configService->save(['batch_size' => 1]);
		$this->paperless->documents = [$this->document(124)];
		$this->paperless->contents[124] = '%PDF-124';

		$report = $this->service->run();

		self::assertSame(0, $report->permanentlyDeleted);
		self::assertSame(1, $report->skipped);
		self::assertArrayHasKey(self::EXPORTED_PATH, $this->storage->files);
	}

	public function testFoldersAreKeptWhenPruningIsOff(): void {
		$this->configService->save(['prune_empty_folders' => false, 'missing_grace_runs' => 1]);
		$this->exportDocuments([$this->document(), $this->document(124)]);
		$excluded = $this->paperless->documents[0];
		$excluded['tags'] = [9];
		$this->paperless->tagInfo = ['names' => ['9' => 'Inbox'], 'inbox' => ['9']];
		$this->paperless->documents = [$excluded];

		$report = $this->service->run();

		self::assertSame(1, $report->removedExcluded);
		self::assertSame(1, $report->movedToTrash);
		self::assertSame(0, $report->foldersPruned);
		self::assertSame([], $this->storage->prunes);
	}

	public function testDryRunDoesNotMutateFilesOrState(): void {
		$this->paperless->documents = [$this->document()];
		$this->paperless->contents[123] = '%PDF-content';
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		$report = $this->service->run(true);
		self::assertSame(1, $report->exported);
		self::assertSame([], $this->storage->files);
		self::assertSame([], $this->state->exports);
		self::assertSame(0, $this->paperless->downloads);
	}

	public function testDryRunReportsAMoveAsMove(): void {
		$this->exportDocument();
		$this->paperless->documents[0]['title'] = 'Strom August korrigiert';

		$report = $this->service->run(true);

		self::assertSame(['MOVE P123: ' . self::ARCHIVE . '/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August korrigiert [P123].pdf'], $report->actions);
		self::assertSame(1, $report->moved);
		self::assertSame('dry-run-completed', $this->settings['status_last_state']);
	}

	public function testDisabledDirectionsAreNotSynchronized(): void {
		$this->configService->save(['inbox_enabled' => false, 'export_enabled' => false]);
		$this->paperless->documents = [$this->document()];
		$this->storage->files['Dokumente/Paperless/Eingang/police.pdf'] = '%PDF-inbox';

		$report = $this->service->run();

		self::assertSame(0, $report->activeDocuments);
		self::assertSame(0, $report->importsSubmitted);
		self::assertSame([], $this->state->exports);
	}

	public function testScheduleFlagAvoidsNextcloudReservedEnabledKey(): void {
		$this->configService->save(['enabled' => true]);

		self::assertTrue($this->settings['sync_enabled']);
		self::assertArrayNotHasKey('enabled', $this->settings);
	}

	/** Expects the warning about the error of the document P123, once in every run that follows. */
	private function expectDocumentWarning(string $path, string $error): void {
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not synchronize the document P{documentId}: {error}',
			['documentId' => 123, 'path' => $path, 'error' => $error],
		);
	}

	/** @param array<string, mixed>|null $document */
	private function exportDocument(?array $document = null): SyncReport {
		return $this->exportDocuments([$document ?? $this->document()]);
	}

	/**
	 * A document that an earlier version exported, with the SHA-256 alone as its fingerprint, on a
	 * Paperless-ngx 2 that reports MD5 checksums, after a new revision of the document.
	 */
	private function exportWithFingerprintOfAnEarlierVersionOnPaperless2(): void {
		$this->paperless->md5Checksums = true;
		$this->exportDocument();
		$this->state->exports[123]['fingerprint'] = hash('sha256', '%PDF-content');
		$this->paperless->documents[0]['modified'] = '2026-08-26T11:00:00+02:00';
	}

	/** @param list<array<string, mixed>> $documents */
	private function exportDocuments(array $documents): SyncReport {
		$this->paperless->documents = $documents;
		foreach ($documents as $document) {
			$this->paperless->contents[(int)$document['id']] = '%PDF-content';
		}
		$this->paperless->correspondents = ['4' => 'Energie GmbH'];
		$this->paperless->documentTypes = ['7' => 'Rechnung'];

		return $this->service->run();
	}

	/** @return list<array<string, mixed>> the documents with the IDs 1 to $count */
	private function documents(int $count): array {
		return array_map(fn (int $id): array => $this->document($id), range(1, $count));
	}

	/** The path of the copy of a document in the archive. */
	private function archivePath(int $id): string {
		return self::ARCHIVE . "/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/** The path of the copy of a document that a run of today moves to the deleted folder. */
	private function deletedToday(int $id = 123): string {
		return self::ARCHIVE . '/_Gelöscht/' . gmdate('Y-m-d') . "/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	/** The path of the copy of a document that Paperless moved to its trash on 2026-08-27. */
	private function trashFolderPath(int $id = 123): string {
		return self::ARCHIVE . "/_Gelöscht/2026-08-27/Energie GmbH/Rechnung/2026/2026-08-26 - Strom August [P{$id}].pdf";
	}

	private function trashDocument(): void {
		$trashed = $this->paperless->documents[0];
		$trashed['deleted_at'] = '2026-08-27T08:00:00+02:00';
		$this->paperless->documents = [];
		$this->paperless->trash = [$trashed];
	}

	/** @return array<string, mixed> */
	private function document(int $id = 123): array {
		return [
			'id' => $id,
			'title' => 'Strom August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'tags' => [],
			'created' => '2026-08-26',
			'added' => '2026-08-26T10:00:00+02:00',
			'modified' => '2026-08-26T10:00:00+02:00',
			'original_file_name' => 'scan.pdf',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];
	}
}
