<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'settings#save', 'url' => '/settings', 'verb' => 'POST'],
		['name' => 'settings#reset', 'url' => '/settings', 'verb' => 'DELETE'],
		['name' => 'sync#run', 'url' => '/sync/run', 'verb' => 'POST'],
		['name' => 'sync#status', 'url' => '/sync/status', 'verb' => 'GET'],
	],
];
