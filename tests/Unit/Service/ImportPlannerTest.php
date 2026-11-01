<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\Plan\ForgetImport;
use OCA\PaperlessSync\Model\Plan\ImportAction;
use OCA\PaperlessSync\Model\Plan\ImportCheckFailed;
use OCA\PaperlessSync\Model\Plan\ImportFailed;
use OCA\PaperlessSync\Model\Plan\ImportSucceeded;
use OCA\PaperlessSync\Model\Plan\RetryUnfinished;
use OCA\PaperlessSync\Model\Plan\SetAside;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\Upload;
use OCA\PaperlessSync\Service\ImportPlanner;
use OCA\PaperlessSync\Service\SyncBatch;
use OCA\PaperlessSync\Service\TargetFiles;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestConfig;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The plan of the inbox decides how the uploads of earlier runs ended and which files go to
 * Paperless, and a dry-run takes the same plan. The tests describe each action in a line, with
 * the paths below the inbox, and take the place of each upload in the batch.
 */
final class ImportPlannerTest extends TestCase {
	private const INBOX = TestConfig::INBOX;
	/** A day, after which a task that Paperless has not finished ends. */
	private const DAY = 86400;

	private TestStorage $storage;
	private TestStateRepository $state;
	private TestPaperlessClient $paperless;
	private TestClock $clock;
	private int $heartbeats = 0;

	protected function setUp(): void {
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->paperless = new TestPaperlessClient();
		$this->clock = new TestClock();
	}

