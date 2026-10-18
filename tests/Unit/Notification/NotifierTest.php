<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Notification;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncReport;
use OCA\PaperlessSync\Notification\NotificationPublisher;
use OCA\PaperlessSync\Notification\Notifier;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCA\PaperlessSync\Tests\Doubles\TestNotification;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NotifierTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private StatusService $status;
	private Notifier $notifier;

	protected function setUp(): void {
		$this->status = $this->statusService(new TestClock());
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(fn (string $app, ?string $language = null): IL10N => $language === 'de' ? $this->germanL10n() : $this->englishL10n());
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.test' . $url);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $parameters): string => "https://cloud.example.test/{$route}?" . http_build_query($parameters));
		$this->notifier = new Notifier($factory, $urls, $this->status);
	}

	public function testTheNotifierIsTheOneOfTheApp(): void {
		self::assertSame(AppConstants::APP_ID, $this->notifier->getID());
		self::assertSame('Paperless Sync', $this->notifier->getName());
	}

	public function testANotificationOfAnotherAppIsNotItsConcern(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare((new TestNotification())->setApp('files')->setSubject(NotificationPublisher::SUBJECT_FAILING), 'en');
	}

	public function testAnUnknownSubjectIsNotItsConcern(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($this->notification('removed_in_a_later_version'), 'en');
	}

	/** The notification names the row as it is when the reader opens it, with the error of its last run. */
	public function testAFailingMirrorNamesItsRunsAndItsError(): void {
		for ($run = 0; $run < 4; ++$run) {
			$this->status->failed(new RuntimeException('Paperless answered 401 {detail}: ' . str_repeat('x', 300)));
		}

		$notification = $this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_FAILING, ['runs' => 3]), 'en');

		self::assertSame('Paperless Sync failed 4 times in a row', $notification->getParsedSubject());
		self::assertStringStartsWith('Paperless answered 401 {detail}: ', $notification->getParsedMessage(), 'A brace of the error stays as it is.');
		self::assertSame(NotificationPublisher::ERROR_LIMIT, mb_strlen($notification->getParsedMessage()));
		self::assertSame('https://cloud.example.test/settings.AdminSettings.index?section=paperless_sync', $notification->getLink());
		self::assertSame('https://cloud.example.test/apps/paperless_sync/img/app-dark.svg', $notification->getIcon());
		self::assertSame('Paperless Sync ist 4 Mal in Folge fehlgeschlagen', $this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_FAILING), 'de')->getParsedSubject());
	}

	public function testAMirrorWithErrorsSaysSo(): void {
		$report = new SyncReport(false, 1000, 1060);
		$report->error('');
		$this->status->completed($report);

		$notification = $this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_FAILING), 'de');

		self::assertSame('Paperless Sync endete 1 Mal in Folge mit Fehlern', $notification->getParsedSubject());
		self::assertSame('', $notification->getParsedMessage(), 'An error without a text adds no message.');
	}

	/** A run that succeeded since removes the notification also when the success could not. */
	public function testTheNotificationOfAMirrorThatWorksAgainGoesAway(): void {
		$this->expectException(AlreadyProcessedException::class);

		$this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_FAILING), 'en');
	}

	public function testAFailedImportLinksItsFileAndGivesTheReason(): void {
		$notification = $this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_IMPORT_FAILED, [
			'file' => ['id' => 42, 'path' => 'Documents/Paperless/Errors/broken.pdf'],
			'reason' => 'Unsupported mime type {type}',
		]), 'de');

		self::assertSame('Paperless konnte broken.pdf nicht importieren', $notification->getParsedSubject());
		self::assertSame('Paperless konnte {file} nicht importieren', $notification->getRichSubject());
		$link = 'https://cloud.example.test/files.viewcontroller.showFile?fileid=42';
		self::assertSame(['file' => [
			'type' => 'file',
			'id' => '42',
			'name' => 'broken.pdf',
			'path' => 'Documents/Paperless/Errors/broken.pdf',
			'link' => $link,
		]], $notification->getRichSubjectParameters());
		self::assertSame($link, $notification->getLink());
		self::assertSame('Unsupported mime type {type}', $notification->getParsedMessage());
	}

	public function testARefusedImportOfAFileThatIsGoneNamesItAlone(): void {
		$notification = $this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_IMPORT_REJECTED, [
			'file' => ['id' => 0, 'path' => 'Documents/Paperless/Inbox/notes.bin'],
			'reason' => '',
		]), 'en');

		self::assertSame('Paperless refused notes.bin; it stays in the inbox until it changes', $notification->getParsedSubject());
		self::assertSame(['file' => ['type' => 'highlight', 'id' => 'Documents/Paperless/Inbox/notes.bin', 'name' => 'notes.bin']], $notification->getRichSubjectParameters());
		self::assertSame('', $notification->getLink());
		self::assertSame('', $notification->getParsedMessage());
	}

	/** @return array<string, array{string, string}> */
	public static function endsOfASentFile(): array {
		return [
			'imported' => [NotificationPublisher::SUBJECT_SEND_IMPORTED, '{file} ist jetzt in Paperless'],
			'known already' => [NotificationPublisher::SUBJECT_SEND_KNOWN, 'Paperless hat {file} bereits'],
			'failed' => [NotificationPublisher::SUBJECT_SEND_FAILED, 'Paperless konnte {file} nicht importieren'],
			'refused' => [NotificationPublisher::SUBJECT_SEND_REFUSED, 'Paperless hat {file} abgelehnt'],
			'gone' => [NotificationPublisher::SUBJECT_SEND_GONE, 'Paperless Sync konnte {file} nicht senden: Die Datei wurde gelöscht, oder du darfst sie nicht mehr lesen'],
		];
	}

	/** The end of the import of a file sent from Files names the file, with the reason of Paperless below it. */
	#[DataProvider('endsOfASentFile')]
	public function testTheEndOfTheImportOfASentFileNamesTheFile(string $subject, string $rich): void {
		$notification = $this->notifier->prepare($this->notification($subject, [
			'file' => ['id' => 42, 'path' => 'Scans/receipt.pdf'],
			'document' => null,
			'url' => 'https://paperless.example.test',
			'reason' => 'Not consuming receipt.pdf',
		]), 'de');

		self::assertSame($rich, $notification->getRichSubject());
		self::assertSame(str_replace('{file}', 'receipt.pdf', $rich), $notification->getParsedSubject());
		self::assertSame('https://cloud.example.test/files.viewcontroller.showFile?fileid=42', $notification->getLink());
		self::assertSame('Not consuming receipt.pdf', $notification->getParsedMessage());
	}

	/** @return array<string, array{string}> */
	public static function documentsOfASentFile(): array {
		return [
			'imported' => [NotificationPublisher::SUBJECT_SEND_IMPORTED],
			'known already' => [NotificationPublisher::SUBJECT_SEND_KNOWN],
		];
	}

	/** A file that Paperless has opens its document there, as "Open in Paperless" does. */
	#[DataProvider('documentsOfASentFile')]
	public function testASentFileThatPaperlessHasLinksItsDocument(string $subject): void {
		$notification = $this->notifier->prepare($this->notification($subject, [
			'file' => ['id' => 42, 'path' => 'Scans/receipt.pdf'],
			'document' => 123,
			'url' => 'https://paperless.example.test/',
			'reason' => '',
		]), 'en');

		self::assertSame('https://paperless.example.test/documents/123/details', $notification->getLink());
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function sentFilesWithoutADocument(): array {
		return [
			'no document' => [['document' => null, 'url' => 'https://paperless.example.test']],
			'no address of Paperless' => [['document' => 123, 'url' => '']],
			'neither' => [[]],
		];
	}

	/** @param array<string, mixed> $parameters */
	#[DataProvider('sentFilesWithoutADocument')]
	public function testASentFileWithoutADocumentLinksTheFile(array $parameters): void {
		$notification = $this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_SEND_IMPORTED, ['file' => ['id' => 42, 'path' => 'Scans/receipt.pdf']] + $parameters), 'en');

		self::assertSame('https://cloud.example.test/files.viewcontroller.showFile?fileid=42', $notification->getLink());
	}

	/** @return list<array{array<string, mixed>}> */
	public static function unreadableFiles(): array {
		return [[[]], [['file' => 'broken.pdf']], [['file' => ['id' => '42', 'path' => 'a.pdf']]], [['file' => ['id' => 42, 'path' => '']]]];
	}

	/** @param array<string, mixed> $parameters */
	#[DataProvider('unreadableFiles')]
	public function testAnImportWithoutItsFileIsNotItsConcern(array $parameters): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($this->notification(NotificationPublisher::SUBJECT_IMPORT_FAILED, $parameters), 'en');
	}

	/** @param array<string, mixed> $parameters */
	private function notification(string $subject, array $parameters = []): TestNotification {
		$notification = new TestNotification();
		$notification->setApp(AppConstants::APP_ID)->setSubject($subject, $parameters);

		return $notification;
	}

	/** Nextcloud's translation of a text that has none: the text, with its parameters and the count. */
	private function englishL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters));

		return $l10n;
	}
}
