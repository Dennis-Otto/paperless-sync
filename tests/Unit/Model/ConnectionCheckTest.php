<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line of the connection checklist, as the settings page shows it in the language of the
 * administrator.
 */
final class ConnectionCheckTest extends TestCase {
	use GermanTranslations;

	/** @return array<string, array{string, string}> every line of the checklist and its German label */
	public static function labels(): array {
		return [
			'Paperless' => [ConnectionCheck::PAPERLESS, 'Paperless'],
			'documents' => [ConnectionCheck::DOCUMENTS, 'Dokumente'],
			'trash' => [ConnectionCheck::TRASH, 'Papierkorb'],
			'correspondents' => [ConnectionCheck::CORRESPONDENTS, 'Korrespondenten'],
			'document types' => [ConnectionCheck::DOCUMENT_TYPES, 'Dokumenttypen'],
			'storage paths' => [ConnectionCheck::STORAGE_PATHS, 'Speicherpfade'],
			'tags' => [ConnectionCheck::TAGS, 'Tags'],
			'upload' => [ConnectionCheck::UPLOAD, 'Hochladen aus dem Eingang'],
			'folder' => [ConnectionCheck::FOLDER, 'Nextcloud-Ordner'],
			'a line that the page doesn\'t know' => ['webhooks', 'webhooks'],
		];
	}

	#[DataProvider('labels')]
	public function testEveryLineHasATranslatedLabel(string $id, string $label): void {
		self::assertSame($label, ConnectionCheck::passed($id, 'The account may add documents and view the tasks that import them.')->translated($this->germanL10n())['label']);
	}

	public function testALineInTheLanguageOfTheAdministrator(): void {
		$line = ConnectionCheck::failed(ConnectionCheck::TAGS, 'Paperless answered without the list (HTTP %s).', ['502'], 'Check that Paperless runs, and the log of Paperless or of the proxy in front of it.');

		self::assertSame([
			'id' => 'tags',
			'label' => 'Tags',
			'status' => 'failed',
			'message' => 'Paperless hat ohne die Liste geantwortet (HTTP 502).',
			'hint' => 'Prüfe, ob Paperless läuft, und das Protokoll von Paperless oder des Proxys davor.',
			'setting' => '',
		], $line->translated($this->germanL10n()));
		self::assertSame(
			'Paperless hat ohne die Liste geantwortet (HTTP 502). Prüfe, ob Paperless läuft, und das Protokoll von Paperless oder des Proxys davor.',
			$line->translatedText($this->germanL10n()),
		);
	}

	public function testALineWithoutAHintIsItsMessage(): void {
		$line = ConnectionCheck::passed(ConnectionCheck::DOCUMENTS, 'Visible to the account: %s', ['12']);

		self::assertSame('', $line->translated($this->germanL10n())['hint']);
		self::assertSame('Für das Konto sichtbar: 12', $line->translatedText($this->germanL10n()));
	}

	/** Only a blocking failure stops a save; a failure of one module and a note never do. */
	public function testOnlyABlockingFailureBlocks(): void {
		self::assertTrue(ConnectionCheck::failed(ConnectionCheck::DOCUMENTS, 'The account may not view them (HTTP 403).', [], '', true)->blocks());
		self::assertFalse(ConnectionCheck::failed(ConnectionCheck::TAGS, 'The account may not view them (HTTP 403).', [], '')->blocks());
		self::assertFalse((new ConnectionCheck(ConnectionCheck::TRASH, ConnectionCheck::WARNING, 'Visible to the account: %s', ['2'], blocking: true))->blocks());
		self::assertFalse(ConnectionCheck::passed(ConnectionCheck::PAPERLESS, 'Paperless answers at this URL.')->blocks());
	}
}
