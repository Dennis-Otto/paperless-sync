<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

/** The paths of the folders and files of a run, relative to the home of the target user. */
final class Paths {
	/** The parts joined by slashes, without the empty ones and without the slashes at their ends. */
	public static function join(string ...$parts): string {
		return implode('/', array_filter(array_map(static fn (string $part): string => trim($part, '/'), $parts), static fn (string $part): bool => $part !== ''));
	}

	/** The path below $root, or the name of the file for a path outside it. */
	public static function relativeTo(string $path, string $root): string {
		$prefix = rtrim($root, '/') . '/';

		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : basename($path);
	}
}
