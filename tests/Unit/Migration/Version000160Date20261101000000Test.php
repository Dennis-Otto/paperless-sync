<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000160Date20261101000000;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Version000160Date20261101000000Test extends TestCase {
	use InMemoryConfiguration;

	private const NO_INFO = null;

	/** @var array<string, int> the rows of each table of the state of the runs */
	private array $rows = ['paperless_sync_export' => 0, 'paperless_sync_import' => 0];

	/** @return array<string, array{string}> the languages whose catalogs this version brings, also a regional variant */
	public static function newCatalogs(): array {
		return ['French' => ['fr'], 'Spanish' => ['es'], 'Spanish of Mexico' => ['es_MX'], 'French of France' => ['fr_FR']];
	}

	/** An instance in a new language that was set up with occ goes on in the English names of its archive. */
	#[DataProvider('newCatalogs')]
	public function testAnInstallationInUseKeepsTheEnglishNamesThatItNeverSaved(string $language): void {
		$this->defaultLanguage = $language;
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'target_user' => 'paperless'];
		self::assertNotSame('Archive', $this->configService()->get()->archiveFolder, 'Without the step, the catalog would rename the archive.');

		$this->migrate('Kept 9 names of folders of Paperless Sync, so that the languages of this version move no archive.');

		$config = $this->configService()->get();
		self::assertSame(['Documents/Paperless', 'Archive', 'Inbox', 'Failed imports', '_Deleted'], [$config->basePath, $config->archiveFolder, $config->inboxFolder, $config->errorFolder, $config->deletedFolder]);
		self::assertSame(['_No correspondent', '_No document type', '_No date', 'Untitled'], [$config->emptyCorrespondent, $config->emptyDocumentType, $config->emptyDate, $config->untitled]);
	}

	public function testAnInstallationWithCopiesAloneKeepsTheEnglishNames(): void {
		$this->defaultLanguage = 'fr';
		$this->rows['paperless_sync_export'] = 2;

		$this->migrate('Kept 9 names of folders of Paperless Sync, so that the languages of this version move no archive.');

		self::assertSame('Archive', $this->configService()->get()->archiveFolder);
	}

	public function testAnInstallationWithImportsAloneKeepsTheEnglishNames(): void {
		$this->defaultLanguage = 'es';
		$this->rows['paperless_sync_import'] = 1;

		$this->migrate('Kept 9 names of folders of Paperless Sync, so that the languages of this version move no archive.');

		self::assertSame('Inbox', $this->configService()->get()->inboxFolder);
	}

	public function testSavedNamesStayAsTheyAre(): void {
		$this->defaultLanguage = 'fr';
		$this->settings = ['target_user' => 'paperless', 'archive_folder' => 'Akten'];

		$this->migrate('Kept 8 names of folders of Paperless Sync, so that the languages of this version move no archive.');

		self::assertSame(['Akten', 'Inbox'], [$this->configService()->get()->archiveFolder, $this->configService()->get()->inboxFolder]);
	}

	/** Earlier steps and every save may have stored all names already. */
	public function testAnInstallationThatStoredEveryNameNeedsNoChange(): void {
		$this->defaultLanguage = 'fr';
		$this->settings = [
			'target_user' => 'paperless', 'base_path' => 'Dokumente/Paperless', 'archive_folder' => 'Archiv', 'inbox_folder' => 'Eingang',
			'error_folder' => 'Fehler', 'deleted_folder' => '_Gelöscht', 'empty_correspondent' => '_Ohne Korrespondent',
			'empty_document_type' => '_Ohne Dokumenttyp', 'empty_date' => '_Ohne Datum', 'untitled' => 'Ohne Titel',
		];

		$this->migrate(self::NO_INFO);

		self::assertSame('Archiv', $this->configService()->get()->archiveFolder);
	}

	/** A German instance keeps its German names, which are not those of the new languages. */
	public function testAnotherLanguageStoresTheNamesThatItHas(): void {
		$this->defaultLanguage = 'de';
		$this->settings = ['target_user' => 'paperless'];

		$this->migrate('Kept 9 names of folders of Paperless Sync, so that the languages of this version move no archive.');

		$this->defaultLanguage = 'fr';
		self::assertSame('Archiv', $this->configService()->get()->archiveFolder, 'Another default language must not rename it now.');
	}

	public function testANewInstallationTakesTheNamesInTheLanguageOfNextcloud(): void {
		$this->defaultLanguage = 'fr';

		$this->migrate(self::NO_INFO);

		self::assertSame('Archives', $this->configService()->get()->archiveFolder);
	}

	/** Run the step, which says $info, or nothing for null. */
	private function migrate(?string $info): void {
		$output = $this->createMock(IOutput::class);
		if ($info === null) {
			$output->expects(self::never())->method('info');
		} else {
			$output->expects(self::once())->method('info')->with($info);
		}
		$step = new Version000160Date20261101000000($this->configService(), $this->connection());

		$step->postSchemaChange($output, fn (): ISchemaWrapper => $this->createStub(ISchemaWrapper::class), []);
	}

	/** A database that has $rows in the tables of the state, and answers a query for the first of them. */
	private function connection(): IDBConnection {
		$connection = $this->createStub(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$table = '';
			$query = $this->createStub(IQueryBuilder::class);
			$query->method('select')->willReturnSelf();
			$query->method('from')->willReturnCallback(function (string $from) use (&$table, $query): IQueryBuilder {
				$table = $from;

				return $query;
			});
			$query->method('setMaxResults')->willReturnSelf();
			$query->method('executeQuery')->willReturnCallback(function () use (&$table): IResult {
				$result = $this->createStub(IResult::class);
				$result->method('fetchOne')->willReturn($this->rows[$table] > 0 ? '1' : false);

				return $result;
			});

			return $query;
		});

		return $connection;
	}
}
