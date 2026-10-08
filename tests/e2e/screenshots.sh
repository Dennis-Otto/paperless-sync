#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Takes the screenshots and animations of the documentation and of the App Store anew:
# docs/images/ and screenshots/. It starts the containers of the end-to-end tests with
# the synthetic household archive of showcase.py, sets the app up as an administrator
# would, and lets screenshots.mjs click through it in the image of Playwright.
#
#   bash tests/e2e/screenshots.sh

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
E2E_PORT="${E2E_PORT:-18084}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_sync_screenshots}"
PASSWORD="e2e-only-password"
TARGET_USER="paperless"
BASE_URL="http://127.0.0.1:${E2E_PORT}"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}" --file "${SCRIPT_DIR}/compose.yaml")
# The same image of Playwright as the accessibility check of run.sh, so that one
# update moves both.
PLAYWRIGHT_IMAGE="$(grep -oE 'mcr\.microsoft\.com/playwright:[^ ]+' "${SCRIPT_DIR}/run.sh" | head -n 1)"

export E2E_PORT

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" logs --no-color --tail 100 || true
	fi
	if [[ "${KEEP_E2E:-0}" != "1" ]]; then
		"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	else
		echo "Screenshot environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
	fi
	exit "${status}"
}
trap cleanup EXIT

occ() {
	"${COMPOSE[@]}" exec -T --user www-data "$@"
}

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait --wait-timeout 300

occ nextcloud php occ maintenance:install \
	--database=sqlite \
	--admin-user=e2e-admin \
	--admin-pass="${PASSWORD}" >/dev/null
occ nextcloud php occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
occ nextcloud php occ config:system:set trusted_domains 2 --value=nextcloud >/dev/null
occ nextcloud php occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
occ nextcloud php occ config:system:set default_language --value=en >/dev/null
occ nextcloud php occ config:system:set default_locale --value=en_US >/dev/null
# The owner of the archive starts with empty files, without the examples of Nextcloud.
occ nextcloud php occ config:system:set skeletondirectory --value='' >/dev/null
occ --env "OC_PASS=${PASSWORD}" nextcloud php occ user:add --password-from-env --display-name=Paperless "${TARGET_USER}" >/dev/null
occ nextcloud php occ app:enable paperless_sync >/dev/null
# Nothing but the app on the pages: no first-run wizard and no dashboard tour.
for app in firstrunwizard nextcloud_announcements recommendations support; do
	occ nextcloud php occ app:disable "${app}" >/dev/null 2>&1 || true
done
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null

# The configuration of the quick start, before the first dry-run: Paperless in the
# same Compose project and synchronization still off. Every setting that the request
# leaves out keeps its default, such as the folders Dokumente/Paperless, Archiv,
# Eingang, Fehler and _Gelöscht, which a shell on Windows would garble.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'settings[paperless_url]=http://paperless:8080' \
	--data-urlencode "settings[target_user]=${TARGET_USER}" \
	--data-urlencode 'settings[excluded_tags]=Private' \
	--data-urlencode 'token=e2e-only-token' \
	--output /dev/null \
	"${BASE_URL}/apps/paperless_sync/settings"

# The browser gets its files through standard input and returns the pictures through
# standard output, as a tar archive; everything it says goes to standard error. The
# image has no font for the interface of Nextcloud, so it gets Inter (fonts.conf), from
# the main mirror of Ubuntu: the mirror in Azure that the image names refuses others.
BROWSER='
	set -e
	exec 3>&1 1>&2
	mkdir /tmp/browser
	cd /tmp/browser
	tar -xf -
	sed -i "s|http://azure.archive.ubuntu.com/|http://archive.ubuntu.com/|" /etc/apt/sources.list.d/*.sources
	apt-get -o Acquire::Retries=3 update --quiet
	apt-get -o Acquire::Retries=3 install --yes --quiet --no-install-recommends fonts-inter
	cp fonts.conf /etc/fonts/local.conf
	npm ci --ignore-scripts --no-audit --no-fund --loglevel=error
	node screenshots.mjs
	tar -C /tmp/pictures -cf - . >&3
'
tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json screenshots.mjs fonts.conf \
	| "${DOCKER_BIN}" run --rm --interactive \
		--network "${PROJECT_NAME}_default" \
		--env NPM_CONFIG_UPDATE_NOTIFIER=false \
		--env E2E_USER=e2e-admin \
		--env "E2E_TARGET_USER=${TARGET_USER}" \
		--env "E2E_PASSWORD=${PASSWORD}" \
		"${PLAYWRIGHT_IMAGE}" \
		sh -c "${BROWSER}" \
	| tar -C "${REPOSITORY_ROOT}" -xf -

echo 'Screenshots taken: docs/images/ and screenshots/.'
