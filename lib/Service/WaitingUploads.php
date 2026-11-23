<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\Owners;
use OCA\PaperlessSync\Model\SyncConfig;

/**
 * The uploads of per-user ownership that wait for their owner, and the documents that the runs
 * hold for them. Paperless makes the account of the app the owner of every upload, and the app
 * gives the document the owner of its file only once the task of the upload has ended, or in a
 * later run when Paperless refused the change. Until then, the document of Alice's upload looks
 * like a document of the account, which would go to the target user, or to the user of the
 * account, so the runs hold every document of the account while an upload waits: the export
 * leaves its copies where they are, and no file of the inbox or of Send to Paperless takes such a
 * document for its own. An upload waits while the state of its file waits for its task, and while
 * its record is open, as UploadRecords explains, also after its file has left the inbox, after an
 * answer that never came, and with the inbox turned off.
 */
final class WaitingUploads {
	/**
	 * The users of Paperless whose uploads of the inbox or of Send to Paperless wait for their
	 * document, which then gets them as its owner. $watched counts every record that has not
	 * ended, also one that only the look of the scheduled runs follows: the run of one document
	 * leaves a document of the account to the scheduled runs while any upload may have created it.
	 *
	 * @return array<int, true>
	 */
	public static function owners(SyncStateRepositoryInterface $state, SyncConfig $config, Owners $owners, int $now, bool $watched): array {
		$waiting = [];
		foreach ($state->allUploads() as $record) {
			$owner = $owners->paperlessUserOf((string)($record['user_id'] ?? ''));
			if ($owner !== null && ($watched ? !UploadRecords::ended($record, $now) : UploadRecords::holds($record, $now))) {
				$waiting[$owner] = true;
			}
		}
		if ($config->inboxEnabled) {
			foreach ($owners->users() as $user) {
				$owner = $owners->paperlessUserOf($user);
				if ($owner !== null && in_array('pending', array_map(static fn (array $import): string => (string)($import['status'] ?? ''), $state->allImports($user)), true)) {
					$waiting[$owner] = true;
				}
			}
		}
		foreach ($state->allSends() as $send) {
			$owner = $owners->paperlessUserOf((string)($send['user_id'] ?? ''));
			if ($owner !== null && ($send['status'] ?? '') === SendService::PENDING) {
				$waiting[$owner] = true;
			}
		}

		return $waiting;
	}

	/**
	 * The user of Paperless whose documents the run holds: the account of the app, while an upload
	 * of one of these owners waits, other than the uploads of $own, the user of Paperless of the
	 * user of Nextcloud whose files ask, whose documents would be theirs anyway. Null holds
	 * nothing, as when no upload waits or each would keep the account as its owner; false holds
	 * every document, when Paperless doesn't name its account.
	 *
	 * @param array<int, true> $waiting
	 */
	public static function held(array $waiting, PaperlessClientInterface $paperless, ?int $own = null): int|false|null {
		if ($own !== null) {
			unset($waiting[$own]);
		}
		if ($waiting === []) {
			return null;
		}
		$account = $paperless->accountId();
		if ($account === null) {
			return false;
		}

		return array_diff_key($waiting, [$account => true]) === [] ? null : $account;
	}
}