	/**
	 * Each case has the files of the inbox, by their paths below it, the states of their uploads,
	 * with 'changed' for a file that changed since its upload, the tasks of Paperless and the
	 * settings.
	 *
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function inboxes(): array {
		return [
			'a new file goes to Paperless' => [
				['files' => ['a.pdf']],
				['upload a.pdf'],
			],
			'a file in a folder goes with the folder, for its tags' => [
				['files' => ['Steuern/2026/a.pdf']],
				['upload Steuern/2026/a.pdf in Steuern/2026'],
			],
			'the batch leaves the other files for a later run' => [
				['files' => ['a.pdf', 'b.pdf'], 'settings' => ['batchSize' => 1]],
				['upload a.pdf', 'skipped'],
			],
			'a file that Paperless imported stays' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'success']]],
				['unchanged'],
			],
			'a file that changed after its import goes to Paperless again' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'success', 'changed' => true]]],
				['forget a.pdf', 'upload a.pdf'],
			],
			'the state of a file that is gone is forgotten' => [
				['imports' => ['gone.pdf' => ['status' => 'pending']]],
				['forget gone.pdf'],
			],
			'a file that Paperless refused waits until it changes' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'rejected']]],
				['skipped'],
			],
			'a refused file that changed goes to Paperless again' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'rejected', 'changed' => true]]],
				['forget a.pdf', 'upload a.pdf'],
			],
			'a file whose upload failed waits for its next attempt' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'retry', 'retry_at' => TestClock::START + 60]]],
				['skipped'],
			],
			'a file whose next attempt is due goes to Paperless, with the count of its attempts' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'retry', 'retry_at' => TestClock::START, 'attempts' => 2]]],
				['upload a.pdf after 2 attempts'],
			],
			'a video, a program and an archive stay aside, and only the document goes to Paperless' => [
				['files' => ['Aus Videos/film.mp4', 'Aus Downloads/setup.exe', 'Aus Downloads/backup.zip', 'Aus Downloads/rechnung.pdf']],
				['set aside Aus Videos/film.mp4 as video', 'set aside Aus Downloads/setup.exe as program', 'set aside Aus Downloads/backup.zip as archive', 'upload Aus Downloads/rechnung.pdf in Aus Downloads'],
			],
			'a file set aside takes no place in the batch, and a full batch still names it' => [
				['files' => ['a.pdf', 'b.mp4', 'c.pdf', 'd.mp4'], 'settings' => ['batchSize' => 1]],
				['upload a.pdf', 'set aside b.mp4 as video', 'skipped', 'set aside d.mp4 as video'],
			],
			'an upload of such a type that Paperless has already still ends' => [
				['files' => ['a.zip'], 'imports' => ['a.zip' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'SUCCESS', 'message' => '', 'documentId' => 7]]],
				['succeeded a.zip as P7'],
			],
			'a file of such a type that Paperless imported stays as it is' => [
				['files' => ['a.zip'], 'imports' => ['a.zip' => ['status' => 'success']]],
				['unchanged'],
			],
			'a file of such a type that changed after its import stays aside' => [
				['files' => ['a.zip'], 'imports' => ['a.zip' => ['status' => 'success', 'changed' => true]]],
				['forget a.zip', 'set aside a.zip as archive'],
			],
			'a file of such a type that Paperless refused before stays aside, with no state left' => [
				['files' => ['a.mp4'], 'imports' => ['a.mp4' => ['status' => 'rejected']]],
				['forget a.mp4', 'set aside a.mp4 as video'],
			],
			'a file of such a type whose next attempt is due goes no more' => [
				['files' => ['a.exe'], 'imports' => ['a.exe' => ['status' => 'retry', 'retry_at' => TestClock::START, 'attempts' => 2]]],
				['forget a.exe', 'set aside a.exe as program'],
			],
			'a file of such a type that waits for its next attempt goes no more' => [
				['files' => ['a.exe'], 'imports' => ['a.exe' => ['status' => 'retry', 'retry_at' => TestClock::START + 60]]],
				['forget a.exe', 'set aside a.exe as program'],
			],
			'the other states still count after a file set aside' => [
				['files' => ['a.mp4', 'b.pdf'], 'imports' => ['a.mp4' => ['status' => 'rejected'], 'b.pdf' => ['status' => 'success']]],
				['forget a.mp4', 'unchanged', 'set aside a.mp4 as video'],
			],
			'a file set aside that is gone leaves nothing behind' => [
				['imports' => ['gone.mp4' => ['status' => 'rejected']]],
				['forget gone.mp4'],
			],
			'a task that succeeded ends the upload' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'SUCCESS', 'message' => '', 'documentId' => 7]]],
				['succeeded a.pdf as P7'],
			],
			'a task that succeeded for a file that changed since its upload' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'changed' => true]], 'tasks' => ['task-1' => ['status' => 'SUCCEEDED', 'message' => '']]],
				['succeeded a.pdf, changed since'],
			],
			'a task that failed moves the file to the error folder, below its folder' => [
				['files' => ['Steuern/a.pdf'], 'imports' => ['Steuern/a.pdf' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'FAILURE', 'message' => 'Broken PDF']]],
				['failed Steuern/a.pdf -> Fehler/Steuern/a.pdf: Broken PDF'],
			],
			'a file that moves to the error folder leaves the marker of its name behind, as only a file can stand for a document' => [
				['files' => ['Steuern [P7]/Scan [P999].pdf'], 'imports' => ['Steuern [P7]/Scan [P999].pdf' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'FAILURE', 'message' => 'Broken PDF']]],
				['failed Steuern [P7]/Scan [P999].pdf -> Fehler/Steuern [P7]/Scan.pdf: Broken PDF'],
			],
			'a file with nothing but a marker gets the name of a document without a title, and never the path of the error folder' => [
				['files' => ['[P999]'], 'imports' => ['[P999]' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'FAILURE', 'message' => 'Broken PDF']]],
				['failed [P999] -> Fehler/Ohne Titel: Broken PDF'],
			],
			'a task that Paperless cancelled' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'REVOKED', 'message' => '']]],
				['failed a.pdf -> Fehler/a.pdf: Paperless cancelled the task before it finished (REVOKED), so it created no document.'],
			],
			'a task that Paperless cancelled, with its reason' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'REVOKED', 'message' => 'Shutdown']]],
				['failed a.pdf -> Fehler/a.pdf: Paperless cancelled the task before it finished (REVOKED), so it created no document: Shutdown'],
			],
			'a task that failed without a reason' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending']], 'tasks' => ['task-1' => ['status' => 'ERROR', 'message' => '']]],
				['failed a.pdf -> Fehler/a.pdf: Paperless gave no reason; its log may have one.'],
			],
			'a task that Paperless has not finished yet waits' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY + 1]]],
				[],
			],
			'a task unfinished a day after the upload succeeded when Paperless has the document' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY]], 'originals' => ['a.pdf' => 9]],
				['succeeded a.pdf as P9'],
			],
			'a task that Paperless still lists a day after the upload moves the file to the error folder' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY]], 'tasks' => ['task-1' => ['status' => 'STARTED', 'message' => 'Running OCR']]],
				['failed a.pdf -> Fehler/a.pdf: Paperless has not finished the import a day after the upload (STARTED: Running OCR), so it may still create the document. (Paperless has not finished the import task task-1 a day after the upload (STARTED: Running OCR))'],
			],
			'a file of a task that Paperless still lists a day after the upload leaves its marker behind, too' => [
				['files' => ['a [P999].pdf'], 'imports' => ['a [P999].pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY]], 'tasks' => ['task-1' => ['status' => 'STARTED', 'message' => '']]],
				['failed a [P999].pdf -> Fehler/a.pdf: Paperless has not finished the import a day after the upload (STARTED), so it may still create the document. (Paperless has not finished the import task task-1 a day after the upload (STARTED))'],
			],
			'a task that Paperless lost goes to Paperless again' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY, 'attempts' => 1]], 'tasks' => ['task-1' => ['status' => 'PENDING', 'message' => '', 'listed' => false]]],
				['retry a.pdf after 2: Paperless has not finished the import task task-1 a day after the upload (PENDING)'],
			],
			'a file that changed since its unfinished upload goes to Paperless again' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY, 'changed' => true]]],
				['retry a.pdf after 1: Paperless has not finished the import task task-1 a day after the upload (PENDING)'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $case
	 * @param list<string> $expected
	 */
	#[DataProvider('inboxes')]
	public function testPlansTheInbox(array $case, array $expected): void {
		/** @var list<string> $files */
		$files = $case['files'] ?? [];
		foreach ($files as $file) {
			$this->storage->files[self::INBOX . '/' . $file] = "%PDF-{$file}";
		}
		/** @var array<string, array<string, int|string|bool>> $imports */
		$imports = $case['imports'] ?? [];
		foreach ($imports as $file => $import) {
			$path = self::INBOX . '/' . $file;
			$changed = (bool)($import['changed'] ?? false);
			unset($import['changed']);
			$this->state->imports[$path] = $import + [
				'path' => $path,
				'etag' => hash('sha256', $changed ? 'before' : "%PDF-{$file}"),
				'task_id' => 'task-1',
				'submitted_at' => TestClock::START,
				'attempts' => 0,
				'retry_at' => 0,
				'last_error' => null,
			];
		}
		/** @var array<string, array{status: string, message: string, documentId?: int|null, listed?: bool}> $tasks */
		$tasks = $case['tasks'] ?? [];
		$this->paperless->tasks = $tasks;
		/** @var array<string, int> $originals */
		$originals = $case['originals'] ?? [];
		foreach ($originals as $file => $document) {
			$this->paperless->originals[hash('sha256', "%PDF-{$file}")] = $document;
		}

		self::assertSame($expected, $this->plan($case['settings'] ?? []));
	}

