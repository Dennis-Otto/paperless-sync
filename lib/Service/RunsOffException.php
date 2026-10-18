<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

/**
 * The settings turn the runs of documents off: the app is not completely configured, or
 * scheduled synchronization or the export is off. A call of the webhook then changes nothing on
 * purpose, unlike a setting that the app can't use, which makes the run fail.
 */
final class RunsOffException extends InvalidSettingException {
}
