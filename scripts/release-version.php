<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use PaperlessSync\ReleaseTools\ReleaseVersionManager;

require_once __DIR__ . '/ReleaseVersionManager.php';

$arguments = array_slice($argv, 1);
$root = dirname(__DIR__);
$date = gmdate('Y-m-d');

foreach ($arguments as $index => $argument) {
	if (str_starts_with($argument, '--root=')) {
		$root = substr($argument, strlen('--root='));
		unset($arguments[$index]);
	} elseif (str_starts_with($argument, '--date=')) {
		$date = substr($argument, strlen('--date='));
		unset($arguments[$index]);
	}
}

$arguments = array_values($arguments);
$command = $arguments[0] ?? '';
$manager = new ReleaseVersionManager(rtrim($root, '/'));
$parseBoolean = static function (string $value, string $name): bool {
	return match ($value) {
		'true' => true,
		'false' => false,
		default => throw new RuntimeException("{$name} must be true or false."),
	};
};

try {
	switch ($command) {
		case 'current':
			echo $manager->currentVersion() . "\n";
			break;
		case 'check':
			echo $manager->check() . "\n";
			break;
		case 'next':
			echo $manager->nextVersion($manager->check(), $arguments[1] ?? '') . "\n";
			break;
		case 'plan':
			$plan = $manager->releasePlan(
				increment: $arguments[1] ?? '',
				currentReleaseExists: $parseBoolean($arguments[2] ?? '', 'current-release-exists'),
				currentReleasePullRequestMerged: $parseBoolean($arguments[3] ?? '', 'current-release-pr-merged'),
				anyReleaseExists: $parseBoolean($arguments[4] ?? '', 'any-release-exists'),
				hasUnreleasedNotes: $parseBoolean($arguments[5] ?? '', 'has-unreleased-notes'),
			);
			echo json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
			break;
		case 'bump':
			echo $manager->bump($arguments[1] ?? '', $date) . "\n";
			break;
		case 'notes':
			echo $manager->releaseNotes($arguments[1] ?? $manager->check());
			break;
		default:
			throw new RuntimeException(
				'Usage: release-version.php current|check|next <increment>|plan <increment> <current-release-exists> <current-release-pr-merged> <any-release-exists> <has-unreleased-notes>|bump <increment>|notes [version]',
			);
	}
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}
