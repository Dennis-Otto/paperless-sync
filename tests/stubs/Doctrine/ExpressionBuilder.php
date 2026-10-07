<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of the Doctrine DBAL class whose constants OCP's IExpressionBuilder uses.
 * Nextcloud ships Doctrine DBAL; the app does not depend on it.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Doctrine\DBAL\Query\Expression;

final class ExpressionBuilder {
	public const EQ = '=';
	public const NEQ = '<>';
	public const LT = '<';
	public const LTE = '<=';
	public const GT = '>';
	public const GTE = '>=';
}
