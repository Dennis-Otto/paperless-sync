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
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.64.0-noble@sha256:06a9939e57531807f8d5fd76ce44b53165ffb7d7501d87ab10e285c20b1e971f"
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
# administrator does: it saves them with the confirmation of the password, tests the
# connection with the token of an account with too few permissions, disconnects and
# connects again with the token, and checks what the page says about unsaved changes,
# results and errors, also in German. files.mjs checks the actions of the app in the
# menu of Files for a member of the group of the setting and for another user, and flow.mjs
# the form of a rule of "Send to Paperless" in the settings of Flow. The files
# of the checks reach the browser through standard input, so that npm leaves nothing in
# the checkout.
accessibility() {
	if [[ -n "${PLAYWRIGHT_PULL}" ]] && ! wait "${PLAYWRIGHT_PULL}"; then
		echo "The image of Playwright didn't download in the background; docker run tries again."
	fi
	PLAYWRIGHT_PULL=""
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json accessibility.mjs settings.mjs files.mjs files-address.mjs flow.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env "E2E_PASSWORD=${PASSWORD}" \
			--env E2E_TOKEN=e2e-only-token \
			--env E2E_VIEW_ONLY_TOKEN=e2e-view-only-token \
			--env E2E_SUPERUSER_TOKEN=e2e-superuser-only-token \
			"${PLAYWRIGHT_IMAGE}" \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node accessibility.mjs && node settings.mjs && node files.mjs && node flow.mjs'
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

# The same for the status API, as the monitor of an administrator reads it, after a run of occ.
assert_status_api_soon() {
	mode="$1"
	output="$2"
	for _ in $(seq 1 20); do
		if [[ "$(status_api e2e-admin "${output}")" == "200" ]] && assert_json "${mode}" "${output}" 2>/dev/null; then
			return 0
		fi
		sleep 0.5
	done
	assert_json "${mode}" "${output}"
}

# A dry-run as the settings page starts it, which answers with its report, or a run as an
# administrator automates it with occ, which prints its report: "Synchronize now" queues its run
# as a background job and answers before it ends. The exit code of occ says how the run ended,
# 1 with errors of single documents, which some steps expect, so the report must agree with it.
sync_run() {
	dry_run="$1"
	output="$2"
	if [[ "${dry_run}" == "false" ]]; then
		code=0
		occ paperless_sync:run --output=json > "${output}" || code=$?
		if ((code > 1)); then
			echo "occ paperless_sync:run ended with exit code ${code}: $(cat "${output}")" >&2
			exit 1
		fi
		assert_json "exit-${code}" "${output}"
		return
	fi
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

# The entries of Paperless Sync in the Activity stream of the target user, newest first,
# as the Activity app gives them to its clients. A stream without entries answers 304
# without a body.
activities() {
	status="$(curl --silent --show-error \
		--user "sync-target:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "$1" \
		--write-out '%{http_code}' \
		"${BASE_URL}/ocs/v2.php/apps/activity/api/v2/activity/paperless_sync?limit=100")"
	case "${status}" in
		200) ;;
		304) printf '{"ocs": {"data": []}}' >"$1" ;;
		*)
			echo "The Activity app answered with HTTP ${status}:" >&2
			cat "$1" >&2
			exit 1
			;;
	esac
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

# The notifications of a user, as the Notifications app gives them to its clients.
notifications() {
	curl --fail-with-body --silent --show-error \
		--user "$1:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "$2" \
		"${BASE_URL}/ocs/v2.php/apps/notifications/api/v2/notifications"
}

# The health of the mirror from the status API of the app, as a monitor reads it with the
# password of a user; prints the HTTP status.
status_api() {
	curl --silent --show-error \
		--user "$1:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "$2" \
		--write-out '%{http_code}' \
		"${BASE_URL}/ocs/v2.php/apps/paperless_sync/api/v1/status"
}

