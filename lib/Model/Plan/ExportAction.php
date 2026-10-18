<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/**
 * A step of the plan of an export, which ExportPlanner plans and ExportExecutor applies, or, in a
 * dry-run, reports without a change: both kinds of run take the same steps.
 */
interface ExportAction {
}
