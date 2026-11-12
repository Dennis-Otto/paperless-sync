<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Service\UnsavedSettingException;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\DB\Exception as DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private ConfigService $service;

	protected function setUp(): void {
		$this->service = $this->configService();
	}

	public function testDefaultsApplyUntilSomethingIsSaved(): void {
		$config = $this->service->get();

		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->enabled);
		self::assertSame('Documents/Paperless', $config->basePath);
		self::assertSame(['Archive', 'Inbox', 'Failed imports', '_Deleted'], [$config->archiveFolder, $config->inboxFolder, $config->errorFolder, $config->deletedFolder]);
		self::assertSame(PathTemplateService::DEFAULT_TEMPLATE, $config->pathTemplate);
		self::assertSame([5, 100, 3], [$config->syncIntervalMinutes, $config->batchSize, $config->missingGraceRuns]);
		self::assertSame(['move', 'replace'], [$config->trashMode, $config->conflictMode]);
		self::assertSame('', $this->service->getToken());
	}

	/** @return array<string, array{string, list<string>}> the default language of Nextcloud and the names that it proposes */
	public static function defaultLanguages(): array {
		$english = ['Documents/Paperless', 'Archive', 'Inbox', 'Failed imports', '_Deleted', '_No correspondent', '_No document type', '_No date', 'Untitled'];
		$german = ['Dokumente/Paperless', 'Archiv', 'Eingang', 'Fehler', '_Gelöscht', '_Ohne Korrespondent', '_Ohne Dokumenttyp', '_Ohne Datum', 'Ohne Titel'];
		$french = ['Documents/Paperless', 'Archives', 'Boîte de réception', 'Imports échoués', '_Supprimés', '_Sans correspondant', '_Sans type de document', '_Sans date', 'Sans titre'];
		$spanish = ['Documentos/Paperless', 'Archivo', 'Bandeja de entrada', 'Importaciones fallidas', '_Eliminados', '_Sin interlocutor', '_Sin tipo de documento', '_Sin fecha', 'Sin título'];
		$chinese = ['文档/Paperless', '归档', '收件箱', '导入失败', '_已删除', '_无往来方', '_无文档类型', '_无日期', '无标题'];
		$traditionalChinese = ['文件/Paperless', '封存', '收件匣', '匯入失敗', '_已刪除', '_無關聯方', '_無文件類型', '_無日期', '無標題'];
		$dutch = ['Documenten/Paperless', 'Archief', 'Inbox', 'Mislukte imports', '_Verwijderd', '_Geen correspondent', '_Geen documenttype', '_Geen datum', 'Zonder titel'];

		return [
			'English' => ['en', $english],
			'British English, without a catalog of its own' => ['en_GB', $english],
			'German' => ['de', $german],
			'formal German, with a catalog of its own and the names of German' => ['de_DE', $german],
			'German of Switzerland, with the catalog of German' => ['de_CH', $german],
			'Dutch' => ['nl', $dutch],
			'Dutch of Belgium, with the catalog of Dutch' => ['nl_BE', $dutch],
			'French' => ['fr', $french],
			'French of Canada, with the catalog of French' => ['fr_CA', $french],
			'Spanish' => ['es', $spanish],
			'Spanish of Mexico, with the catalog of Spanish' => ['es_MX', $spanish],
			'Simplified Chinese' => ['zh_CN', $chinese],
			'Traditional Chinese of Taiwan' => ['zh_TW', $traditionalChinese],
			'Traditional Chinese of Hong Kong, without a catalog of it or of Chinese' => ['zh_HK', $english],
			'Italian, without a catalog yet' => ['it', $english],
			'Brazilian Portuguese, without a catalog of it or of Portuguese' => ['pt_BR', $english],
		];
	}

	/**
	 * A new installation proposes the names of its folders, and the fallbacks for empty metadata,
	 * in the default language of Nextcloud, also to an administrator who reads another one.
	 *
	 * @param list<string> $names
	 */
	#[DataProvider('defaultLanguages')]
	public function testNamesFollowTheDefaultLanguageOfNextcloud(string $language, array $names): void {
		$this->defaultLanguage = $language;

		$config = $this->service->get();

		self::assertSame($names, [
			$config->basePath, $config->archiveFolder, $config->inboxFolder, $config->errorFolder, $config->deletedFolder,
			$config->emptyCorrespondent, $config->emptyDocumentType, $config->emptyDate, $config->untitled,
		]);
		self::assertSame($names[1], $this->service->validate(self::validSettings(), 'secret-token')->archiveFolder, 'A dry-run and a save take the same names.');
	}

	/**
	 * Formal German, de_DE, proposed the names of German before it had a catalog of its own, and
	 * proposes the same names with it, so that the catalog moves no archive that never stored them.
	 */
	public function testFormalGermanProposesTheNamesOfGermanWithAndWithoutItsCatalog(): void {
		$this->defaultLanguage = 'de_DE';
		$this->withoutCatalogs = ['de_DE'];
		$before = $this->configService();
		$this->withoutCatalogs = [];
		$now = $this->configService();

		self::assertSame(['de', 'de_DE'], [$before->defaultLanguage(), $now->defaultLanguage()]);
		self::assertEquals($before->get(), $now->get());
		self::assertSame(['Dokumente/Paperless', 'Archiv', 'Ohne Titel'], [$now->get()->basePath, $now->get()->archiveFolder, $now->get()->untitled]);
	}

	/** @return array<string, array{bool|string, string, string}> force_language, default_language and the proposed archive folder */
	public static function systemLanguages(): array {
		return [
			'a forced language before the default one' => ['de_DE', 'en', 'Archiv'],
			'force_language true, which names no language' => [true, 'de', 'Archiv'],
			'no default language' => [false, '', 'Archive'],
		];
	}

	/** The names follow force_language before default_language, as Nextcloud does, and English without either. */
	#[DataProvider('systemLanguages')]
	public function testNamesFollowTheForcedLanguageOfNextcloud(bool|string $forced, string $default, string $archive): void {
		[$this->forcedLanguage, $this->defaultLanguage] = [$forced, $default];

		self::assertSame($archive, $this->service->get()->archiveFolder);
	}

	/** The settings that only tell whether the app is set up don't read the token. */
	public function testReadsTheTargetUserWithoutTheToken(): void {
		self::assertSame('', $this->service->targetUser());
		$this->settings['target_user'] = ' paperless ';

		self::assertSame('paperless', $this->service->targetUser());
		self::assertSame(0, $this->credentialReads);
	}

	/**
	 * Nextcloud finds a user whatever the case of the ID, but its activities, notifications and
	 * shares name the user by the ID as it spells it, so the target user is saved so, as the users
	 * of the tables are (#461).
	 */
	public function testTheTargetUserIsSavedAsNextcloudSpellsTheId(): void {
		$this->usersOfNextcloud[] = 'dennis';

		$config = $this->service->save(['target_user' => ' Dennis '] + self::validSettings(), 'secret-token');

		self::assertSame(['dennis', 'dennis'], [$config->targetUser, $this->settings['target_user']]);
		self::assertSame('dennis', $this->service->validate(['target_user' => 'DENNIS'])->targetUser, 'A check of unsaved settings takes the same ID.');
	}

	/**
	 * A save refuses a new target user that Nextcloud doesn't have, but keeps the saved one when it
	 * is gone since, so that a schedule can always be paused; the test of the connection names it.
	 */
	public function testASavedTargetUserThatIsGoneSinceDoesNotKeepTheScheduleFromAPause(): void {
		$this->service->save(['enabled' => true] + self::validSettings(), 'secret-token');
		// As occ may store it, with the spaces that a save takes away.
		$this->settings['target_user'] = ' paperless ';
		$this->usersOfNextcloud = ['admin'];

		$paused = $this->service->save(['enabled' => false]);

		self::assertSame([false, 'paperless'], [$paused->enabled, $this->settings['target_user']]);
		$this->expectException(InvalidSettingException::class);
		$this->expectExceptionMessage('Nextcloud user Paperless does not exist.');
		$this->service->save(['target_user' => 'Paperless']);
	}

	/**
	 * A dry-run takes the saved target user, which it compares by the ID as Nextcloud spells it: the
	 * same user in another case, as an earlier version stored it or the page sends it, is no other.
	 */
	public function testADryRunTakesTheSavedTargetUserInAnyCase(): void {
		$this->usersOfNextcloud[] = 'dennis';
		$this->service->save(['target_user' => 'dennis'] + self::validSettings(), 'secret-token');
		// As an earlier version stored it.
		$this->settings['target_user'] = 'Dennis';

		self::assertSame('dennis', $this->service->validateForDryRun(['target_user' => 'dennis'])->targetUser);
		self::assertSame('dennis', $this->service->validateForDryRun(['target_user' => 'DENNIS '])->targetUser);
		try {
			$this->service->validateForDryRun(['target_user' => 'admin']);
			self::fail('A dry-run must not read the files of another user.');
		} catch (UnsavedSettingException $exception) {
			self::assertSame('target_user', $exception->setting);
		}
	}

	/**
	 * The step of the update stores a target user that an earlier version stored as it was typed
	 * with the ID as Nextcloud spells it, once; one that Nextcloud doesn't have stays as it is.
	 */
	public function testATargetUserOfAnEarlierVersionIsSpelledAsNextcloudSpellsIt(): void {
		self::assertNull($this->service->spellTargetUser(), 'Nothing to store without a target user.');
		self::assertSame([], $this->settings);

		$this->usersOfNextcloud[] = 'dennis';
		$this->settings['target_user'] = ' Dennis ';
		self::assertSame('dennis', $this->service->spellTargetUser());
		self::assertSame('dennis', $this->settings['target_user']);
		self::assertArrayHasKey('target_user', $this->lazyKeys, 'The settings are lazy, as a save stores them.');
		self::assertNull($this->service->spellTargetUser(), 'Once is enough.');

		$this->settings['target_user'] = 'Gone';
		self::assertNull($this->service->spellTargetUser());
		self::assertSame('Gone', $this->settings['target_user']);
	}

	/** A saved name stays when the default language of Nextcloud changes. */
	public function testASavedNameKeepsItsLanguage(): void {
		$this->defaultLanguage = 'de';
		$this->service->save(self::validSettings(), 'secret-token');
		$this->defaultLanguage = 'en';

		self::assertSame(['Dokumente/Paperless', 'Archiv', 'Ohne Titel'], [$this->service->get()->basePath, $this->service->get()->archiveFolder, $this->service->get()->untitled]);
	}

	/** An installation in use keeps the names that it has: those that it saved, and given ones for the others. */
	public function testKeepsTheGivenNamesOfTheSettingsThatHaveNone(): void {
		$this->settings = ['target_user' => 'paperless', 'archive_folder' => 'Akten'];

		self::assertSame(1, $this->service->keepNames(['base_path' => 'Dokumente/Paperless', 'archive_folder' => 'Archiv', 'paperless_url' => 'https://elsewhere.example.test']));

		$config = $this->service->get();
		self::assertSame(['Dokumente/Paperless', 'Akten', 'Inbox', ''], [$config->basePath, $config->archiveFolder, $config->inboxFolder, $config->paperlessUrl], 'Only the names without a value of their own, and no other setting.');
		self::assertArrayHasKey('base_path', $this->lazyKeys);
		self::assertSame(0, $this->service->keepNames(['base_path' => 'Scans']), 'A kept name is a saved one.');
	}

	/** What a run uses stays, whatever the default language of Nextcloud becomes or the catalogs bring. */
	public function testKeepsTheDefaultNamesThatARunUses(): void {
		$this->defaultLanguage = 'fr';
		$this->settings = ['target_user' => 'paperless', 'archive_folder' => 'Akten'];

		self::assertSame(8, $this->service->keepDefaultNames());

		$this->defaultLanguage = 'de';
		$config = $this->service->get();
		self::assertSame(['Documents/Paperless', 'Akten', 'Boîte de réception', 'Sans titre'], [$config->basePath, $config->archiveFolder, $config->inboxFolder, $config->untitled]);
		self::assertSame(0, $this->service->keepDefaultNames(), 'Names that are kept once need no second time.');
	}

	public function testSavesNormalizedSettingsAndTheToken(): void {
		$config = $this->service->save([
			'paperless_url' => ' https://paperless.example.test/ ',
			'target_user' => ' paperless ',
			'base_path' => '/Dokumente\\Paperless/',
			'archive_folder' => ' Archiv ',
			'excluded_tags' => "Privat, Steuer\nPrivat,,\r\n ",
			'enabled' => true,
			'sync_interval_minutes' => '15',
			'empty_correspondent' => 'Ohne: Korrespondent',
			'not_a_setting' => 'ignored',
		], ' secret-token ');

		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertSame('paperless', $config->targetUser);
		self::assertSame('Dokumente/Paperless', $config->basePath);
		self::assertSame('Archiv', $config->archiveFolder);
		self::assertSame('Privat, Steuer', $config->excludedTags);
		self::assertTrue($config->enabled);
		self::assertSame(15, $config->syncIntervalMinutes);
		self::assertSame('Ohne_ Korrespondent', $config->emptyCorrespondent);
		self::assertTrue($config->tokenConfigured);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
		self::assertSame(15, $this->settings['sync_interval_minutes']);
		self::assertArrayNotHasKey('not_a_setting', $this->settings);
	}

	public function testTheTagsOfTheInboxAreOffUntilTheyAreTurnedOn(): void {
		$config = $this->service->save(self::validSettings(), 'secret-token');

		self::assertSame([false, '', false], [$config->inboxFolderTags, $config->inboxTags, $config->inboxCreateTags]);
	}

	public function testTheTagsForEveryImportAreSavedOnceEachWhateverTheirCase(): void {
		$config = $this->service->save(self::validSettings() + [
			'inbox_folder_tags' => true,
			'inbox_tags' => " Scanned,, nextcloud\nscanned\r\nNextcloud , 2026 ",
			'inbox_create_tags' => true,
		], 'secret-token');

		self::assertSame([true, 'Scanned, nextcloud, 2026', true], [$config->inboxFolderTags, $config->inboxTags, $config->inboxCreateTags]);
		self::assertSame([true, 'Scanned, nextcloud, 2026', true], [$this->settings['inbox_folder_tags'], $this->settings['inbox_tags'], $this->settings['inbox_create_tags']]);
		self::assertSame('Scanned, nextcloud, 2026', $this->service->get()->inboxTags);
	}

	/**
	 * The archive mirrors every document until tags to mirror are saved, each once, whatever its
	 * case, and a list of nothing but separators mirrors every document again.
	 */
	public function testTheArchiveMirrorsEveryDocumentUntilTagsToMirrorAreSaved(): void {
		self::assertSame('', $this->service->get()->includedTags);

		$config = $this->service->save(self::validSettings() + ['included_tags' => " Nextcloud,, steuer\nnextcloud\r\n"], 'secret-token');

		self::assertSame('Nextcloud, steuer', $config->includedTags);
		self::assertSame('Nextcloud, steuer', $this->settings['included_tags']);
		self::assertSame('Nextcloud, steuer', $this->service->get()->includedTags);
		self::assertSame('', $this->service->save(['included_tags' => " ,\n, "])->includedTags);
	}

	/** @return array<string, array{string}> the stored URL as an administrator may enter it again */
	public static function sameUrls(): array {
		return [
			'unchanged' => ['https://paperless.example.test'],
			'with a slash and spaces' => [' https://paperless.example.test/ '],
		];
	}

	/**
	 * A call of the webhook of Paperless mirrors its document at once, unless the administrator
	 * leaves the documents to the background jobs.
	 */
	public function testTheWebhookMirrorsAtOnceUntilTheSettingsSayOtherwise(): void {
		self::assertTrue($this->service->get()->webhookImmediate);

		$config = $this->service->save(['webhook_immediate' => false] + self::validSettings(), 'secret-token');

		self::assertFalse($config->webhookImmediate);
		self::assertFalse($this->settings['webhook_immediate']);
		self::assertFalse($this->service->get()->webhookImmediate);
	}

	/** The metadata next to the archive is off until an administrator turns it on. */
	public function testTheMetadataNextToTheArchiveIsOffUntilTurnedOn(): void {
		self::assertFalse($this->service->get()->writeMetadata);

		$config = $this->service->save(['write_metadata' => true] + self::validSettings(), 'secret-token');

		self::assertTrue($config->writeMetadata);
		self::assertTrue($this->settings['write_metadata']);
		self::assertTrue($this->service->get()->writeMetadata);
	}

	/** The administrators may send files from Files until another group, or none, is chosen. */
	public function testTheAdministratorsMaySendFilesUntilTheSettingsSayOtherwise(): void {
		self::assertSame('admin', $this->service->get()->sendGroup);

		self::assertSame('scanners', $this->service->save(['send_group' => ' scanners '] + self::validSettings(), 'secret-token')->sendGroup);
		self::assertSame('scanners', $this->settings['send_group']);

		self::assertSame('', $this->service->save(['send_group' => ''] + self::validSettings())->sendGroup, 'An empty group turns the action off.');
	}

	/** The documents that Paperless shares are per-user ownership and more: they take its table and its group, and per-user ownership alone doesn't read who may view a document. */
	public function testTheDocumentsThatPaperlessSharesTakeTheSettingsOfPerUserOwnership(): void {
		$config = $this->service->save(['ownership' => 'per-user-shared', 'user_map' => ' jdoe=ADMIN ', 'user_group' => ' scanners '] + self::validSettings(), 'secret-token');

		self::assertSame([true, true, 'jdoe = admin', 'scanners'], [$config->perUser(), $config->sharedDocuments(), $config->userMap, $config->userGroup]);
		self::assertSame('per-user-shared', $this->settings['ownership']);
		$perUser = $this->service->save(['ownership' => 'per-user'] + self::validSettings());
		self::assertSame([true, false], [$perUser->perUser(), $perUser->sharedDocuments()]);
		$single = $this->service->save(['ownership' => 'single'] + self::validSettings());
		self::assertSame([false, false], [$single->perUser(), $single->sharedDocuments()]);
	}

	/**
	 * Single ownership stays the default, as before per-user ownership; per-user ownership keeps
	 * the table with the IDs of the users as Nextcloud spells them, so that a run finds them.
	 */
	public function testOwnershipIsSingleUntilAnAdministratorChoosesPerUser(): void {
		$config = $this->service->get();
		self::assertSame([SyncConfig::SINGLE, false, '', ''], [$config->ownership, $config->perUser(), $config->userMap, $config->userGroup]);

		$config = $this->service->save(['ownership' => 'per-user', 'user_map' => " jdoe=ADMIN \n\n Max Muster = paperless", 'user_group' => ' scanners '] + self::validSettings(), 'secret-token');

		self::assertSame([true, "jdoe = admin\nMax Muster = paperless", 'scanners'], [$config->perUser(), $config->userMap, $config->userGroup]);
		self::assertSame(['per-user', "jdoe = admin\nMax Muster = paperless", 'scanners'], [$this->settings['ownership'], $this->settings['user_map'], $this->settings['user_group']]);
		$alice = $config->withTargetUser('alice');
		self::assertSame(['alice', 'paperless'], [$alice->targetUser, $config->targetUser]);
		self::assertSame(array_diff_key($config->jsonSerialize(), ['targetUser' => true]), array_diff_key($alice->jsonSerialize(), ['targetUser' => true]), 'The archive of another user has the same settings.');
	}

	/** The table and the group count only in per-user ownership, so single ownership keeps them unchecked, as typed. */
	public function testSingleOwnershipKeepsTheTableAndTheGroupAsTheyAre(): void {
		$config = $this->service->save(['ownership' => 'single', 'user_map' => " half a line \n", 'user_group' => 'nobody'] + self::validSettings(), 'secret-token');

		self::assertSame([false, 'half a line', 'nobody'], [$config->perUser(), $config->userMap, $config->userGroup]);
	}

	/** The documents follow their storage paths of Paperless only once an administrator turns it on. */
	public function testTheStoragePathsOfPaperlessAreFollowedOnlyOnceTurnedOn(): void {
		self::assertFalse($this->service->get()->followStoragePaths);

		$config = $this->service->save(['follow_storage_paths' => true] + self::validSettings(), 'secret-token');

		self::assertTrue($config->followStoragePaths);
		self::assertTrue($this->settings['follow_storage_paths']);
		self::assertTrue($this->service->get()->followStoragePaths);
		self::assertTrue($this->service->validateForDryRun([])->followStoragePaths, 'A dry-run takes the saved setting.');
		self::assertFalse($this->service->validateForDryRun(['follow_storage_paths' => false])->followStoragePaths, 'A dry-run of the page takes its switch, as the path template.');
	}

	/**
	 * Without an address in the browser, the links to Paperless take the Paperless URL, as before
	 * the setting, also without a read of the token.
	 */
	public function testTheLinksTakeThePaperlessUrlUntilAnAddressInTheBrowserIsSaved(): void {
		self::assertSame(['', ''], [$this->service->get()->linkUrl(), $this->service->configuredLinkUrl()]);

		$config = $this->service->save(self::validSettings(), 'secret-token');

		self::assertSame('', $config->paperlessPublicUrl);
		self::assertSame('', $this->settings['paperless_public_url']);
		self::assertSame('https://paperless.example.test', $config->linkUrl());
		self::assertSame('https://paperless.example.test', $this->service->settings()->linkUrl());
		self::assertSame('https://paperless.example.test', $this->service->configuredLinkUrl());
	}

	/**
	 * With Paperless at a name that only the server knows, such as http://paperless:8000 in a
	 * Docker network, the links take the address in the browser, while the requests and the token
	 * stay with the Paperless URL (#446).
	 */
	public function testTheLinksTakeTheAddressInTheBrowser(): void {
		$config = $this->service->save([
			'paperless_url' => 'http://paperless:8000',
			'paperless_local_network' => true,
			'paperless_public_url' => ' https://paperless.example.com/ ',
		] + self::validSettings(), 'secret-token');

		self::assertSame('https://paperless.example.com', $config->paperlessPublicUrl);
		self::assertSame('https://paperless.example.com', $this->settings['paperless_public_url']);
		self::assertSame('https://paperless.example.com', $config->linkUrl());
		self::assertSame('http://paperless:8000', $config->paperlessUrl);
		self::assertSame(['url' => 'http://paperless:8000', 'token' => 'secret-token'], $this->credential);
		self::assertSame('http://paperless:8000', $this->service->connection($config)->url);
		self::assertSame('https://paperless.example.com', $this->service->configuredLinkUrl());
		self::assertSame('http://paperless:8000', $this->service->configuredUrl());
		self::assertSame('https://paperless.example.com', $this->service->settings()->linkUrl());
		self::assertTrue($this->service->settings()->tokenConfigured, 'The address in the browser does not take the token away.');

		// The address in the browser only changes links: a dry-run may try another one unsaved.
		self::assertSame('https://dms.example.com', $this->service->validateForDryRun(['paperless_public_url' => 'https://dms.example.com/'])->linkUrl());

		// Emptied again, the links take the Paperless URL.
		$config = $this->service->save(['paperless_url' => 'http://paperless:8000', 'paperless_public_url' => ' '] + self::validSettings(), '');
		self::assertSame('http://paperless:8000', $config->linkUrl());
		self::assertSame('http://paperless:8000', $this->service->configuredLinkUrl());
	}

	/** The tags of Paperless reach Nextcloud only once an administrator turns them on. */
	public function testTheTagsInNextcloudAreOffUntilTurnedOn(): void {
		self::assertFalse($this->service->get()->nextcloudTags);

		$config = $this->service->save(['nextcloud_tags' => true] + self::validSettings(), 'secret-token');

		self::assertTrue($config->nextcloudTags);
		self::assertTrue($this->settings['nextcloud_tags']);
		self::assertTrue($this->service->get()->nextcloudTags);
	}

	/** Nextcloud loads the settings when the app asks for them, not with every request of every user. */
	public function testEverySettingIsStoredAndReadLazily(): void {
		$this->service->save(self::validSettings() + ['enabled' => true, 'batch_size' => 20], 'secret-token');
		$this->service->reset();

		self::assertNotSame([], $this->settings);
		self::assertSame([], array_keys(array_diff_key($this->settings, $this->lazyKeys)));
		$this->lazyReads = [];
		$config = $this->service->get();
		self::assertSame(['https://paperless.example.test', 'paperless', 20, false], [$config->paperlessUrl, $config->targetUser, $config->batchSize, $config->enabled]);
		self::assertCount(43, $this->lazyReads, 'Every setting is read as a lazy one.');
		self::assertSame('https://paperless.example.test', $this->service->configuredUrl());
	}

	/** The settings of an earlier version, which Nextcloud loaded with every request, become lazy and keep their values. */
	public function testSettingsOfAnEarlierVersionBecomeLazy(): void {
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'sync_enabled' => true, 'batch_size' => 20, 'enabled' => 'yes', 'installed_version' => '0.2.0'];
		$this->lazyKeys = ['batch_size' => true];

		self::assertSame(2, $this->service->storeLazily());

		$lazy = array_keys($this->lazyKeys);
		sort($lazy);
		self::assertSame(['batch_size', 'paperless_url', 'sync_enabled'], $lazy, 'The keys of Nextcloud for the app, such as enabled, stay as they are.');
		self::assertSame(0, $this->service->storeLazily());
		$config = $this->service->get();
		self::assertSame(['https://paperless.example.test', true, 20], [$config->paperlessUrl, $config->enabled, $config->batchSize]);
	}

	#[DataProvider('sameUrls')]
	public function testSavingTheSameUrlWithoutATokenKeepsTheStoredOne(string $url): void {
		$this->service->save(self::validSettings(), 'secret-token');

		$config = $this->service->save(['paperless_url' => $url, 'batch_size' => 50]);

		self::assertSame(50, $config->batchSize);
		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertSame('secret-token', $this->service->getToken());
		self::assertSame('secret-token', $this->service->resolveToken(' ', 'https://paperless.example.test'));
	}

	public function testATokenThatIsEnteredIsUsedForAnyUrl(): void {
		$this->service->save(self::validSettings(), 'secret-token');

		self::assertSame('other-token', $this->service->resolveToken(' other-token ', 'https://other.example.test'));
		$config = $this->service->save(['paperless_url' => 'https://other.example.test'], 'other-token');

		self::assertSame('https://other.example.test', $config->paperlessUrl);
		self::assertSame(['url' => 'https://other.example.test', 'token' => 'other-token'], $this->credential);
	}

	/** @return array<string, array{string}> URLs that may lead to another server or another app than the stored one */
	public static function otherUrls(): array {
		return [
			'another host' => ['https://attacker.example.test'],
			'another port' => ['https://paperless.example.test:8443'],
			'another scheme' => ['http://paperless.example.test'],
			'another path on the same host' => ['https://paperless.example.test/other-app'],
		];
	}

	/**
	 * The stored token goes only to the URL that it was saved with. Otherwise, whoever can change
	 * the settings could point the URL at a server of their own and receive the token with the test
	 * of the connection, without knowing it.
	 */
	#[DataProvider('otherUrls')]
	public function testANewUrlNeedsTheTokenAgain(string $url): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$stored = $this->settings;
		$calls = [
			'validate' => fn (): mixed => $this->service->validate(['paperless_url' => $url], ' '),
			'save' => fn (): mixed => $this->service->save(['paperless_url' => $url]),
			'resolveToken' => fn (): mixed => $this->service->resolveToken('', $url),
		];

		foreach ($calls as $method => $call) {
			try {
				$call();
				self::fail("{$method}() must refuse a new URL without a token.");
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Enter the API token again to connect to another Paperless URL.', $exception->getMessage());
			}
		}
		self::assertSame($stored, $this->settings);
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * The provisioning API of Nextcloud and occ may change the URL without the settings page. The
	 * next run must not send the stored token there.
	 */
	public function testAUrlChangedElsewhereGetsNoToken(): void {
		$this->service->save(self::validSettings(), 'secret-token');

		$this->settings['paperless_url'] = 'https://attacker.example.test';

		self::assertSame('', $this->service->getToken());
		self::assertFalse($this->service->get()->tokenConfigured);
		try {
			$this->service->validate([]);
			self::fail('The settings page must not take over a URL that was changed elsewhere without the token.');
		} catch (InvalidArgumentException $exception) {
			self::assertSame('Enter the API token again to connect to another Paperless URL.', $exception->getMessage());
		}
		self::assertSame('secret-token', $this->service->resolveToken('', 'https://paperless.example.test'), 'The URL that the token was saved with still gets it.');

		$this->settings['paperless_url'] = 'https://paperless.example.test/';
		self::assertSame('secret-token', $this->service->getToken());
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'secret-token'], $this->credential);
	}

	/**
	 * A run takes the saved settings and its connection to Paperless together, from one read of
	 * the token, which is a query of the credentials store and a decryption.
	 */
	public function testTheSnapshotHoldsTheSettingsAndTheirConnection(): void {
		$this->service->save(['paperless_local_network' => true] + self::validSettings(), 'secret-token');
		$this->credentialReads = 0;

		[$config, $connection] = $this->service->snapshot();

		self::assertSame(1, $this->credentialReads);
		self::assertEquals($this->service->get(), $config);
		self::assertTrue($config->tokenConfigured);
		self::assertEquals(new PaperlessConnection('https://paperless.example.test', 'secret-token', true), $connection);
	}

	/** The snapshot keeps the token from a URL that was changed elsewhere, as getToken() does. */
	public function testTheSnapshotOfAUrlChangedElsewhereHasNoToken(): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$this->settings['paperless_url'] = 'https://attacker.example.test';

		[$config, $connection] = $this->service->snapshot();

		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->complete());
		self::assertEquals(new PaperlessConnection('https://attacker.example.test', '', false), $connection);

		$this->settings['paperless_url'] = ' https://paperless.example.test/ ';
		self::assertSame('secret-token', $this->service->snapshot()[1]->token(), 'The URL that the token was saved with still gets it, however it is written.');
	}

	/**
	 * The connection of settings that are not saved, such as those of a dry-run: their URL and local
	 * network, with the stored token only for the URL that it was saved with.
	 */
	public function testTheConnectionOfSettingsHasTheTokenOnlyForItsUrl(): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$this->credentialReads = 0;

		$connection = $this->service->connection($this->service->validateForDryRun(['batch_size' => 5]));

		self::assertEquals(new PaperlessConnection('https://paperless.example.test', 'secret-token', false), $connection);
		self::assertSame(2, $this->credentialReads, 'One read to check the settings, one for the connection.');
		$other = $this->service->validate(['paperless_url' => 'https://other.example.test'], 'other-token');
		self::assertSame('', $this->service->connection($other)->token(), 'The stored token stays with the URL that it was saved with.');
	}

	/** A token that an earlier version stored without its URL waits for the update to bind it to one. */
	public function testATokenWithoutItsUrlIsNotUsed(): void {
		$this->settings['paperless_url'] = 'https://paperless.example.test';
		$this->credential = 'secret-token';

		self::assertSame('', $this->service->getToken());
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');
		$this->service->validate(self::validSettings());
	}

	/** @return array<string, array{mixed}> what else the store of credentials may hold */
	public static function unusableCredentials(): array {
		return [
			'without a URL' => [['token' => 'secret-token']],
			'without a token' => [['url' => 'https://paperless.example.test']],
			'with an empty token' => [['url' => 'https://paperless.example.test', 'token' => '']],
			'with a token that is no text' => [['url' => 'https://paperless.example.test', 'token' => 42]],
		];
	}

	#[DataProvider('unusableCredentials')]
	public function testAnIncompleteCredentialIsNoToken(mixed $credential): void {
		$this->settings['paperless_url'] = 'https://paperless.example.test';
		$this->credential = $credential;

		self::assertSame('', $this->service->getToken());
		self::assertFalse($this->service->bindUnboundToken());
		self::assertSame($credential, $this->credential);
	}

	public function testATokenIsRequired(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');

		$this->service->validate(self::validSettings());
	}

	public function testValidationLeavesTheStoredSettingsAlone(): void {
		$config = $this->service->validate(self::validSettings() + ['batch_size' => 7], 'secret-token');

		self::assertSame(7, $config->batchSize);
		self::assertSame([], $this->settings);
		self::assertNull($this->credential);
	}

	/**
	 * Nextcloud starts the background job every five minutes at most, so a shorter interval can't
	 * be kept: it can't be saved, and one that an earlier version or occ stored acts as five
	 * minutes, as it always did, and is shown as such.
	 */
	public function testTheIntervalIsAtLeastTheTimeBetweenTwoBackgroundJobs(): void {
		self::assertSame(5, ConfigService::MINIMUM_INTERVAL_MINUTES);
		self::assertSame(5, $this->service->save(['sync_interval_minutes' => 5] + self::validSettings(), 'secret-token')->syncIntervalMinutes);
		self::assertSame(1440, $this->service->save(['sync_interval_minutes' => 1440] + self::validSettings())->syncIntervalMinutes);

		$this->settings['sync_interval_minutes'] = 1;
		self::assertSame(5, $this->service->get()->syncIntervalMinutes);

		$config = $this->service->save(['batch_size' => 20] + self::validSettings());
		self::assertSame([5, 20], [$config->syncIntervalMinutes, $config->batchSize], 'Saving other settings keeps the interval that acts.');
		self::assertSame(5, $this->settings['sync_interval_minutes']);
	}

	/**
	 * Disconnecting deletes the token, as its button and its question say, and turns off the
	 * schedule, which would fail without the token. The other settings stay, the deletion policy
	 * of the administrator too, so that connecting again needs the token and not all of them.
	 */
	public function testDisconnectDeletesTheTokenAndTurnsOffTheSchedule(): void {
		$this->service->save(self::validSettings() + ['enabled' => true, 'permanent_delete' => true, 'base_path' => 'Archive/Paperless', 'batch_size' => 20], 'secret-token');
		$stored = $this->settings;

		$config = $this->service->reset();

		self::assertNull($this->credential);
		self::assertSame(array_replace($stored, ['sync_enabled' => false, 'token_url' => '']), $this->settings, 'Without the URL of the token, which settings() reads.');
		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->enabled);
		self::assertSame('https://paperless.example.test', $config->paperlessUrl);
		self::assertSame(['paperless', 'Archive/Paperless', 20], [$config->targetUser, $config->basePath, $config->batchSize]);
		self::assertTrue($config->permanentDelete);
		self::assertSame('', $this->service->getToken());
	}

	/** Disconnecting needs no saved settings, such as right after the installation. */
	public function testDisconnectWithoutSettingsKeepsTheDefaults(): void {
		$config = $this->service->reset();

		self::assertSame(['token_url' => '', 'sync_enabled' => false, 'paperless_local_network' => false], $this->settings);
		self::assertSame($this->configService()->get()->jsonSerialize(), $config->jsonSerialize());
		self::assertSame('', $config->paperlessUrl);
		self::assertFalse($config->tokenConfigured);
	}

	/**
	 * Allowing the local network lifts a protection of Nextcloud for the requests to Paperless, so
	 * it is off until an administrator turns it on, and off again after disconnecting.
	 */
	public function testTheLocalNetworkIsAllowedOnlyAfterItWasTurnedOn(): void {
		self::assertFalse($this->service->get()->paperlessLocalNetwork);

		$config = $this->service->save(self::validSettings() + ['paperless_local_network' => true], 'secret-token');

		self::assertTrue($config->paperlessLocalNetwork);
		self::assertTrue($this->settings['paperless_local_network']);
		self::assertTrue($config->jsonSerialize()['paperlessLocalNetwork'], 'The settings page shows it.');
		self::assertTrue($this->service->save(self::validSettings())->paperlessLocalNetwork, 'A save that leaves it out keeps it.');

		self::assertFalse($this->service->reset()->paperlessLocalNetwork);
		self::assertFalse($this->settings['paperless_local_network']);
	}

	/** @return array<string, array{mixed, bool}> a value of the setting as a request may send it, and whether it allows the local network */
	public static function valuesOfTheLocalNetwork(): array {
		return [
			'true' => [true, true],
			'false' => [false, false],
			'1 of a form' => ['1', true],
			'0 of a form' => ['0', false],
			'the number 1' => [1, true],
			'"true"' => ['true', true],
			'"on"' => ['on', true],
			'"false"' => ['false', false],
			'empty' => ['', false],
			'anything else' => ['maybe', false],
			'null' => [null, false],
		];
	}

	/** Only a clear yes lifts the protection of Nextcloud; "false", which PHP would take for true, is a no. */
	#[DataProvider('valuesOfTheLocalNetwork')]
	public function testOnlyAClearYesAllowsTheLocalNetwork(mixed $value, bool $allowed): void {
		$config = $this->service->save(self::validSettings() + ['paperless_local_network' => $value], 'secret-token');

		self::assertSame($allowed, $config->paperlessLocalNetwork);
		self::assertSame($allowed, $this->settings['paperless_local_network']);
	}

	/**
	 * Settings that a save refuses, with the message in English, the setting that the page moves
	 * the focus to, and the message in German, which names a setting by its label on the page.
	 *
	 * @return array<string, array{array<string, mixed>, string, string, string}>
	 */
	public static function invalidSettings(): array {
		$url = 'Die Paperless-Adresse darf weder Zugangsdaten noch eine Abfrage oder ein Fragment enthalten.';
		$interval = 'Der Wert von „Intervall in Minuten“ muss zwischen 5 und 1440 liegen.';
		$publicUrl = 'Die Paperless-Adresse im Browser darf weder Zugangsdaten noch eine Abfrage oder ein Fragment enthalten.';

		return [
			'empty URL' => [['paperless_url' => ' '], 'Enter a valid Paperless URL.', 'paperless_url', 'Gib eine gültige Paperless-Adresse ein.'],
			'URL without scheme' => [['paperless_url' => 'paperless.example.test'], 'Enter a valid Paperless URL.', 'paperless_url', 'Gib eine gültige Paperless-Adresse ein.'],
			'FTP URL' => [['paperless_url' => 'ftp://paperless.example.test'], 'The Paperless URL must use HTTP or HTTPS.', 'paperless_url', 'Die Paperless-Adresse muss HTTP oder HTTPS verwenden.'],
			'URL with credentials' => [['paperless_url' => 'https://user:secret@paperless.example.test'], 'The Paperless URL must not contain credentials, a query, or a fragment.', 'paperless_url', $url],
			'URL with a query' => [['paperless_url' => 'https://paperless.example.test/?page=1'], 'The Paperless URL must not contain credentials, a query, or a fragment.', 'paperless_url', $url],
			'URL with a fragment' => [['paperless_url' => 'https://paperless.example.test/#top'], 'The Paperless URL must not contain credentials, a query, or a fragment.', 'paperless_url', $url],
			// The address in the browser becomes the target of links: an http(s) URL alone, never a script.
			'address in the browser without scheme' => [['paperless_public_url' => 'paperless.example.com'], 'Enter a valid address of Paperless in the browser, or leave it empty.', 'paperless_public_url', 'Gib eine gültige Paperless-Adresse im Browser ein oder lass das Feld leer.'],
			'address in the browser that runs a script' => [['paperless_public_url' => 'javascript:alert(1)'], 'Enter a valid address of Paperless in the browser, or leave it empty.', 'paperless_public_url', 'Gib eine gültige Paperless-Adresse im Browser ein oder lass das Feld leer.'],
			'FTP address in the browser' => [['paperless_public_url' => 'ftp://paperless.example.com'], 'The address of Paperless in the browser must use HTTP or HTTPS.', 'paperless_public_url', 'Die Paperless-Adresse im Browser muss HTTP oder HTTPS verwenden.'],
			'address in the browser with credentials' => [['paperless_public_url' => 'https://user:secret@paperless.example.com'], 'The address of Paperless in the browser must not contain credentials, a query, or a fragment.', 'paperless_public_url', $publicUrl],
			'address in the browser with a query' => [['paperless_public_url' => 'https://paperless.example.com/?page=1'], 'The address of Paperless in the browser must not contain credentials, a query, or a fragment.', 'paperless_public_url', $publicUrl],
			'address in the browser with a fragment' => [['paperless_public_url' => 'https://paperless.example.com/#top'], 'The address of Paperless in the browser must not contain credentials, a query, or a fragment.', 'paperless_public_url', $publicUrl],
			'no target user' => [['target_user' => '  '], 'A Nextcloud target user is required.', 'target_user', 'Ein Nextcloud-Zielbenutzer ist erforderlich.'],
			// A user whom someone creates later under the ID must not get every document unasked.
			'a target user that does not exist' => [['target_user' => 'nobody'], 'Nextcloud user nobody does not exist.', 'target_user', 'Den Nextcloud-Benutzer nobody gibt es nicht.'],
			'base path leaving the user folder' => [['base_path' => 'Dokumente/../..'], 'Folder paths must be relative and must not contain empty, . or .. components.', '', 'Ordnerpfade müssen relativ sein und dürfen keine leeren Teile, . oder .. enthalten.'],
			'no base path' => [['base_path' => ' / '], 'A relative folder path is required.', '', 'Ein relativer Ordnerpfad ist erforderlich.'],
			'nested folder name' => [['archive_folder' => 'Archiv/2026'], 'Folder names must contain exactly one path component.', '', 'Ordnernamen müssen aus genau einem Pfadteil bestehen.'],
			'shared folder name' => [['error_folder' => 'Inbox'], 'Archive, inbox, and error folders must have different names.', '', 'Archiv-, Eingangs- und Fehlerordner müssen verschiedene Namen haben.'],
			'template without ID' => [['path_template' => '{{ title }}{{ extension }}'], 'The archive path template must contain {{ id }}.', 'path_template', 'Die Archiv-Pfadvorlage muss {{ id }} enthalten.'],
			'interval shorter than the background job' => [['sync_interval_minutes' => 4], '"Interval in minutes" must be between 5 and 1440.', 'sync_interval_minutes', $interval],
			'interval longer than a day' => [['sync_interval_minutes' => 1441], '"Interval in minutes" must be between 5 and 1440.', 'sync_interval_minutes', $interval],
			'batch too large' => [['batch_size' => 1001], '"Maximum changes per module and run" must be between 1 and 1000.', 'batch_size', 'Der Wert von „Maximale Änderungen je Modul und Lauf“ muss zwischen 1 und 1000 liegen.'],
			'too many confirmation runs' => [['missing_grace_runs' => 31], '"Required consecutive missing scans" must be between 1 and 30.', 'missing_grace_runs', 'Der Wert von „Erforderliche aufeinanderfolgende Läufe ohne das Dokument“ muss zwischen 1 und 30 liegen.'],
			'unknown trash policy' => [['trash_mode' => 'delete'], 'Choose one of the options of "Paperless trash behavior".', 'trash_mode', 'Wähle eine der Optionen von „Verhalten beim Paperless-Papierkorb“.'],
			'unknown conflict policy' => [['conflict_mode' => 'rename'], 'Choose one of the options of "File conflict policy".', 'conflict_mode', 'Wähle eine der Optionen von „Dateikonflikt-Regel“.'],
			// A group that someone creates later under the name must not get the action unasked.
			'a group that does not exist' => [['send_group' => 'scaners'], 'Nextcloud group scaners does not exist.', 'send_group', 'Die Nextcloud-Gruppe scaners gibt es nicht.'],
			'unknown ownership' => [['ownership' => 'shared'], 'Choose one of the options of "Who gets the documents".', 'ownership', 'Wähle eine der Optionen von „Wer die Dokumente erhält“.'],
			'a line of the table that is no mapping' => [['ownership' => 'per-user', 'user_map' => "jdoe = admin\njdoe"], '"Paperless users with another Nextcloud ID", line 2: write the user of Paperless, an equals sign and the user ID of Nextcloud, such as jdoe = john.doe.', 'user_map', '„Paperless-Benutzer mit einer anderen Nextcloud-ID“, Zeile 2: Schreibe den Benutzer von Paperless, ein Gleichheitszeichen und die Benutzer-ID von Nextcloud, etwa jdoe = john.doe.'],
			'a user of Paperless twice in the table' => [['ownership' => 'per-user', 'user_map' => "jdoe = admin\nJDoe = paperless"], '"Paperless users with another Nextcloud ID", line 2: the user JDoe of Paperless has a line already.', 'user_map', '„Paperless-Benutzer mit einer anderen Nextcloud-ID“, Zeile 2: Der Benutzer JDoe von Paperless hat schon eine Zeile.'],
			// A user whom someone creates later under the ID must not get the documents unasked.
			'a user of Nextcloud in the table that does not exist' => [['ownership' => 'per-user', 'user_map' => 'jdoe = john.doe'], '"Paperless users with another Nextcloud ID", line 1: Nextcloud has no user with the ID john.doe.', 'user_map', '„Paperless-Benutzer mit einer anderen Nextcloud-ID“, Zeile 1: Nextcloud hat keinen Benutzer mit der ID john.doe.'],
			'a group of the users that does not exist' => [['ownership' => 'per-user', 'user_group' => 'scaners'], 'Nextcloud group scaners does not exist.', 'user_group', 'Die Nextcloud-Gruppe scaners gibt es nicht.'],
			'a group of the users that does not exist, with the documents that Paperless shares' => [['ownership' => 'per-user-shared', 'user_group' => 'scaners'], 'Nextcloud group scaners does not exist.', 'user_group', 'Die Nextcloud-Gruppe scaners gibt es nicht.'],
			'a line of the table that is no mapping, with the documents that Paperless shares' => [['ownership' => 'per-user-shared', 'user_map' => 'jdoe'], '"Paperless users with another Nextcloud ID", line 1: write the user of Paperless, an equals sign and the user ID of Nextcloud, such as jdoe = john.doe.', 'user_map', '„Paperless-Benutzer mit einer anderen Nextcloud-ID“, Zeile 1: Schreibe den Benutzer von Paperless, ein Gleichheitszeichen und die Benutzer-ID von Nextcloud, etwa jdoe = john.doe.'],
		];
	}

	/** @param array<string, mixed> $settings */
	#[DataProvider('invalidSettings')]
	public function testRejectsInvalidSettings(array $settings, string $message, string $setting, string $german): void {
		try {
			$this->service->save($settings + self::validSettings(), 'secret-token');
			self::fail('A save must refuse invalid settings.');
		} catch (InvalidSettingException $exception) {
			self::assertSame($message, $exception->getMessage());
			self::assertSame($setting, $exception->setting, 'The page moves the focus to the setting that the message names.');
			self::assertSame($german, $exception->translated($this->germanL10n()));
		}
		self::assertSame([], $this->settings, 'Invalid settings must not be stored.');
	}

	/** The limits of the numbers are those of the fields on the settings page, which a save accepts. */
	public function testTheLimitsOfTheNumbersAreAccepted(): void {
		$config = $this->service->save(['sync_interval_minutes' => 1440, 'batch_size' => 1, 'missing_grace_runs' => 30] + self::validSettings(), 'secret-token');

		self::assertSame([1440, 1, 30], [$config->syncIntervalMinutes, $config->batchSize, $config->missingGraceRuns]);
	}

	public function testUppercaseSchemeIsAccepted(): void {
		self::assertSame('HTTPS://paperless.example.test', $this->service->normalizeUrl('HTTPS://paperless.example.test/'));
		self::assertSame('HTTP://paperless.example.com', $this->service->save(['paperless_public_url' => 'HTTP://paperless.example.com/'] + self::validSettings(), 'secret-token')->paperlessPublicUrl);
	}

	/**
	 * After a restore or a move of Nextcloud with another secret in config.php, the credentials
	 * store can't decrypt the token, and throws: the token counts as none, the settings stay as they
	 * are, and the log says why and what to do (#460).
	 */
	public function testATokenThatCanNotBeDecryptedCountsAsNone(): void {
		$this->service->save(self::validSettings() + ['enabled' => true, 'batch_size' => 20], 'secret-token');
		$this->credentialsUnreadable = true;

		$config = $this->service->get();

		self::assertFalse($config->tokenConfigured);
		self::assertFalse($config->complete(), 'A run doesn\'t start without a token.');
		self::assertSame(['https://paperless.example.test', 'paperless', true, 20], [$config->paperlessUrl, $config->targetUser, $config->enabled, $config->batchSize]);
		self::assertSame('', $this->service->getToken());
		[$snapshot, $connection] = $this->service->snapshot();
		self::assertFalse($snapshot->tokenConfigured);
		self::assertSame('', $connection->token());
		self::assertSame('', $this->service->connection($config)->token());
		self::assertTrue($this->service->tokenUnreadable());
		self::assertContains('Paperless Sync could not read its stored Paperless API token, as after a restore or a move of Nextcloud with another secret (HMAC does not match.). Enter the token again in its settings.', $this->loggedWarnings);
	}

	/** A token that can't be decrypted has to be entered again, as none, also for the same URL. */
	public function testATokenThatCanNotBeDecryptedHasToBeEnteredAgain(): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$this->credentialsUnreadable = true;

		try {
			$this->service->save(self::validSettings());
			self::fail('A save without the token must be refused.');
		} catch (InvalidSettingException $exception) {
			self::assertSame('A Paperless API token is required.', $exception->getMessage());
		}
		$config = $this->service->save(self::validSettings(), 'new-token');

		self::assertTrue($config->tokenConfigured, 'The store encrypts the new token with the secret of this Nextcloud.');
		self::assertFalse($this->service->tokenUnreadable());
		self::assertSame('new-token', $this->service->getToken());
	}

	/** An update from a version before 0.11.1 goes on with a token that can't be decrypted, as with none. */
	public function testAnUnboundTokenThatCanNotBeDecryptedIsNone(): void {
		$this->settings['paperless_url'] = 'https://paperless.example.test';
		$this->credential = 'old-token';
		$this->credentialsUnreadable = true;

		self::assertFalse($this->service->bindUnboundToken());
		self::assertSame('old-token', $this->credential, 'The token stays, for a restore of the right secret.');
	}

	/** A store that holds no token, or one it can read, is no store with a token that it can't read. */
	public function testATokenThatIsMissingOrReadableIsNotUnreadable(): void {
		self::assertFalse($this->service->tokenUnreadable());

		$this->service->save(self::validSettings(), 'secret-token');

		self::assertFalse($this->service->tokenUnreadable());
		self::assertSame([], $this->loggedWarnings);
	}

	/** A token that can't be decrypted is logged once a request, not with every read of the settings. */
	public function testATokenThatCanNotBeDecryptedIsLoggedOnce(): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$this->credentialsUnreadable = true;

		$this->service->get();
		$this->service->get();
		$this->service->snapshot();

		self::assertCount(1, $this->loggedWarnings);
	}

	/**
	 * A failure of the database is no token that can't be decrypted: it goes on as it is, instead of
	 * sending the administrator to enter the token again.
	 */
	public function testAFailureOfTheDatabaseIsNoUnreadableToken(): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$failure = new DatabaseException('The database is gone.');
		$this->credentialsFailure = $failure;

		foreach ([fn (): mixed => $this->service->get(), fn (): mixed => $this->service->tokenUnreadable()] as $read) {
			try {
				$read();
				self::fail('A failure of the database must go on.');
			} catch (DatabaseException $exception) {
				self::assertSame($failure, $exception);
			}
		}
		self::assertSame([], $this->loggedWarnings);
	}

	/**
	 * Files reads the settings with every load, for every user: settings() gives them as get() does,
	 * but without a read, and a decryption, of the token.
	 */
	public function testSettingsDoNotReadTheToken(): void {
		$this->service->save(self::validSettings() + ['batch_size' => 20], 'secret-token');
		$this->credentialReads = 0;

		$config = $this->service->settings();

		self::assertSame(0, $this->credentialReads);
		self::assertSame($this->service->get()->jsonSerialize(), $config->jsonSerialize());
		self::assertTrue($config->tokenConfigured);
		self::assertTrue($config->complete());
	}

	/** As in get(), the token counts only for the URL that it was stored with, and not after a disconnect. */
	public function testSettingsNameTheTokenOnlyForItsUrl(): void {
		$this->service->save(self::validSettings(), 'secret-token');
		$this->settings['paperless_url'] = 'https://other.example.test';

		self::assertFalse($this->service->settings()->tokenConfigured, 'Another URL, as occ may set it, gets no token.');

		$this->settings['paperless_url'] = 'https://paperless.example.test';
		self::assertTrue($this->service->settings()->tokenConfigured);
		$this->service->reset();
		self::assertFalse($this->service->settings()->tokenConfigured);
	}

	/**
	 * An installation of an earlier version stored the token without its URL in the app
	 * configuration: settings() reads the store once, and the record of what it found after that.
	 */
	public function testSettingsOfAnEarlierVersionReadTheTokenOnce(): void {
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'target_user' => 'paperless'];
		$this->credential = ['url' => 'https://paperless.example.test', 'token' => 'secret-token'];

		self::assertTrue($this->service->settings()->tokenConfigured);
		self::assertTrue($this->service->settings()->tokenConfigured);

		self::assertSame(1, $this->credentialReads);
		self::assertSame('https://paperless.example.test', $this->settings['token_url']);
		self::assertArrayHasKey('token_url', $this->lazyKeys, 'Nextcloud loads it only when the app asks for it.');
	}

	/** Such an installation with a token that can't be decrypted reads it as none, once, and goes on. */
	public function testSettingsOfAnEarlierVersionWithATokenThatCanNotBeDecrypted(): void {
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'target_user' => 'paperless'];
		$this->credential = ['url' => 'https://paperless.example.test', 'token' => 'secret-token'];
		$this->credentialsUnreadable = true;

		self::assertFalse($this->service->settings()->tokenConfigured);
		self::assertFalse($this->service->settings()->tokenConfigured);

		self::assertSame(1, $this->credentialReads);
		self::assertCount(1, $this->loggedWarnings);
		self::assertSame('', $this->settings['token_url']);
	}
}