# A call of the webhook as a workflow of Paperless makes it: from the container of the mock,
# which plays Paperless, to Nextcloud by its name in the network, with the header
# Authorization of $2, none when it is empty, and the JSON body $3. Writes the answer to the
# file $1 and prints the HTTP status.
webhook() {
	{ "${COMPOSE[@]}" exec -T paperless-mock python -c '
import sys, urllib.error, urllib.request
headers = {"Content-Type": "application/json"}
if sys.argv[1]:
    headers["Authorization"] = sys.argv[1]
request = urllib.request.Request(
    "http://nextcloud/index.php/apps/paperless_sync/api/v1/webhook",
    data=sys.argv[2].encode(),
    headers=headers,
    method="POST",
)
try:
    with urllib.request.urlopen(request, timeout=120) as response:
        status, body = response.status, response.read()
except urllib.error.HTTPError as error:
    status, body = error.code, error.read()
sys.stdout.write(body.decode())
sys.stderr.write(str(status))' "$2" "$3" >"$1"; } 2>&1
}

# A call of the webhook that must answer with the HTTP status $1 and the result $2 for P123.
assert_webhook() {
	expected="$1"
	result="$2"
	authorization="$3"
	body="$4"
	status="$(webhook "${TMP_DIR}/webhook.json" "${authorization}" "${body}")"
	if [[ "${status}" != "${expected}" ]]; then
		echo "The webhook answered HTTP ${status} instead of ${expected}: $(cat "${TMP_DIR}/webhook.json")" >&2
		exit 1
	fi
	if [[ -n "${result}" ]]; then
		assert_json webhook-result "${TMP_DIR}/webhook.json" "${result}"
	fi
}

# The calls with a wrong secret that Nextcloud's brute-force protection counted for the address
# of the mock, which plays Paperless.
webhook_attempts() {
	address="$("${COMPOSE[@]}" exec -T paperless-mock python -c 'import socket; print(socket.gethostbyname(socket.gethostname()))')"
	occ security:bruteforce:attempts --output=json "${address}" paperless_sync_webhook \
		| json_value attempts
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

# The names in the trash bin of a user, Deleted files in Files, one per line: a run deletes into
# the trash bin of the user whose files it changes, whoever started it and whatever the process
# did before (decision 0026). Names with a character that XML escapes don't occur in the suite.
trash_names() {
	user="$1"
	output="${TMP_DIR}/trash-$RANDOM.xml"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--request PROPFIND \
		--header 'Depth: 1' \
		--header 'Content-Type: application/xml' \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:trashbin-filename/></d:prop></d:propfind>' \
		--output "${output}" \
		"${BASE_URL}/remote.php/dav/trashbin/${user}/trash"
	{ grep --only-matching '<nc:trashbin-filename>[^<]*</nc:trashbin-filename>' "${output}" || true; } \
		| sed -e 's/<nc:trashbin-filename>//' -e 's/<\/nc:trashbin-filename>//'
}

# Fails unless the trash bin of the user holds one more file or folder of the name than the list
# of trash_names from before the step.
assert_trashed() {
	user="$1"
	name="$2"
	before="$3"
	if [[ ! -f "${before}" ]]; then
		echo "No list of the trash bin of ${user} from before the step: ${before}" >&2
		exit 1
	fi
	count_before="$(grep --count --line-regexp --fixed-strings -- "${name}" "${before}" || true)"
	count_after="$(trash_names "${user}" | grep --count --line-regexp --fixed-strings -- "${name}" || true)"
	if ((count_after <= count_before)); then
		echo "The trash bin of ${user} holds ${count_after} entries named ${name}, as many as before: the run deleted it for good instead of moving it to the trash bin." >&2
		exit 1
	fi
}

# A collaborative tag that a user makes in Files, which every user may assign; prints its ID.
create_tag() {
	user="$1"
	name="$2"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--request POST \
		--header 'Content-Type: application/json' \
		--data "{\"name\": \"${name}\", \"userVisible\": true, \"userAssignable\": true}" \
		--dump-header - --output /dev/null \
		"${BASE_URL}/remote.php/dav/systemtags/" \
		| tr -d '\r' | sed -n 's|^[Cc]ontent-[Ll]ocation: .*/systemtags/\([0-9][0-9]*\)$|\1|p'
}

# The collaborative tags of a file as Files shows them to the user, each as its name and
# whether users may assign it, such as insurance:false, one per line in the order of the names.
# The answer goes into a file, which the Python of the mock then reads: Scorecard reports a pipe
# of curl into exec … python as a download that runs ("Downloads that a script runs" in
# scripts/check-project.sh). A command substitution, such as that of assert_file_tags, doesn't
# stop at a failure, so a request that fails returns its status at once.
file_tags() {
	user="$1"
	file="$2"
	answer="${TMP_DIR}/file-tags-$RANDOM.xml"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--request PROPFIND \
		--header 'Depth: 1' \
		--header 'Content-Type: application/xml' \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:display-name/><oc:user-assignable/></d:prop></d:propfind>' \
		--output "${answer}" \
		"${BASE_URL}/remote.php/dav/systemtags-relations/files/${file}" || return
	"${COMPOSE[@]}" exec -T paperless-mock python -c '
import sys, xml.etree.ElementTree as tree
ns = {"d": "DAV:", "oc": "http://owncloud.org/ns"}
tags = []
for prop in tree.parse(sys.stdin).getroot().iterfind("d:response/d:propstat/d:prop", ns):
    name = prop.find("oc:display-name", ns)
    if name is not None and name.text:
        tags.append(name.text + ":" + str(prop.findtext("oc:user-assignable", namespaces=ns)))
print("\n".join(sorted(tags)))' < "${answer}"
}

# The tags of the copy of P123 at its renamed path are those that a step expects.
assert_file_tags() {
	expected="$1"
	actual="$(file_tags sync-target "$(file_id sync-target "${RENAMED_PATH}")")"
	if [[ "${actual}" != "${expected}" ]]; then
		echo "The copy of P123 has the tags $(printf '%q' "${actual}") instead of $(printf '%q' "${expected}")." >&2
		exit 1
	fi
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
	'data-test-url="/apps/paperless_sync/settings/test"' \
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
	foreach (["settings.save", "settings.test", "settings.reset", "settings.preview", "sync.run", "sync.status"] as $route) {
		echo "route=", $urls->linkToRoute("paperless_sync." . $route), "\n";
	}
' 2>/dev/null | grep '^route=' | sed 's#^route=/index.php/#route=/#' | tr '\n' ' ')"
EXPECTED='route=/apps/paperless_sync/settings route=/apps/paperless_sync/settings/test route=/apps/paperless_sync/settings route=/apps/paperless_sync/settings/preview route=/apps/paperless_sync/sync/run route=/apps/paperless_sync/sync/status '
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

# The connection checklist of an account that may view the documents but neither the tags
# nor add documents, which the test of the documents alone would have passed: Test
# connection names each permission to grant, and saves nothing.
checklist() {
	endpoint="$1"
	output="$2"
	token="${3:-e2e-view-only-token}"
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data-urlencode 'settings[paperless_url]=http://paperless-mock:8080' \
		--data-urlencode 'settings[paperless_local_network]=1' \
		--data-urlencode 'settings[target_user]=sync-target' \
		--data-urlencode 'settings[base_path]=Documents/Paperless' \
		--data-urlencode 'settings[export_enabled]=1' \
		--data-urlencode 'settings[inbox_enabled]=1' \
		--data-urlencode "token=${token}" \
		--output "${output}" \
		"${BASE_URL}/apps/paperless_sync/${endpoint}"
}
checklist settings/test "${TMP_DIR}/checklist-view-only.json"
assert_json checklist-view-only "${TMP_DIR}/checklist-view-only.json"
if occ config:app:get paperless_sync paperless_url >/dev/null 2>&1; then
	echo "Test connection saved the settings." >&2
	exit 1
fi
# Saving goes on with a permission that one module lacks, so that it can be granted in
# Paperless afterwards, and the answer lists the same checklist. The save below replaces
# the token with the one of the full account.
checklist settings "${TMP_DIR}/saved-with-failures.json"
assert_json saved-with-failures "${TMP_DIR}/saved-with-failures.json"
# Without the permission View for UI Settings, Paperless doesn't tell the checklist the
# permissions of the account: the lines of the trash and the inbox name that permission.
checklist settings/test "${TMP_DIR}/checklist-no-ui-settings.json" e2e-no-ui-settings-token
assert_json checklist-no-ui-settings "${TMP_DIR}/checklist-no-ui-settings.json"

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
occ paperless_sync:run --dry-run --output=json > "${TMP_DIR}/dry-run-occ.json"
assert_json dry-run "${TMP_DIR}/dry-run-occ.json"
assert_dav_missing sync-target "${INITIAL_PATH}"
# A dry-run changes nothing, so the Activity stream of the target user stays empty.
activities "${TMP_DIR}/activity-dry-run.json"
assert_json activity-none "${TMP_DIR}/activity-dry-run.json"

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

# "Synchronize now" queues the run as a background job and answers at once, with the status that
# the page follows; the worker of Nextcloud runs the job here, as cron does on a server.
QUEUED_STATUS="$(curl --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'dryRun=false' \
	--output "${TMP_DIR}/queued.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/sync/run")"
if [[ "${QUEUED_STATUS}" != "202" ]]; then
	echo "\"Synchronize now\" answered HTTP ${QUEUED_STATUS} instead of 202: $(cat "${TMP_DIR}/queued.json")" >&2
	exit 1
fi
assert_json queued "${TMP_DIR}/queued.json"
assert_dav_missing sync-target "${INITIAL_PATH}"
occ background-job:worker --once 'OCA\PaperlessSync\Cron\ManualSyncJob' >/dev/null
assert_status_soon status-export "${TMP_DIR}/export.json"
assert_dav_file sync-target "${INITIAL_PATH}" 'Synthetic Paperless archive document P123.'
# One entry for the run, with the link of the file that it wrote.
activities "${TMP_DIR}/activity-export.json"
assert_json activity-export "${TMP_DIR}/activity-export.json"
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

# The webhook of a workflow of Paperless: a call names a document that Paperless added or
# changed, and its copy changes during the call, with no background job in between. Calls
# without the secret are refused and counted by Nextcloud's brute-force protection, and a call
# is no run of the status, from which the schedule counts.
RUN_BEFORE_WEBHOOK="$(last_run)"
assert_webhook 401 '' 'Bearer no-secret-yet' '{"document": 123}'
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--output "${TMP_DIR}/webhook-secret.json" \
	"${BASE_URL}/apps/paperless_sync/settings/webhook"
assert_json webhook-secret "${TMP_DIR}/webhook-secret.json"
WEBHOOK_SECRET="$(json_value secret < "${TMP_DIR}/webhook-secret.json")"
assert_webhook 401 '' '' '{"document": 123}'
assert_webhook 401 '' 'Bearer wrong-secret' '{"document": 123}'
if [[ "$(webhook_attempts)" -lt 3 ]]; then
	echo "Nextcloud's brute-force protection did not count the calls without the secret." >&2
	exit 1
fi
# Scheduled synchronization is still off, as before the first dry-run was reviewed: a call
# changes nothing, until it is on.
control /control/scenario/active >/dev/null
assert_webhook 409 off "Bearer ${WEBHOOK_SECRET}" '{"document": 123}'
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
save_settings "${TMP_DIR}/webhook-enabled-settings.json" enabled=1
assert_webhook 200 moved "Bearer ${WEBHOOK_SECRET}" '{"document": 123}'
assert_dav_missing sync-target "${RENAMED_PATH}"
assert_dav_file sync-target "${INITIAL_PATH}" 'Synthetic Paperless archive document P123.'
assert_file_id "${INITIAL_PATH}"
if [[ "$(webhook_attempts)" != "0" ]]; then
	echo "A call with the secret did not end the delay of the address of Paperless." >&2
	exit 1
fi
# Paperless-ngx 2.x has no {{ doc_id }}: its {{ doc_url }} names the document by its address, and
# the parameters of a workflow send the number as text.
control /control/scenario/renamed >/dev/null
assert_webhook 200 moved "Bearer ${WEBHOOK_SECRET}" '{"document": "http://paperless.example.test/documents/123/"}'
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
assert_webhook 200 unchanged "Bearer ${WEBHOOK_SECRET}" '{"document": "123"}'
assert_webhook 404 missing "Bearer ${WEBHOOK_SECRET}" '{"document": 999}'
assert_webhook 400 '' "Bearer ${WEBHOOK_SECRET}" '{"title": "Monthly invoice"}'
# With "Mirror the document during the call" off, the document waits for the background job.
save_settings "${TMP_DIR}/webhook-queued-settings.json" webhook_immediate=0
control /control/scenario/active >/dev/null
assert_webhook 202 queued "Bearer ${WEBHOOK_SECRET}" '{"document": 123}'
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
occ background-job:worker --once 'OCA\PaperlessSync\Cron\DocumentSyncJob' >/dev/null
assert_dav_missing sync-target "${RENAMED_PATH}"
assert_dav_file sync-target "${INITIAL_PATH}" 'Synthetic Paperless archive document P123.'
save_settings "${TMP_DIR}/webhook-immediate-settings.json" webhook_immediate=1
control /control/scenario/renamed >/dev/null
assert_webhook 200 moved "Bearer ${WEBHOOK_SECRET}" '{"document": 123}'
assert_file_id "${RENAMED_PATH}"
save_settings "${TMP_DIR}/webhook-done-settings.json" enabled=0
if [[ "$(last_run)" != "${RUN_BEFORE_WEBHOOK}" ]]; then
	echo "A call of the webhook changed the status of the last run: ${RUN_BEFORE_WEBHOOK} became $(last_run)." >&2
	exit 1
fi
# The settings page shows the last call, and never the secret again.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--output "${TMP_DIR}/webhook-state.json" \
	"${BASE_URL}/apps/paperless_sync/settings/webhook"
assert_json webhook-state "${TMP_DIR}/webhook-state.json" "${WEBHOOK_SECRET}"
mock_state > "${TMP_DIR}/mock-after-webhook.json"
assert_json mock-after-webhook "${TMP_DIR}/mock-after-webhook.json"

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
# A scheduled run, without a user session, has its entry as well, without an author.
activities "${TMP_DIR}/activity-job.json"
assert_json activity-job "${TMP_DIR}/activity-job.json"
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
trash_names sync-target > "${TMP_DIR}/trash-before-excluded.txt"
sync_run false "${TMP_DIR}/excluded.json"
assert_json excluded "${TMP_DIR}/excluded.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
if [[ "$(dav_status sync-target PROPFIND 'Documents/Paperless/Archive/Example%20GmbH')" != "404" ]]; then
	echo 'Empty archive hierarchy was not pruned after exclusion.' >&2
	exit 1
fi
# The removed copy and the folders that its removal emptied are in the trash bin of the target
# user, to restore.
COPY_NAME='2026-08-26 - Monthly invoice corrected [P123].pdf'
assert_trashed sync-target "${COPY_NAME}" "${TMP_DIR}/trash-before-excluded.txt"
assert_trashed sync-target 'Example GmbH' "${TMP_DIR}/trash-before-excluded.txt"

# With tags to mirror, the archive holds only the documents with one of them: the document of
# the scenario lacks Private, a tag of the mock, so it stays out, whatever the case of the name.
# Without the list, the next steps export the document again.
control /control/scenario/renamed >/dev/null
save_settings "${TMP_DIR}/included.json" included_tags=private
assert_json included "${TMP_DIR}/included.json"
sync_run false "${TMP_DIR}/not-included.json"
assert_json not-included "${TMP_DIR}/not-included.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
save_settings "${TMP_DIR}/included.json" included_tags=

# Nextcloud applies no quota to the temporary file of a write, so the app checks the free space
# itself: with the quota of the target user used up, the export stops at its first document, and
# the document follows once there is space again.
control /control/scenario/renamed >/dev/null
occ user:setting sync-target files quota '1 B' >/dev/null
sync_run false "${TMP_DIR}/export-stopped.json"
occ user:setting --delete sync-target files quota >/dev/null
assert_json export-stopped "${TMP_DIR}/export-stopped.json"
# The status keeps the error of the document for the settings page, which reads it in the
# language of its administrator, while the run keeps it in English, as the log does.
assert_status_soon status-errors "${TMP_DIR}/status-errors.json"
occ user:setting e2e-admin core lang de >/dev/null
assert_status_soon status-errors-german "${TMP_DIR}/status-errors-german.json"
occ user:setting --delete e2e-admin core lang >/dev/null
assert_dav_missing sync-target "${RENAMED_PATH}"

sync_run false "${TMP_DIR}/re-export.json"
assert_json export "${TMP_DIR}/re-export.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'

# The tags of Paperless as tags of Nextcloud: with the setting on, the copy of P123 gets the tag
# insurance of its document, which every user sees and only administrators assign, and keeps the
# tag Holiday that its user put on it. A user made the tag TAX, 2026 already, which Nextcloud
# holds once whatever the case, so the copy goes without the other tag of its document, and the
# dry-run says so. Without the tags in Paperless, the copy loses insurance and keeps Holiday.
HOLIDAY_TAG="$(create_tag sync-target Holiday)"
create_tag sync-target 'TAX, 2026' >/dev/null
curl --fail-with-body --silent --show-error --user "sync-target:${PASSWORD}" --request PUT \
	"${BASE_URL}/remote.php/dav/systemtags-relations/files/$(file_id sync-target "${RENAMED_PATH}")/${HOLIDAY_TAG}" >/dev/null
save_settings "${TMP_DIR}/tags-settings.json" nextcloud_tags=1
control /control/scenario/tagged >/dev/null
sync_run true "${TMP_DIR}/tags-dry-run.json"
assert_json tags-dry-run "${TMP_DIR}/tags-dry-run.json"
assert_file_tags 'Holiday:true'
sync_run false "${TMP_DIR}/tags-run.json"
assert_json tags-run "${TMP_DIR}/tags-run.json"
assert_file_tags $'Holiday:true\ninsurance:false'
control /control/scenario/renamed >/dev/null
sync_run false "${TMP_DIR}/tags-removed.json"
assert_json tags-removed "${TMP_DIR}/tags-removed.json"
assert_file_tags 'Holiday:true'
save_settings "${TMP_DIR}/tags-off-settings.json" nextcloud_tags=0

# The metadata next to the archive: with the setting on, a run writes the sidecar of P123, with
# the names of its correspondent and document type, its note and its custom field, and the index
# of the archive, both in the hidden folder .paperless. The copy in the deleted folder keeps its
# sidecar in the steps below, and the deletion of the document takes it along.
SIDECAR_PATH='Documents/Paperless/Archive/.paperless/P123.json'
INDEX_PATH='Documents/Paperless/Archive/.paperless/index.csv'
save_settings "${TMP_DIR}/metadata-settings.json" write_metadata=1
sync_run false "${TMP_DIR}/metadata.json"
assert_json metadata-run "${TMP_DIR}/metadata.json"
curl --fail-with-body --silent --show-error \
	--user "sync-target:${PASSWORD}" \
	--output "${TMP_DIR}/sidecar.json" \
	"${DAV_BASE}/sync-target/${SIDECAR_PATH}"
assert_json sidecar "${TMP_DIR}/sidecar.json"
assert_dav_file sync-target "${INDEX_PATH}" '123,Monthly invoice corrected,Example GmbH,Invoice,'
assert_dav_file sync-target "${INDEX_PATH}" ',Example GmbH/Invoice/2026/2026-08-26 - Monthly invoice corrected [P123].pdf,active,'

control /control/scenario/trash >/dev/null
sync_run false "${TMP_DIR}/trash.json"
assert_json trash "${TMP_DIR}/trash.json"
assert_dav_missing sync-target "${RENAMED_PATH}"
assert_dav_file sync-target "${TRASH_PATH}" 'Synthetic Paperless archive document P123.'
assert_dav_file sync-target "${SIDECAR_PATH}" '"title": "Monthly invoice corrected"'
assert_dav_file sync-target "${INDEX_PATH}" ',_Deleted/2026-08-27/Example GmbH/Invoice/2026/2026-08-26 - Monthly invoice corrected [P123].pdf,trash,'

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
# Deletion off: the document that Paperless purged from its trash would be deleted in this run,
# but with permanent deletion off it keeps its copy. A dry-run shows it without changing the
# state, so that the next run deletes the copy with permanent deletion on again. The setting
# changes through the settings page, as an administrator changes it: after a change through occ,
# the runs of the web server still saw the old value.
save_settings "${TMP_DIR}/deletion-off-settings.json" permanent_delete=0
sync_run true "${TMP_DIR}/deletion-off.json"
save_settings "${TMP_DIR}/deletion-on-settings.json" permanent_delete=1
assert_json deletion-off "${TMP_DIR}/deletion-off.json"
assert_dav_file sync-target "${TRASH_PATH}" 'Synthetic Paperless archive document P123.'
trash_names sync-target > "${TMP_DIR}/trash-before-deleted.txt"
sync_run false "${TMP_DIR}/deleted.json"
assert_json deleted "${TMP_DIR}/deleted.json"
assert_dav_missing sync-target "${TRASH_PATH}"
assert_trashed sync-target "${COPY_NAME}" "${TMP_DIR}/trash-before-deleted.txt"
# The sidecar went with the copy, and the index has no row for the document any more.
assert_dav_missing sync-target "${SIDECAR_PATH}"
assert_dav_file sync-target "${INDEX_PATH}" 'id,title,correspondent'
if assert_dav_file sync-target "${INDEX_PATH}" 'Monthly invoice corrected'; then
	echo 'The index of the metadata still lists the deleted document P123.' >&2
	exit 1
fi
save_settings "${TMP_DIR}/metadata-off-settings.json" write_metadata=0

# The subfolders of a file of the inbox become its tags, with a tag for every import: the
# mock has a tag insurance, in lower case, and none named Scanned or 2026, which the run
# creates. Test connection checks the permissions of the tags first.
for folder in 'Documents/Paperless/Inbox/Insurance' 'Documents/Paperless/Inbox/Insurance/2026'; do
	MKCOL_STATUS="$(dav_status sync-target MKCOL "${folder}")"
	if [[ "${MKCOL_STATUS}" != "201" && "${MKCOL_STATUS}" != "405" ]]; then
		echo "Unexpected WebDAV MKCOL status: ${MKCOL_STATUS}" >&2
		exit 1
	fi
done
settings_data inbox_folder_tags=1 inbox_create_tags=1 'inbox_tags=Scanned'
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	"${settings[@]}" \
	--data-urlencode 'token=e2e-only-token' \
	--output "${TMP_DIR}/inbox-tags-checklist.json" \
	"${BASE_URL}/apps/paperless_sync/settings/test"
assert_json inbox-tags-checklist "${TMP_DIR}/inbox-tags-checklist.json"
save_settings "${TMP_DIR}/inbox-tags-settings.json" inbox_folder_tags=1 inbox_create_tags=1 'inbox_tags=Scanned'
assert_json inbox-tags-settings "${TMP_DIR}/inbox-tags-settings.json"
dav_upload sync-target 'Documents/Paperless/Inbox/Insurance/2026/policy.pdf' '%PDF-1.4 Synthetic inbox PDF policy.'
sync_run false "${TMP_DIR}/import-submitted.json"
assert_json import-submitted "${TMP_DIR}/import-submitted.json"
assert_json import-tags "${TMP_DIR}/import-submitted.json"
mock_state > "${TMP_DIR}/mock-tags.json"
assert_json mock-tags "${TMP_DIR}/mock-tags.json"
save_settings "${TMP_DIR}/inbox-tags-off-settings.json" inbox_folder_tags=0 inbox_create_tags=0 'inbox_tags='
control /control/tasks/success >/dev/null
sync_run false "${TMP_DIR}/import-success.json"
assert_json import-success "${TMP_DIR}/import-success.json"
assert_dav_missing sync-target 'Documents/Paperless/Inbox/Insurance/2026/policy.pdf'

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
assert_status_soon paperless "${TMP_DIR}/status-paperless-2.json" 2.20.15
control /control/paperless/3.3.0 >/dev/null

# Paperless refuses a file of a type it doesn't take. The file stays in the inbox, and
# the runs after it don't upload it again until it changes.
dav_upload sync-target 'Documents/Paperless/Inbox/notes.bin' 'Synthetic unsupported file.'
sync_run false "${TMP_DIR}/import-rejected.json"
assert_json import-rejected "${TMP_DIR}/import-rejected.json"
sync_run false "${TMP_DIR}/import-waiting.json"
assert_json import-waiting "${TMP_DIR}/import-waiting.json"
assert_dav_file sync-target 'Documents/Paperless/Inbox/notes.bin' 'Synthetic unsupported file.'
# Every import that Paperless finished, failed or refused has an entry of its own.
activities "${TMP_DIR}/activity-imports.json"
assert_json activity-imports "${TMP_DIR}/activity-imports.json"
# The target user hears of every import that failed or was refused in a notification as well.
notifications sync-target "${TMP_DIR}/notifications-imports.json"
assert_json notifications-imports "${TMP_DIR}/notifications-imports.json"

assert_status_soon status "${TMP_DIR}/status.json"
occ paperless_sync:status --output=json --exit-code > "${TMP_DIR}/status-occ.json"
assert_json status "${TMP_DIR}/status-occ.json"

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

# Pages that shift while a run reads them, one document deleted and another one added, keep
# the count of Paperless, so a scan looks complete without a document that Paperless still
# has. The run asks Paperless for it before it counts a missing scan, and the copy stays
# without one.
control /control/left-out/123 >/dev/null
sync_run false "${TMP_DIR}/left-out.json"
control /control/left-out/none >/dev/null
assert_json left-out "${TMP_DIR}/left-out.json"
assert_dav_file sync-target "${RENAMED_PATH}" 'Synthetic Paperless archive document P123.'
mock_state > "${TMP_DIR}/mock-left-out.json"
assert_json mock-left-out "${TMP_DIR}/mock-left-out.json"

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

# "Send to Paperless" in the menu of Files: e2e-admin, a member of the group admin of the
# setting, sends a file of their own, which stays where it is. The background job uploads it
# with the next run of cron, follows its task and tells the sender in a notification that
# Paperless has it, with a link to the document; e2e-other may not send. files.mjs then clicks
# the actions in the browser.
send_files() {
	curl --silent --show-error \
		--user "$1:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'Content-Type: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data "{\"fileIds\": [$3]}" \
		--output "$2" \
		--write-out '%{http_code}' \
		"${BASE_URL}/apps/paperless_sync/api/v1/send"
}
dav_upload e2e-admin 'receipt.pdf' '%PDF-1.4 Synthetic inbox PDF receipt.'
dav_upload e2e-admin 'Invoice%20%5BP123%5D.pdf' '%PDF-1.4 Synthetic copy of P123.'
dav_upload e2e-other 'letter.pdf' '%PDF-1.4 Synthetic letter.'
RECEIPT_ID="$(file_id e2e-admin 'receipt.pdf')"
SEND_STATUS="$(send_files e2e-other "${TMP_DIR}/send-other.json" "${RECEIPT_ID}")"
if [[ "${SEND_STATUS}" != "403" ]]; then
	echo "A user outside the group of the setting could send a file: HTTP ${SEND_STATUS}, $(cat "${TMP_DIR}/send-other.json")" >&2
	exit 1
fi
SEND_STATUS="$(send_files e2e-admin "${TMP_DIR}/send.json" "${RECEIPT_ID}")"
if [[ "${SEND_STATUS}" != "200" ]] || ! grep --fixed-strings 'Paperless Sync sends 1 file to Paperless.' "${TMP_DIR}/send.json" >/dev/null; then
	echo "Send to Paperless did not queue the file: HTTP ${SEND_STATUS}, $(cat "${TMP_DIR}/send.json")" >&2
	exit 1
fi
SEND_JOB_ID="$(occ background-job:list --class='OCA\PaperlessSync\Cron\SendJob' --output=json | json_value 0 id)"
occ background-job:execute --force-execute "${SEND_JOB_ID}" >/dev/null
mock_state > "${TMP_DIR}/mock-sent.json"
assert_json mock-sent "${TMP_DIR}/mock-sent.json"
control /control/task-of/receipt.pdf/success >/dev/null
occ background-job:execute --force-execute "${SEND_JOB_ID}" >/dev/null
notifications e2e-admin "${TMP_DIR}/notifications-sent.json"
assert_json notifications-sent "${TMP_DIR}/notifications-sent.json"
assert_dav_file e2e-admin 'receipt.pdf' 'Synthetic inbox PDF receipt.'
echo "Send to Paperless passes after ${SECONDS} s."

# "Send to Paperless" in Flow: e2e-admin saves a rule through the API of Flow that sends every
# file whose name starts with scan- when it is created, with the tags Private and insurance of
# the rule; a rule with a tag that Paperless Sync can't send is refused. e2e-other, who may not
# send from Files, uploads a file that the rule matches and one that it doesn't. The background
# job uploads the first with the tags of the rule, the file stays where it is, and its owner
# hears nothing of an import that worked. flow.mjs then edits the rule in the settings of Flow.
flow_rule() {
	# The rule as the settings of Flow save it, with the operation of the argument, a JSON string.
	curl --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'Content-Type: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data '{"class": "OCA\\PaperlessSync\\Operation\\SendToPaperless", "name": "Scans", "checks": [{"class": "OCA\\WorkflowEngine\\Check\\FileName", "operator": "matches", "value": "/^scan-/i"}], "operation": '"$2"', "entity": "OCA\\WorkflowEngine\\Entity\\File", "events": ["\\OCP\\Files::postCreate"]}' \
		--output "$1" \
		--write-out '%{http_code}' \
		"${BASE_URL}/ocs/v2.php/apps/workflowengine/api/v1/workflows/global"
}
FLOW_STATUS="$(flow_rule "${TMP_DIR}/flow-comma.json" '"{\"tags\": [\"Tax, 2026\"]}"')"
if [[ "${FLOW_STATUS}" != "400" ]] || ! grep --fixed-strings 'hold a comma or a line break: Tax, 2026' "${TMP_DIR}/flow-comma.json" >/dev/null; then
	echo "Flow saved a rule with a tag that Paperless Sync can't send: HTTP ${FLOW_STATUS}, $(cat "${TMP_DIR}/flow-comma.json")" >&2
	exit 1
fi
FLOW_STATUS="$(flow_rule "${TMP_DIR}/flow.json" '"{\"tags\": [\"Private\", \"insurance\"]}"')"
if [[ "${FLOW_STATUS}" != "200" ]]; then
	echo "Flow did not save the rule of Send to Paperless: HTTP ${FLOW_STATUS}, $(cat "${TMP_DIR}/flow.json")" >&2
	exit 1
fi
dav_upload e2e-other 'scan-insurance.pdf' '%PDF-1.4 Synthetic inbox PDF scan of an insurance letter.'
dav_upload e2e-other 'holiday.pdf' '%PDF-1.4 Synthetic holiday plan.'
occ background-job:execute --force-execute "${SEND_JOB_ID}" >/dev/null
mock_state > "${TMP_DIR}/mock-flow.json"
assert_json mock-flow "${TMP_DIR}/mock-flow.json"
control /control/task-of/scan-insurance.pdf/success >/dev/null
occ background-job:execute --force-execute "${SEND_JOB_ID}" >/dev/null
notifications e2e-other "${TMP_DIR}/notifications-flow.json"
assert_json notifications-flow "${TMP_DIR}/notifications-flow.json"
assert_dav_file e2e-other 'scan-insurance.pdf' 'Synthetic inbox PDF scan'
echo "Send to Paperless in Flow passes after ${SECONDS} s."

# The administration settings and the report of a dry-run over a larger archive meet
# WCAG 2.1 AA.
if [[ "${E2E_ACCESSIBILITY}" == "1" ]]; then
	control /control/scenario/archive >/dev/null
	accessibility
	echo "The accessibility check passes after ${SECONDS} s."
fi

# The setup of the account of the app with the token of a superuser of Paperless: the app
# creates the account with the permissions of the saved modules, saves the settings with its
# token and answers the checklist, in which nothing fails. A second setup changes the user
# only with update. The token of the superuser reaches no answer, which assert_json.py
# checks, not the configuration of the app, and no line of the log, which assert_log.py
# checks below. Then the runs go on with the token of the account of the README.
account_setup() {
	output="$1"
	shift
	curl --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--request POST \
		--data-urlencode 'superuserToken=e2e-superuser-only-token' \
		--data-urlencode 'username=e2e-app' \
		"$@" \
		--output "${output}" \
		--write-out '%{http_code}' \
		"${BASE_URL}/apps/paperless_sync/settings/account"
}
ACCOUNT_STATUS="$(account_setup "${TMP_DIR}/account-created.json")"
if [[ "${ACCOUNT_STATUS}" != "200" ]]; then
	echo "The setup of the account answered HTTP ${ACCOUNT_STATUS}: $(cat "${TMP_DIR}/account-created.json")" >&2
	exit 1
fi
assert_json account-created "${TMP_DIR}/account-created.json"
ACCOUNT_STATUS="$(account_setup "${TMP_DIR}/account-exists.json")"
if [[ "${ACCOUNT_STATUS}" != "409" ]]; then
	echo "A second setup of the account changed the user without update: HTTP ${ACCOUNT_STATUS}, $(cat "${TMP_DIR}/account-exists.json")" >&2
	exit 1
fi
assert_json account-exists "${TMP_DIR}/account-exists.json"
ACCOUNT_STATUS="$(account_setup "${TMP_DIR}/account-updated.json" --data-urlencode 'update=1')"
if [[ "${ACCOUNT_STATUS}" != "200" ]]; then
	echo "The update of the account answered HTTP ${ACCOUNT_STATUS}: $(cat "${TMP_DIR}/account-updated.json")" >&2
	exit 1
fi
assert_json account-updated "${TMP_DIR}/account-updated.json"
if occ config:list paperless_sync --private | grep --fixed-strings e2e-superuser-only-token >/dev/null; then
	echo "The configuration of the app holds the token of the superuser of Paperless." >&2
	exit 1
fi
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'token=e2e-only-token' \
	--output "${TMP_DIR}/account-restored.json" \
	"${BASE_URL}/apps/paperless_sync/settings"
echo "The setup of the account in Paperless passes after ${SECONDS} s."

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

# A tag to mirror that Paperless doesn't have, a typo here, stops the export before a file
# changes, as the run would otherwise remove the copies of the documents that should stay. It
# comes after assert_log.py as well, because the failed run logs its error.
save_settings "${TMP_DIR}/included.json" included_tags=Nextclod
INCLUDED_CODE=0
occ paperless_sync:run --dry-run > "${TMP_DIR}/included-missing.txt" 2>&1 || INCLUDED_CODE=$?
save_settings "${TMP_DIR}/included.json" included_tags=
if [[ "${INCLUDED_CODE}" != "2" ]] || ! grep --fixed-strings 'Paperless has no tag named "Nextclod"' "${TMP_DIR}/included-missing.txt" >/dev/null; then
	echo "A tag to mirror that Paperless doesn't have must stop the run, but it ended with exit code ${INCLUDED_CODE}: $(cat "${TMP_DIR}/included-missing.txt")" >&2
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
	--data-urlencode 'dryRun=true' \
	--output "${TMP_DIR}/unreachable.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/sync/run")"
# On the command line, the run says on standard error why it failed, and its exit code and the
# status tell a script and a monitoring check that it failed.
UNREACHABLE_CODE=0
occ paperless_sync:run > "${TMP_DIR}/unreachable-occ.txt" 2>&1 || UNREACHABLE_CODE=$?
UNREACHABLE_STATE_CODE=0
occ paperless_sync:status --exit-code >/dev/null || UNREACHABLE_STATE_CODE=$?
"${COMPOSE[@]}" up --detach --wait --wait-timeout 60 paperless-mock >/dev/null
if [[ "${UNREACHABLE_STATUS}" != "500" ]]; then
	echo "A dry-run without Paperless answered HTTP ${UNREACHABLE_STATUS} instead of 500: $(cat "${TMP_DIR}/unreachable.json")" >&2
	exit 1
fi
if [[ "${UNREACHABLE_CODE}" != "2" || "${UNREACHABLE_STATE_CODE}" != "2" ]] \
	|| ! grep --fixed-strings 'The synchronization failed: ' "${TMP_DIR}/unreachable-occ.txt" >/dev/null; then
	echo "occ paperless_sync:run without Paperless ended with exit code ${UNREACHABLE_CODE} and the status with ${UNREACHABLE_STATE_CODE} instead of 2: $(cat "${TMP_DIR}/unreachable-occ.txt")" >&2
	exit 1
fi
if [[ "$(log_count 'Paperless synchronization failed')" == "0" ]]; then
	echo 'The log of Nextcloud holds no failed run, so the check of the token below would prove nothing.' >&2
	exit 1
fi
# PHP puts a SensitiveParameterValue in place of each argument marked #[\SensitiveParameter],
# which Nextcloud 35 and before writes into the log as it is, and Nextcloud 36 as the text
# "*** sensitive parameters replaced ***", taking the value out of every other frame as well.
# Without either in the log, PHP left out the arguments of every frame
# (zend.exception_ignore_args=On, as in php.ini-production), and the count of the token below
# would pass without the fix. Nextcloud writes that text alone in place of all the arguments of
# some functions of its own as well, also when PHP left them out, so only the text after another
# argument of its frame counts.
if [[ "$(log_count SensitiveParameterValue)" == "0" \
	&& "$(log_count ',"*** sensitive parameters replaced ***"')" == "0" ]]; then
	echo 'The log of Nextcloud holds no argument replaced by PHP, so the check of the token below would prove nothing.' >&2
	exit 1
fi
TOKEN_IN_LOG="$(log_count e2e-only-token)"
if [[ "${TOKEN_IN_LOG}" != "0" ]] || grep --fixed-strings e2e-only-token "${TMP_DIR}/unreachable.json" "${TMP_DIR}/unreachable-occ.txt" >/dev/null; then
	echo "A run without Paperless put the token into its answer, its output or ${TOKEN_IN_LOG} line(s) of the log of Nextcloud." >&2
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

# A mirror that keeps failing tells the administrators. The run above without Paperless was the
# first of a row of runs that failed; after the third, every administrator has one notification,
# the setup check of the app names the error, and the status API reports it to a monitor, but
# not to a user who is no administrator. The next run that succeeds removes the notification.
"${COMPOSE[@]}" stop paperless-mock >/dev/null
for attempt in 2 3; do
	FAILING_CODE=0
	occ paperless_sync:run > "${TMP_DIR}/failing-${attempt}.txt" 2>&1 || FAILING_CODE=$?
	if [[ "${FAILING_CODE}" != "2" ]]; then
		echo "Run ${attempt} without Paperless ended with exit code ${FAILING_CODE} instead of 2: $(cat "${TMP_DIR}/failing-${attempt}.txt")" >&2
		exit 1
	fi
done
"${COMPOSE[@]}" up --detach --wait --wait-timeout 60 paperless-mock >/dev/null
notifications e2e-admin "${TMP_DIR}/notifications-failing.json"
assert_json notifications-failing "${TMP_DIR}/notifications-failing.json"
assert_status_api_soon status-api-failing "${TMP_DIR}/status-api-failing.json"
USER_API_STATUS="$(status_api sync-target "${TMP_DIR}/status-api-user.json")"
if [[ "${USER_API_STATUS}" != "403" ]]; then
	echo "The status API answered HTTP ${USER_API_STATUS} instead of 403 to a user who is no administrator: $(cat "${TMP_DIR}/status-api-user.json")" >&2
	exit 1
fi
# occ setupchecks fails while a check fails, which is the point here.
occ setupchecks >"${TMP_DIR}/setupchecks.txt" 2>&1 || true
if ! grep --fixed-strings 'runs of Paperless Sync failed, the last one with:' "${TMP_DIR}/setupchecks.txt" >/dev/null; then
	echo "occ setupchecks does not name the failing mirror:" >&2
	cat "${TMP_DIR}/setupchecks.txt" >&2
	exit 1
fi
sync_run false "${TMP_DIR}/recovered.json"
assert_json recovered "${TMP_DIR}/recovered.json"
notifications e2e-admin "${TMP_DIR}/notifications-recovered.json"
assert_json notifications-recovered "${TMP_DIR}/notifications-recovered.json"
assert_status_api_soon status-api-recovered "${TMP_DIR}/status-api-recovered.json"

# Per-user ownership (#84): each user of Nextcloud gets the documents that their user of
# Paperless owns, in their own files below the same base folder, and the copies that the target
# user had of them leave its archive. The Paperless user E2E-Other gets e2e-other, whatever the
# case, and e2e-owner a user of its own. The file that e2e-owner puts into their inbox becomes
# theirs in Paperless, and comes back into their archive. The other users see none of it.
"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
	php occ user:add --password-from-env e2e-owner >/dev/null
control /control/scenario/per-user
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'settings[ownership]=per-user' \
	--output "${TMP_DIR}/checklist-per-user.json" \
	"${BASE_URL}/apps/paperless_sync/settings/test"
assert_json checklist-per-user "${TMP_DIR}/checklist-per-user.json"
save_settings "${TMP_DIR}/per-user-settings.json" ownership=per-user
# The copies leave the archive of the target user alone (assert_json per-user).
# The trash bin of each user who can lose a copy, from before the run.
for trash_user in sync-target e2e-other e2e-owner; do
	trash_names "${trash_user}" > "${TMP_DIR}/trash-before-per-user-${trash_user}.txt"
done
sync_run false "${TMP_DIR}/per-user.json"
assert_json per-user "${TMP_DIR}/per-user.json"
# Each copy is in the files of its user alone, and the copies that left are gone: the user and
# the path, for WebDAV, of each change of a type in a report.
per_user_files() {
	type="$1"
	"${COMPOSE[@]}" exec -T paperless-mock python -c '
import json, sys
from urllib.parse import quote
for entry in json.load(sys.stdin)["entries"]:
    if entry["type"] == sys.argv[1]:
        print(entry["user"], quote(entry["path"]))' "${type}" < "$2"
}
# The helpers set user and path themselves, so the loops name theirs otherwise.
while read -r copy_user copy_path; do
	assert_dav_file "${copy_user}" "${copy_path}" 'Synthetic Paperless archive document'
	for other_user in sync-target e2e-other e2e-owner; do
		if [[ "${other_user}" != "${copy_user}" ]]; then
			assert_dav_missing "${other_user}" "${copy_path}"
		fi
	done
done < <(per_user_files export "${TMP_DIR}/per-user.json")
while read -r copy_user copy_path; do
	assert_dav_missing "${copy_user}" "${copy_path}"
	# The copy that left the archive of the target user is in its trash bin.
	assert_trashed "${copy_user}" "$(basename -- "$(printf '%b' "${copy_path//%/\\x}")")" "${TMP_DIR}/trash-before-per-user-${copy_user}.txt"
done < <(per_user_files remove-reassigned "${TMP_DIR}/per-user.json")
dav_upload e2e-owner 'Documents/Paperless/Inbox/owner-letter.pdf' '%PDF-1.4 Synthetic letter of e2e-owner'
sync_run false "${TMP_DIR}/per-user-upload.json"
control /control/task-of/owner-letter.pdf/success
# The run gives the new document its owner, and its export brings it into the archive of e2e-owner.
trash_names e2e-owner > "${TMP_DIR}/trash-before-per-user-sent.txt"
sync_run false "${TMP_DIR}/per-user-sent.json"
mock_state > "${TMP_DIR}/mock-owner-changes.json"
assert_json mock-owner-changes "${TMP_DIR}/mock-owner-changes.json"
assert_dav_missing e2e-owner 'Documents/Paperless/Inbox/owner-letter.pdf'
# The file of the inbox of e2e-owner is in the trash bin of e2e-owner after its import, although
# the run set up the files of the target user first.
assert_trashed e2e-owner 'owner-letter.pdf' "${TMP_DIR}/trash-before-per-user-sent.txt"
SENT_DOCUMENT="$(json_value ownerChanges 0 document < "${TMP_DIR}/mock-owner-changes.json")"
assert_json per-user-sent "${TMP_DIR}/per-user-sent.json" "${SENT_DOCUMENT}"
save_settings "${TMP_DIR}/single-settings.json" ownership=single

checks='a Paperless in the local network without allow_local_remote_servers, the connection checklist of accounts with too few permissions, dry-run, export, metadata moves with the SHA-256 checksums of Paperless-ngx 3 and the MD5 checksums of 2.x, a file that keeps its ID, its share and a version through a new content, the background job, the webhook of a Paperless workflow with its secret and the throttling of wrong ones, exclusion, deletions into the trash bin of the user of each copy, the tags of Paperless as tags of Nextcloud beside those of a user, trash, guarded deletion, inbox subfolders as tags with tags that it creates, inbox success/failure/duplicate/revocation/refusal in the task shapes of Paperless-ngx 2.20 and 3.x, Send to Paperless from Files and by a rule of Flow with its tags, the entries of the Activity stream, "Synchronize now" as a background job, runs, the status and the exit codes of occ, API version 9 in every request and a Paperless-ngx without it, pruning, restore, conflicts, vanished documents, long and unusual names, access isolation, per-user ownership with the owners of Paperless and the inbox of each user'
if [[ "${E2E_ACCESSIBILITY}" == "1" ]]; then
	checks="${checks}, accessibility, the settings in the browser"
fi
echo "Docker E2E passed on ${DATABASE_NAME} with ${STORAGE_NAME}: ${checks}, a run without Paperless that keeps the token out of the log, a URL changed past the settings page that does not get the token, and a failing mirror that notifies the administrators, shows in the setup checks and the status API, and clears after a success."
