<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\PaperlessConnection;

interface PaperlessClientFactoryInterface {
	/**
	 * A client of the Paperless of $connection: every request of the client goes to its URL, with
	 * its token, whatever the settings say meanwhile. A run passes $signOfLife, which the client
	 * calls while a document moves, so that a long download or upload keeps the run alive
	 * (StatusService::HEARTBEAT_GRACE).
	 *
	 * @param (\Closure(): void)|null $signOfLife
	 */
	public function connect(PaperlessConnection $connection, ?\Closure $signOfLife = null): PaperlessClientInterface;
}
