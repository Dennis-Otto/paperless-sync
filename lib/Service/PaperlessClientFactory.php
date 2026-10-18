<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\PaperlessConnection;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use OCP\Security\IRemoteHostValidator;

/**
 * The clients of Paperless-ngx, one for each connection, which Nextcloud's container can't make on
 * its own: a run, a dry-run, the run of one document and the settings page each take theirs.
 */
final class PaperlessClientFactory implements PaperlessClientFactoryInterface {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IClientService $clientService,
		private ITempManager $tempManager,
		private IRemoteHostValidator $remoteHostValidator,
	) {
	}

	public function connect(PaperlessConnection $connection): PaperlessClientInterface {
		return new PaperlessApiService($connection, $this->clientService, $this->tempManager, $this->remoteHostValidator);
	}
}
