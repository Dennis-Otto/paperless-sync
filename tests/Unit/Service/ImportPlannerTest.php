<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\PerUserScope;
use OCA\PaperlessSync\Model\Plan\ForgetImport;
use OCA\PaperlessSync\Model\Plan\ImportAction;
use OCA\PaperlessSync\Model\Plan\ImportCheckFailed;
use OCA\PaperlessSync\Model\Plan\ImportFailed;
use OCA\PaperlessSync\Model\Plan\ImportSucceeded;
use OCA\PaperlessSync\Model\Plan\MoveImport;
use OCA\PaperlessSync\Model\Plan\RetryUnfinished;
use OCA\PaperlessSync\Model\Plan\SetAside;
use OCA\PaperlessSync\Model\Plan\Skipped;
use OCA\PaperlessSync\Model\Plan\Unchanged;
use OCA\PaperlessSync\Model\Plan\Upload;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ImportPlanner;
use OCA\PaperlessSync\Service\PaperlessNotFoundException;
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
	 * with 'changed' for a file that changed since its upload and 'moved' for the path to which its
	 * file was renamed or moved, the tasks of Paperless and the settings.
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
			// The failure is that of the former content, which Paperless never had in its new form (#452).
			'a task that failed for a file that changed since its upload' => [
				['files' => ['Steuern/a.pdf'], 'imports' => ['Steuern/a.pdf' => ['status' => 'pending', 'changed' => true]], 'tasks' => ['task-1' => ['status' => 'FAILURE', 'message' => 'Broken PDF']]],
				['failed Steuern/a.pdf -> Fehler/Steuern/a.pdf: Broken PDF, changed since'],
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
			'a task unfinished a day after the upload succeeded when the document that the MD5 alone found has the content' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY]], 'md5Originals' => ['a.pdf' => 9], 'contents' => [9 => '%PDF-a.pdf']],
				['succeeded a.pdf as P9'],
			],
			'a task lost a day after the upload goes to Paperless again when the document that the MD5 alone found has other content' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY]], 'md5Originals' => ['a.pdf' => 9], 'contents' => [9 => '%PDF-other'], 'tasks' => ['task-1' => ['status' => 'PENDING', 'message' => '', 'listed' => false]]],
				['retry a.pdf after 1: Paperless has not finished the import task task-1 a day after the upload (PENDING)'],
			],
			'a task still listed a day after the upload moves the file to the error folder when the document that the MD5 alone found has other content' => [
				['files' => ['a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'submitted_at' => TestClock::START - self::DAY]], 'md5Originals' => ['a.pdf' => 9], 'contents' => [9 => '%PDF-other'], 'tasks' => ['task-1' => ['status' => 'STARTED', 'message' => '']]],
				['failed a.pdf -> Fehler/a.pdf: Paperless has not finished the import a day after the upload (STARTED), so it may still create the document. (Paperless has not finished the import task task-1 a day after the upload (STARTED))'],
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
			'a renamed file whose upload is pending goes on at its new path' => [
				['files' => ['b.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'moved' => 'b.pdf']], 'tasks' => ['task-1' => ['status' => 'SUCCESS', 'message' => '', 'documentId' => 7]]],
				['move a.pdf -> b.pdf', 'succeeded b.pdf as P7'],
			],
			'a file moved into a folder while Paperless still imports it waits there, and goes to Paperless no second time' => [
				['files' => ['Steuern/a.pdf'], 'imports' => ['a.pdf' => ['status' => 'pending', 'moved' => 'Steuern/a.pdf']]],
				['move a.pdf -> Steuern/a.pdf'],
			],
			'the kept files of a renamed folder stay imported' => [
				['files' => ['Neu/a.pdf', 'Neu/b.pdf'], 'imports' => ['Alt/a.pdf' => ['status' => 'success', 'moved' => 'Neu/a.pdf'], 'Alt/b.pdf' => ['status' => 'success', 'moved' => 'Neu/b.pdf']]],
				['move Alt/a.pdf -> Neu/a.pdf', 'unchanged', 'move Alt/b.pdf -> Neu/b.pdf', 'unchanged'],
			],
			'a renamed file that Paperless refused goes to Paperless again, as its new name may be one that Paperless takes' => [
				['files' => ['b.pdf'], 'imports' => ['a.pdf' => ['status' => 'rejected', 'moved' => 'b.pdf']]],
				['forget a.pdf', 'upload b.pdf'],
			],
			'a renamed file whose upload failed still waits for its next attempt' => [
				['files' => ['b.pdf'], 'imports' => ['a.pdf' => ['status' => 'retry', 'retry_at' => TestClock::START + 60, 'moved' => 'b.pdf']]],
				['move a.pdf -> b.pdf', 'skipped'],
			],
			'a renamed file that changed since its import goes to Paperless again' => [
				['files' => ['b.pdf'], 'imports' => ['a.pdf' => ['status' => 'success', 'changed' => true, 'moved' => 'b.pdf']]],
				['move a.pdf -> b.pdf', 'forget b.pdf', 'upload b.pdf'],
			],
			'a file with a state of its own keeps it, also when the state of a file that is gone names it' => [
				['files' => ['b.pdf'], 'imports' => ['a.pdf' => ['status' => 'success', 'moved' => 'b.pdf'], 'b.pdf' => ['status' => 'success']]],
				['forget a.pdf', 'unchanged'],
			],
			'a state without the ID of its file, from an earlier version, is forgotten, and the executor asks Paperless before the upload' => [
				['files' => ['b.pdf'], 'imports' => ['a.pdf' => ['status' => 'success']]],
				['forget a.pdf', 'upload b.pdf'],
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
			$moved = (string)($import['moved'] ?? '');
			unset($import['changed'], $import['moved']);
			if ($moved !== '') {
				$import['file_id'] = (int)$this->storage->findFileId(TestStorage::USER, self::INBOX . '/' . $moved);
			}
			$this->state->imports[$path] = $import + [
				'path' => $path,
				'etag' => hash('sha256', $changed ? 'before' : '%PDF-' . ($moved !== '' ? $moved : $file)),
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
		/** @var array<string, int> $md5Originals */
		$md5Originals = $case['md5Originals'] ?? [];
		foreach ($md5Originals as $file => $document) {
			$this->paperless->originals[hash('md5', "%PDF-{$file}")] = $document;
		}
		/** @var array<int, string> $contents */
		$contents = $case['contents'] ?? [];
		$this->paperless->contents = $contents;

		self::assertSame($expected, $this->plan($case['settings'] ?? []));
	}

	/**
	 * Files of the same name meet in the error folder, when a scanner names every scan the same, or
	 * when names lost their markers. Such a file takes the next free number there, so that it
	 * replaces no other file, whatever the conflict policy, and, with the policy skip, ends without
	 * a conflict.
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
			'a file without a marker keeps a free name' => ['Scan.pdf', ['Andere.pdf'], 'Scan.pdf'],
			'a file without a marker takes the next free number too' => ['Scan.pdf', ['Scan.pdf'], 'Scan (2).pdf'],
			'a file without a marker after earlier failures of its name' => ['Scan_0001.pdf', ['Scan_0001.pdf', 'Scan_0001 (2).pdf'], 'Scan_0001 (3).pdf'],
			'the .error.txt of the name is taken' => ['Scan.pdf', ['Scan.pdf.error.txt'], 'Scan (2).pdf'],
		];
	}

	/** @param list<string> $taken */
	#[DataProvider('namesInTheErrorFolder')]
	public function testAFailedFileTakesTheNextFreeNameInTheErrorFolder(string $name, array $taken, string $expected): void {
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

	/**
	 * Without the original of a document that the MD5 alone found, the end of an unfinished upload
	 * can't be told: the check fails, the file stays where it is, and the next run asks again.
	 */
	public function testAnUnfinishedUploadWaitsWhenTheOriginalCannotBeDownloadedToConfirmIt(): void {
		$path = self::INBOX . '/a.pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START - self::DAY];
		$this->paperless->originals[hash('md5', '%PDF-a')] = 9;
		$this->paperless->downloadException = new RuntimeException('Paperless answered with HTTP 500');

		self::assertSame(['check failed a.pdf: Paperless answered with HTTP 500'], $this->plan());
		self::assertSame([[9, true]], $this->paperless->downloadRequests);
	}

	/**
	 * An original that Paperless doesn't have (HTTP 404) confirms nothing, so the unfinished upload
	 * goes on to its other ends, as for a document of other content, instead of failing its check
	 * in every run.
	 */
	public function testAnUnfinishedUploadGoesOnWhenPaperlessHasNoOriginalToConfirmIt(): void {
		$path = self::INBOX . '/a.pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START - self::DAY];
		$this->paperless->originals[hash('md5', '%PDF-a')] = 9;
		$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => '', 'listed' => false];
		$this->paperless->downloadException = new PaperlessNotFoundException('Client error: 404 Not Found');

		self::assertSame(['retry a.pdf after 1: Paperless has not finished the import task task-1 a day after the upload (PENDING)'], $this->plan());
		self::assertSame([[9, true]], $this->paperless->downloadRequests);
	}

	/**
	 * In per-user ownership, an upload that Paperless has not finished a day later ends as imported
	 * only on a document of the inbox's user, or on the account's document that Paperless added at
	 * or after its first upload, which is the upload itself and gets its owner; a state of an
	 * earlier version has the time of the upload alone. Any other document, of another user or
	 * older, costs no download, and the upload goes on to its other ends: the error folder while
	 * Paperless lists the task, a new upload when it doesn't.
	 *
	 * @return array<string, array{array<string, mixed>, int|null, bool, list<string>}>
	 */
	public static function documentsOfUnfinishedUploads(): array {
		$failed = ['failed a.pdf -> Fehler/a.pdf: Paperless has not finished the import a day after the upload (PENDING), so it may still create the document. (Paperless has not finished the import task task-1 a day after the upload (PENDING))'];
		$retry = ['retry a.pdf after 1: Paperless has not finished the import task task-1 a day after the upload (PENDING)'];

		return [
			'a document of the inbox\'s user' => [['id' => 9, 'owner' => 3], null, true, ['succeeded a.pdf as P9']],
			'the account\'s document, newer than the record of the upload' => [['id' => 9, 'owner' => 1], 0, true, ['succeeded a.pdf as P9, the lost upload']],
			'the account\'s document, newer, with a clock of Paperless behind' => [['id' => 9, 'owner' => 1, 'added' => '2026-08-26T09:00:00+00:00'], 8, true, ['succeeded a.pdf as P9, the lost upload']],
			'the account\'s document, without a record of the upload' => [['id' => 9, 'owner' => 1], null, true, $failed],
			'the account\'s document, which Paperless had before the upload' => [['id' => 9, 'owner' => 1], 9, true, $failed],
			'a document of another user, while Paperless lists the task' => [['id' => 9, 'owner' => 4], 0, true, $failed],
			'a document of another user, after Paperless lost the task' => [['id' => 9, 'owner' => 4], 0, false, $retry],
		];
	}

	/**
	 * @param array<string, mixed> $document
	 * @param int|null $knownId the ID that the record of the upload keeps, or null without a record
	 * @param list<string> $expected
	 */
	#[DataProvider('documentsOfUnfinishedUploads')]
	public function testAnUnfinishedUploadEndsOnlyOnADocumentOfItsUser(array $document, ?int $knownId, bool $listed, array $expected): void {
		$path = self::INBOX . '/a.pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START - self::DAY];
		if ($knownId !== null) {
			$this->state->saveUpload(TestStorage::USER, hash('sha256', '%PDF-a'), ['known_id' => $knownId, 'task_id' => 'task-1', 'created_at' => TestClock::START - self::DAY]);
		}
		$this->paperless->accountId = 1;
		$this->paperless->originals[hash('md5', '%PDF-a')] = 9;
		$this->paperless->contents[9] = '%PDF-a';
		$this->paperless->documents[] = $document;
		if (!$listed) {
			$this->paperless->tasks['task-1'] = ['status' => 'PENDING', 'message' => '', 'listed' => false];
		}

		self::assertSame($expected, $this->plan(scope: self::scope()));
		self::assertSame(str_starts_with($expected[0], 'succeeded') ? [[9, true]] : [], $this->paperless->downloadRequests, 'The owner is read before the download.');
	}

	/** A document of the account that the run holds for an upload of another user counts for nobody: the check fails, and the upload stays pending. */
	public function testAnUnfinishedUploadWaitsForADocumentThatTheRunHolds(): void {
		$path = self::INBOX . '/a.pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START - self::DAY];
		$this->paperless->originals[hash('sha256', '%PDF-a')] = 9;
		$this->paperless->documents[] = ['id' => 9, 'owner' => 1, 'added' => '2026-08-27T11:00:00+00:00'];

		self::assertSame(['check failed a.pdf: Paperless has the content of the file as the document 9, which may be that of an upload that waits for its owner, so the file waits until that upload ends'], $this->plan(scope: self::scope(1)));
	}

	/**
	 * The text next to a file whose task Paperless still lists a day after the upload says that the
	 * record of the upload follows the task further in per-user ownership, for a user whose
	 * uploads get a record, and only that the file can go once the document appears otherwise.
	 *
	 * @return array<string, array{PerUserScope|null, string}>
	 */
	public static function textsOfUnfinishedUploads(): array {
		return [
			'per-user ownership' => [self::scope(), 'Paperless Sync follows the task for 30 days after the upload: once Paperless creates the document, a run gives it its owner and names it in the report, and this file can go. '],
			'single ownership' => [null, 'Once the document appears in Paperless, this file can go. '],
		];
	}

	#[DataProvider('textsOfUnfinishedUploads')]
	public function testTheTextOfAnUnfinishedUploadSaysHowLongTheAppFollowsIt(?PerUserScope $scope, string $sentence): void {
		$path = self::INBOX . '/a.pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START - self::DAY];
		$this->paperless->accountId = 1;

		$actions = iterator_to_array($this->planner(TestConfig::of([]), new SyncBatch(1), $scope)->plan(), false);

		self::assertInstanceOf(ImportFailed::class, $actions[0]);
		self::assertStringContainsString("so Paperless may still create the document. The file was not uploaded again, because Paperless-ngx 3.x imports a duplicate as a second document.\n\n{$sentence}Should the document not appear, check that the worker of Paperless runs, then move the file back into the inbox.\n\n", $actions[0]->diagnostic);
	}

	/**
	 * Paperless-ngx 2.x names the title of the document that a duplicate has the content of,
	 * whoever owns it. In per-user ownership the reason names it by its ID alone, in the words of
	 * Paperless-ngx 3.x; with one target user for every document, it keeps the words of Paperless.
	 *
	 * @return array<string, array{string, bool, string}>
	 */
	public static function reasonsOfDuplicates(): array {
		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/paperless-ngx-2/task-duplicate.json'), true, 512, JSON_THROW_ON_ERROR);
		$recorded = (string)$fixture['body'][0]['result'];

		return [
			'the answer of Paperless-ngx 2.x' => [$recorded, true, 'copy.pdf: Not consuming copy.pdf: It is a duplicate of document #1'],
			'a title with a number of its own' => ['Not consuming a.pdf: It is a duplicate of Bill (#5) of May (#77).', true, 'Not consuming a.pdf: It is a duplicate of document #77'],
			'a reason that Paperless cut short' => ['Not consuming a.pdf: It is a duplicate of A very long tit', true, 'Not consuming a.pdf: It is a duplicate of a document of Paperless'],
			'the words of Paperless-ngx 3.x' => ['Not consuming: It is a duplicate of document #123, which is in the trash of Paperless', true, 'Not consuming: It is a duplicate of document #123, which is in the trash of Paperless'],
			'another reason' => ['Unsupported mime type text/plain', true, 'Unsupported mime type text/plain'],
			'one target user for every document' => [$recorded, false, $recorded],
		];
	}

	#[DataProvider('reasonsOfDuplicates')]
	public function testTheReasonOfADuplicateNamesTheDocumentByItsIdInPerUserOwnership(string $message, bool $perUser, string $reason): void {
		self::assertSame($reason, ImportPlanner::failureReason(['status' => 'FAILURE', 'message' => $message, 'documentId' => null, 'listed' => true], $perUser));
	}

	/** A cancelled task keeps the reason of Paperless, without a title in per-user ownership as well. */
	public function testTheReasonOfACancelledDuplicateNamesTheDocumentByItsId(): void {
		self::assertSame('Paperless cancelled the task before it finished (REVOKED), so it created no document: It is a duplicate of document #3', ImportPlanner::failureReason(['status' => 'REVOKED', 'message' => 'It is a duplicate of Secret (#3).', 'documentId' => null, 'listed' => true], true));
	}

	/** The error folder of an inbox in per-user ownership gets the reason without the title, too. */
	public function testAFailedDuplicateOfPerUserOwnershipNamesTheDocumentByItsId(): void {
		$path = self::INBOX . '/a.pdf';
		$this->storage->files[$path] = '%PDF-a';
		$this->state->imports[$path] = ['path' => $path, 'etag' => hash('sha256', '%PDF-a'), 'status' => 'pending', 'task_id' => 'task-1', 'submitted_at' => TestClock::START];
		$this->paperless->tasks['task-1'] = ['status' => 'FAILURE', 'message' => 'Not consuming a.pdf: It is a duplicate of Secret (#3).'];

		self::assertSame(['failed a.pdf -> Fehler/a.pdf: Not consuming a.pdf: It is a duplicate of document #3'], $this->plan(['ownership' => SyncConfig::PER_USER]));
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

	/** An upload names the ID of its file, which its state keeps, so that the state follows the file when it is renamed. */
	public function testAnUploadNamesTheIdOfItsFile(): void {
		$this->storage->files[self::INBOX . '/a.pdf'] = '%PDF-a';
		$id = $this->storage->findFileId(TestStorage::USER, self::INBOX . '/a.pdf');

		$actions = iterator_to_array($this->planner()->plan(), false);

		self::assertCount(1, $actions);
		self::assertInstanceOf(Upload::class, $actions[0]);
		self::assertSame($id, $actions[0]->fileId);
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
	private function plan(array $settings = [], ?PerUserScope $scope = null): array {
		$config = TestConfig::of($settings);
		$batch = new SyncBatch($config->batchSize);
		$lines = [];
		foreach ($this->planner($config, $batch, $scope)->plan() as $action) {
			$lines[] = self::describe($action);
			if ($action instanceof Upload) {
				$batch->take(SyncBatch::WRITE);
			}
		}

		return $lines;
	}

	/** The planner of the inbox, with the settings and the batch of a test, or those of the tests. */
	/**
	 * Per-user ownership, in which the inbox is that of a user other than the target user, with the
	 * user 3 of Paperless; bob is the user 4, and the account of the app, the user 1, has no user of
	 * Nextcloud.
	 */
	private static function scope(int|false|null $held = null): PerUserScope {
		$route = static fn (int $id, string $name, ?string $user): array => ['id' => $id, 'name' => $name, 'user' => $user, 'reason' => $user !== null ? Owners::NAME : Owners::NONE, 'detail' => ''];

		return new PerUserScope(new Owners('target', [3 => $route(3, TestStorage::USER, TestStorage::USER), 4 => $route(4, 'bob', 'bob'), 1 => $route(1, 'paperless-sync', null)]), TestStorage::USER, $held);
	}

	private function planner(?SyncConfig $config = null, ?SyncBatch $batch = null, ?PerUserScope $scope = null): ImportPlanner {
		$config ??= TestConfig::of([]);

		return new ImportPlanner(
			$config,
			$this->state,
			$batch ?? new SyncBatch($config->batchSize),
			new TargetFiles($this->storage, TestStorage::USER),
			$this->paperless,
			$this->clock,
			self::INBOX,
			TestConfig::ERRORS,
			function (): void {
				++$this->heartbeats;
			},
			$scope,
		);
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
			$action instanceof MoveImport => 'move ' . self::below($action->from) . ' -> ' . self::below($action->to),
			$action instanceof ImportSucceeded => 'succeeded ' . self::below($action->context->path)
				. ($action->sameContent ? '' : ', changed since')
				. ($action->document !== null ? " as P{$action->document}" : '')
				. ($action->lostUpload ? ', the lost upload' : ''),
			$action instanceof ImportFailed => 'failed ' . self::below($action->context->path) . ' -> '
				. substr($action->destination, strlen('Dokumente/Paperless/')) . ": {$action->reason}"
				. ($action->unfinished !== '' ? " ({$action->unfinished})" : '')
				. ($action->changed ? ', changed since' : ''),
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
