<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of the Doctrine DBAL class whose constants OCP's IQueryBuilder uses.
 * Nextcloud ships Doctrine DBAL; the app does not depend on it.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Doctrine\DBAL\Types;

final class Types {
	public const BOOLEAN = 'boolean';
	public const DATE_MUTABLE = 'date';
	public const DATE_IMMUTABLE = 'date_immutable';
	public const DATETIME_MUTABLE = 'datetime';
	public const DATETIME_IMMUTABLE = 'datetime_immutable';
	public const DATETIMETZ_MUTABLE = 'datetimetz';
	public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
	public const TIME_MUTABLE = 'time';
}
