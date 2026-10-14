#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

# Git Bash on Windows would turn the paths inside the containers, such as
# /mock/assert_json.py, into paths of Windows before docker sees them.
export MSYS2_ARG_CONV_EXCL="${MSYS2_ARG_CONV_EXCL:+${MSYS2_ARG_CONV_EXCL};}/mock/"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
E2E_PORT="${E2E_PORT:-18083}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_sync_e2e}"
PASSWORD="e2e-only-password"
BASE_URL="http://127.0.0.1:${E2E_PORT}"
DAV_BASE="${BASE_URL}/remote.php/dav/files"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}" --file "${SCRIPT_DIR}/compose.yaml")
# The database of Nextcloud: SQLite in its own files, or MariaDB (mysql) or PostgreSQL
# (pgsql) in a container of compose.<database>.yaml. Its primary storage: its disk
# (local), or an object store with the API of S3 in a container of compose.s3.yaml.
E2E_DATABASE="${E2E_DATABASE:-sqlite}"
E2E_OBJECTSTORE="${E2E_OBJECTSTORE:-local}"
case "${E2E_DATABASE}" in
	sqlite) DATABASE_NAME=SQLite DATABASE_TYPE=sqlite3 ;;
	mysql) DATABASE_NAME=MariaDB DATABASE_TYPE=mysql ;;
	pgsql) DATABASE_NAME=PostgreSQL DATABASE_TYPE=pgsql ;;
	*)
		echo "E2E_DATABASE=${E2E_DATABASE} is none of sqlite, mysql and pgsql." >&2
		exit 1
		;;
esac
if [[ "${E2E_DATABASE}" != sqlite ]]; then
	COMPOSE+=(--file "${SCRIPT_DIR}/compose.${E2E_DATABASE}.yaml")
fi
case "${E2E_OBJECTSTORE}" in
	local) STORAGE_NAME="local storage" ;;
	s3)
		STORAGE_NAME="S3 object storage"
		COMPOSE+=(--file "${SCRIPT_DIR}/compose.s3.yaml")
		;;
	*)
		echo "E2E_OBJECTSTORE=${E2E_OBJECTSTORE} is neither local nor s3." >&2
		exit 1
		;;
esac
# E2E_ACCESSIBILITY=0 leaves out the checks in the browser at the end, accessibility.mjs and
# settings.mjs, which don't depend on the database or the storage; run-all.sh runs them once
# for every version.
E2E_ACCESSIBILITY="${E2E_ACCESSIBILITY:-1}"
TMP_DIR="$(mktemp -d)"
# The browser of the accessibility check (accessibility.mjs). Keep its version equal to
# playwright-core in package.json; Renovate updates both together, and
# scripts/check-project.sh compares them.
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27"
# The download of that image in the background, while Nextcloud installs.
PLAYWRIGHT_PULL=""

export E2E_PORT

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps || true
		"${COMPOSE[@]}" logs --no-color --tail 300 || true
	fi
	rm -rf "${TMP_DIR}"
	if [[ -n "${PLAYWRIGHT_PULL}" ]]; then
		kill "${PLAYWRIGHT_PULL}" 2>/dev/null || true
	fi
	if [[ "${KEEP_E2E:-0}" == "1" ]]; then
		echo "E2E environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
	elif [[ "${CI:-}" != "true" ]]; then
		# A runner of the CI is thrown away with its containers after the job.
		"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 1 >/dev/null 2>&1 || true
	fi
	exit "${status}"
}
trap cleanup EXIT
# A signal to stop, such as the time limit of run-all.sh, fails the suite, so that
# cleanup shows the state of the containers.
trap 'exit 143' TERM

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

# Checks a JSON file in one of the modes of assert_json.py, some with a value to
# compare, such as the time of an earlier run.
assert_json() {
	mode="$1"
	file="$2"
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_json.py "${mode}" "${@:3}" < "${file}"
}

# A value of the JSON on standard input, by its keys and indexes: json_value 0 id.
json_value() {
	"${COMPOSE[@]}" exec -T paperless-mock python -c '
import json, sys
value = json.load(sys.stdin)
for key in sys.argv[1:]:
    value = value[int(key)] if isinstance(value, list) else value[key]
print(value)' "$@"
}

control() {
	path="$1"
	"${COMPOSE[@]}" exec -T paperless-mock python -c \
		"import urllib.request; urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8080${path}', method='POST')).read()"
}

# What the Paperless mock has seen: downloads, uploads and refused requests.
mock_state() {
	"${COMPOSE[@]}" exec -T paperless-mock python -c \
		"import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8080/control/state').read().decode())"
}

# axe-core checks the pages of the app in Chromium (accessibility.mjs), inside the
# network of the Compose project, and settings.mjs then uses the settings as an
# administrator does: it saves them with the confirmation of the password, disconnects
# and connects again with the token, and checks what the page says about unsaved
# changes, results and errors, also in German. The files of the checks reach the
# browser through standard input, so that npm leaves nothing in the checkout.
accessibility() {
	if [[ -n "${PLAYWRIGHT_PULL}" ]] && ! wait "${PLAYWRIGHT_PULL}"; then
		echo "The image of Playwright didn't download in the background; docker run tries again."
	fi
	PLAYWRIGHT_PULL=""
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json accessibility.mjs settings.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env "E2E_PASSWORD=${PASSWORD}" \
			--env E2E_TOKEN=e2e-only-token \
			"${PLAYWRIGHT_IMAGE}" \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node accessibility.mjs && node settings.mjs'
}

