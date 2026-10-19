<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\Plan\ExportAction;
use OCA\PaperlessSync\Model\Plan\ExportCopy;
use OCA\PaperlessSync\Model\Plan\Failed;
use OCA\PaperlessSync\Model\Plan\ForgetState;
use OCA\PaperlessSync\Model\Plan\MoveToDeleted;
use OCA\PaperlessSync\Model\Plan\RemoveCopy;
use OCA\PaperlessSync\Model\Plan\SaveState;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\WaitForMissing;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ExportFilter;
use OCA\PaperlessSync\Service\ExportPlanner;
use OCA\PaperlessSync\Service\ExportStates;
use OCA\PaperlessSync\Service\SyncBatch;
use OCA\PaperlessSync\Service\TargetFiles;
use OCA\PaperlessSync\Service\UserTimeZones;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCA\PaperlessSync\Tests\Doubles\TestTimeZones;
use OCP\Config\IUserConfig;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The plan of the export decides what a run does with each document, and a dry-run takes the
 * same plan. The tests describe each action of a plan in a line, and take its place in the batch
 * as a dry-run does, in which every change succeeds.
 */
final class ExportPlannerTest extends TestCase {
	private const ARCHIVE = TestConfig::ARCHIVE;
	/** The day on which Paperless moved the documents of the tests to its trash, in UTC. */
	private const TRASH_DAY = '2026-08-27';
	/** The tags of Paperless: 9 is a tag of its inbox, 8 the tag that the settings exclude. */
	private const TAGS = ['names' => ['9' => 'Posteingang', '8' => 'Privat'], 'inbox' => ['9']];

	private TestStorage $storage;
	private TestStateRepository $state;
	private TestPaperlessClient $paperless;
	private TestClock $clock;
	private int $heartbeats = 0;
	private int $missingActive = 0;

	protected function setUp(): void {
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->paperless = new TestPaperlessClient();
		$this->clock = new TestClock();
	}

