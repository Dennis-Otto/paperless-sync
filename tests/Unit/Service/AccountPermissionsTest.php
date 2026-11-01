<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\AccountPermissions;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The permissions of Paperless that the setup of the account grants: those of the modules that
 * the settings turn on, and no more.
 */
final class AccountPermissionsTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	/** @return array<string, array{array<string, mixed>, list<string>}> the settings and the codenames of the permissions */
	public static function modules(): array {
		$none = ['export_enabled' => false, 'inbox_enabled' => false, 'send_group' => ''];

		return [
			'no module, as a check of the connection alone' => [$none, ['view_document', 'view_uisettings']],
			'the archive' => [
				['export_enabled' => true] + $none,
				['view_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_uisettings'],
			],
			'the archive with the owner and a custom field in its path' => [
				['export_enabled' => true, 'path_template' => '{{ owner_username }}/{{ custom_fields.Invoice number }}/{{ title }} [P{{ id }}]'] + $none,
				['view_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_customfield', 'view_uisettings', 'view_user'],
			],
			'the archive with the metadata next to it' => [
				['export_enabled' => true, 'write_metadata' => true] + $none,
				['view_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_customfield', 'view_uisettings'],
			],
			'the inbox' => [
				['inbox_enabled' => true] + $none,
				['view_document', 'add_document', 'view_paperlesstask', 'view_uisettings'],
			],
			'"Send to Paperless" alone' => [
				['send_group' => 'admin'] + $none,
				['view_document', 'add_document', 'view_paperlesstask', 'view_uisettings'],
			],
			'the tags of the inbox' => [
				['inbox_enabled' => true, 'inbox_tags' => 'Scanned'] + $none,
				['view_document', 'add_document', 'view_tag', 'view_paperlesstask', 'view_uisettings'],
			],
			'the tags of the folders of the inbox, created when missing' => [
				['inbox_enabled' => true, 'inbox_folder_tags' => true, 'inbox_create_tags' => true] + $none,
				['view_document', 'add_document', 'view_tag', 'add_tag', 'view_paperlesstask', 'view_uisettings'],
			],
			'missing tags to create, without tags of the inbox' => [
				['inbox_enabled' => true, 'inbox_create_tags' => true] + $none,
				['view_document', 'add_document', 'view_paperlesstask', 'view_uisettings'],
			],
			'per-user ownership of the archive' => [
				['export_enabled' => true, 'ownership' => 'per-user'] + $none,
				['view_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_uisettings', 'view_user'],
			],
			'the documents that Paperless shares, also with a group' => [
				['export_enabled' => true, 'ownership' => 'per-user-shared'] + $none,
				['view_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_uisettings', 'view_user', 'view_group'],
			],
			'per-user ownership with the inbox, whose uploads get their owner' => [
				['export_enabled' => true, 'inbox_enabled' => true, 'ownership' => 'per-user'] + $none,
				['view_document', 'add_document', 'change_document', 'view_correspondent', 'view_documenttype', 'view_storagepath', 'view_tag', 'view_paperlesstask', 'view_uisettings', 'view_user'],
			],
		];
	}

	/** @param array<string, mixed> $settings @param list<string> $permissions */
	#[DataProvider('modules')]
	public function testGrantsThePermissionsOfTheModulesAlone(array $settings, array $permissions): void {
		$config = $this->configService()->validate(['paperless_url' => 'https://paperless.example.test', 'target_user' => 'paperless'] + $settings, 'secret-token');

		self::assertSame($permissions, (new AccountPermissions(new PathTemplateService()))->of($config));
	}

	/** The page and the command show each permission as the settings of Paperless show it. */
	public function testNamesThePermissionsAsPaperlessShowsThem(): void {
		self::assertSame(
			['„Anzeigen“ für Dokumente', '„Ändern“ für Dokumente', '„Hinzufügen“ für Tags', '„Anzeigen“ für Benutzer', 'delete_document'],
			AccountPermissions::labels(['view_document', 'change_document', 'add_tag', 'view_user', 'delete_document'], $this->germanL10n()),
		);
	}
}
