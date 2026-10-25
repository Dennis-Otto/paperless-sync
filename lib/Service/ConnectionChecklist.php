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
	) {
	}

	/**
	 * The checks of Paperless, with $token to the URL of $config and its local network, in
	 * per-user ownership who gets the documents of whom, once Paperless answered, then whether the
	 * target user may create files in the base folder, which every run needs, whatever Paperless
	 * answered.
	 *
	 * @return list<ConnectionCheck>
	 */
	public function checks(SyncConfig $config, #[\SensitiveParameter] string $token): array {
		$paperless = $this->clients->connect(PaperlessConnection::of($config, $token));
		$checks = $paperless->preflight($config);
		if ($config->perUser() && array_filter($checks, static fn (ConnectionCheck $check): bool => $check->blocks()) === []) {
			array_push($checks, ...$this->ownerMapping->checks($config, $paperless, $this->storage));
		}
		$checks[] = $this->folder($config);

		return $checks;
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
