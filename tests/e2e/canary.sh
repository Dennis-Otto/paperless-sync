#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The end-to-end tests against the coming Nextcloud, which the weekly canary of
# .github/workflows/e2e.yml runs. The coming Nextcloud is the newest beta or release
# candidate of the next major version while Nextcloud publishes one, and otherwise the
# daily build of its master branch. Both come from download.nextcloud.com, signed with
# the release key of Nextcloud, and replace the code in the image of the newest
# release, which keeps its PHP, Apache and start script. run.sh enables the app with
# --force, as max-version of appinfo/info.xml doesn't name that version yet; that
# doesn't make the app compatible with it.

set -euo pipefail

# A relative folder is one of the directory that the script started in.
[[ -z "${E2E_ARTIFACTS:-}" || "${E2E_ARTIFACTS}" == /* ]] || E2E_ARTIFACTS="${PWD}/${E2E_ARTIFACTS}"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
# The newest release of Nextcloud, whose image takes the coming code.
BASE_IMAGE="${CANARY_BASE_IMAGE:-nextcloud:apache}"
IMAGE="${CANARY_IMAGE:-paperless-sync-e2e:coming}"
DOWNLOADS="https://download.nextcloud.com/server"
# The key that signs every package of Nextcloud, which the official image checks too.
RELEASE_KEY="28806A878AE423A28372792ED75899B9A724937A"
TMP_DIR="$(mktemp -d)"

# The trap of EXIT below runs it, which ShellCheck doesn't follow past the exits at the end.
# shellcheck disable=SC2317,SC2329
cleanup() {
	# Only the agent of the keyring of the canary: before it, gpgconf would stop the agent of
	# your own keys.
	if [[ "${GNUPGHOME:-}" == "${TMP_DIR}/gnupg" ]]; then
		gpgconf --kill all >/dev/null 2>&1 || true
	fi
	rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

# The coming Nextcloud comes from the servers of Nextcloud, not from a release on GitHub, so
# curl downloads it without a token: .github/download.sh is for the asset of a release on
# GitHub, through its API (the blueprint's decision 0133, which leaves the downloads of other
# hosts to curl), and the token of the job goes to no other host. curl tries again after
# every error, a reset connection too, and after a stall, as the blueprint's decision 0011
# has it: 15 seconds to connect, 30 seconds below 1 KB a second, 5 minutes for a file, up to
# six times, 1, 2, 4, 8 and 16 seconds apart, and no new attempt after 10 minutes, well
# within the 30 minutes of the job. When every attempt fails, the canary stops with the error
# of curl and a line that says what happened, and with E2E_ARTIFACTS keeps both as the log of
# a failed run, so that tests/e2e/failure.py names that line as the first error in the issue
# of the canary.
fetch() {
	if curl --fail --location --silent --show-error --retry 5 --retry-all-errors --retry-max-time 600 \
		--connect-timeout 15 --speed-limit 1024 --speed-time 30 --max-time 300 --remote-time \
		--output "$2" "$1" 2>"${TMP_DIR}/curl.log"; then
		return 0
	fi
	# failure.py cuts a first error after 300 characters, so the line stays short enough for
	# the longest address, that of the signature of a prerelease.
	echo "Nextcloud's server didn't deliver $1 in up to six attempts: a timeout or 502, 503 or 504 is an outage, run the canary again later; a 404 means Nextcloud moved the file." >>"${TMP_DIR}/curl.log"
	cat "${TMP_DIR}/curl.log" >&2
	if [[ -n "${E2E_ARTIFACTS:-}" ]]; then
		mkdir -p "${E2E_ARTIFACTS}/coming"
		cp "${TMP_DIR}/curl.log" "${E2E_ARTIFACTS}/coming/run.log"
		echo "The coming Nextcloud, which the canary couldn't download" >"${E2E_ARTIFACTS}/coming/title.txt"
	fi
	exit 1
}

# This is PHP code, not a shell expression.
# shellcheck disable=SC2016
VERSION_CODE='require "/usr/src/nextcloud/version.php"; echo $OC_VersionString;'

"${DOCKER_BIN}" pull --quiet "${BASE_IMAGE}" >/dev/null
released="$("${DOCKER_BIN}" run --rm --entrypoint php "${BASE_IMAGE}" -r "${VERSION_CODE}")"
released_major="${released%%.*}"

# The newest beta or release candidate of a major version above the newest release.
fetch "${DOWNLOADS}/prereleases/" "${TMP_DIR}/prereleases.html"
prerelease="$(grep -oE 'nextcloud-[0-9]+\.0\.0(beta|rc)[0-9]+\.tar\.bz2"' "${TMP_DIR}/prereleases.html" |
	tr -d '"' | sort -u |
	sed -E 's/^nextcloud-([0-9]+)\.0\.0(beta|rc)([0-9]+)\.tar\.bz2$/\1 \2 \3 &/' |
	awk -v released="${released_major}" '$1 > released { print $1, ($2 == "rc" ? 2 : 1), $3, $4 }' |
	sort -k1,1n -k2,2n -k3,3n | tail -n 1 | cut -d ' ' -f 4 || true)"
if [[ -n "${prerelease}" ]]; then
	package="${DOWNLOADS}/prereleases/${prerelease}"
else
	package="${DOWNLOADS}/daily/latest-master.tar.bz2"
fi

mkdir "${TMP_DIR}/context"
archive="${TMP_DIR}/context/nextcloud.tar.bz2"
fetch "${package}" "${archive}"
fetch "${package}.asc" "${TMP_DIR}/nextcloud.tar.bz2.asc"
fetch https://nextcloud.com/nextcloud.asc "${TMP_DIR}/nextcloud.asc"
export GNUPGHOME="${TMP_DIR}/gnupg"
mkdir "${GNUPGHOME}"
chmod 700 "${GNUPGHOME}" 2>/dev/null || true
gpg --batch --quiet --import "${TMP_DIR}/nextcloud.asc" 2>/dev/null
if ! gpg --batch --status-fd 1 --verify "${TMP_DIR}/nextcloud.tar.bz2.asc" "${archive}" 2>/dev/null |
	grep -E "^\[GNUPG:\] VALIDSIG ${RELEASE_KEY} " >/dev/null; then
	echo "${package} carries no valid signature of the release key ${RELEASE_KEY}." >&2
	exit 1
fi
built="$(date -u -r "${archive}" +%Y-%m-%d)"

# The code of the coming Nextcloud replaces that of the image; the configuration the
# image adds for Docker stays.
"${DOCKER_BIN}" build --quiet --tag "${IMAGE}" --build-arg "BASE_IMAGE=${BASE_IMAGE}" \
	--file - "${TMP_DIR}/context" >/dev/null <<'DOCKERFILE'
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
COPY nextcloud.tar.bz2 /tmp/nextcloud.tar.bz2
RUN set -eux; \
	mv /usr/src/nextcloud/config /tmp/image-config; \
	rm -rf /usr/src/nextcloud; \
	tar -xjf /tmp/nextcloud.tar.bz2 -C /usr/src/; \
	rm -rf /tmp/nextcloud.tar.bz2 /usr/src/nextcloud/updater /tmp/image-config/config.sample.php; \
	cp /tmp/image-config/*.php /usr/src/nextcloud/config/; \
	rm -rf /tmp/image-config; \
	mkdir -p /usr/src/nextcloud/data /usr/src/nextcloud/custom_apps; \
	chmod +x /usr/src/nextcloud/occ
DOCKERFILE

version="$("${DOCKER_BIN}" run --rm --entrypoint php "${IMAGE}" -r "${VERSION_CODE}")"
label="Nextcloud ${version} (${package##*/} of ${built})"
echo "The coming Nextcloud: ${label}, in the image of Nextcloud ${released}."
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
	echo "version=${label}" >>"${GITHUB_OUTPUT}"
fi

if [[ -z "${E2E_ARTIFACTS:-}" ]]; then
	NEXTCLOUD_IMAGE="${IMAGE}" E2E_IGNORE_MAX_VERSION=1 bash "${SCRIPT_DIR}/run.sh"
	exit 0
fi

# With E2E_ARTIFACTS, a failure leaves its evidence in the folder coming of it, as a run of
# run-all.sh does: its log, run.log, its title, title.txt, and what run.sh keeps, so that
# tests/e2e/failure.py names its first error in the issue of the canary.
evidence="${E2E_ARTIFACTS}/coming"
status=0
# Nobody waits for the canary, and its issue stays: its traces keep the snapshots of the page.
NEXTCLOUD_IMAGE="${IMAGE}" E2E_IGNORE_MAX_VERSION=1 E2E_ARTIFACTS="${evidence}" E2E_BROWSER_SNAPSHOTS=1 \
	bash "${SCRIPT_DIR}/run.sh" 2>&1 | tee "${TMP_DIR}/run.log" || status=$?
if ((status != 0)); then
	mkdir -p "${evidence}"
	cp "${TMP_DIR}/run.log" "${evidence}/run.log"
	echo "${label}" >"${evidence}/title.txt"
fi
exit "${status}"
