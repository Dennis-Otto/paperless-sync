#!/bin/sh

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Sets Nextcloud up for the demo, as www-data in its container before each start of the web
# server, which reads the routes of an app as it starts. Every line can run again.

set -eu

occ() {
	php /var/www/html/occ "$@" >/dev/null
}

# The system cron of the service cron, and links to this machine from background jobs.
occ background:cron
occ config:system:set overwrite.cli.url --value="http://localhost:${DEMO_NEXTCLOUD_PORT}"
# The files of the demo user start with the archive alone, without the examples of Nextcloud.
occ config:system:set skeletondirectory --value=''
# Nothing in front of the archive at the first login.
occ app:disable firstrunwizard

# The app of this checkout with demo/compose.local.yaml, or that of an earlier start.
if [ -f /var/www/html/custom_apps/paperless_sync/appinfo/info.xml ]; then
	occ app:enable paperless_sync
else
	echo "Installing Paperless Sync from the App Store."
	occ app:install paperless_sync
fi
