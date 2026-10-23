<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Service\FullStorageException;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\RunException;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An error of a run, which the status keeps in English, as the log shows it, with its text and
 * parameters, and which the settings page reads in the language of its administrator.
 */
final class RunErrorTest extends TestCase {
	use GermanTranslations;

	/** An error of the app is English for the log and German on a German page, an error within it too. */
	public function testAnErrorInEnglishAndInGerman(): void {
		$error = new RunError('Archive of %1$s: %2$s Its documents wait until Nextcloud can store them.', [
			'alice',
			new RunError('Nextcloud user %s does not exist.', ['alice'], 'target_user'),
		]);

		self::assertSame('Archive of alice: Nextcloud user alice does not exist. Its documents wait until Nextcloud can store them.', $error->message());
		self::assertSame('Archiv von alice: Den Nextcloud-Benutzer alice gibt es nicht. Seine Dokumente warten, bis Nextcloud sie speichern kann.', $error->translated($this->germanL10n()));
	}

	/** What Paperless or Nextcloud said stays as it came, also within an error of the app, and a % in it is no placeholder. */
	public function testAPlainErrorStaysAsItCame(): void {
		$plain = RunError::plain('HTTP 500: 100% broken');
		$error = new RunError('Import %1$s: %2$s', ['Eingang/a.pdf', $plain]);

		self::assertFalse($plain->translatable);
		self::assertSame('HTTP 500: 100% broken', $plain->message());
		self::assertSame('HTTP 500: 100% broken', $plain->translated($this->germanL10n()));
		self::assertSame('Import Eingang/a.pdf: HTTP 500: 100% broken', $error->translated($this->germanL10n()));
	}

	/** @return array<string, array{\Throwable, string, string}> an exception, its English message and its German one */
	public static function exceptions(): array {
		return [
			'an invalid setting' => [
				new InvalidSettingException('Nextcloud user %s does not exist.', ['paperless'], 'target_user'),
				'Nextcloud user paperless does not exist.',
				'Den Nextcloud-Benutzer paperless gibt es nicht.',
			],
			'a local address that Nextcloud blocks' => [
				new PaperlessLocalNetworkException('paperless'),
				'Nextcloud blocks connections to local addresses such as paperless. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
				'Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless. Wenn Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.',
			],
			'a failure of the run that the app explains' => [
				new RunException('Paperless did not list its custom fields, which the archive path template uses; the user of the API token needs the permission to view custom fields: %s', [RunError::plain('HTTP 403')]),
				'Paperless did not list its custom fields, which the archive path template uses; the user of the API token needs the permission to view custom fields: HTTP 403',
				'Paperless hat seine benutzerdefinierten Felder nicht aufgelistet, die die Archiv-Pfadvorlage verwendet; der Benutzer des API-Tokens braucht die Berechtigung, benutzerdefinierte Felder anzuzeigen: HTTP 403',
			],
			'a full storage' => [
				new FullStorageException('Not enough free space in Nextcloud: the file needs %1$s bytes, and %2$s are free.', ['12', '11']),
				'Not enough free space in Nextcloud: the file needs 12 bytes, and 11 are free.',
				'Nextcloud hat nicht genug freien Speicherplatz: Die Datei braucht 12 Bytes, und 11 sind frei.',
			],
			'an answer of Paperless' => [new \RuntimeException(' Paperless timed out '), 'Paperless timed out', 'Paperless timed out'],
			'an exception without a message' => [new \LogicException(), 'LogicException', 'LogicException'],
		];
	}

	#[DataProvider('exceptions')]
	public function testTheErrorOfAnException(\Throwable $exception, string $english, string $german): void {
		$error = RunError::of($exception);

		self::assertSame($english, $error->message());
		self::assertSame($german, $error->translated($this->germanL10n()));
		if ($exception instanceof InvalidSettingException || $exception instanceof PaperlessLocalNetworkException) {
			self::assertNotSame('', $error->setting, 'The error keeps the setting that it names.');
		}
	}

	/** The status keeps the error as JSON, which a later request reads back as it was. */
	public function testTheStatusReadsBackTheErrorThatItKept(): void {
		$error = new RunError('Archive of %1$s: %2$s Its documents wait until Nextcloud can store them.', [
			'alice',
			new RunError('Nextcloud user %s does not exist.', ['alice'], 'target_user'),
		]);

		$stored = json_decode(json_encode($error, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame([
			'message' => 'Archive of alice: Nextcloud user alice does not exist. Its documents wait until Nextcloud can store them.',
			'text' => 'Archive of %1$s: %2$s Its documents wait until Nextcloud can store them.',
			'parameters' => [
				'alice',
				['message' => 'Nextcloud user alice does not exist.', 'text' => 'Nextcloud user %s does not exist.', 'parameters' => ['alice'], 'setting' => 'target_user'],
			],
		], $stored);
		self::assertEquals($error, RunError::stored($stored));
		self::assertSame($error, RunError::stored($error));
		self::assertSame(['message' => 'HTTP 500'], RunError::plain('HTTP 500')->jsonSerialize());
		self::assertEquals(RunError::plain('HTTP 500'), RunError::stored(['message' => 'HTTP 500']));
		self::assertSame(['message' => 'Tags: x', 'text' => 'Tags: %s', 'parameters' => ['x']], (new RunError('Tags: %s', ['x']))->jsonSerialize());
	}

	/** @return array<string, array{mixed}> what is no error that the status kept */
	public static function noErrors(): array {
		return [
			'nothing' => [null],
			'the plain text of an earlier version' => ['Nextcloud user paperless does not exist.'],
			'an empty record' => [[]],
			'a record without its message' => [['text' => 'Tags: %s']],
		];
	}

	#[DataProvider('noErrors')]
	public function testWhatIsNoErrorReadsAsNone(mixed $value): void {
		self::assertNull(RunError::stored($value));
	}

	/** A record with parameters of another shape still reads, with an empty value in their place. */
	public function testAStoredErrorWithBrokenParameters(): void {
		$error = RunError::stored(['message' => 'Tags P1: x', 'text' => 'Tags P%1$s: %2$s', 'parameters' => [1, ['no' => 'error']], 'setting' => 3]);

		self::assertNotNull($error);
		self::assertSame(['', ''], $error->parameters);
		self::assertSame('', $error->setting);
		self::assertEquals(new RunError('Tags: %s'), RunError::stored(['message' => 'Tags: %s', 'text' => 'Tags: %s', 'parameters' => 'none']));
	}

	/** The status shortens every value of an error, but never its text, which is the key of its translation. */
	public function testShortenedKeepsTheText(): void {
		$long = str_repeat('é', 20);
		$error = new RunError('Import %1$s: %2$s', [$long, RunError::plain($long)], 'inbox_path');

		$shortened = $error->shortened(5);

		self::assertEquals(new RunError('Import %1$s: %2$s', ['ééééé', RunError::plain('ééééé')], 'inbox_path'), $shortened);
		self::assertSame('Import ééééé: ééééé', $shortened->translated($this->germanL10n()));
	}
}
