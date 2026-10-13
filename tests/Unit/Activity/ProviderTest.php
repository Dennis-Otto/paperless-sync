<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Activity;

use OCA\PaperlessSync\Activity\ActivityPublisher;
use OCA\PaperlessSync\Activity\Provider;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestActivityEvent;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entries of the activity stream in the words of the reader, from the parameters that the
 * Activity app stored as JSON.
 */
final class ProviderTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private const CLOUD = 'https://cloud.example.test';
	private const ARCHIVE = 'Dokumente/Paperless/Archiv';

	private Provider $provider;

	protected function setUp(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(fn (string $app, ?string $language = null): IL10N => match ($language) {
			'de' => $this->germanL10n(),
			default => $this->englishL10n(),
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $parameters): string {
			self::assertSame('files.viewcontroller.showFile', $route);

			return self::CLOUD . '/index.php/f/' . (string)$parameters['fileid'];
		});
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => self::CLOUD . $url);
		$this->provider = new Provider($factory, $urls, $this->configService());
	}

	public function testRunNamesItsChangesAndItsFirstFiles(): void {
		$event = $this->parse('en', ActivityPublisher::SUBJECT_RUN, $this->runEntry());

		self::assertSame(
			'Paperless Sync added or updated 3 documents, moved 1 document and moved 2 documents to {trash}: {file1}, {file2} and 4 more',
			$event->getRichSubject(),
		);
		self::assertSame(
			'Paperless Sync added or updated 3 documents, moved 1 document and moved 2 documents to _Gelöscht: Strom August [P123].pdf, Police [P124].pdf and 4 more',
			$event->getParsedSubject(),
		);
		self::assertSame([
			'trash' => [
				'type' => 'file',
				'id' => '20',
				'name' => '_Gelöscht',
				'path' => self::ARCHIVE . '/_Gelöscht',
				'link' => self::CLOUD . '/index.php/f/20',
			],
			'file1' => [
				'type' => 'file',
				'id' => '11',
				'name' => 'Strom August [P123].pdf',
				'path' => self::ARCHIVE . '/Energie GmbH/Strom August [P123].pdf',
				'link' => self::CLOUD . '/index.php/f/11',
			],
			'file2' => [
				'type' => 'file',
				'id' => '12',
				'name' => 'Police [P124].pdf',
				'path' => self::ARCHIVE . '/Versicherung/Police [P124].pdf',
				'link' => self::CLOUD . '/index.php/f/12',
			],
		], $event->getRichSubjectParameters());
		self::assertSame(self::CLOUD . '/index.php/f/5', $event->getLink(), 'The entry opens the archive folder.');
		self::assertSame(self::CLOUD . '/apps/paperless_sync/img/app.svg', $event->getIcon());
	}

	public function testRunInGerman(): void {
		$event = $this->parse('de', ActivityPublisher::SUBJECT_RUN, $this->runEntry());

		self::assertSame(
			'Paperless Sync hat 3 Dokumente hinzugefügt oder aktualisiert, 1 Dokument verschoben und 2 Dokumente nach _Gelöscht verschoben: Strom August [P123].pdf, Police [P124].pdf und 4 weitere',
			$event->getParsedSubject(),
		);
	}

	/** @return array<string, array{array<string, int>, string, string}> */
	public static function changes(): array {
		return [
			'one deletion' => [['deleted' => 1], 'Paperless Sync deleted 1 document', 'Paperless Sync hat 1 Dokument gelöscht'],
			'two changes' => [
				['removed' => 2, 'submitted' => 1],
				'Paperless Sync removed 2 excluded documents and sent 1 file to Paperless',
				'Paperless Sync hat 2 ausgeschlossene Dokumente entfernt und 1 Datei an Paperless gesendet',
			],
			'every kind' => [
				['exported' => 1, 'moved' => 2, 'deleted' => 3, 'removed' => 1, 'submitted' => 4],
				'Paperless Sync added or updated 1 document, moved 2 documents, deleted 3 documents, removed 1 excluded document and sent 4 files to Paperless',
				'Paperless Sync hat 1 Dokument hinzugefügt oder aktualisiert, 2 Dokumente verschoben, 3 Dokumente gelöscht, 1 ausgeschlossenes Dokument entfernt und 4 Dateien an Paperless gesendet',
			],
		];
	}

	/** @param array<string, int> $counts */
	#[DataProvider('changes')]
	public function testRunWithoutFilesListsItsChanges(array $counts, string $english, string $german): void {
		$parameters = $counts + ['files' => [], 'more' => 0, 'folder' => ['id' => 0, 'path' => self::ARCHIVE]];

		$event = $this->parse('en', ActivityPublisher::SUBJECT_RUN, $parameters);

		self::assertSame($english, $event->getParsedSubject());
		self::assertSame($english, $event->getRichSubject());
		self::assertSame([], $event->getRichSubjectParameters());
		self::assertSame('', $event->getLink(), 'A folder that is gone has no link.');
		self::assertSame($german, $this->parse('de', ActivityPublisher::SUBJECT_RUN, $parameters)->getParsedSubject());
	}

	public function testRunListsOnlyTheFilesThatItCanRead(): void {
		$event = $this->parse('en', ActivityPublisher::SUBJECT_RUN, [
			'exported' => 2,
			'files' => [['id' => 0, 'path' => 'gone.pdf'], 'broken', ['id' => '7', 'path' => 'a.pdf'], ['id' => 8, 'path' => ''], ['id' => 9, 'path' => self::ARCHIVE . '/b.pdf']],
			'more' => 'many',
			'folder' => 'broken',
		]);

		self::assertSame('Paperless Sync added or updated 2 documents: {file1}', $event->getRichSubject());
		self::assertSame(['file1'], array_keys($event->getRichSubjectParameters()));
		self::assertSame('9', $event->getRichSubjectParameters()['file1']['id']);
		self::assertSame('', $event->getLink());
	}

	public function testRunWithoutChangesIsNotForTheStream(): void {
		$this->expectException(UnknownActivityException::class);

		$this->parse('en', ActivityPublisher::SUBJECT_RUN, ['exported' => 0, 'moved' => -1, 'trashed' => 2, 'files' => [['id' => 11, 'path' => 'a.pdf']]]);
	}

	public function testImportNamesTheNewDocumentWithItsLinkToPaperless(): void {
		$parameters = ['file' => ['id' => 0, 'path' => 'Dokumente/Paperless/Eingang/Versicherung/scan.pdf'], 'document' => 123];

		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORTED, $parameters);

		self::assertSame('{file} was imported into Paperless as {document}', $event->getRichSubject());
		self::assertSame('scan.pdf was imported into Paperless as P123', $event->getParsedSubject());
		self::assertSame([
			'file' => ['type' => 'highlight', 'id' => 'Dokumente/Paperless/Eingang/Versicherung/scan.pdf', 'name' => 'scan.pdf'],
			'document' => ['type' => 'highlight', 'id' => '123', 'name' => 'P123', 'link' => 'https://paperless.example.test/documents/123/details'],
		], $event->getRichSubjectParameters(), 'The inbox file is gone after its import, so it has no link.');
		self::assertSame('https://paperless.example.test/documents/123/details', $event->getLink());
		self::assertSame('scan.pdf wurde als P123 in Paperless importiert', $this->parse('de', ActivityPublisher::SUBJECT_IMPORTED, $parameters)->getParsedSubject());
	}

	public function testImportOfAFileThatStaysLinksTheFile(): void {
		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORTED, ['file' => ['id' => 31, 'path' => 'Eingang/scan.pdf'], 'document' => null]);

		self::assertSame('{file} was imported into Paperless', $event->getRichSubject());
		self::assertSame('scan.pdf was imported into Paperless', $event->getParsedSubject());
		self::assertSame('file', $event->getRichSubjectParameters()['file']['type']);
		self::assertSame(self::CLOUD . '/index.php/f/31', $event->getLink());
		self::assertSame('scan.pdf wurde in Paperless importiert', $this->parse('de', ActivityPublisher::SUBJECT_IMPORTED, ['file' => ['id' => 31, 'path' => 'Eingang/scan.pdf']])->getParsedSubject());
	}

	public function testImportOfAFileThatIsGoneWithoutADocumentHasNoLink(): void {
		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORTED, ['file' => ['id' => 0, 'path' => 'Eingang/scan.pdf'], 'document' => 0]);

		self::assertSame('scan.pdf was imported into Paperless', $event->getParsedSubject());
		self::assertSame('', $event->getLink());
	}

	public function testDocumentLinksToPaperlessOnlyWhileItsAddressIsKnown(): void {
		$this->settings['paperless_url'] = '';

		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORTED, ['file' => ['id' => 31, 'path' => 'Eingang/scan.pdf'], 'document' => 123]);

		self::assertSame(['type' => 'highlight', 'id' => '123', 'name' => 'P123'], $event->getRichSubjectParameters()['document']);
		self::assertSame(self::CLOUD . '/index.php/f/31', $event->getLink());
	}

	public function testFailedImportLinksTheFileInTheErrorFolderAndGivesTheReason(): void {
		$parameters = ['file' => ['id' => 41, 'path' => 'Dokumente/Paperless/Fehler/broken.pdf'], 'reason' => 'Unsupported mime type {application/x-broken}'];

		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORT_FAILED, $parameters);

		self::assertSame('Paperless could not import {file}', $event->getRichSubject());
		self::assertSame('Paperless could not import broken.pdf', $event->getParsedSubject());
		self::assertSame('Unsupported mime type {application/x-broken}', $event->getParsedMessage(), 'A brace of the reason is text.');
		self::assertSame('', $event->getRichMessage());
		self::assertSame(self::CLOUD . '/index.php/f/41', $event->getLink());
		self::assertSame('Paperless konnte broken.pdf nicht importieren', $this->parse('de', ActivityPublisher::SUBJECT_IMPORT_FAILED, $parameters)->getParsedSubject());
	}

	public function testRefusedFileStaysInTheInbox(): void {
		$parameters = ['file' => ['id' => 51, 'path' => 'Eingang/notes.bin'], 'reason' => 'File type application/octet-stream not supported'];

		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORT_REJECTED, $parameters);

		self::assertSame('Paperless refused notes.bin; it stays in the inbox until it changes', $event->getParsedSubject());
		self::assertSame('File type application/octet-stream not supported', $event->getParsedMessage());
		self::assertSame(self::CLOUD . '/index.php/f/51', $event->getLink());
		self::assertSame(
			'Paperless hat notes.bin abgelehnt; die Datei bleibt im Eingang, bis sie sich ändert',
			$this->parse('de', ActivityPublisher::SUBJECT_IMPORT_REJECTED, $parameters)->getParsedSubject(),
		);
	}

	public function testRefusalWithoutAReasonHasNoMessage(): void {
		$event = $this->parse('en', ActivityPublisher::SUBJECT_IMPORT_REJECTED, ['file' => ['id' => 0, 'path' => 'Eingang/notes.bin'], 'reason' => '']);

		self::assertSame('', $event->getParsedMessage());
		self::assertSame('', $event->getLink());
	}

	/** @return array<string, array{string, string, array<string, mixed>}> */
	public static function unknownEntries(): array {
		return [
			'another app' => ['files', ActivityPublisher::SUBJECT_RUN, ['deleted' => 1]],
			'another subject' => [AppConstants::APP_ID, 'created_self', []],
			'an import without its file' => [AppConstants::APP_ID, ActivityPublisher::SUBJECT_IMPORTED, ['document' => 123]],
			'a failure without its file' => [AppConstants::APP_ID, ActivityPublisher::SUBJECT_IMPORT_FAILED, ['file' => 'broken.pdf']],
		];
	}

	/** @param array<string, mixed> $parameters */
	#[DataProvider('unknownEntries')]
	public function testEntryThatItCannotReadIsLeftToOthers(string $app, string $subject, array $parameters): void {
		$event = (new TestActivityEvent())->setApp($app)->setSubject($subject, $parameters);

		$this->expectException(UnknownActivityException::class);

		$this->provider->parse('en', $event);
	}

	/** @param array<string, mixed> $parameters */
	private function parse(string $language, string $subject, array $parameters): TestActivityEvent {
		// The Activity app stores the parameters as JSON and reads them back.
		/** @var array<string, mixed> $stored */
		$stored = json_decode(json_encode($parameters, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		$event = new TestActivityEvent();
		$event->setApp(AppConstants::APP_ID)->setType(ActivityPublisher::TYPE)->setAffectedUser('paperless')->setSubject($subject, $stored);

		$parsed = $this->provider->parse($language, $event);

		self::assertSame($event, $parsed);
		self::assertTrue($event->isValidParsed());

		return $event;
	}

	/** @return array<string, mixed> the entry of a run, as ActivityPublisher stores it */
	private function runEntry(): array {
		return [
			'exported' => 3,
			'moved' => 1,
			'trashed' => 2,
			'deleted' => 0,
			'removed' => 0,
			'submitted' => 0,
			'files' => [
				['id' => 11, 'path' => self::ARCHIVE . '/Energie GmbH/Strom August [P123].pdf'],
				['id' => 12, 'path' => self::ARCHIVE . '/Versicherung/Police [P124].pdf'],
			],
			'more' => 4,
			'trash' => ['id' => 20, 'path' => self::ARCHIVE . '/_Gelöscht'],
			'folder' => ['id' => 5, 'path' => self::ARCHIVE],
		];
	}

	/** English, the language of the texts in the code: the singular for 1 and the plural for every other count. */
	private function englishL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters));

		return $l10n;
	}
}
