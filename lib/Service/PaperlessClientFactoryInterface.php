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
	 * its token, whatever the settings say meanwhile.
	 */
	public function connect(PaperlessConnection $connection): PaperlessClientInterface;
}
