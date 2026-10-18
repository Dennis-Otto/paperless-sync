<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model\Plan;

/** The copy of a document that stays at its path, as the metadata next to the archive describes it. */
final class Copy {
	/**
	 * @param array<string, mixed> $document the document as Paperless lists it
	 * @param string $fingerprint the checksums of the copy, as the state keeps them
	 * @param bool $original whether the copy is the original file of the document, not its archive version
	 */
	public function __construct(
		public readonly array $document,
		public readonly string $path,
		public readonly string $fingerprint,
		public readonly bool $original,
	) {
	}
}
