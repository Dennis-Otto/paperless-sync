<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

/**
 * The files of the inbox that never go to Paperless, by the extensions of their names: videos,
 * programs and archives, which no parser of Paperless makes a document of, whatever its version
 * and its settings. Every other file goes to Paperless, which judges it by its content and refuses
 * what it can't use, so a list of what Paperless takes would go out of date and hold back real
 * documents, such as a scan with an odd name (decision 0031).
 */
final class InboxFileTypes {
	public const VIDEO = 'video';
	public const PROGRAM = 'program';
	public const ARCHIVE = 'archive';

	/** The extensions of each kind, in lower case. */
	private const EXTENSIONS = [
		self::VIDEO => [
			'3g2', '3gp', 'asf', 'avi', 'divx', 'dv', 'f4v', 'flv', 'm2ts', 'm2v', 'm4v', 'mkv', 'mov',
			'mp4', 'mpe', 'mpeg', 'mpg', 'mts', 'mxf', 'ogv', 'rm', 'rmvb', 'vob', 'webm', 'wmv',
		],
		self::PROGRAM => [
			'aab', 'apk', 'appimage', 'appx', 'appxbundle', 'bat', 'cmd', 'com', 'deb', 'dll', 'dmg',
			'dylib', 'exe', 'flatpak', 'ipa', 'jar', 'msi', 'msix', 'msixbundle', 'msp', 'msu', 'pkg',
			'ps1', 'rpm', 'run', 'scr', 'sh', 'snap', 'so', 'sys', 'vbs', 'xapk',
		],
		self::ARCHIVE => [
			'7z', 'ace', 'arj', 'bz2', 'cab', 'gz', 'img', 'iso', 'lha', 'lz', 'lz4', 'lzh', 'lzma', 'rar',
			'tar', 'tbz', 'tbz2', 'tgz', 'txz', 'vhd', 'vhdx', 'vmdk', 'wim', 'xz', 'z', 'zip', 'zst',
		],
	];

	/**
	 * The kind of a file that the inbox sets aside, by its name, whatever the case of its extension,
	 * or null for a file that goes to Paperless.
	 *
	 * @return self::VIDEO|self::PROGRAM|self::ARCHIVE|null
	 */
	public static function setAsideKind(string $name): ?string {
		$dot = strrpos($name, '.');
		if ($dot === false) {
			return null;
		}
		$extension = strtolower(substr($name, $dot + 1));
		foreach (self::EXTENSIONS as $kind => $extensions) {
			if (in_array($extension, $extensions, true)) {
				return $kind;
			}
		}

		return null;
	}
}
