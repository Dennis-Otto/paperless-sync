<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

/**
 * An exception whose message the app wrote, so that a reader who doesn't read English gets it in
 * the language of the reader: RunError::of() keeps its text, its parameters and its setting.
 */
interface TranslatableError {
	public function runError(): RunError;
}
