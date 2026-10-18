<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

use OCP\IL10N;

/**
 * One line of the connection checklist of the settings page: what was checked, whether it
 * passed, failed or passed with a note, what was found and what to do. Its texts are English,
 * with %s for their parameters, as the tests read them; translated() gives the line in the
 * language of the administrator, from the translations of the app.
 *
 * A blocking failure makes the settings unusable for every run: Paperless can't be reached, the
 * token is refused, the documents can't be read or the folder in Nextcloud can't take files. A
 * save refuses settings with one. Every other failure concerns one module, and a save goes on
 * with it, so that an administrator can save first and fix the permissions in Paperless after.
 */
final class ConnectionCheck {
	public const PASSED = 'passed';
	public const FAILED = 'failed';
	/** Passed, with a note that the administrator should read. */
	public const WARNING = 'warning';

	/** What the checks of Paperless and Nextcloud check, in the order of the checklist. */
	public const PAPERLESS = 'paperless';
	public const DOCUMENTS = 'documents';
	public const TRASH = 'trash';
	public const CORRESPONDENTS = 'correspondents';
	public const DOCUMENT_TYPES = 'document_types';
	public const STORAGE_PATHS = 'storage_paths';
	public const TAGS = 'tags';
	public const UPLOAD = 'upload';
	public const INBOX_TAGS = 'inbox_tags';
	public const FOLDER = 'folder';

	/**
	 * @param self::PASSED|self::FAILED|self::WARNING $status
	 * @param list<string> $parameters the values of the placeholders of $message
	 * @param list<string> $hintParameters the values of the placeholders of $hint
	 * @param string $setting the setting that the line names as the way out, which the page moves the focus to
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly string $message,
		public readonly array $parameters = [],
		public readonly string $hint = '',
		public readonly array $hintParameters = [],
		public readonly bool $blocking = false,
		public readonly string $setting = '',
	) {
	}

	/** @param list<string> $parameters */
	public static function passed(string $id, string $message, array $parameters = []): self {
		return new self($id, self::PASSED, $message, $parameters);
	}

	/**
	 * @param list<string> $parameters
	 * @param list<string> $hintParameters
	 */
	public static function warning(string $id, string $message, array $parameters, string $hint, array $hintParameters = []): self {
		return new self($id, self::WARNING, $message, $parameters, $hint, $hintParameters);
	}

	/**
	 * @param list<string> $parameters
	 * @param list<string> $hintParameters
	 */
	public static function failed(string $id, string $message, array $parameters, string $hint, bool $blocking = false, array $hintParameters = []): self {
		return new self($id, self::FAILED, $message, $parameters, $hint, $hintParameters, $blocking);
	}

	/** The line as a warning when it failed, for what only an optional feature needs: it blocks nothing then. */
	public function warningOnly(): self {
		return $this->status === self::FAILED
			? new self($this->id, self::WARNING, $this->message, $this->parameters, $this->hint, $this->hintParameters, false, $this->setting)
			: $this;
	}

	/** Whether the failure makes the settings unusable for every run, so that a save refuses them. */
	public function blocks(): bool {
		return $this->status === self::FAILED && $this->blocking;
	}

	/**
	 * The line in the language of the administrator, as the settings page shows it.
	 *
	 * @return array{id: string, label: string, status: string, message: string, hint: string, setting: string}
	 */
	public function translated(IL10N $l10n): array {
		return [
			'id' => $this->id,
			'label' => $this->label($l10n),
			'status' => $this->status,
			'message' => $l10n->t($this->message, $this->parameters),
			'hint' => $this->hint === '' ? '' : $l10n->t($this->hint, $this->hintParameters),
			'setting' => $this->setting,
		];
	}

	/**
	 * The message and the hint in the language of the administrator, as one text. The hint starts
	 * a sentence of its own, also after a message without a full stop at its end, such as one of
	 * Guzzle while Paperless is down, which ends with the URL.
	 */
	public function translatedText(IL10N $l10n): string {
		$line = $this->translated($l10n);
		if ($line['hint'] === '') {
			return $line['message'];
		}
		$message = rtrim($line['message']);

		return (preg_match('/[.!?]$/', $message) === 1 ? $message : $message . '.') . ' ' . $line['hint'];
	}

	private function label(IL10N $l10n): string {
		return match ($this->id) {
			self::PAPERLESS => $l10n->t('Paperless'),
			self::DOCUMENTS => $l10n->t('Documents'),
			self::TRASH => $l10n->t('Trash'),
			self::CORRESPONDENTS => $l10n->t('Correspondents'),
			self::DOCUMENT_TYPES => $l10n->t('Document types'),
			self::STORAGE_PATHS => $l10n->t('Storage paths'),
			self::TAGS => $l10n->t('Tags'),
			self::UPLOAD => $l10n->t('Upload from the inbox'),
			self::INBOX_TAGS => $l10n->t('Tags of the inbox'),
			self::FOLDER => $l10n->t('Nextcloud folder'),
			default => $this->id,
		};
	}
}