	/**
	 * Names that lost their markers may meet in the error folder. Such a file takes the next free
	 * number there, so that it replaces no other file and, with the conflict policy skip, ends
	 * without a conflict.
	 *
	 * @return array<string, array{string, list<string>, string}>
	 */
	public static function namesInTheErrorFolder(): array {
		return [
			'the name is free' => ['Scan [P999].pdf', ['Andere.pdf'], 'Scan.pdf'],
			'the name is taken' => ['Scan [P999].pdf', ['Scan.pdf'], 'Scan (2).pdf'],
			'the first numbers are taken' => ['Scan [P999].pdf', ['Scan.pdf', 'Scan (2).pdf', 'Scan (3).pdf'], 'Scan (4).pdf'],
			'the number comes before the last extension' => ['Scan [P999].tar.pdf', ['Scan.tar.pdf'], 'Scan.tar (2).pdf'],
			'a name without an extension' => ['Scan [P999]', ['Scan'], 'Scan (2)'],
			'a name that takes the fallback' => ['[P999].pdf', ['Ohne Titel.pdf'], 'Ohne Titel (2).pdf'],
			'a file without a marker stays at its path, where the conflict policy decides' => ['Scan.pdf', ['Scan.pdf'], 'Scan.pdf'],
		];
	}

	/** @param list<string> $taken */
	#[DataProvider('namesInTheErrorFolder')]
	public function testANameThatLostItsMarkerTakesTheNextFreeNumber(string $name, array $taken, string $expected): void {
		$path = self::INBOX . '/' . $name;
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1'];
		$this->paperless->tasks = ['task-1' => ['status' => 'FAILURE', 'message' => 'Broken PDF']];
		foreach ($taken as $file) {
			$this->storage->files[TestConfig::ERRORS . '/' . $file] = 'there';
		}

		self::assertSame(["failed {$name} -> Fehler/{$expected}: Broken PDF"], $this->plan());
	}

	/** A look at the error folder that fails is a failure of the check: the file stays where it is, and the next run asks again. */
	public function testAFailedLookAtTheErrorFolderFailsOnlyItsFile(): void {
		$path = self::INBOX . '/a [P999].pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1'];
		$this->paperless->tasks = ['task-1' => ['status' => 'FAILURE', 'message' => 'Broken PDF']];
		$this->storage->existsFailures[TestConfig::ERRORS . '/a.pdf'] = new RuntimeException('Nextcloud is busy');

