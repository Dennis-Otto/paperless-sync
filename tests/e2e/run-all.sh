#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The end-to-end tests against the current release of every Nextcloud version that
# appinfo/info.xml supports, from min-version to max-version, side by side: run.sh
# for each version at once, with the image nextcloud:<version>-apache, in a Compose
# project and on a port of its own. The downloads of one version overlap with the
# installation of another, so that all take little longer than one. When all are
# done, it prints the log of each version and fails if one of them failed.
# .github/workflows/e2e.yml runs it.
#
#   bash tests/e2e/run-all.sh
#
# E2E_VERSIONS="33 35" chooses the versions instead; KEEP_E2E=1 keeps the
# containers of each, as with run.sh.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
LOG_DIR="$(mktemp -d)"
trap 'rm -rf "${LOG_DIR}"' EXIT

versions=()
if [[ -n "${E2E_VERSIONS:-}" ]]; then
	read -ra versions <<<"${E2E_VERSIONS}"
else
	nextcloud="$(grep -oE '<nextcloud [^>]*>' "${REPOSITORY_ROOT}/appinfo/info.xml" | head -n 1 || true)"
	low="$(sed -nE 's/.* min-version="([0-9]+)[^"]*".*/\1/p' <<<"${nextcloud}")"
	high="$(sed -nE 's/.* max-version="([0-9]+)[^"]*".*/\1/p' <<<"${nextcloud}")"
	if [[ -z "${low}" || -z "${high}" ]]; then
		echo "appinfo/info.xml names no min-version and max-version of Nextcloud." >&2
		exit 1
	fi
	for ((version = low; version <= high; version++)); do
		versions+=("${version}")
	done
fi
for version in "${versions[@]}"; do
	if [[ ! "${version}" =~ ^[0-9]+$ ]]; then
		echo "${version} is no major version of Nextcloud, such as 33." >&2
		exit 1
	fi
done
if ((${#versions[@]} == 0)); then
	echo "No version of Nextcloud to test." >&2
	exit 1
fi

# One version, with its log and its result in LOG_DIR: the exit code of run.sh and
# the seconds it took.
run() {
	local version="$1" started="${SECONDS}" status=0
	NEXTCLOUD_IMAGE="nextcloud:${version}-apache" \
		E2E_PORT="$((18080 + version))" \
		E2E_PROJECT_NAME="paperless_sync_e2e_${version}" \
		bash "${SCRIPT_DIR}/run.sh" >"${LOG_DIR}/${version}.log" 2>&1 || status=$?
	echo "${status} $((SECONDS - started))" >"${LOG_DIR}/${version}.result"
}

duration() {
	if (($1 >= 60)); then
		echo "$(($1 / 60)) min $(($1 % 60)) s"
	else
		echo "$1 s"
	fi
}

echo "The end-to-end tests run against Nextcloud ${versions[*]} side by side; their logs follow when all are done."
pids=()
for version in "${versions[@]}"; do
	run "${version}" &
	pids+=("$!")
	echo "  Nextcloud ${version}: ${LOG_DIR}/${version}.log"
done

failed=()
rows=()
for index in "${!versions[@]}"; do
	version="${versions[index]}"
	wait "${pids[index]}" || true
	status=1
	seconds=0
	if [[ -f "${LOG_DIR}/${version}.result" ]]; then
		read -r status seconds <"${LOG_DIR}/${version}.result"
	fi
	release="$(sed -nE 's/^The tests run against Nextcloud ([^ ]+)\.$/\1/p' "${LOG_DIR}/${version}.log" | head -n 1)"
	if [[ "${status}" == 0 ]]; then
		result=passed
	else
		result=failed
		failed+=("${version}")
	fi
	rows+=("| ${version} | ${release:-?} | ${result} | $(duration "${seconds}") |")

	title="Nextcloud ${version} (${release:-?}): ${result} after $(duration "${seconds}")"
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::group::${title}"
		cat "${LOG_DIR}/${version}.log"
		echo "::endgroup::"
		if [[ "${result}" == failed ]]; then
			echo "::error title=Docker E2E::The end-to-end tests fail against Nextcloud ${version}; the group \"${title}\" of the log shows why."
		fi
	else
		printf '\n== %s\n' "${title}"
		cat "${LOG_DIR}/${version}.log"
	fi
done

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "### Docker E2E"
		echo
		echo "| Nextcloud | Release | Result | Time |"
		echo "| --- | --- | --- | --- |"
		printf '%s\n' "${rows[@]}"
	} >>"${GITHUB_STEP_SUMMARY}"
fi

printf '\n'
printf '%s\n' "${rows[@]}"
if ((${#failed[@]} > 0)); then
	echo "The end-to-end tests fail against Nextcloud ${failed[*]}; E2E_VERSIONS=\"${failed[*]}\" bash tests/e2e/run-all.sh runs them again." >&2
	exit 1
fi
echo "The end-to-end tests pass against Nextcloud ${versions[*]}."