	/**
	 * What the plan does with an active document: it keeps, exports or moves its copy, and leaves
	 * those out that the settings exclude or that wait for a later run.
	 *
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function activeDocuments(): array {
		return [
			'a new document is exported' => [
				['documents' => [[1, 'A']]],
				['export P1: ' . self::path('A', 1)],
			],
			'an unchanged document stays' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'A']]],
				['unchanged P1'],
			],
			'a new title moves the copy, which keeps its content' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'B']]],
				['move P1: ' . self::path('A', 1) . ' -> ' . self::path('B', 1)],
			],
			'a new revision with the same content stays, with the checksum that Paperless reported' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'A', 'm2']]],
				['unchanged P1 with checksum'],
			],
			'a new revision with a new content is written' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'A', 'm2']], 'contents' => [1 => '%PDF-new']],
				['export P1: ' . self::path('A', 1)],
			],
			'a new title and a new content move the copy and write it in place' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'B', 'm2']], 'contents' => [1 => '%PDF-new']],
				['export P1: ' . self::path('A', 1) . ' -> ' . self::path('B', 1)],
			],
			'a copy that is gone is written again' => [
				['exported' => [[1, 'A']], 'gone' => [self::path('A', 1)], 'documents' => [[1, 'A']]],
				['export P1: ' . self::path('A', 1)],
			],
			'a file that two documents share is not moved but written anew, and left behind' => [
				['exported' => [[1, 'A'], [2, 'A']], 'shared' => true, 'documents' => [[1, 'B']]],
				['export P1: ' . self::path('B', 1) . ', removes ' . self::path('A', 1)],
			],
			'the newest documents go first, and the others wait for the batch of a later run' => [
				['documents' => [[1, 'A'], [3, 'C'], [2, 'B']], 'settings' => ['batchSize' => 2]],
				['export P3: ' . self::path('C', 3), 'export P2: ' . self::path('B', 2), 'skipped P1'],
			],
			'a document whose last change failed follows the others' => [
				['exported' => [[3, 'C', 'm1', ['last_error' => 'locked']]], 'gone' => [self::path('C', 3)], 'documents' => [[3, 'C'], [1, 'A']]],
				['export P1: ' . self::path('A', 1), 'export P3: ' . self::path('C', 3)],
			],
			'a document of the inbox of Paperless is left out' => [
				['documents' => [[1, 'A', 'm1', [9]]]],
				['skipped'],
			],
			'the copy of a document with an excluded tag is removed, whatever the case of the tag' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'A', 'm1', [['id' => 8]]]], 'settings' => ['excludedTags' => 'privat']],
				['skipped', 'remove-excluded P1: ' . self::path('A', 1)],
			],
			'the state of a document with an excluded tag and without a copy is forgotten' => [
				['exported' => [[1, 'A']], 'gone' => [self::path('A', 1)], 'documents' => [[1, 'A', 'm1', [8]]], 'settings' => ['excludedTags' => 'Privat']],
				['skipped', 'forget P1'],
			],
			'the copy of an excluded document waits while the batch is full' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'A', 'm1', [9]], [2, 'B']], 'settings' => ['batchSize' => 1]],
				['export P2: ' . self::path('B', 2), 'skipped'],
			],
			'a file of the user at the path is a conflict when conflicts are skipped, which takes its place in the batch' => [
				['files' => [self::path('A', 2) => 'a file of the user'], 'documents' => [[2, 'A'], [1, 'B']], 'settings' => ['conflictMode' => 'skip', 'batchSize' => 1]],
				['failed P2: Nextcloud file conflict at ' . self::path('A', 2) . '. (attempt)', 'skipped P1'],
			],
			'the copy of the app is written in place when conflicts are skipped' => [
				['exported' => [[1, 'A']], 'documents' => [[1, 'A', 'm2']], 'contents' => [1 => '%PDF-new'], 'settings' => ['conflictMode' => 'skip']],
				['export P1: ' . self::path('A', 1) . ', in place'],
			],
			'a copy that the user changed stays at its path when conflicts are skipped' => [
				['exported' => [[1, 'A']], 'files' => [self::path('A', 1) => 'changed by the user'], 'documents' => [[1, 'B', 'm2']], 'contents' => [1 => '%PDF-new'], 'settings' => ['conflictMode' => 'skip']],
				['failed P1: Nextcloud file conflict at ' . self::path('A', 1) . '. (attempt)'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $case
	 * @param list<string> $expected
	 */
	#[DataProvider('activeDocuments')]
	public function testPlansTheActiveDocuments(array $case, array $expected): void {
		$documents = $this->arrange($case);

		self::assertSame($expected, $this->plan($documents, [], false, $case['settings'] ?? []));
	}

	/**
	 * What the plan does with a document in the trash of Paperless.
	 *
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function trashedDocuments(): array {
		return [
			'the copy moves to the deleted folder, into the folder of the day of the deletion' => [
				['exported' => [[1, 'A']], 'trash' => [1]],
				['trash P1: ' . self::path('A', 1) . ' -> ' . self::ARCHIVE . '/_Gelöscht/' . self::TRASH_DAY . '/A [P1].pdf'],
			],
			// Without an executor, the state keeps its missing scans, which the run resets with the
			// save of the trash: the plan resets them again, as a dry-run does, without a write.
			'a copy in the deleted folder stays' => [
				['exported' => [[1, 'A', 'm1', ['state' => 'trash', 'missing_runs' => 2]]], 'trash' => [1]],
				['unchanged P1', 'save P1: missing_runs=0'],
			],
			'the keep policy only marks the document' => [
				['exported' => [[1, 'A']], 'trash' => [1], 'settings' => ['trashMode' => 'keep']],
				['save P1: state=trash, missing_runs=0'],
			],
			'a copy that is gone only marks the document' => [
				['exported' => [[1, 'A']], 'gone' => [self::path('A', 1)], 'trash' => [1]],
				['save P1: state=trash, missing_runs=0'],
			],
			'a document that the run never exported has no action' => [
				['trash' => [1]],
				[],
			],
			'the move waits while the batch is full' => [
				['exported' => [[1, 'A']], 'trash' => [1], 'documents' => [[2, 'B']], 'settings' => ['batchSize' => 1]],
				['export P2: ' . self::path('B', 2), 'skipped'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $case
	 * @param list<string> $expected
	 */
	#[DataProvider('trashedDocuments')]
	public function testPlansTheTrash(array $case, array $expected): void {
		$documents = $this->arrange($case);
		$trash = array_map(static fn (int $id): array => self::document($id, 'A') + ['deleted_at' => '2026-08-27T08:00:00+02:00'], $case['trash']);

		self::assertSame($expected, $this->plan($documents, $trash, true, $case['settings'] ?? []));
	}

