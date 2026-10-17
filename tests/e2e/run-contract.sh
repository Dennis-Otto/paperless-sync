#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The contract tests against a real Paperless-ngx, which the job paperless of
# .github/workflows/e2e.yml runs for each line of Paperless:
#
#   bash tests/e2e/run-contract.sh 2    the last release of Paperless-ngx 2.x
#   bash tests/e2e/run-contract.sh 3    the current release of Paperless-ngx 3.x
#
# The mock of run.sh answers as the app expects; this one answers as Paperless does.
# contract.py seeds a fresh Paperless through its API, as a household does, and checks
# every answer that the app reads: the fields of the documents and the trash, the pages
# of a list, the tasks of an upload, a failed and a refused upload, and the checksums of
# the metadata against those of the downloads. Then the app runs against it in
# Nextcloud: the export, a new title, the trash of Paperless and back, and the inbox,
# with a file that Paperless takes and one that it can't read.
#
# The answers of Paperless become the fixtures of the unit tests in
# tests/fixtures/paperless-ngx-<line>/. The script compares their shapes with those of the
# fixtures and fails when a field is gone or has another type; CONTRACT_RECORD=1 writes
# the new answers there instead. CONTRACT_RECORDINGS=<folder> keeps them in that folder.

set -euo pipefail

# Git Bash on Windows would turn the paths inside the containers into paths of Windows.
export MSYS2_ARG_CONV_EXCL="${MSYS2_ARG_CONV_EXCL:+${MSYS2_ARG_CONV_EXCL};}/mock/;/fixtures/"

LINE="${1:-3}"
if [[ "${LINE}" != 2 && "${LINE}" != 3 ]]; then
	echo "Usage: bash tests/e2e/run-contract.sh 2|3, for Paperless-ngx 2.x or 3.x." >&2
	exit 2
fi
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
# A port and a Compose project of each line, so that both lines can run side by side.
E2E_PORT="${E2E_PORT:-1809${LINE}}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_sync_contract_${LINE}}"
PASSWORD="e2e-only-password"
BASE_URL="http://127.0.0.1:${E2E_PORT}"
DAV_BASE="${BASE_URL}/remote.php/dav/files/sync-target"
PAPERLESS_URL="http://paperless-${LINE}:8000"
FIXTURES="tests/fixtures/paperless-ngx-${LINE}"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}"
	--file "${SCRIPT_DIR}/compose.yaml" --file "${SCRIPT_DIR}/compose.paperless.yaml" --profile "paperless-${LINE}")
TMP_DIR="$(mktemp -d)"
PATHS=()

export E2E_PORT

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps || true
		"${COMPOSE[@]}" logs --no-color --tail 200 || true
	fi
	rm -rf "${TMP_DIR}"
	if [[ "${KEEP_E2E:-0}" == "1" ]]; then
		echo "Contract environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
	elif [[ "${CI:-}" != "true" ]]; then
		"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 1 >/dev/null 2>&1 || true
	fi
	exit "${status}"
}
trap cleanup EXIT
trap 'exit 143' TERM

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

# contract.py in the container of the mock, inside the network of Compose: Python alone.
contract() {
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/contract.py "$@"
}

nextcloud_request() {
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		"$@"
}

# A run of the app, whose report contract.py checks; the paths of the files that the run
# wrote, URL-encoded for WebDAV, go into the array PATHS. "Synchronize now" of the settings
# page only queues a background job, so the run goes through occ, which runs it at once and
# answers with its report. Its exit code 1 means errors of single documents, which
# contract.py judges; a higher one means a run that didn't end.
sync_run() {
	local code=0
	occ paperless_sync:run --output=json > "${TMP_DIR}/$1.json" || code=$?
	if ((code > 1)); then
		echo "occ paperless_sync:run ended with exit code ${code}: $(cat "${TMP_DIR}/$1.json")" >&2
		exit 1
	fi
	contract report "$1" < "${TMP_DIR}/$1.json" > "${TMP_DIR}/$1.paths"
	mapfile -t PATHS < "${TMP_DIR}/$1.paths"
}

# The bytes of a file of the target user, by its path as contract.py prints it.
dav_get() {
	curl --fail-with-body --silent --show-error --user "sync-target:${PASSWORD}" "${DAV_BASE}/$1"
}

dav_put() {
	curl --fail-with-body --silent --show-error --user "sync-target:${PASSWORD}" --upload-file - "${DAV_BASE}/$1" >/dev/null
}

dav_status() {
	curl --silent --output /dev/null --write-out '%{http_code}' --user "sync-target:${PASSWORD}" --request "$1" "${DAV_BASE}/$2"
}

file_id() {
	curl --fail-with-body --silent --show-error --user "sync-target:${PASSWORD}" --request PROPFIND --header 'Depth: 0' \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' \
		"${DAV_BASE}/$1" | grep -oE '<oc:fileid>[0-9]+</oc:fileid>' | grep -oE '[0-9]+'
}

# The file of a document in Nextcloud has the bytes of its archived version in Paperless.
assert_content() {
	dav_get "$2" | contract content "$1"
}

assert_missing() {
	status="$(dav_status GET "$1")"
	if [[ "${status}" != 404 ]]; then
		echo "Nextcloud still has $1: HTTP ${status}." >&2
		exit 1
	fi
}

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 1 >/dev/null 2>&1 || true
# Paperless starts with its database and its search index, Nextcloud next to it.
"${COMPOSE[@]}" up --detach --wait --wait-timeout 600
echo "Paperless and Nextcloud run after ${SECONDS} s."

