#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The smoke test of the demo, which the job demo of .github/workflows/e2e.yml runs:
#
#   bash tests/e2e/demo.sh            the app from the App Store, as demo/README.md starts it
#   bash tests/e2e/demo.sh --local    the app of this checkout
#
# It starts demo/compose.yaml, waits for the container init and checks what a person gets:
# an archive with every seeded document that is finished in Paperless, and the generated
# password in demo/.env, which Git ignores and which signs in to Nextcloud and Paperless. A
# second start changes nothing. An archive that stays empty fails the test.

set -euo pipefail

# Git Bash on Windows would turn the paths inside the containers into paths of Windows.
export MSYS2_ARG_CONV_EXCL="${MSYS2_ARG_CONV_EXCL:+${MSYS2_ARG_CONV_EXCL};}/var/"

# The documents of tests/e2e/showcase.py without the one in the inbox of Paperless, which
# the app leaves out; tests/e2e/test_demo.py compares both numbers with that file.
ARCHIVE_FILES=14
PAPERLESS_DOCUMENTS=15

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_sync_demo_test}"
ENV_FILE="${REPOSITORY_ROOT}/demo/.env"
ARCHIVE="/var/www/html/data/demo/files/Documents/Paperless/Archive"
# Ports of their own, beside a demo that someone runs on this machine.
export DEMO_NEXTCLOUD_PORT="${DEMO_NEXTCLOUD_PORT:-18580}"
export DEMO_PAPERLESS_PORT="${DEMO_PAPERLESS_PORT:-18581}"

COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}" --file "${REPOSITORY_ROOT}/demo/compose.yaml")
case "${1:-}" in
	"") ;;
	--local) COMPOSE+=(--file "${REPOSITORY_ROOT}/demo/compose.local.yaml") ;;
	*)
		echo "Usage: bash tests/e2e/demo.sh [--local]" >&2
		exit 2
		;;
esac

# A file that this test made goes with it, one that was here stays.
ENV_WAS_THERE=false
if [[ -f "${ENV_FILE}" ]]; then
	ENV_WAS_THERE=true
fi

# The log of a run in the CI stays free of the password of the demo.
masked() {
	sed -E 's/password [0-9a-f]{32}/password (in demo\/.env)/'
}

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		# The line where tests/e2e/failure.py stops reading: the logs below aren't the test.
		echo "The demo tests fail with exit code ${status}." >&2
		"${COMPOSE[@]}" ps --all || true
		"${COMPOSE[@]}" logs --no-color --tail 100 2>&1 | masked || true
	fi
	if [[ "${KEEP_E2E:-0}" == "1" ]]; then
		echo "Demo kept at http://localhost:${DEMO_NEXTCLOUD_PORT} (project ${PROJECT_NAME})."
	else
		"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 1 >/dev/null 2>&1 || true
		if [[ "${ENV_WAS_THERE}" == false ]]; then
			rm -f "${ENV_FILE}"
		fi
	fi
	exit "${status}"
}
trap cleanup EXIT
trap 'exit 143' TERM

# Starts the demo and waits for its container init, whose exit code decides.
start() {
	local code=0
	"${COMPOSE[@]}" up --detach --quiet-pull
	"${COMPOSE[@]}" wait init >/dev/null || code=$?
	"${COMPOSE[@]}" logs --no-color --no-log-prefix init | masked
	if ((code != 0)); then
		echo "The container init of the demo ended with exit code ${code}." >&2
		exit 1
	fi
}

# The archive that the first run wrote, as Nextcloud keeps it. A folder that isn't there counts
# as an empty archive.
expect_archive() {
	local found count
	found="$("${COMPOSE[@]}" exec -T --user www-data nextcloud find "${ARCHIVE}" -type f -name '*.pdf' || true)"
	count="$(grep --count '\.pdf' <<<"${found}" || true)"
	if [[ "${count}" != "${ARCHIVE_FILES}" ]]; then
		echo "The archive of the demo has ${count:-0} files instead of ${ARCHIVE_FILES}." >&2
		exit 1
	fi
}

password() {
	sed -n 's/^DEMO_PASSWORD=//p' "${ENV_FILE}"
}

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 1 >/dev/null 2>&1 || true

start
expect_archive
echo "The first start passes after ${SECONDS} s."

if ! git check-ignore --quiet demo/.env; then
	echo "Git doesn't ignore demo/.env, which holds the password of the demo." >&2
	exit 1
fi
PASSWORD="$(password)"
if [[ -z "${PASSWORD}" ]]; then
	echo "demo/.env holds no password." >&2
	exit 1
fi
# A person signs in to Nextcloud and Paperless with it, through the ports on this machine.
LISTING="$(mktemp)"
curl --fail-with-body --silent --show-error --user "demo:${PASSWORD}" --request PROPFIND --header 'Depth: 1' \
	--output "${LISTING}" "http://127.0.0.1:${DEMO_NEXTCLOUD_PORT}/remote.php/dav/files/demo/Documents/Paperless/Archive/"
if ! grep --fixed-strings 'Example%20Insurance' "${LISTING}" >/dev/null; then
	echo "The archive of the demo has no folder of the correspondent Example Insurance: $(cat "${LISTING}")" >&2
	exit 1
fi
curl --fail-with-body --silent --show-error --user "admin:${PASSWORD}" --header 'Accept: application/json; version=9' \
	--output "${LISTING}" "http://127.0.0.1:${DEMO_PAPERLESS_PORT}/api/documents/?page_size=1&fields=id"
if ! grep --extended-regexp "\"count\": ?${PAPERLESS_DOCUMENTS}\b" "${LISTING}" >/dev/null; then
	echo "Paperless of the demo doesn't have ${PAPERLESS_DOCUMENTS} documents: $(cat "${LISTING}")" >&2
	exit 1
fi
rm -f "${LISTING}"
echo "The password of demo/.env passes after ${SECONDS} s."

# Another docker compose up: the same password, the same archive, and no documents twice.
start
expect_archive
if [[ "$(password)" != "${PASSWORD}" ]]; then
	echo "The second start of the demo changed the password in demo/.env." >&2
	exit 1
fi
echo "The second start passes after ${SECONDS} s."
