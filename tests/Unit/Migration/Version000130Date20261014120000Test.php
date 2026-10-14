<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000130Date20261014120000;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * An installation that is in use keeps the German names of its folders, which every earlier
 * version proposed, when the defaults start to follow the language of Nextcloud.
 */
final class Version000130Date20261014120000Test extends TestCase {
	use InMemoryConfiguration;

	private const GERMAN = [
		'base_path' => 'Dokumente/Paperless',
		'archive_folder' => 'Archiv',
		'inbox_folder' => 'Eingang',
		'error_folder' => 'Fehler',
		'deleted_folder' => '_Gelöscht',
		'empty_correspondent' => '_Ohne Korrespondent',
		'empty_document_type' => '_Ohne Dokumenttyp',
		'empty_date' => '_Ohne Datum',
		'untitled' => 'Ohne Titel',
	];

	/** @var array<string, int> the rows of each table of the state of the runs */
	private array $rows = ['paperless_sync_export' => 0, 'paperless_sync_import' => 0];

	public function testAnInstallationWithATargetUserKeepsTheGermanNamesThatItNeverSaved(): void {
		// Set up with occ, which stores only the settings that it names.
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'target_user' => 'paperless'];

		$this->migrate('Kept 9 German names of folders of Paperless Sync, which earlier versions proposed in every language.');

		self::assertSame(self::GERMAN, $this->names(), 'An English instance keeps the archive where it is.');
		foreach (array_keys(self::GERMAN) as $key) {
			self::assertArrayHasKey($key, $this->lazyKeys, 'Nextcloud loads the names only when the app asks for them.');
		}
	}

	public function testAnInstallationWithCopiesKeepsTheGermanNames(): void {
		$this->rows['paperless_sync_export'] = 1;

		$this->migrate('Kept 9 German names of folders of Paperless Sync, which earlier versions proposed in every language.');

		self::assertSame(self::GERMAN, $this->names());
	}

	public function testAnInstallationWithImportsAloneKeepsTheGermanNames(): void {
		$this->rows['paperless_sync_import'] = 1;

		$this->migrate('Kept 9 German names of folders of Paperless Sync, which earlier versions proposed in every language.');

		self::assertSame('Eingang', $this->configService()->get()->inboxFolder, 'The inbox stays where its users put their files.');
	}

	public function testSavedNamesStayAsTheyAre(): void {
		$this->configService()->save(self::validSettings() + ['base_path' => 'Scans', 'archive_folder' => 'Akten'], 'secret-token');
		// A name that a version before it didn't know yet, and so never saved.
		unset($this->settings['empty_date']);

		$this->migrate('Kept 1 German names of folders of Paperless Sync, which earlier versions proposed in every language.');

		$config = $this->configService()->get();
		self::assertSame('Scans', $config->basePath);
		self::assertSame('Akten', $config->archiveFolder);
		self::assertSame('Inbox', $config->inboxFolder, 'A name that the installation saved stays, also an English one.');
		self::assertSame('_Ohne Datum', $config->emptyDate);
	}

	public function testAnInstallationThatSavedEveryNameNeedsNoChange(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$saved = $this->settings;

		$this->migrate(null);

		self::assertSame($saved, $this->settings);
	}

	public function testANewInstallationTakesTheNamesInTheLanguageOfNextcloud(): void {
		$this->migrate(null);

		self::assertSame([], $this->settings, 'Nothing is set up yet, so nothing is kept.');
		self::assertSame('Documents/Paperless', $this->configService()->get()->basePath);
		$this->defaultLanguage = 'de';
		self::assertSame('Dokumente/Paperless', $this->configService()->get()->basePath);
	}

	/** Run the step, which says $info, or nothing for null. */
	private function migrate(?string $info): void {
		$output = $this->createMock(IOutput::class);
		if ($info === null) {
			$output->expects(self::never())->method('info');
		} else {
			$output->expects(self::once())->method('info')->with($info);
		}
		$step = new Version000130Date20261014120000($this->configService(), $this->connection());

		$step->postSchemaChange($output, fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);
	}

	/** @return array<string, string> the names that the app uses, by their setting */
	private function names(): array {
		$config = $this->configService()->get()->jsonSerialize();
		$properties = [
			'base_path' => 'basePath', 'archive_folder' => 'archiveFolder', 'inbox_folder' => 'inboxFolder',
			'error_folder' => 'errorFolder', 'deleted_folder' => 'deletedFolder', 'empty_correspondent' => 'emptyCorrespondent',
			'empty_document_type' => 'emptyDocumentType', 'empty_date' => 'emptyDate', 'untitled' => 'untitled',
		];

		return array_map(static fn (string $property): string => (string)$config[$property], $properties);
	}

	/** A database that has $rows in the tables of the state, and answers a query for the first of them. */
	private function connection(): IDBConnection {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$table = '';
			$query = $this->createMock(IQueryBuilder::class);
			$query->method('select')->with('id')->willReturnSelf();
			$query->method('from')->willReturnCallback(function (string $from) use (&$table, $query): IQueryBuilder {
				$table = $from;

				return $query;
			});
			$query->method('setMaxResults')->with(1)->willReturnSelf();
			$query->method('executeQuery')->willReturnCallback(function () use (&$table): IResult {
				self::assertArrayHasKey($table, $this->rows);
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn($this->rows[$table] > 0 ? '1' : false);
				$result->expects(self::once())->method('closeCursor');

				return $result;
			});

			return $query;
		});

		return $connection;
	}
}
