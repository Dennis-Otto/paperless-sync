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

	/**
	 * The name of a file without any marker of a document such as [P123], by the expression with which
	 * Paperless Unified Search looks for one. That app then drops some, such as [P007], which stands for
	 * no document; these go here as well, to stay on the safe side. So does one that the removal of
	 * another one forms. A name without a marker stays as it is. Of a name that loses one, the spaces
	 * and the dots that would make it unusable go as well, and $fallback stands in front of what is
	 * left when no name is left, so that it is neither empty nor . or .. nor a hidden file.
	 */
	public static function withoutMarkers(string $name, string $fallback): string {
		$clean = $name;
		do {
			$before = $clean;
			// A failure of the expression leaves no name, rather than one that may still carry a marker.
			$clean = preg_replace('/ *\[P\d+\]/', '', $before) ?? '';
		} while ($clean !== $before);
		if ($clean === $name) {
			return $name;
		}
		$clean = rtrim(ltrim($clean, ' '), ' .');

		return $clean === '' || $clean[0] === '.' ? $fallback . $clean : $clean;
	}
}