# Saves settings as the administration settings do, such as conflict_mode=skip; the
# others keep their values, and so does the token.
save_settings() {
	output="$1"
	shift
	settings=()
	for setting in "$@"; do
		settings+=(--data-urlencode "settings[${setting%%=*}]=${setting#*=}")
	done
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		"${settings[@]}" \
		--output "${output}" \
		"${BASE_URL}/apps/paperless_sync/settings"
}

# The times when the last run started, completed and ended, from which the background job
# computes its next run, as it reads them from the database.
last_run() {
	for key in status_last_started status_last_completed status_last_ended; do
		printf '%s ' "$(occ config:app:get paperless_sync "${key}")"
	done
}

# Checks the status of a run in another process, such as the background job, as the
# web server shows it: it keeps the config of the apps for up to 3 seconds
# (AppConfig::LOCAL_CACHE_TTL), so it may show the run a little later.
assert_status_soon() {
	mode="$1"
	output="$2"
	for _ in $(seq 1 20); do
		sync_status "${output}"
		if assert_json "${mode}" "${output}" "${@:3}" 2>/dev/null; then
			return 0
		fi
		sleep 0.5
	done
	assert_json "${mode}" "${output}" "${@:3}"
}

sync_run() {
	dry_run="$1"
	output="$2"
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data-urlencode "dryRun=${dry_run}" \
		--output "${output}" \
		"${BASE_URL}/apps/paperless_sync/sync/run"
}

# The settings of the page as curl sends them, from setting=value, such as
# path_template=…, into the array settings.
settings_data() {
	settings=()
	for setting in "$@"; do
		settings+=(--data-urlencode "settings[${setting%%=*}]=${setting#*=}")
	done
}

# The preview of the path template with settings of the page, which it doesn't save.
settings_preview() {
	output="$1"
	shift
	settings_data "$@"
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		"${settings[@]}" \
		--output "${output}" \
		"${BASE_URL}/apps/paperless_sync/settings/preview"
}

# A dry-run with settings of the page that are not saved; prints the HTTP status.
dry_run_unsaved() {
	output="$1"
	shift
	settings_data "$@"
	curl --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data-urlencode 'dryRun=true' \
		"${settings[@]}" \
		--output "${output}" \
		--write-out '%{http_code}' \
		"${BASE_URL}/apps/paperless_sync/sync/run"
}

# The status of the last run, as the settings page shows it.
sync_status() {
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "$1" \
		"${BASE_URL}/apps/paperless_sync/sync/status"
}

dav_status() {
	user="$1"
	method="$2"
	path="$3"
	curl --silent --output /dev/null --write-out '%{http_code}' \
		--user "${user}:${PASSWORD}" \
		--request "${method}" \
		--header 'Depth: 0' \
		"${DAV_BASE}/${user}/${path}"
}

assert_dav_missing() {
	user="$1"
	path="$2"
	status="$(dav_status "${user}" GET "${path}")"
	if [[ "${status}" != "404" && "${status}" != "403" ]]; then
		echo "Expected missing or inaccessible WebDAV path, got HTTP ${status}: ${path}" >&2
		exit 1
	fi
}

dav_upload() {
	user="$1"
	path="$2"
	content="$3"
	printf '%s\n' "${content}" \
		| curl --fail-with-body --silent --show-error \
			--user "${user}:${PASSWORD}" \
			--upload-file - \
			"${DAV_BASE}/${user}/${path}" >/dev/null
}

assert_dav_file() {
	user="$1"
	path="$2"
	needle="$3"
	output="${TMP_DIR}/dav-file-$RANDOM"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--output "${output}" \
		"${DAV_BASE}/${user}/${path}"
	grep --fixed-strings "${needle}" "${output}" >/dev/null
}

# The ID of a file in Nextcloud, to which its shares, comments, tags, favorites and versions belong.
file_id() {
	user="$1"
	path="$2"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--request PROPFIND \
		--header 'Depth: 0' \
		--header 'Content-Type: application/xml' \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' \
		"${DAV_BASE}/${user}/${path}" \
		| grep -oE '<oc:fileid>[0-9]+</oc:fileid>' | grep -oE '[0-9]+'
}

# The file of the document keeps the ID of its first export through every change of the document.
assert_file_id() {
	path="$1"
	actual="$(file_id sync-target "${path}")"
	if [[ "${actual}" != "${FILE_ID}" ]]; then
		echo "The file has the ID ${actual} instead of ${FILE_ID}, which loses its shares, comments and versions: ${path}" >&2
		exit 1
	fi
}

# The entries of the versions app for the file of the document.
version_count() {
	curl --fail-with-body --silent --show-error \
		--user "sync-target:${PASSWORD}" \
		--request PROPFIND \
		--header 'Depth: 1' \
		"${BASE_URL}/remote.php/dav/versions/sync-target/versions/${FILE_ID}" \
		| grep -oE '<d:response>' | wc -l
}