contract api "${PAPERLESS_URL}" "${LINE}"
echo "The API contract passes after ${SECONDS} s."

if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install --admin-user=e2e-admin --admin-pass="${PASSWORD}" >/dev/null
fi
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
# The app reaches Paperless in the network of Compose with its own setting for the local
# network, as run.sh does, without allow_local_remote_servers for every app.
occ config:system:delete allow_local_remote_servers >/dev/null
occ config:app:set password_policy enforceHaveIBeenPwned --type=boolean --value=false >/dev/null
"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
	php occ user:add --password-from-env sync-target >/dev/null
occ app:enable paperless_sync >/dev/null
occ background:cron >/dev/null
# The web server reads the routes of the app that it enabled only after a restart, as in run.sh.
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null
echo "Nextcloud and the app are set up after ${SECONDS} s."

# The settings of run.sh, with the token of Paperless that contract.py got.
nextcloud_request --request POST \
	--data-urlencode "settings[paperless_url]=${PAPERLESS_URL}" \
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
	--data-urlencode 'settings[missing_grace_runs]=2' \
	--data-urlencode 'settings[delete_inbox_after_success]=1' \
	--data-urlencode 'settings[inbox_tags]=contract, Nextcloud' \
	--data-urlencode 'settings[inbox_create_tags]=1' \
	--data-urlencode 'settings[conflict_mode]=replace' \
	--data-urlencode "token=$(contract token)" \
	--output "${TMP_DIR}/config.json" \
	"${BASE_URL}/apps/paperless_sync/settings"

# The export: the invoice and the statement, each with the bytes of its archived version.
sync_run export
EXPORTED=("${PATHS[@]}")
assert_content invoice "${EXPORTED[0]}"
assert_content statement "${EXPORTED[1]}"
FILE_ID="$(file_id "${EXPORTED[0]}")"

# A new title in Paperless moves the file, which keeps its ID in Nextcloud.
contract rename
sync_run move
MOVED="${PATHS[0]}"
assert_missing "${EXPORTED[0]}"
assert_content invoice "${MOVED}"
if [[ "$(file_id "${MOVED}")" != "${FILE_ID}" ]]; then
	echo "The file of the invoice lost its ID ${FILE_ID} when it moved." >&2
	exit 1
fi

# The trash of Paperless, and back.
contract trash
sync_run trash
TRASHED="${PATHS[0]}"
assert_missing "${MOVED}"
assert_content invoice "${TRASHED}"
contract restore
sync_run restore
RESTORED="${PATHS[0]}"
assert_missing "${TRASHED}"
assert_content invoice "${RESTORED}"
echo "The export, the move and the trash pass after ${SECONDS} s."

# The inbox: a PDF that Paperless takes, and one that it can't read. The first run uploads
# both, with the tag Contract, which contract.py made, and the tag Nextcloud, which the run
# creates; the second reads their tasks once Paperless has ended them.
contract pdf receipt.pdf | dav_put 'Documents/Paperless/Inbox/receipt.pdf'
printf '%%PDF-1.4 Synthetic broken contract test file.\n' | dav_put 'Documents/Paperless/Inbox/broken.pdf'
sync_run import-submitted
contract wait
sync_run import-ended
contract imported receipt.pdf "${PATHS[0]}"
assert_missing 'Documents/Paperless/Inbox/receipt.pdf'
assert_missing 'Documents/Paperless/Inbox/broken.pdf'
# The reason of Paperless next to the file in the error folder.
if ! dav_get "${PATHS[1]}.error.txt" | grep --fixed-strings 'broken.pdf: Error occurred while consuming document broken.pdf' >/dev/null; then
	echo "The error folder has no reason of Paperless for broken.pdf: $(dav_get "${PATHS[1]}.error.txt" || true)" >&2
	exit 1
fi
echo "The inbox passes after ${SECONDS} s."

nextcloud_request --output "${TMP_DIR}/status.json" "${BASE_URL}/apps/paperless_sync/sync/status"
contract status < "${TMP_DIR}/status.json"
"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| contract log

# The answers of Paperless: into the fixtures, or compared with them.
RECORDINGS="${CONTRACT_RECORDINGS:-${TMP_DIR}/recordings}"
if [[ "${CONTRACT_RECORD:-0}" == "1" ]]; then
	RECORDINGS="${FIXTURES}"
	rm -rf "${RECORDINGS}"
fi
mkdir -p "${RECORDINGS}"
contract fixtures | tar -xf - -C "${RECORDINGS}"
if [[ "${CONTRACT_RECORD:-0}" == "1" ]]; then
	echo "The answers of Paperless are the fixtures in ${FIXTURES} now."
elif ! contract shapes "/fixtures/paperless-ngx-${LINE}"; then
	echo "Paperless answers in another shape than the fixtures in ${FIXTURES}: a field that the unit tests" >&2
	echo "read is gone or has another type. Adapt the app, then record them anew:" >&2
	echo "CONTRACT_RECORD=1 bash tests/e2e/run-contract.sh ${LINE}" >&2
	exit 1
fi
echo "Paperless Sync works with Paperless-ngx ${LINE}.x after ${SECONDS} s."
