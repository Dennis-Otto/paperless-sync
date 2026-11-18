<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Whether the transfer of a document keeps the options of cURL that this Nextcloud sets for every
// request (#542). Nextcloud merges its defaults with the options of a request key by key, so the
// options of cURL of the transfer replace all of its own, and the app hands them back itself:
// PaperlessApiService::NEXTCLOUD_CURL_DEFAULTS. This compares them with the defaults of the
// Nextcloud that it runs in, so that a default that a new version of Nextcloud adds or changes
// fails the end-to-end tests of that version, and of the coming one in canary.sh, before a user
// meets it. run.sh hands it to php in the container of Nextcloud on standard input, before the
// first export; it prints one line, or says what differs and fails.

require '/var/www/html/lib/base.php';

set_exception_handler(function (\Throwable $exception): void {
	fwrite(STDERR, 'client_defaults.php failed: ' . $exception->getMessage() . PHP_EOL);
	exit(1);
});

$client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
// The defaults are private to Nextcloud's client, which offers no other way to read them.
$nextcloud = (new \ReflectionMethod($client, 'buildRequestOptions'))->invoke($client, [])['curl'] ?? [];

\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('paperless_sync');
$app = (new \ReflectionClassConstant(\OCA\PaperlessSync\Service\PaperlessApiService::class, 'NEXTCLOUD_CURL_DEFAULTS'))->getValue();

ksort($nextcloud);
ksort($app);
if ($nextcloud !== $app) {
	fwrite(STDERR, sprintf(
		"Nextcloud %s sets the options of cURL %s for every request, but the transfer of a document keeps %s: change PaperlessApiService::NEXTCLOUD_CURL_DEFAULTS to those of Nextcloud.\n",
		\OCP\Server::get(\OCP\ServerVersion::class)->getVersionString(),
		json_encode($nextcloud, JSON_THROW_ON_ERROR),
		json_encode($app, JSON_THROW_ON_ERROR),
	));
	exit(1);
}
echo 'The transfer of a document keeps the options of cURL of Nextcloud: ' . json_encode($app, JSON_THROW_ON_ERROR) . PHP_EOL;
