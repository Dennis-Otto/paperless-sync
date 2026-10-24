<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IL10N;

/**
 * The permissions of Paperless that the account of the app needs, for the modules that the
 * settings turn on and no more: the table that the README shows, that the connection checklist
 * checks and that the setup of the account grants (decision 0027). Paperless names a permission by
 * its codename, such as add_document, and its settings show it as a box in the row of a kind of
 * object, such as Add for Documents.
 */
final class AccountPermissions {
	public const VIEW_DOCUMENT = 'view_document';
	public const ADD_DOCUMENT = 'add_document';
	public const CHANGE_DOCUMENT = 'change_document';
	public const VIEW_CORRESPONDENT = 'view_correspondent';
	public const VIEW_DOCUMENT_TYPE = 'view_documenttype';
	public const VIEW_STORAGE_PATH = 'view_storagepath';
	public const VIEW_TAG = 'view_tag';
	public const ADD_TAG = 'add_tag';
	public const VIEW_CUSTOM_FIELD = 'view_customfield';
	public const VIEW_PAPERLESS_TASK = 'view_paperlesstask';
	public const VIEW_UI_SETTINGS = 'view_uisettings';
	public const VIEW_USER = 'view_user';

	/** Each permission as the settings of Paperless show it, in the order of the table. */
	private const LABELS = [
		self::VIEW_DOCUMENT => 'View for Documents',
		self::ADD_DOCUMENT => 'Add for Documents',
		self::CHANGE_DOCUMENT => 'Change for Documents',
		self::VIEW_CORRESPONDENT => 'View for Correspondents',
		self::VIEW_DOCUMENT_TYPE => 'View for Document Types',
		self::VIEW_STORAGE_PATH => 'View for Storage Paths',
		self::VIEW_TAG => 'View for Tags',
		self::ADD_TAG => 'Add for Tags',
		self::VIEW_CUSTOM_FIELD => 'View for Custom Fields',
		self::VIEW_PAPERLESS_TASK => 'View for Paperless Tasks',
		self::VIEW_UI_SETTINGS => 'View for UI Settings',
		self::VIEW_USER => 'View for Users',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PathTemplateService $pathTemplate,
	) {
	}

	/**
	 * The codenames of the permissions that the modules of $config need, in the order of the table:
	 *
	 * - always View for Documents, and View for UI Settings, which tells the connection checklist
	 *   the permissions and the ID of the account;
	 * - the archive View for Correspondents, Document Types, Storage Paths and Tags, View for Users
	 *   for {{ owner_username }} and View for Custom Fields for a custom field of the path template
	 *   or the metadata next to the archive;
	 * - an upload, from the inbox or with "Send to Paperless", Add for Documents and View for
	 *   Paperless Tasks, and the tags of the inbox View for Tags, with Add for Tags to create them;
	 * - per-user ownership View for Users, and with uploads Change for Documents, which gives the
	 *   document of an upload its owner.
	 *
	 * The access to the documents of other owners is no permission of an account but one of each
	 * document, which Paperless gives through its owner, its permissions or a workflow.
	 *
	 * @return list<string>
	 */
	public function of(SyncConfig $config): array {
		// The inbox and the files that people send from Files reach Paperless as uploads.
		$uploads = $config->inboxEnabled || $config->sendGroup !== '';
		$permissions = [self::VIEW_DOCUMENT, self::VIEW_UI_SETTINGS];
		if ($config->exportEnabled) {
			array_push($permissions, self::VIEW_CORRESPONDENT, self::VIEW_DOCUMENT_TYPE, self::VIEW_STORAGE_PATH, self::VIEW_TAG);
			if ($this->pathTemplate->usesOwner($config->pathTemplate)) {
				$permissions[] = self::VIEW_USER;
			}
			if ($config->writeMetadata || $this->pathTemplate->customFieldNames($config->pathTemplate) !== []) {
				$permissions[] = self::VIEW_CUSTOM_FIELD;
			}
		}
		if ($uploads) {
			array_push($permissions, self::ADD_DOCUMENT, self::VIEW_PAPERLESS_TASK);
			if ($config->inboxFolderTags || $config->inboxTags !== '') {
				$permissions[] = self::VIEW_TAG;
				if ($config->inboxCreateTags) {
					$permissions[] = self::ADD_TAG;
				}
			}
		}
		if ($config->perUser()) {
			$permissions[] = self::VIEW_USER;
			if ($uploads) {
				$permissions[] = self::CHANGE_DOCUMENT;
			}
		}

		return array_values(array_intersect(array_keys(self::LABELS), $permissions));
	}

	/**
	 * The permissions with these codenames as the settings of Paperless show them, such as "View
	 * for Documents", in the language of the reader; a codename that the table lacks as it is.
	 *
	 * @param list<string> $permissions
	 * @return list<string>
	 */
	public static function labels(array $permissions, IL10N $l10n): array {
		return array_map(
			static fn (string $permission): string => isset(self::LABELS[$permission]) ? $l10n->t(self::LABELS[$permission]) : $permission,
			$permissions,
		);
	}
}
