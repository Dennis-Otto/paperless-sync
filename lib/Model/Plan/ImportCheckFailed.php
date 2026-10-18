<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/** The run could not check the upload of a file of the inbox, which stays pending, with the error. */
final class ImportCheckFailed implements ImportAction {
	public function __construct(
		public readonly ImportContext $context,
		public readonly \Throwable $exception,
	) {
	}
}
