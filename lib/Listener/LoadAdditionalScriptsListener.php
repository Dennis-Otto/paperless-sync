<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Listener;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\FileActions;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Brings the actions of the app into Files when Files loads the scripts of other apps: js/files.js,
 * which registers "Send to Paperless" and "Open in Paperless", with what it may offer the user
 * as the initial state files. A user who may use neither gets no script.
 *
 * @template-implements IEventListener<Event>
 */
final class LoadAdditionalScriptsListener implements IEventListener {
	/**
	 * The event of Files, which is not part of the public API of Nextcloud: Application registers
	 * the listener by its name, so that the app needs no class of Files.
	 */
	public const EVENT = 'OCA\Files\Event\LoadAdditionalScriptsEvent';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private FileActions $fileActions,
		private IUserSession $userSession,
		private IInitialState $initialState,
	) {
	}

	public function handle(Event $event): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$offers = $this->fileActions->offers($user->getUID());
		if (!$offers['send'] && $offers['paperlessUrl'] === '') {
			return;
		}
		$this->initialState->provideInitialState('files', $offers);
		// An init script, which Nextcloud loads before Files reads the registered actions, with the
		// translations of the app.
		Util::addInitScript(AppConstants::APP_ID, 'files');
	}
}
