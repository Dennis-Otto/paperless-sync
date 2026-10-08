#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
E2E_PORT="${E2E_PORT:-18083}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_sync_e2e}"
PASSWORD="e2e-only-password"
BASE_URL="http://127.0.0.1:${E2E_PORT}"
DAV_BASE="${BASE_URL}/remote.php/dav/files"
TMP_DIR="$(mktemp -d)"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}" --file "${SCRIPT_DIR}/compose.yaml")

export E2E_PORT

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps || true
		"${COMPOSE[@]}" logs --no-color --tail 300 || true
	fi
	rm -rf "${TMP_DIR}"
	if [[ "${KEEP_E2E:-0}" != "1" ]]; then
		"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	else
		echo "E2E environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
	fi
	exit "${status}"
}
trap cleanup EXIT

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

assert_json() {
	mode="$1"
	file="$2"
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_json.py "${mode}" < "${file}"
}

control() {
	path="$1"
	"${COMPOSE[@]}" exec -T paperless-mock python -c \
		"import urllib.request; urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8080${path}', method='POST')).read()"
}

# axe-core checks the pages of the app in Chromium (accessibility.mjs), inside the
# network of the Compose project. The files of the check reach the browser through
# standard input, so that npm leaves nothing in the checkout. Keep the version of the
# image of Playwright equal to playwright-core in package.json; Renovate updates both
# together, and scripts/check-project.sh compares them.
accessibility() {
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json accessibility.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env "E2E_PASSWORD=${PASSWORD}" \
			mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27 \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node accessibility.mjs'
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

INITIAL_PATH='Documents/Paperless/Archive/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20%5BP123%5D.pdf'
RENAMED_PATH='Documents/Paperless/Archive/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20corrected%20%5BP123%5D.pdf'
TRASH_PATH='Documents/Paperless/Archive/_Deleted/2026-08-27/Example%20GmbH/Invoice/2026/2026-08-26%20-%20Monthly%20invoice%20corrected%20%5BP123%5D.pdf'
ERROR_PATH='Documents/Paperless/Errors/broken.pdf'

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait --wait-timeout 300

if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install \
		--database=sqlite \
		--admin-user=e2e-admin \
		--admin-pass="${PASSWORD}" >/dev/null
fi
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
# The browser of the accessibility check reaches Nextcloud by its name in the network.
occ config:system:set trusted_domains 2 --value=nextcloud >/dev/null
occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null

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
# The first-run wizard would cover the pages that the accessibility check opens.
occ app:disable firstrunwizard >/dev/null 2>&1 || true
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null

# The administration settings render the form with all of its routes.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-settings.html" \
	"${BASE_URL}/index.php/settings/admin/paperless_sync"
for attribute in \
	'data-save-url="/apps/paperless_sync/settings"' \
	'data-reset-url="/apps/paperless_sync/settings"' \
	'data-run-url="/apps/paperless_sync/sync/run"' \
	'data-status-url="/apps/paperless_sync/sync/status"'; do
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
	foreach (["settings.save", "settings.reset", "sync.run", "sync.status"] as $route) {
		echo "route=", $urls->linkToRoute("paperless_sync." . $route), "\n";
	}
' 2>/dev/null | grep '^route=' | sed 's#^route=/index.php/#route=/#' | tr '\n' ' ')"
EXPECTED='route=/apps/paperless_sync/settings route=/apps/paperless_sync/settings route=/apps/paperless_sync/sync/run route=/apps/paperless_sync/sync/status '
if [[ "${ROUTES}" != "${EXPECTED}" ]]; then
	echo "The routes are missing before the app is loaded: ${ROUTES}" >&2
	exit 1
fi

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'settings[paperless_url]=http://paperless-mock:8080' \
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

sync_run false "${TMP_DIR}/export.json"
assert_json export "${TMP_DIR}/export.json"
assert_dav_file sync-target "${INITIAL_PATH}" 'Synthetic Paperless archive document P123.'

control /control/scenario/renamed >/dev/null
sync_run false "${TMP_DIR}/move.json"
assert_json move "${TMP_DIR}/move.json"
assert_dav_missing sync-target "${INITIAL_PATH}"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
"${COMPOSE[@]}" exec -T paperless-mock python -c \
	"import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8080/control/state').read().decode())" \
	> "${TMP_DIR}/mock-after-move.json"
assert_json mock-after-move "${TMP_DIR}/mock-after-move.json"

control /control/scenario/excluded >/dev/null
sync_run false "${TMP_DIR}/excluded.json"
assert_json excluded "${TMP_DIR}/excluded.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
if [[ "$(dav_status sync-target PROPFIND 'Documents/Paperless/Archive/Example%20GmbH')" != "404" ]]; then
	echo 'Empty archive hierarchy was not pruned after exclusion.' >&2
	exit 1
fi

control /control/scenario/renamed >/dev/null
sync_run false "${TMP_DIR}/re-export.json"
assert_json export "${TMP_DIR}/re-export.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'

control /control/scenario/trash >/dev/null
sync_run false "${TMP_DIR}/trash.json"
assert_json trash "${TMP_DIR}/trash.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
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
printf '%s\n' '%PDF-1.4 Synthetic inbox PDF policy.' \
	| curl --fail-with-body --silent --show-error \
		--user "sync-target:${PASSWORD}" \
		--upload-file - \
		"${DAV_BASE}/sync-target/Documents/Paperless/Inbox/Insurance/policy.pdf" >/dev/null
sync_run false "${TMP_DIR}/import-submitted.json"
assert_json import-submitted "${TMP_DIR}/import-submitted.json"
control /control/tasks/success >/dev/null
sync_run false "${TMP_DIR}/import-success.json"
assert_json import-success "${TMP_DIR}/import-success.json"
assert_dav_missing sync-target 'Documents/Paperless/Inbox/Insurance/policy.pdf'

printf '%s\n' '%PDF-1.4 Synthetic inbox PDF broken.' \
	| curl --fail-with-body --silent --show-error \
		--user "sync-target:${PASSWORD}" \
		--upload-file - \
		"${DAV_BASE}/sync-target/Documents/Paperless/Inbox/broken.pdf" >/dev/null
sync_run false "${TMP_DIR}/failed-submitted.json"
assert_json import-submitted "${TMP_DIR}/failed-submitted.json"
control /control/tasks/failure >/dev/null
sync_run false "${TMP_DIR}/import-failed.json"
assert_json import-failed "${TMP_DIR}/import-failed.json"
assert_dav_missing sync-target 'Documents/Paperless/Inbox/broken.pdf'
assert_dav_file sync-target "${ERROR_PATH}" 'Synthetic inbox PDF broken.'
assert_dav_file sync-target "${ERROR_PATH}.error.txt" 'Synthetic unsupported mime type'
assert_dav_missing e2e-other "${ERROR_PATH}"

# Paperless refuses a file of a type it doesn't take. The file stays in the inbox, and
# the runs after it don't upload it again until it changes.
printf '%s\n' 'Synthetic unsupported file.' \
	| curl --fail-with-body --silent --show-error \
		--user "sync-target:${PASSWORD}" \
		--upload-file - \
		"${DAV_BASE}/sync-target/Documents/Paperless/Inbox/notes.bin" >/dev/null
sync_run false "${TMP_DIR}/import-rejected.json"
assert_json import-rejected "${TMP_DIR}/import-rejected.json"
sync_run false "${TMP_DIR}/import-waiting.json"
assert_json import-waiting "${TMP_DIR}/import-waiting.json"
assert_dav_file sync-target 'Documents/Paperless/Inbox/notes.bin' 'Synthetic unsupported file.'

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--output "${TMP_DIR}/status.json" \
	"${BASE_URL}/apps/paperless_sync/sync/status"
assert_json status "${TMP_DIR}/status.json"

"${COMPOSE[@]}" exec -T paperless-mock python -c \
	"import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8080/control/state').read().decode())" \
	> "${TMP_DIR}/mock-final.json"
assert_json mock-final "${TMP_DIR}/mock-final.json"

# The administration settings and the report of a dry-run over a larger archive meet
# WCAG 2.1 AA.
control /control/scenario/archive >/dev/null
accessibility

"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

echo 'Docker E2E passed: dry-run, export, metadata move, exclusion, trash, guarded deletion, inbox success/failure/refusal, pruning, access isolation, and accessibility.'