INITIAL_PATH='Documents/Paperless/Archive/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20%5BP123%5D.pdf'
RENAMED_PATH='Documents/Paperless/Archive/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20corrected%20%5BP123%5D.pdf'
TRASH_PATH='Documents/Paperless/Archive/_Deleted/2026-08-27/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20corrected%20%5BP123%5D.pdf'
ERROR_PATH='Documents/Paperless/Errors/broken.pdf'
STATEMENT_PATH='Documents/Paperless/Archive/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Annual%20statement%20%5BP124%5D.pdf'

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 1 >/dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait --wait-timeout 300
# The seconds since the start at the end of each phase show where a slow run spends them.
echo "The containers run after ${SECONDS} s."
if [[ "${E2E_ACCESSIBILITY}" == "1" ]] && ! "${DOCKER_BIN}" image inspect "${PLAYWRIGHT_IMAGE}" >/dev/null 2>&1; then
	"${DOCKER_BIN}" pull --quiet "${PLAYWRIGHT_IMAGE}" >/dev/null &
	PLAYWRIGHT_PULL=$!
fi

install_options=(--database="${E2E_DATABASE}")
case "${E2E_DATABASE}" in
	mysql) install_options+=(--database-host=mariadb) ;;
	pgsql) install_options+=(--database-host=postgres) ;;
esac
if [[ "${E2E_DATABASE}" != sqlite ]]; then
	install_options+=(--database-name=nextcloud --database-user=nextcloud --database-pass="${PASSWORD}")
fi
if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install \
		"${install_options[@]}" \
		--admin-user=e2e-admin \
		--admin-pass="${PASSWORD}" >/dev/null
fi
# Nextcloud runs on the database and the storage asked for, so that a variant can't
# pass on SQLite and the disk by mistake.
INSTALLED_TYPE="$(occ config:system:get dbtype)"
OBJECTSTORE_CLASS="$(occ config:system:get objectstore class 2>/dev/null || true)"
if [[ "${INSTALLED_TYPE}" != "${DATABASE_TYPE}" ]]; then
	echo "Nextcloud runs on the database ${INSTALLED_TYPE}, not on ${DATABASE_TYPE}." >&2
	exit 1
fi
if [[ "${E2E_OBJECTSTORE}" == s3 && "${OBJECTSTORE_CLASS}" != *'\ObjectStore\S3' ]] ||
	[[ "${E2E_OBJECTSTORE}" == local && -n "${OBJECTSTORE_CLASS}" ]]; then
	echo "Nextcloud keeps its files in ${OBJECTSTORE_CLASS:-local storage}, not in ${STORAGE_NAME}." >&2
	exit 1