		self::assertSame(['check failed a [P999].pdf: Nextcloud is busy'], $this->plan());
	}

	/** A check of a task that fails keeps the upload pending, and the other uploads go on. */
	public function testATaskThatCannotBeCheckedFailsOnlyItsFile(): void {
		$this->storage->files[self::INBOX . '/a.pdf'] = '%PDF-a';
		$this->storage->files[self::INBOX . '/b.pdf'] = '%PDF-b';
		$this->state->imports[self::INBOX . '/a.pdf'] = ['path' => self::INBOX . '/a.pdf', 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1'];
		$this->paperless->taskException = new RuntimeException('Paperless is down');

		self::assertSame(['check failed a.pdf: Paperless is down', 'upload b.pdf'], $this->plan());
	}

	/** A search for the document of an unfinished task that fails is a failure of the check, too. */
	public function testAFailedSearchForTheDocumentOfAnUnfinishedTaskFailsOnlyItsFile(): void {
		$this->storage->files[self::INBOX . '/a.pdf'] = '%PDF-a';
		$this->state->imports[self::INBOX . '/a.pdf'] = ['path' => self::INBOX . '/a.pdf', 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => 0];
		$this->paperless->findException = new RuntimeException('Paperless is down');

		self::assertSame(['check failed a.pdf: Paperless is down'], $this->plan());
	}

	/** A large batch of scans goes through as a small one does: the number of files sets nothing aside. */
	public function testTheNumberOfFilesSetsNothingAside(): void {
		$expected = [];
		for ($scan = 1000; $scan < 1500; ++$scan) {
			$this->storage->files[self::INBOX . "/Stapel/{$scan}.pdf"] = "%PDF-{$scan}";
			$expected[] = "upload Stapel/{$scan}.pdf in Stapel";
		}

		self::assertSame($expected, $this->plan(['batchSize' => 500]));
	}

	/** Each file that may go to Paperless is a sign of life of the run, also one that waits for a later run. */
	public function testEveryFileThatMayGoToPaperlessIsASignOfLife(): void {
		$this->storage->files[self::INBOX . '/c.mp4'] = 'video';
		$this->storage->files[self::INBOX . '/a.pdf'] = '%PDF-a';
		$this->storage->files[self::INBOX . '/b.pdf'] = '%PDF-b';

		$this->plan(['batchSize' => 1]);

		self::assertSame(3, $this->heartbeats);
	}

	/** Only the inbox, and its folders when the settings say so, has files for Paperless. */
	public function testReadsTheFilesOfTheInboxAlone(): void {
		$this->storage->files[self::INBOX . '/Steuern/a.pdf'] = '%PDF-a';
		$this->storage->files[self::INBOX . '/b.pdf'] = '%PDF-b';
		$this->storage->files[TestConfig::ARCHIVE . '/c.pdf'] = '%PDF-c';

		self::assertSame(['upload b.pdf'], $this->plan(['recursiveInbox' => false]));
	}

	/**
	 * Plans the inbox and takes the place of each upload in the batch; the answer describes each
	 * action in a line.
	 *
	 * @param array<string, bool|int|string> $settings
	 * @return list<string>
	 */
	private function plan(array $settings = []): array {
		$config = TestConfig::of($settings);
		$batch = new SyncBatch($config->batchSize);
		$planner = new ImportPlanner(
			$config,
			$this->state,
			$batch,
			new TargetFiles($this->storage, TestStorage::USER),
			$this->paperless,
			$this->clock,
			self::INBOX,
			TestConfig::ERRORS,
			function (): void {
				++$this->heartbeats;
			},
		);
		$lines = [];
		foreach ($planner->plan() as $action) {
			$lines[] = self::describe($action);
			if ($action instanceof Upload) {
				$batch->take(SyncBatch::WRITE);
			}
		}

		return $lines;
	}

	private static function describe(ImportAction $action): string {
		return match (true) {
			$action instanceof Upload => 'upload ' . self::below($action->path)
				. ($action->attempts > 0 ? " after {$action->attempts} attempts" : '')
				. ($action->folder !== '' ? " in {$action->folder}" : ''),
			$action instanceof Unchanged => 'unchanged',
			$action instanceof Skipped => 'skipped',
			$action instanceof SetAside => 'set aside ' . self::below($action->path) . " as {$action->kind}",
			$action instanceof ForgetImport => 'forget ' . self::below($action->path),
			$action instanceof ImportSucceeded => 'succeeded ' . self::below($action->context->path)
				. ($action->sameContent ? '' : ', changed since')
				. ($action->document !== null ? " as P{$action->document}" : ''),
			$action instanceof ImportFailed => 'failed ' . self::below($action->context->path) . ' -> '
				. substr($action->destination, strlen('Dokumente/Paperless/')) . ": {$action->reason}"
				. ($action->unfinished !== '' ? " ({$action->unfinished})" : ''),
			$action instanceof RetryUnfinished => 'retry ' . self::below($action->context->path) . " after {$action->attempts}: {$action->error}",
			$action instanceof ImportCheckFailed => 'check failed ' . self::below($action->context->path) . ': ' . $action->exception->getMessage(),
			default => $action::class,
		};
	}

	/** The path below the inbox. */
	private static function below(string $path): string {
		return substr($path, strlen(self::INBOX) + 1);
	}
}
