<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\IL10N;

/**
 * The connection checklist of settings, which the settings page shows after Test connection, a
 * save that tests and the setup of the account in Paperless, and which occ
 * paperless_sync:create-account prints.
 */
final class ConnectionChecklist {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessClientFactoryInterface $clients,
		private NextcloudStorageInterface $storage,
		private OwnerMapping $ownerMapping,
		private IL10N $l10n,
		private TagShares $tagShares,
	) {
	}

	/**
	 * The checks of Paperless, with $token to the URL of $config and its local network, in
	 * per-user ownership who gets the documents of whom, and with users with tags whether Paperless
	 * has their tags, once Paperless answered, then whether the target user may create files in the
	 * base folder, which every run needs, whatever Paperless answered, and with users with tags
	 * whether a share of the target user shows them more than their tags.
	 *
	 * @return list<ConnectionCheck>
	 */
	public function checks(SyncConfig $config, #[\SensitiveParameter] string $token): array {
		$paperless = $this->clients->connect(PaperlessConnection::of($config, $token));
		$checks = $paperless->preflight($config);
		$answered = array_filter($checks, static fn (ConnectionCheck $check): bool => $check->blocks()) === [];
		if ($config->perUser() && $answered) {
			array_push($checks, ...$this->ownerMapping->checks($config, $paperless, $this->storage));
		}
		if (UserTags::of($config)->users() !== [] && $answered) {
			$checks[] = $this->userTags($config, $paperless);
		}
		$checks[] = $this->folder($config);
		$shares = $this->tagShares->check($config);
		if ($shares !== null) {
			$checks[] = $shares;
		}

		return $checks;
	}

	/**
	 * Whether Paperless has every tag that the lines of "Tags that a user may see" name: the runs
	 * leave the archive of a user with a tag that it doesn't have as it is.
	 */
	private function userTags(SyncConfig $config, PaperlessClientInterface $paperless): ConnectionCheck {
		try {
			$unknown = UserTags::of($config)->unknown($paperless->tags()['names']);
		} catch (\Throwable $exception) {
			return new ConnectionCheck(ConnectionCheck::USER_TAGS, ConnectionCheck::WARNING, 'Paperless did not list its tags: %s', [$exception->getMessage()], 'The runs need them to give the users with tags their documents.', [], false, 'user_tags');
		}
		if ($unknown === []) {
			return ConnectionCheck::passed(ConnectionCheck::USER_TAGS, 'Paperless has every tag that "Tags that a user may see" names.');
		}
		$lines = [];
		foreach ($unknown as $user => $names) {
			$lines[] = $user . ': "' . implode('", "', $names) . '"';
		}

		return new ConnectionCheck(ConnectionCheck::USER_TAGS, ConnectionCheck::WARNING, 'Paperless has no tag of these names, which "Tags that a user may see" names: %s.', [implode('; ', $lines)], 'The runs leave the archive of such a user as it is until the line or Paperless names the tag: correct the line, or create the tag in Paperless.', [], false, 'user_tags');
	}

	/** Whether the target user may create files in the base folder, which every run needs. */
	public function folder(SyncConfig $config): ConnectionCheck {
		try {
			$this->storage->test($config->targetUser, $config->basePath);

			return ConnectionCheck::passed(ConnectionCheck::FOLDER, '%s may create files in %s.', [$config->targetUser, $config->basePath]);
		} catch (\Throwable $exception) {
			return ConnectionCheck::failed(
				ConnectionCheck::FOLDER,
				'Nextcloud cannot store the files there: %s',
				[$exception instanceof InvalidSettingException ? $exception->translated($this->l10n) : $exception->getMessage()],
				'Check the target user ID, and that the base folder is a folder in which that user may create files.',
				true,
			);
		}
	}
}