fi
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
# The browser of the accessibility check reaches Nextcloud by its name in the network.
occ config:system:set trusted_domains 2 --value=nextcloud >/dev/null
# Nextcloud refuses addresses of the local network, such as the one of paperless-mock, as
# on every server where nobody turned allow_local_remote_servers on for every app. The app
# reaches the mock with its own setting "Paperless runs in my local network" instead.
occ config:system:delete allow_local_remote_servers >/dev/null
# The password policy would look up every new password at Have I Been Pwned: a
# request to the internet that the tests don't need and that can fail.
occ config:app:set password_policy enforceHaveIBeenPwned --type=boolean --value=false >/dev/null
NEXTCLOUD_VERSION="$(occ status --output=json | grep -oE '"versionstring":"[^"]+"' | cut -d '"' -f 4 || true)"
echo "The tests run against Nextcloud ${NEXTCLOUD_VERSION:-?} on ${DATABASE_NAME}, with ${STORAGE_NAME}."

for user in sync-target e2e-other; do
	"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
		php occ user:add --password-from-env "${user}" >/dev/null
done

# The coming Nextcloud of canary.sh is newer than max-version of appinfo/info.xml:
# --force enables the app there anyway, without making it compatible.
enable_options=()
if [[ "${E2E_IGNORE_MAX_VERSION:-0}" == "1" ]]; then
	enable_options+=(--force)
fi
occ app:enable "${enable_options[@]}" paperless_sync >/dev/null
occ router:list \
	| grep --fixed-strings 'paperless_sync.settings.save' \
	| grep --fixed-strings '/apps/paperless_sync/settings' >/dev/null
occ background-job:list | grep --fixed-strings 'OCA\PaperlessSync\Cron\SyncJob' >/dev/null
# Background jobs run only when the tests start them, as system cron would: a page
# that the accessibility check opens doesn't start them as well.
occ background:cron >/dev/null
# The first-run wizard would cover the pages that the accessibility check opens.
occ app:disable firstrunwizard >/dev/null 2>&1 || true
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null
echo "Nextcloud and the app are set up after ${SECONDS} s."

# The administration settings render the form with all of its routes.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-settings.html" \
	"${BASE_URL}/index.php/settings/admin/paperless_sync"
for attribute in \
	'data-save-url="/apps/paperless_sync/settings"' \
	'data-reset-url="/apps/paperless_sync/settings"' \
	'data-run-url="/apps/paperless_sync/sync/run"' \
	'data-status-url="/apps/paperless_sync/sync/status"' \
	'data-preview-url="/apps/paperless_sync/settings/preview"' \
	'data-variable="created_month_name"' \
	'id="initial-state-paperless_sync-status"' \
	'id="initial-state-paperless_sync-config"' \
	'data-setting="paperless_local_network"'; do
	if ! grep --fixed-strings "${attribute}" "${TMP_DIR}/admin-settings.html" >/dev/null; then
		echo "The administration settings render no ${attribute}." >&2
		exit 1
	fi
done

# The navigation of the administration settings lists the app's own section on
# every page (Dennis-Otto/paperless-unified-search#25): Nextcloud 33 as links,
# Nextcloud 34 in the initial state settings-sections.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-overview.html" \
	"${BASE_URL}/index.php/settings/admin/overview"
SECTIONS="$(grep --only-matching 'id="initial-state-settings-sections" value="[^"]*"' \
	"${TMP_DIR}/admin-overview.html" | sed 's/.*value="//; s/"$//' | base64 --decode || true)"
if ! grep --fixed-strings '/settings/admin/paperless_sync"' "${TMP_DIR}/admin-overview.html" >/dev/null &&
	! grep --fixed-strings '"id":"paperless_sync"' <<<"${SECTIONS}" >/dev/null; then
	echo "The navigation of the administration settings lists no Paperless Sync section." >&2
	exit 1
fi

# Nextcloud loads the routes of appinfo/routes.php only for apps that are already
# loaded. The app's attribute routes exist without that, as in a PHP script
# (Dennis-Otto/paperless-unified-search#25). Scripts link through index.php.
# shellcheck disable=SC2016
ROUTES="$("${COMPOSE[@]}" exec -T --user www-data nextcloud php -r '
	require "/var/www/html/lib/base.php";
	$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
	foreach (["settings.save", "settings.reset", "settings.preview", "sync.run", "sync.status"] as $route) {
		echo "route=", $urls->linkToRoute("paperless_sync." . $route), "\n";
	}
' 2>/dev/null | grep '^route=' | sed 's#^route=/index.php/#route=/#' | tr '\n' ' ')"
EXPECTED='route=/apps/paperless_sync/settings route=/apps/paperless_sync/settings route=/apps/paperless_sync/settings/preview route=/apps/paperless_sync/sync/run route=/apps/paperless_sync/sync/status '
if [[ "${ROUTES}" != "${EXPECTED}" ]]; then
	echo "The routes are missing before the app is loaded: ${ROUTES}" >&2
	exit 1
fi

# Without the setting, Nextcloud refuses the address of the mock, and the answer says how to
# allow it instead of Nextcloud's own "Host "paperless-mock" violates local access rules".
REFUSED_STATUS="$(curl --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'settings[paperless_url]=http://paperless-mock:8080' \
	--data-urlencode 'settings[target_user]=sync-target' \
	--data-urlencode 'token=e2e-only-token' \
	--output "${TMP_DIR}/local-network-refused.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/settings")"
if [[ "${REFUSED_STATUS}" != "400" ]]; then
	echo "Saving a Paperless in the local network without its setting answered HTTP ${REFUSED_STATUS} instead of 400: $(cat "${TMP_DIR}/local-network-refused.json")" >&2
	exit 1
fi
assert_json local-network-refused "${TMP_DIR}/local-network-refused.json"

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'settings[paperless_url]=http://paperless-mock:8080' \
	--data-urlencode 'settings[paperless_local_network]=1' \
	--data-urlencode 'settings[target_user]=sync-target' \
	--data-urlencode 'settings[base_path]=Documents/Paperless' \
	--data-urlencode 'settings[archive_folder]=Archive' \
	--data-urlencode 'settings[inbox_folder]=Inbox' \
	--data-urlencode 'settings[error_folder]=Errors' \
	--data-urlencode 'settings[deleted_folder]=_Deleted' \
	--data-urlencode 'settings[export_enabled]=1' \
	--data-urlencode 'settings[inbox_enabled]=1' \
	--data-urlencode 'settings[skip_inbox]=1' \
	--data-urlencode 'settings[trash_mode]=move' \
	--data-urlencode 'settings[permanent_delete]=1' \
	--data-urlencode 'settings[allow_direct_delete]=0' \
	--data-urlencode 'settings[missing_grace_runs]=2' \
	--data-urlencode 'settings[prune_empty_folders]=1' \
	--data-urlencode 'settings[delete_inbox_after_success]=1' \
	--data-urlencode 'settings[recursive_inbox]=1' \
	--data-urlencode 'settings[conflict_mode]=replace' \
	--data-urlencode 'token=e2e-only-token' \
	--output "${TMP_DIR}/config.json" \
	"${BASE_URL}/apps/paperless_sync/settings"
assert_json config "${TMP_DIR}/config.json"

sync_run true "${TMP_DIR}/dry-run.json"
assert_json dry-run "${TMP_DIR}/dry-run.json"
assert_dav_missing sync-target "${INITIAL_PATH}"

# The preview of the path template: where the newest documents go with the template of the
# page, with the variables of Paperless's own file names, before anything is saved.
settings_preview "${TMP_DIR}/preview.json" \
	'path_template={{ owner_username }}/{{ created_month_name }}/{{ asn }} {{ title }} {{ custom_fields.Invoice number }} ({{ page_count }} pages) [P{{ id }}]'
assert_json preview "${TMP_DIR}/preview.json"
# A dry-run tries unsaved settings without saving them; the export below still takes the
# saved template. It keeps the saved target user, which only a save may change.
DRY_RUN_STATUS="$(dry_run_unsaved "${TMP_DIR}/dry-run-unsaved.json" 'path_template={{ created_year }}/{{ title }} [P{{ id }}]' 'inbox_enabled=0')"
if [[ "${DRY_RUN_STATUS}" != "200" ]]; then
	echo "The dry-run of unsaved settings answered HTTP ${DRY_RUN_STATUS}: $(cat "${TMP_DIR}/dry-run-unsaved.json")" >&2
	exit 1
fi
assert_json dry-run-unsaved "${TMP_DIR}/dry-run-unsaved.json"
DRY_RUN_STATUS="$(dry_run_unsaved "${TMP_DIR}/dry-run-other-user.json" 'target_user=e2e-other')"
if [[ "${DRY_RUN_STATUS}" != "400" ]]; then
	echo "A dry-run for another target user answered HTTP ${DRY_RUN_STATUS} instead of 400: $(cat "${TMP_DIR}/dry-run-other-user.json")" >&2
	exit 1
fi
assert_json dry-run-other-user "${TMP_DIR}/dry-run-other-user.json"
assert_dav_missing sync-target "${INITIAL_PATH}"

sync_run false "${TMP_DIR}/export.json"
assert_json export "${TMP_DIR}/export.json"
assert_dav_file sync-target "${INITIAL_PATH}" 'Synthetic Paperless archive document P123.'
FILE_ID="$(file_id sync-target "${INITIAL_PATH}")"

control /control/scenario/renamed >/dev/null
sync_run false "${TMP_DIR}/move.json"
assert_json move "${TMP_DIR}/move.json"
assert_dav_missing sync-target "${INITIAL_PATH}"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
assert_file_id "${RENAMED_PATH}"
# Paperless-ngx reports MD5 checksums up to 2.x and SHA-256 since 3.0: with either, a change
# of the metadata alone moves the file, here back and forth, without a download.
control /control/checksum/md5 >/dev/null
control /control/scenario/active >/dev/null
sync_run false "${TMP_DIR}/move-md5.json"
assert_json move "${TMP_DIR}/move-md5.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
assert_dav_file sync-target "${INITIAL_PATH}" 'Synthetic Paperless archive document P123.'
control /control/scenario/renamed >/dev/null
sync_run false "${TMP_DIR}/move-md5-back.json"
assert_json move "${TMP_DIR}/move-md5-back.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
assert_file_id "${RENAMED_PATH}"
control /control/checksum/sha256 >/dev/null
mock_state > "${TMP_DIR}/mock-after-move.json"
assert_json mock-after-move "${TMP_DIR}/mock-after-move.json"

# A new content of the document updates its file in place, in a run by hand: the file keeps its
# ID, and a share of it shows the new content.
curl --fail-with-body --silent --show-error \
	--user "sync-target:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'path=/Documents/Paperless/Archive/Example GmbH/Invoice/2026/2026-08-26 - Monthly invoice corrected [P123].pdf' \
	--data-urlencode 'shareType=0' \
	--data-urlencode 'shareWith=e2e-other' \
	--output /dev/null \
	"${BASE_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
assert_dav_file e2e-other "${RENAMED_PATH##*/}" 'Synthetic Paperless archive document P123.'
control /control/content/revised >/dev/null
sync_run false "${TMP_DIR}/revised.json"
assert_json export "${TMP_DIR}/revised.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123, revised.'
assert_file_id "${RENAMED_PATH}"
assert_dav_file e2e-other "${RENAMED_PATH##*/}" 'Synthetic Paperless archive document P123, revised.'
# The background job, as system cron starts it. Until synchronization is enabled, it does
# nothing. Then it runs when the interval since the last run has passed, here as if that run was
# an hour ago, also right after a dry-run: a dry-run changes nothing, so it doesn't push the
# schedule back. Started again at once, the job waits for the interval. In the job, the versions
# app of Nextcloud also keeps the previous content as a version.
VERSIONS_BEFORE="$(version_count)"
control /control/content/original >/dev/null
HOUR_AGO="$(($(date +%s) - 3600))"
for key in status_last_started status_last_completed status_last_ended status_last_sync_started status_last_sync_ended; do
	occ config:app:set paperless_sync "${key}" --type=integer --value="${HOUR_AGO}" >/dev/null
done
JOB_ID="$(occ background-job:list --class='OCA\PaperlessSync\Cron\SyncJob' --output=json | json_value 0 id)"
occ background-job:execute --force-execute "${JOB_ID}" >/dev/null
if [[ "$(last_run)" != "${HOUR_AGO} ${HOUR_AGO} ${HOUR_AGO} " ]]; then
	echo 'The background job synchronized while synchronization was disabled.' >&2
	exit 1
fi
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123, revised.'
# With the schedule on, saving tests the connection first.
save_settings "${TMP_DIR}/enabled.json" enabled=1
assert_json enabled "${TMP_DIR}/enabled.json"
sync_run true "${TMP_DIR}/dry-run-before-job.json"
occ background-job:execute --force-execute "${JOB_ID}" >/dev/null
assert_status_soon job "${TMP_DIR}/job.json" "${HOUR_AGO}"
LAST_RUN="$(last_run)"
occ background-job:execute --force-execute "${JOB_ID}" >/dev/null
if [[ "$(last_run)" != "${LAST_RUN}" ]]; then
	echo 'The background job ran again before its interval had passed.' >&2
	exit 1
fi
# The schedule goes off again, as the steps after this one and settings.mjs expect it.
save_settings "${TMP_DIR}/disabled.json" enabled=0
assert_json disabled "${TMP_DIR}/disabled.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
assert_file_id "${RENAMED_PATH}"
VERSIONS_AFTER="$(version_count)"
if ((VERSIONS_AFTER != VERSIONS_BEFORE + 1)); then
	echo "The versions app has ${VERSIONS_AFTER} entries for the file after the update in the background job instead of $((VERSIONS_BEFORE + 1))." >&2
	exit 1
fi

control /control/scenario/excluded >/dev/null
sync_run false "${TMP_DIR}/excluded.json"
assert_json excluded "${TMP_DIR}/excluded.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
if [[ "$(dav_status sync-target PROPFIND 'Documents/Paperless/Archive/Example%20GmbH')" != "404" ]]; then
	echo 'Empty archive hierarchy was not pruned after exclusion.' >&2
	exit 1
fi

# Nextcloud applies no quota to the temporary file of a write, so the app checks the free space
# itself: with the quota of the target user used up, the export stops at its first document, and
# the document follows once there is space again.
control /control/scenario/renamed >/dev/null
occ user:setting sync-target files quota '1 B' >/dev/null
sync_run false "${TMP_DIR}/export-stopped.json"
occ user:setting --delete sync-target files quota >/dev/null
assert_json export-stopped "${TMP_DIR}/export-stopped.json"
# The status keeps the error of the document for the settings page.
sync_status "${TMP_DIR}/status-errors.json"
assert_json status-errors "${TMP_DIR}/status-errors.json"
assert_dav_missing sync-target "${RENAMED_PATH}"

sync_run false "${TMP_DIR}/re-export.json"
assert_json export "${TMP_DIR}/re-export.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'

control /control/scenario/trash >/dev/null
sync_run false "${TMP_DIR}/trash.json"
assert_json trash "${TMP_DIR}/trash.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
assert_dav_file sync-target "${TRASH_PATH}" 'Synthetic Paperless archive document P123.'

# A page that comes back empty while its count still lists the document counts nothing as missing,
# so the document waits for its first missing run in the next step.
control /control/scenario/incomplete >/dev/null
sync_run false "${TMP_DIR}/scan-incomplete.json"
assert_json scan-incomplete "${TMP_DIR}/scan-incomplete.json"
assert_dav_file sync-target "${TRASH_PATH}" 'Synthetic Paperless archive document P123.'

control /control/scenario/empty >/dev/null
sync_run false "${TMP_DIR}/missing-wait.json"
assert_json missing-wait "${TMP_DIR}/missing-wait.json"
assert_dav_file sync-target "${TRASH_PATH}" 'Synthetic Paperless archive document P123.'
sync_run false "${TMP_DIR}/deleted.json"
assert_json deleted "${TMP_DIR}/deleted.json"
assert_dav_missing sync-target "${TRASH_PATH}"

MKCOL_STATUS="$(dav_status sync-target MKCOL 'Documents/Paperless/Inbox/Insurance')"
if [[ "${MKCOL_STATUS}" != "201" && "${MKCOL_STATUS}" != "405" ]]; then
	echo "Unexpected WebDAV MKCOL status: ${MKCOL_STATUS}" >&2
	exit 1
fi
dav_upload sync-target 'Documents/Paperless/Inbox/Insurance/policy.pdf' '%PDF-1.4 Synthetic inbox PDF policy.'
sync_run false "${TMP_DIR}/import-submitted.json"
assert_json import-submitted "${TMP_DIR}/import-submitted.json"
control /control/tasks/success >/dev/null
sync_run false "${TMP_DIR}/import-success.json"
assert_json import-success "${TMP_DIR}/import-success.json"
assert_dav_missing sync-target 'Documents/Paperless/Inbox/Insurance/policy.pdf'

dav_upload sync-target 'Documents/Paperless/Inbox/broken.pdf' '%PDF-1.4 Synthetic inbox PDF broken.'
sync_run false "${TMP_DIR}/failed-submitted.json"
assert_json import-submitted "${TMP_DIR}/failed-submitted.json"
control /control/tasks/failure >/dev/null
sync_run false "${TMP_DIR}/import-failed.json"
assert_json import-failed "${TMP_DIR}/import-failed.json"
assert_dav_missing sync-target 'Documents/Paperless/Inbox/broken.pdf'
assert_dav_file sync-target "${ERROR_PATH}" 'Synthetic inbox PDF broken.'
assert_dav_file sync-target "${ERROR_PATH}.error.txt" 'broken.pdf: Synthetic unsupported mime type'
assert_dav_missing e2e-other "${ERROR_PATH}"

# A duplicate and a task that Paperless revoked end their imports too, with what Paperless
# said, here in the shapes of Paperless-ngx 2.20, whose status then names its version.
dav_upload sync-target 'Documents/Paperless/Inbox/copy.pdf' '%PDF-1.4 Synthetic inbox PDF copy.'
dav_upload sync-target 'Documents/Paperless/Inbox/cancelled.pdf' '%PDF-1.4 Synthetic inbox PDF cancelled.'
sync_run false "${TMP_DIR}/ended-submitted.json"
assert_json import-submitted "${TMP_DIR}/ended-submitted.json" 2
control /control/task-of/copy.pdf/duplicate >/dev/null
control /control/task-of/cancelled.pdf/revoked >/dev/null
control /control/paperless/2.20.15 >/dev/null
sync_run false "${TMP_DIR}/import-ended.json"
assert_json import-failed "${TMP_DIR}/import-ended.json" 2
assert_dav_file sync-target 'Documents/Paperless/Errors/copy.pdf.error.txt' 'It is a duplicate of Monthly invoice (#123).'
assert_dav_file sync-target 'Documents/Paperless/Errors/cancelled.pdf.error.txt' 'Paperless cancelled the task before it finished (REVOKED)'
sync_status "${TMP_DIR}/status-paperless-2.json"
assert_json paperless "${TMP_DIR}/status-paperless-2.json" 2.20.15
control /control/paperless/3.3.0 >/dev/null

# Paperless refuses a file of a type it doesn't take. The file stays in the inbox, and
# the runs after it don't upload it again until it changes.
dav_upload sync-target 'Documents/Paperless/Inbox/notes.bin' 'Synthetic unsupported file.'
sync_run false "${TMP_DIR}/import-rejected.json"
assert_json import-rejected "${TMP_DIR}/import-rejected.json"
sync_run false "${TMP_DIR}/import-waiting.json"
assert_json import-waiting "${TMP_DIR}/import-waiting.json"
assert_dav_file sync-target 'Documents/Paperless/Inbox/notes.bin' 'Synthetic unsupported file.'

sync_status "${TMP_DIR}/status.json"
assert_json status "${TMP_DIR}/status.json"

mock_state > "${TMP_DIR}/mock-final.json"
assert_json mock-final "${TMP_DIR}/mock-final.json"

# The deleted document is back in Paperless, and the next run exports it again.
control /control/scenario/renamed >/dev/null
sync_run false "${TMP_DIR}/export-again.json"
assert_json export "${TMP_DIR}/export-again.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'

# Restored from the trash of Paperless, a document gets its copy back from the deleted
# folder, without a download, and the deleted folder goes when it is empty.
control /control/scenario/trash >/dev/null
sync_run false "${TMP_DIR}/trash-again.json"
assert_json trash "${TMP_DIR}/trash-again.json"
assert_dav_file sync-target "${TRASH_PATH}" 'Synthetic Paperless archive document P123.'
DOWNLOADS="$(mock_state | json_value downloads)"
control /control/scenario/restored >/dev/null
sync_run false "${TMP_DIR}/restored.json"
assert_json restored "${TMP_DIR}/restored.json"
assert_dav_missing sync-target "${TRASH_PATH}"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
if [[ "$(dav_status sync-target PROPFIND 'Documents/Paperless/Archive/_Deleted')" != "404" ]]; then
	echo 'The empty deleted folder was not pruned after the restore.' >&2
	exit 1
fi
mock_state > "${TMP_DIR}/mock-restored.json"
assert_json mock-no-download "${TMP_DIR}/mock-restored.json" "${DOWNLOADS}"

# With conflict_mode=skip, a file of the user at the path of a new document stays as
# it is, and the run reports the conflict without downloading the document in vain;
# with replace, the document takes its place.
dav_upload sync-target "${STATEMENT_PATH}" 'Synthetic file of the user.'
save_settings "${TMP_DIR}/conflict-skip.json" conflict_mode=skip
assert_json conflict-skip "${TMP_DIR}/conflict-skip.json"
control /control/scenario/conflict >/dev/null
DOWNLOADS="$(mock_state | json_value downloads)"
sync_run false "${TMP_DIR}/conflict.json"
assert_json conflict "${TMP_DIR}/conflict.json"
assert_dav_file sync-target "${STATEMENT_PATH}" 'Synthetic file of the user.'
mock_state > "${TMP_DIR}/mock-conflict.json"
assert_json mock-no-download "${TMP_DIR}/mock-conflict.json" "${DOWNLOADS}"
save_settings "${TMP_DIR}/conflict-replace.json" conflict_mode=replace
assert_json conflict-replace "${TMP_DIR}/conflict-replace.json"
sync_run false "${TMP_DIR}/conflict-replaced.json"
assert_json conflict-replaced "${TMP_DIR}/conflict-replaced.json"
assert_dav_file sync-target "${STATEMENT_PATH}" 'Synthetic Paperless archive document P124.'

# A document that vanishes from Paperless without passing through its trash keeps its
# copy for the grace runs, then the copy moves to the deleted folder of that day. As
# direct deletion is not allowed, the runs after it leave the copy there.
control /control/scenario/vanished >/dev/null
sync_run false "${TMP_DIR}/vanished-wait.json"
assert_json vanished-wait "${TMP_DIR}/vanished-wait.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
DAY_BEFORE="$(date -u +%F)"
sync_run false "${TMP_DIR}/vanished.json"
DAY_AFTER="$(date -u +%F)"
assert_json vanished "${TMP_DIR}/vanished.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
for day in "${DAY_BEFORE}" "${DAY_AFTER}"; do
	VANISHED_PATH="Documents/Paperless/Archive/_Deleted/${day}/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20corrected%20%5BP123%5D.pdf"
	if [[ "$(dav_status sync-target GET "${VANISHED_PATH}")" == "200" ]]; then
		break
	fi
done
assert_dav_file sync-target "${VANISHED_PATH}" 'Synthetic Paperless archive document P123.'
sync_run false "${TMP_DIR}/vanished-kept.json"
assert_json vanished-kept "${TMP_DIR}/vanished-kept.json"
assert_dav_file sync-target "${VANISHED_PATH}" 'Synthetic Paperless archive document P123.'

# Titles and names that the path template must take as they are: "$1" and "{{ … }}"
# stay text, and names in Chinese, emoji and Cyrillic shrink to the bytes that Nextcloud
# accepts, also for the temporary name of each write.
control /control/scenario/names >/dev/null
sync_run false "${TMP_DIR}/names.json"
mapfile -t NAMED_PATHS < <(assert_json names "${TMP_DIR}/names.json")
if ((${#NAMED_PATHS[@]} != 4)); then
	echo "The run wrote ${#NAMED_PATHS[@]} of the 4 documents with long or unusual names." >&2
	exit 1
fi
for path in "${NAMED_PATHS[@]}"; do
	assert_dav_file sync-target "${path}" 'Synthetic Paperless archive document P123.'
done

echo "The scenarios pass after ${SECONDS} s."

# The administration settings and the report of a dry-run over a larger archive meet
# WCAG 2.1 AA.
if [[ "${E2E_ACCESSIBILITY}" == "1" ]]; then
	control /control/scenario/archive >/dev/null
	accessibility
	echo "The accessibility check passes after ${SECONDS} s."
fi

"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

# A Paperless-ngx before 2.16 doesn't serve version 9 of its API, which every request of the app
# asks for, so a run says which Paperless it needs instead of reading answers of another shape.
control /control/paperless/2.15.3 >/dev/null
OLD_PAPERLESS_STATUS="$(curl --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'dryRun=true' \
	--output "${TMP_DIR}/old-paperless.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/sync/run")"
control /control/paperless/3.3.0 >/dev/null
if [[ "${OLD_PAPERLESS_STATUS}" != "500" ]] || ! grep --fixed-strings 'Paperless Sync needs Paperless-ngx 2.16 or newer' "${TMP_DIR}/old-paperless.json" >/dev/null; then
	echo "A run against Paperless-ngx 2.15 did not name the Paperless it needs: HTTP ${OLD_PAPERLESS_STATUS}, $(cat "${TMP_DIR}/old-paperless.json")" >&2
	exit 1
fi

# A run that can't reach Paperless, the most common failure, logs its error without the token,
# although Nextcloud writes the arguments of every frame of the trace of an exception. It comes
# last, because assert_log.py above fails on the error that it logs.
log_count() {
	# The text to count reaches the shell of the container as its argument $1.
	# shellcheck disable=SC2016
	"${COMPOSE[@]}" exec -T nextcloud sh -c 'grep -c -F -e "$1" /var/www/html/data/nextcloud.log || true' sh "$1"
}
"${COMPOSE[@]}" stop paperless-mock >/dev/null
UNREACHABLE_STATUS="$(curl --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'dryRun=false' \
	--output "${TMP_DIR}/unreachable.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/sync/run")"
"${COMPOSE[@]}" up --detach --wait --wait-timeout 60 paperless-mock >/dev/null
if [[ "${UNREACHABLE_STATUS}" != "500" ]]; then
	echo "A run without Paperless answered HTTP ${UNREACHABLE_STATUS} instead of 500: $(cat "${TMP_DIR}/unreachable.json")" >&2
	exit 1
fi
if [[ "$(log_count 'Paperless synchronization failed')" == "0" ]]; then
	echo 'The log of Nextcloud holds no failed run, so the check of the token below would prove nothing.' >&2
	exit 1
fi
# PHP puts a SensitiveParameterValue in place of each argument marked #[\SensitiveParameter].
# Without one in the log, PHP left out the arguments of every frame
# (zend.exception_ignore_args=On, as in php.ini-production), and the count of the token below
# would pass without the fix.
if [[ "$(log_count SensitiveParameterValue)" == "0" ]]; then
	echo 'The log of Nextcloud holds no argument replaced by PHP, so the check of the token below would prove nothing.' >&2
	exit 1
fi
TOKEN_IN_LOG="$(log_count e2e-only-token)"
if [[ "${TOKEN_IN_LOG}" != "0" ]] || grep --fixed-strings e2e-only-token "${TMP_DIR}/unreachable.json" >/dev/null; then
	echo "A run without Paperless put the token into its answer or into ${TOKEN_IN_LOG} line(s) of the log of Nextcloud." >&2
	exit 1
fi

# The provisioning API of Nextcloud may change any value of the app, the URL too, without the
# settings page, which asks for the token again. The next run must not send the stored token to
# the new URL; the URL that the token was saved with gets it again.
set_paperless_url() {
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data-urlencode "value=$1" \
		--output /dev/null \
		"${BASE_URL}/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/paperless_sync/paperless_url"
}
set_paperless_url 'http://paperless-mock:8080/elsewhere'
REPOINTED_STATUS="$(curl --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'dryRun=true' \
	--output "${TMP_DIR}/repointed.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/sync/run")"
if [[ "${REPOINTED_STATUS}" != "500" ]] || ! grep --fixed-strings 'not completely configured' "${TMP_DIR}/repointed.json" >/dev/null; then
	echo "A run after the URL was changed through the provisioning API used the stored token: HTTP ${REPOINTED_STATUS}, $(cat "${TMP_DIR}/repointed.json")" >&2
	exit 1
fi
set_paperless_url 'http://paperless-mock:8080'
sync_run true "${TMP_DIR}/back.json"

checks='a Paperless in the local network without allow_local_remote_servers, dry-run, export, metadata moves with the SHA-256 checksums of Paperless-ngx 3 and the MD5 checksums of 2.x, a file that keeps its ID, its share and a version through a new content, the background job, exclusion, trash, guarded deletion, inbox success/failure/duplicate/revocation/refusal in the task shapes of Paperless-ngx 2.20 and 3.x, API version 9 in every request and a Paperless-ngx without it, pruning, restore, conflicts, vanished documents, long and unusual names, access isolation'
if [[ "${E2E_ACCESSIBILITY}" == "1" ]]; then
	checks="${checks}, accessibility, the settings in the browser"
fi
echo "Docker E2E passed on ${DATABASE_NAME} with ${STORAGE_NAME}: ${checks}, a run without Paperless that keeps the token out of the log, and a URL changed past the settings page that does not get the token."
