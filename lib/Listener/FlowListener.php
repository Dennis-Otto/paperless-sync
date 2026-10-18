<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Listener;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Operation\SendToPaperless;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\LoadSettingsScriptsEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * Brings "Send to Paperless" into Flow: the operation, when Flow asks the apps for theirs, and
 * js/flow.js, which registers its form for the tags of a rule, when Flow loads the scripts of its
 * settings, after its own.
 *
 * @template-implements IEventListener<Event>
 */
final class FlowListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private SendToPaperless $operation,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof RegisterOperationsEvent) {
			$event->registerOperation($this->operation);
		} elseif ($event instanceof LoadSettingsScriptsEvent) {
			Util::addScript(AppConstants::APP_ID, 'flow', 'workflowengine');
		}
	}
}
