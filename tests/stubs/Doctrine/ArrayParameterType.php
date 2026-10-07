<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of the Doctrine DBAL class whose constants OCP's IQueryBuilder uses.
 * Nextcloud ships Doctrine DBAL; the app does not depend on it.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Doctrine\DBAL;

final class ArrayParameterType {
	public const INTEGER = 101;
	public const STRING = 102;
}
