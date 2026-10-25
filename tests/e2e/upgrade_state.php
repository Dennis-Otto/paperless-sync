<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// What upgrade.sh compares before and after the upgrade (assert_upgrade.py): every row of every
// table of the app, its values in the configuration of the apps, its credentials and its executed
// migrations, and every file and folder below the base folder of each user, with its ID. It reads them from Nextcloud,
// not through the app, so that it reads the release and the commit alike. upgrade.sh hands it
// to php in the container of Nextcloud on standard input, with the users and the base folder in
// E2E_USERS and E2E_BASE_PATH, and it prints one line, STATE and the JSON.

require '/var/www/html/lib/base.php';

// Nextcloud only logs an exception that ends a script; this one says why it failed.
set_exception_handler(function (\Throwable $exception): void {
	fwrite(STDERR, 'upgrade_state.php failed: ' . $exception->getMessage() . PHP_EOL);
	exit(1);
});

$prefix = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
$connection = \OCP\Server::get(\OC\DB\Connection::class);
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$state = ['tables' => [], 'appconfig' => [], 'credentials' => [], 'migrations' => [], 'files' => []];

// The tables of the app, whichever its migrations created.
$tables = [];
foreach ($connection->createSchemaManager()->listTableNames() as $name) {
	if (str_starts_with($name, $prefix . 'paperless_sync_')) {
		$tables[] = substr($name, strlen($prefix));
	}
}
sort($tables);
foreach ($tables as $table) {
	$result = $db->getQueryBuilder()->select('*')->from($table)->orderBy('id')->executeQuery();
	$state['tables'][$table] = $result->fetchAll();
	$result->closeCursor();
}

$query = $db->getQueryBuilder();
$result = $query->select('configkey', 'configvalue')->from('appconfig')
	->where($query->expr()->eq('appid', $query->createNamedParameter('paperless_sync')))
	->orderBy('configkey')->executeQuery();
foreach ($result->fetchAll() as $row) {
	$state['appconfig'][$row['configkey']] = $row['configvalue'];
}
$result->closeCursor();

// The migrations that Nextcloud executed, as occ migrations:status counts them, which only a
// Nextcloud in debug mode has.
$query = $db->getQueryBuilder();
$result = $query->select('version')->from('migrations')
	->where($query->expr()->eq('app', $query->createNamedParameter('paperless_sync')))
	->orderBy('version')->executeQuery();
$state['migrations'] = array_map(fn (array $row): string => (string)$row['version'], $result->fetchAll());
$result->closeCursor();

// The token and the secret of the webhook, as the credentials manager of Nextcloud keeps them,
// encrypted.
$query = $db->getQueryBuilder();
$result = $query->select('user', 'identifier', 'credentials')->from('storages_credentials')
	->where($query->expr()->like('identifier', $query->createNamedParameter('paperless_sync.%')))
	->orderBy('identifier')->executeQuery();
foreach ($result->fetchAll() as $row) {
	$state['credentials'][$row['user'] . '/' . $row['identifier']] = $row['credentials'];
}
$result->closeCursor();

$root = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$base = (string)getenv('E2E_BASE_PATH');
foreach (array_filter(explode(' ', (string)getenv('E2E_USERS'))) as $user) {
	$files = [];
	$folder = $root->getUserFolder($user);
	if ($folder->nodeExists($base)) {
		$walk = function (\OCP\Files\Folder $folder, string $path) use (&$walk, &$files): void {
			foreach ($folder->getDirectoryListing() as $node) {
				$files[$path . $node->getName()] = $node->getId();
				if ($node instanceof \OCP\Files\Folder) {
					$walk($node, $path . $node->getName() . '/');
				}
			}
		};
		$node = $folder->get($base);
		if ($node instanceof \OCP\Files\Folder) {
			$walk($node, $base . '/');
		}
	}
	ksort($files);
	$state['files'][$user] = $files;
}

echo "\nSTATE ", json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