	/**
	 * What the plan does with an exported document that Paperless no longer lists; the deleted
	 * folder of the day of the run is DAY.
	 *
	 * @return array<string, array{array<string, mixed>, list<string>, int}>
	 */
	public static function missingDocuments(): array {
		$deleted = self::ARCHIVE . '/_Gelöscht/DAY/A [P1].pdf';

		return [
			'the copy waits for the missing scans that the settings require' => [
				['exported' => [[1, 'A']]],
				['wait P1: 1/3'],
				1,
			],
			'the copy moves to the deleted folder after the last of them' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]]],
				['missing P1: ' . self::path('A', 1) . ' -> ' . $deleted],
				1,
			],
			'an incomplete scan counts no document as missing' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]], 'incomplete' => true],
				[],
				0,
			],
			'the copy of a document that Paperless deleted from its trash is deleted, when the settings allow it' => [
				['exported' => [[1, 'A', 'm1', ['state' => 'trash', 'missing_runs' => 2]]], 'settings' => ['permanentDelete' => true]],
				['delete P1: ' . self::path('A', 1)],
				0,
			],
			'the state of a deleted document without a copy is forgotten' => [
				['exported' => [[1, 'A', 'm1', ['state' => 'trash', 'missing_runs' => 2]]], 'gone' => [self::path('A', 1)], 'settings' => ['permanentDelete' => true]],
				['forget P1'],
				0,
			],
			'a document deleted without the trash moves to the deleted folder without direct deletion' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]], 'settings' => ['permanentDelete' => true]],
				['missing P1: ' . self::path('A', 1) . ' -> ' . $deleted],
				1,
			],
			'a document deleted without the trash is deleted with direct deletion' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]], 'settings' => ['permanentDelete' => true, 'allowDirectDelete' => true]],
				['delete P1: ' . self::path('A', 1)],
				1,
			],
			'the deletion waits while the batch is full' => [
				['exported' => [[1, 'A', 'm1', ['state' => 'trash', 'missing_runs' => 2]]], 'documents' => [[2, 'B']], 'settings' => ['permanentDelete' => true, 'batchSize' => 1]],
				['export P2: ' . self::path('B', 2), 'skipped'],
				0,
			],
			'the move waits while the batch is full' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]], 'documents' => [[2, 'B']], 'settings' => ['batchSize' => 1]],
				['export P2: ' . self::path('B', 2), 'skipped'],
				1,
			],
			'the keep policy only marks the document' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]], 'settings' => ['trashMode' => 'keep']],
				['save P1: state=missing, missing_runs=3'],
				1,
			],
			'a document that Paperless lists again starts its count anew' => [
				['exported' => [[1, 'A', 'm1', ['missing_runs' => 2]]], 'documents' => [[1, 'A']]],
				['unchanged P1', 'save P1: missing_runs=0'],
				0,
			],
			'a state without a valid document ID is left alone' => [
				['exported' => [[0, 'A', 'm1', ['missing_runs' => 2]]]],
				[],
				0,
			],
		];
	}

	/**
	 * @param array<string, mixed> $case
	 * @param list<string> $expected
	 */
	#[DataProvider('missingDocuments')]
	public function testPlansTheMissingDocuments(array $case, array $expected, int $missingActive): void {
		$documents = $this->arrange($case);

		$plan = $this->plan($documents, [], !($case['incomplete'] ?? false), $case['settings'] ?? []);

		self::assertSame(str_replace('DAY', $this->clock->today(), $expected), $plan);
		self::assertSame($missingActive, $this->missingActive, 'The dry-run warns of a trash that it cannot see by this count.');
	}

	/**
	 * A document with a new path changes, whatever its content, so while the batch is full it waits
	 * for a later run, and so do the question for its checksum and the read of its copy.
	 */
	public function testADocumentThatWaitsForALaterRunCostsNoQuestionToPaperless(): void {
		$documents = $this->arrange(['exported' => [[1, 'A']], 'documents' => [[1, 'B', 'm2'], [2, 'C']]]);

		self::assertSame(['export P2: ' . self::path('C', 2), 'skipped'], $this->plan($documents, [], false, ['batchSize' => 1]));
		self::assertSame(0, $this->paperless->checksumRequests);
		self::assertSame([], $this->storage->reads);
	}

	/** A document at the same path learns its checksum while the batch is full, so that a later run doesn't ask for it again. */
	public function testADocumentThatWaitsForALaterRunKeepsTheChecksumThatItLearned(): void {
		$documents = $this->arrange(['exported' => [[1, 'A']], 'documents' => [[1, 'A', 'm2'], [2, 'C']], 'contents' => [1 => '%PDF-new']]);

		$plan = $this->plan($documents, [], false, ['batchSize' => 1]);

		self::assertSame(['export P2: ' . self::path('C', 2), 'skipped P1 with remote_checksum, remote_revision, fingerprint'], $plan);
		self::assertSame(1, $this->paperless->checksumRequests);
	}

	/**
	 * Documents whose last change failed take turns: the one that failed longest ago first, one
	 * without a time of its last run before all others, and the newest of the same time first.
	 */
	public function testDocumentsWhoseChangeFailedTakeTurns(): void {
		$documents = $this->arrange([
			'exported' => [[2, 'B', 'm1', ['last_error' => 'locked', 'last_seen' => 50]], [3, 'C', 'm1', ['last_error' => 'locked', 'last_seen' => 50]], [4, 'D', 'm1', ['last_error' => 'locked']], [5, 'E', 'm1', ['last_error' => 'locked', 'last_seen' => 1]], [6, 'F', 'm1', ['last_error' => 'locked', 'last_seen' => 10]]],
			'documents' => [[2, 'B2', 'm1'], [3, 'C2', 'm1'], [4, 'D2', 'm1'], [5, 'E2', 'm1'], [6, 'F2', 'm1'], [1, 'A']],
		]);

		$plan = $this->plan($documents, [], false);

		self::assertSame(['export P1', 'move P4', 'move P5', 'move P6', 'move P3', 'move P2'], array_map(static fn (string $line): string => (string)strstr($line, ':', true), $plan));
	}

	/** Paperless is asked once for the checksum of each revision: the state keeps the answer. */
	public function testAChecksumThatTheStateKeepsIsNotAskedForAgain(): void {
		$documents = $this->arrange(['exported' => [[1, 'A', 'm1', ['remote_checksum' => hash('sha256', '%PDF-1'), 'remote_revision' => 'archive:m2']]], 'documents' => [[1, 'A', 'm2']]]);

		self::assertSame(['unchanged P1'], $this->plan($documents, [], false));
		self::assertSame(0, $this->paperless->checksumRequests);
	}

	/** A path that the template can't render fails the document before it takes a place in the batch. */
	public function testAPathThatFailsLeavesThePlaceOfItsDocumentToTheNext(): void {
		$documents = [self::document(2, 'fails'), self::document(1, 'A')];

		self::assertSame(['failed P2: No path for fails', 'export P1: ' . self::path('A', 1)], $this->plan($documents, [], false, ['batchSize' => 1]));
	}

	/** A question to Nextcloud that fails concerns only its document, which the report names in its part of the run. */
	public function testAFailedQuestionToNextcloudFailsOnlyItsDocument(): void {
		$documents = $this->arrange([
			'exported' => [[1, 'A'], [2, 'B'], [3, 'C', 'm1', ['missing_runs' => 2]]],
			'documents' => [[1, 'A', 'm1', [9]]],
		]);
		foreach ([1 => 'A', 2 => 'B', 3 => 'C'] as $id => $title) {
			$this->storage->existsFailures[self::path($title, $id)] = new NotPermittedException("Storage of P{$id} is not available");
		}
		$trash = [self::document(2, 'B') + ['deleted_at' => '2026-08-27T08:00:00+02:00']];

		self::assertSame([
			'skipped',
			'failed Excluded P1: Storage of P1 is not available',
			'failed Trash P2: Storage of P2 is not available',
			'failed Missing P3: Storage of P3 is not available',
		], $this->plan($documents, $trash, true));
	}

	/**
	 * Per-user ownership: the copy of a document that the archive of another user takes now is
	 * removed, within the batch, and one of a document whose user the run can't tell stays; neither
	 * counts as missing, as Paperless lists both.
	 */
	public function testTheCopyOfADocumentOfAnotherUserLeavesTheArchive(): void {
		$this->arrange(['exported' => [[1, 'A'], [2, 'B'], [3, 'C', 'm1', ['missing_runs' => 1]], [4, 'D']], 'documents' => []]);
		unset($this->storage->files[self::path('D', 4)]);

		self::assertSame([
			'remove-reassigned P1: ' . self::path('A', 1),
			'forget P4',
			// Paperless lists it again, so its count of missing scans starts anew.
			'save P3: missing_runs=0',
		], $this->plan([], [], true, ['missingGraceRuns' => 1], [1 => true, 2 => false, 3 => false, 4 => true, 5 => true]));
		self::assertSame(0, $this->missingActive);
	}

	public function testTheCopyOfADocumentOfAnotherUserWaitsWhileTheBatchIsFull(): void {
		$documents = $this->arrange(['exported' => [[1, 'A']], 'documents' => [[2, 'B']]]);

		self::assertSame(['export P2: ' . self::path('B', 2)], $this->plan($documents, [], true, ['batchSize' => 1], [1 => true]));
	}

	/** The run of one document knows neither the trash nor which documents are gone. */
	public function testThePlanOfOneDocumentLeavesTheTrashAndTheMissingDocuments(): void {
		$documents = $this->arrange(['exported' => [[2, 'B', 'm1', ['missing_runs' => 2]]], 'documents' => [[1, 'A']]]);

		self::assertSame(['export P1: ' . self::path('A', 1)], $this->plan($documents, null, true));
		self::assertSame(1, $this->heartbeats);
	}

	/** Each document that the plan reaches, active, in the trash or missing, is a sign of life of the run. */
	public function testEveryDocumentIsASignOfLife(): void {
		$documents = $this->arrange(['exported' => [[2, 'B'], [3, 'C']], 'documents' => [[1, 'A']]]);

		$this->plan($documents, [self::document(2, 'B') + ['deleted_at' => '']], true);

		self::assertSame(4, $this->heartbeats, 'One active document, one in the trash and two states.');
	}

	public function testADocumentWithoutAValidIdFailsThePlan(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Paperless returned a document without a valid ID.');

		$this->plan([['id' => 'x']], [], true);
	}

	public function testATrashedDocumentWithoutAValidIdFailsThePlan(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Paperless returned a document without a valid ID.');

		$this->plan([], [['id' => 0]], true);
	}

	/**
	 * Sets up the exported documents, with their copies and states, the other files and the
	 * contents in Paperless, and returns the active documents.
	 *
	 * @param array<string, mixed> $case
	 * @return list<array<string, mixed>>
	 */
	private function arrange(array $case): array {
		/** @var list<array{0: int, 1: string, 2?: string, 3?: array<string, int|string|null>}> $exported */
		$exported = $case['exported'] ?? [];
		foreach ($exported as $export) {
			[$id, $title] = $export;
			$content = "%PDF-{$id}";
			$path = ($case['shared'] ?? false) ? self::path('A', 1) : self::path($title, $id);
			$this->storage->files[$path] = $content;
			$this->state->exports[$id] = array_merge(['document_id' => $id], TestStateRepository::EXPORT_DEFAULTS, [
				'path' => $path,
				'fingerprint' => hash('sha256', $content) . ' ' . hash('md5', $content),
				'source_revision' => 'archive:' . ($export[2] ?? 'm1'),
			], $export[3] ?? []);
		}
		foreach ($case['gone'] ?? [] as $path) {
			unset($this->storage->files[$path]);
		}
		foreach ($case['files'] ?? [] as $path => $content) {
			$this->storage->files[$path] = $content;
		}
		$documents = [];
		/** @var list<array{0: int, 1: string, 2?: string, 3?: list<mixed>}> $active */
		$active = $case['documents'] ?? [];
		foreach ($active as $document) {
			$id = $document[0];
			$this->paperless->contents[$id] = $case['contents'][$id] ?? "%PDF-{$id}";
			$documents[] = self::document($id, $document[1], $document[2] ?? 'm1', $document[3] ?? []);
		}
		$this->storage->reads = [];

		return $documents;
	}

	/**
	 * Plans the export and takes the place of each action in the batch, as a dry-run does, in which
	 * every change succeeds; the answer describes each action in a line.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @param list<array<string, mixed>>|null $trash
	 * @param array<string, bool|int|string> $settings
	 * @return list<string>
	 */
	private function plan(array $documents, ?array $trash, bool $complete, array $settings = [], array $others = []): array {
		$config = TestConfig::of($settings);
		$batch = new SyncBatch($config->batchSize);
		$planner = $this->planner($config, $batch);
		$lines = [];
		foreach ($planner->plan($documents, $trash, $complete, $others) as $action) {
			$lines[] = self::describe($action);
			if ($action instanceof ExportCopy || ($action instanceof Failed && $action->attempt)) {
				$batch->take(SyncBatch::EXPORT);
				if ($action instanceof ExportCopy) {
					$batch->succeeded();
				}
			} elseif ($action instanceof MoveToDeleted) {
				$batch->take(SyncBatch::WRITE);
			} elseif ($action instanceof RemoveCopy) {
				$batch->take(SyncBatch::DELETE);
			}
		}
		$this->missingActive = $planner->missingActive();

		return $lines;
	}

	private function planner(SyncConfig $config, SyncBatch $batch): ExportPlanner {
		$this->paperless->tagInfo = self::TAGS;

		return new ExportPlanner(
			$config,
			new ExportStates($this->state, TestStorage::USER),
			$batch,
			new TargetFiles($this->storage, TestStorage::USER),
			$this->paperless,
			static function (array $document): string {
				$title = (string)($document['title'] ?? '');
				if ($title === 'fails') {
					throw new RuntimeException("No path for {$title}");
				}

				return $title . ' [P' . (int)($document['id'] ?? 0) . '].pdf';
			},
			new ExportFilter($config, self::TAGS),
			new UserTimeZones($this->createStub(IUserConfig::class), new TestTimeZones()),
			$this->clock,
			self::ARCHIVE,
			function (): void {
				++$this->heartbeats;
			},
		);
	}

	private static function describe(ExportAction $action): string {
		return match (true) {
			$action instanceof ExportCopy => $action->type() . " P{$action->context->document}: "
				. ($action->move ? $action->oldPath . ' -> ' : '') . $action->target
				. ($action->download && $action->ownTarget && !$action->move ? ', in place' : '')
				. ($action->removesOldCopy() ? ', removes ' . $action->oldPath : ''),
			$action instanceof MoveToDeleted => "{$action->type} P{$action->context->document}: {$action->path} -> {$action->target}",
			$action instanceof RemoveCopy => "{$action->type} P{$action->context->document}: {$action->path}",
			$action instanceof Unchanged => 'unchanged' . ($action->document !== null ? " P{$action->document}" : '')
				. (isset($action->values['remote_checksum']) ? ' with checksum' : ''),
			$action instanceof Skipped => 'skipped' . ($action->document !== null ? " P{$action->document}" : '')
				. ($action->values !== [] ? ' with ' . implode(', ', array_keys($action->values)) : ''),
			$action instanceof SaveState => "save P{$action->document}: " . implode(', ', array_map(
				static fn (string $key): string => $key . '=' . (string)$action->values[$key],
				array_values(array_intersect(['state', 'missing_runs'], array_keys($action->values))),
			)),
			$action instanceof ForgetState => "forget P{$action->document}",
			$action instanceof WaitForMissing => "wait P{$action->document}: {$action->scans}/{$action->limit}",
			$action instanceof Failed => "failed {$action->context->label}P{$action->context->document}: {$action->exception->getMessage()}"
				. ($action->attempt ? ' (attempt)' : ''),
			default => $action::class,
		};
	}

	/**
	 * A document of Paperless with an archived file, as the tests need it.
	 *
	 * @param list<mixed> $tags
	 * @return array<string, mixed>
	 */
	private static function document(int $id, string $title, string $modified = 'm1', array $tags = []): array {
		return ['id' => $id, 'title' => $title, 'modified' => $modified, 'archived_file_name' => 'archive.pdf', 'tags' => $tags];
	}

	/** The path of the copy of a document in the archive. */
	private static function path(string $title, int $id): string {
		return self::ARCHIVE . "/{$title} [P{$id}].pdf";
	}
}
