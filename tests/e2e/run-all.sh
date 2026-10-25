#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The end-to-end tests against the current release of every Nextcloud version that
# appinfo/info.xml supports, from min-version to max-version, and against the newest
# of them on other databases and storage, side by side: run.sh for each at once, with
# the image nextcloud:<version>-apache, in a Compose project and on a port of its own.
# The downloads of one overlap with the installation of another, so that all take
# little longer than one. When all are done, it prints the log of each and fails if one
# of them failed. .github/workflows/e2e.yml runs it.
#
#   bash tests/e2e/run-all.sh
#
# Every version runs on SQLite with local storage, as compose.yaml has it. The variants
# run besides, on the newest version: E2E_VARIANTS, by default
# "pgsql mysql+s3 pgsql+upgrade mysql+upgrade", names each by its database, sqlite, mysql
# (MariaDB) or pgsql (PostgreSQL), with +s3 for an object store as primary storage, and
# +upgrade for the upgrade from the last release to the package of the commit instead of
# the scenario (upgrade.sh); E2E_VARIANTS=none runs none. They leave out the accessibility
# check, which every version runs. An upgrade runs on the newest version that the release
# supports as well, as its users have it, and all of them share one download of the release
# and one package of the commit (packages.sh).
#
# E2E_VERSIONS="33 35" chooses the versions instead, and E2E_VERSIONS=none runs only
# the variants, on max-version; the CI runs those in a job of their own, next to the
# versions. KEEP_E2E=1 keeps the containers of each, as with run.sh.
# E2E_VERSION_TIMEOUT, by default 15m, ends a run that hangs before the job of the CI
# ends after 20 minutes, so that the logs of all are still printed.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

# The supported versions, from min-version to max-version of appinfo/info.xml.
supported() {
	local nextcloud low high version
	nextcloud="$(grep -oE '<nextcloud [^>]*>' "${REPOSITORY_ROOT}/appinfo/info.xml" | head -n 1 || true)"
	low="$(sed -nE 's/.* min-version="([0-9]+)[^"]*".*/\1/p' <<<"${nextcloud}")"
	high="$(sed -nE 's/.* max-version="([0-9]+)[^"]*".*/\1/p' <<<"${nextcloud}")"
	if [[ -z "${low}" || -z "${high}" ]]; then
		echo "appinfo/info.xml names no min-version and max-version of Nextcloud." >&2
		return 1
	fi
	for ((version = low; version <= high; version++)); do
		echo "${version}"
	done
}

versions=()
case "${E2E_VERSIONS:-}" in
	"")
		range="$(supported)"
		read -ra versions <<<"${range//$'\n'/ }"
		;;
	none) ;;
	*) read -ra versions <<<"${E2E_VERSIONS}" ;;
esac
newest=0
for version in "${versions[@]}"; do
	if [[ ! "${version}" =~ ^[0-9]+$ ]]; then
		echo "${version} is no major version of Nextcloud, such as 33." >&2
		exit 1
	fi
	if ((version > newest)); then
		newest="${version}"
	fi
done

variants=()
case "${E2E_VARIANTS:-}" in
	"") variants=(pgsql mysql+s3 pgsql+upgrade mysql+upgrade) ;;
	none) ;;
	*) read -ra variants <<<"${E2E_VARIANTS}" ;;
esac
if ((${#versions[@]} + ${#variants[@]} == 0)); then
	echo "No version of Nextcloud to test." >&2
	exit 1
fi
# Without versions, the variants run on max-version.
if ((newest == 0)); then
	range="$(supported)"
	newest="${range##*$'\n'}"
fi

database_name() {
	case "$1" in
		sqlite) echo SQLite ;;
		mysql) echo MariaDB ;;
		pgsql) echo PostgreSQL ;;
	esac
}

LOG_DIR="$(mktemp -d)"
trap 'rm -rf "${LOG_DIR}"' EXIT

# The packages of the upgrade, once for every variant with +upgrade: the last release from
# GitHub and the package of the commit. The upgrade runs on the newest version that both the
# release and the commit support, as the users of the release have it before they update.
release_version=""
upgrade_newest="${newest}"
for variant in "${variants[@]}"; do
	if [[ "${variant}" == *+upgrade && -z "${release_version}" ]]; then
		E2E_RELEASE_PACKAGE="$(bash "${SCRIPT_DIR}/packages.sh" release "${LOG_DIR}/packages")"
		E2E_PACKAGE="$(bash "${SCRIPT_DIR}/packages.sh" commit "${LOG_DIR}/packages")"
		export E2E_RELEASE_PACKAGE E2E_PACKAGE
		read -r release_version release_low release_high <<<"$(bash "${SCRIPT_DIR}/packages.sh" info "${E2E_RELEASE_PACKAGE}")"
		range="$(supported)"
		low="${range%%$'\n'*}"
		if ((release_high < upgrade_newest)); then
			upgrade_newest="${release_high}"
		fi
		if ((upgrade_newest < low || upgrade_newest < release_low)); then
			echo "The last release ${release_version} supports Nextcloud ${release_low} to ${release_high}, and the commit ${low} to ${newest}: no installation can update the app without updating Nextcloud first, so there is no upgrade to test." >&2
			exit 1
		fi
	fi
done

# Every run: its key, which names its log and its Compose project, its version, its
# database and storage, its port, whether it checks accessibility, whether it upgrades
# from the last release, and its title.
keys=() run_versions=() databases=() storages=() ports=() accessibility=() upgrades=() titles=()
add_run() {
	keys+=("$1")
	run_versions+=("$2")
	databases+=("$3")
	storages+=("$4")
	ports+=("$5")
	accessibility+=("$6")
	upgrades+=("$7")
	titles+=("$8")
}
for version in "${versions[@]}"; do
	add_run "${version}" "${version}" sqlite local "$((18080 + version))" 1 0 "Nextcloud ${version}"
done
for index in "${!variants[@]}"; do
	variant="${variants[index]}"
	if [[ ! "${variant}" =~ ^(sqlite|mysql|pgsql)(\+s3)?(\+upgrade)?$ ]]; then
		echo "${variant} is no variant such as pgsql, mysql+s3 or pgsql+upgrade: a database, sqlite, mysql or pgsql, +s3 for an object store and +upgrade for the upgrade from the last release." >&2
		exit 1
	fi
	database="${BASH_REMATCH[1]}"
	storage=local
	version="${newest}"
	upgrade=0
	if [[ -n "${BASH_REMATCH[3]}" ]]; then
		version="${upgrade_newest}"
		upgrade=1
	fi
	title="Nextcloud ${version} on $(database_name "${database}")"
	if [[ -n "${BASH_REMATCH[2]}" ]]; then
		storage=s3
		title="${title} with S3"
	fi
	if ((upgrade)); then
		title="${title}, upgraded from ${release_version}"
	fi
	# Ports of 100 apart, so that they don't meet those of the versions.
	add_run "${version}-${variant//+/-}" "${version}" "${database}" "${storage}" \
		"$((18080 + newest + 100 * (index + 1)))" 0 "${upgrade}" "${title}"
done

# The limit of one run: timeout of coreutils, which Git Bash has too, ends run.sh,
# whose cleanup still shows the state of its containers. Where there is none, as on
# macOS, they run without a limit.
limit=()
if timeout --version >/dev/null 2>&1; then
	limit=(timeout --kill-after=30s "${E2E_VERSION_TIMEOUT:-15m}")
fi

# One run, with its log and its result in LOG_DIR: the exit code of run.sh and the
# seconds it took. Its process ID, of run.sh or of the timeout around it, lets stop()
# end it.
run() {
	local index="$1" key="${keys[$1]}" started="${SECONDS}" status=0
	NEXTCLOUD_IMAGE="nextcloud:${run_versions[index]}-apache" \
		E2E_PORT="${ports[index]}" \
		E2E_PROJECT_NAME="paperless_sync_e2e_${key//-/_}" \
		E2E_DATABASE="${databases[index]}" \
		E2E_OBJECTSTORE="${storages[index]}" \
		E2E_ACCESSIBILITY="${accessibility[index]}" \
		E2E_UPGRADE="${upgrades[index]}" \
		"${limit[@]}" bash "${SCRIPT_DIR}/run.sh" >"${LOG_DIR}/${key}.log" 2>&1 &
	echo "$!" >"${LOG_DIR}/${key}.pid"
	wait "$!" || status=$?
	echo "${status} $((SECONDS - started))" >"${LOG_DIR}/${key}.result"
}

duration() {
	if (($1 >= 60)); then
		echo "$(($1 / 60)) min $(($1 % 60)) s"
	else
		echo "$1 s"
	fi
}

# The log of one run, in a group of its own in the CI.
show() {
	local key="$1" title="$2"
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::group::${title}"
		cat "${LOG_DIR}/${key}.log" 2>/dev/null || true
		echo "::endgroup::"
	else
		printf '\n== %s\n' "${title}"
		cat "${LOG_DIR}/${key}.log" 2>/dev/null || true
	fi
}

# Ctrl-C, or a signal to stop, ends every run, which runs in the background where
# Ctrl-C doesn't reach it: run.sh still cleans up, and the logs that aren't printed
# yet follow before LOG_DIR goes.
stop() {
	local code="$1" file index
	trap - INT TERM
	echo "The end-to-end tests are stopped; the logs so far follow." >&2
	for file in "${LOG_DIR}"/*.pid; do
		if [[ -f "${file}" ]]; then
			kill -TERM "$(<"${file}")" 2>/dev/null || true
		fi
	done
	wait || true
	for ((index = printed; index < ${#keys[@]}; index++)); do
		show "${keys[index]}" "${titles[index]}: stopped"
	done
	exit "${code}"
}

echo "The end-to-end tests run side by side; their logs follow when all are done."
printed=0
trap 'stop 130' INT
trap 'stop 143' TERM
pids=()
for index in "${!keys[@]}"; do
	run "${index}" &
	pids+=("$!")
	echo "  ${titles[index]}: ${LOG_DIR}/${keys[index]}.log"
done

failed=()
rows=()
for index in "${!keys[@]}"; do
	key="${keys[index]}"
	wait "${pids[index]}" || true
	status=1
	seconds=0
	if [[ -f "${LOG_DIR}/${key}.result" ]]; then
		read -r status seconds <"${LOG_DIR}/${key}.result"
	fi
	release="$(sed -nE 's/^The tests run against Nextcloud ([^ ]+) on .*/\1/p' "${LOG_DIR}/${key}.log" | head -n 1)"
	if [[ "${status}" == 0 ]]; then
		result=passed
	elif [[ "${status}" == 124 ]]; then
		# The exit code of timeout when the limit ended run.sh.
		result="timed out"
		failed+=("${index}")
	else
		result=failed
		failed+=("${index}")
	fi
	storage=local
	if [[ "${storages[index]}" == s3 ]]; then
		storage=S3
	fi
	app="the commit"
	if [[ "${upgrades[index]}" == 1 ]]; then
		app="upgraded from ${release_version}"
	fi
	rows+=("| ${run_versions[index]} | $(database_name "${databases[index]}") | ${storage} | ${app} | ${release:-?} | ${result} | $(duration "${seconds}") |")

	title="${titles[index]} (${release:-?}): ${result} after $(duration "${seconds}")"
	show "${key}" "${title}"
	if [[ "${GITHUB_ACTIONS:-}" == "true" && "${result}" != passed ]]; then
		echo "::error title=Docker E2E::The end-to-end tests ${result} against ${titles[index]}; the group \"${title}\" of the log shows why."
	fi
	printed=$((index + 1))
done
trap - INT TERM

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "### Docker E2E"
		echo
		echo "| Nextcloud | Database | Storage | App | Release | Result | Time |"
		echo "| --- | --- | --- | --- | --- | --- | --- |"
		printf '%s\n' "${rows[@]}"
	} >>"${GITHUB_STEP_SUMMARY}"
fi

printf '\n'
printf '%s\n' "${rows[@]}"
if ((${#failed[@]} > 0)); then
	echo "The end-to-end tests fail against:" >&2
	for index in "${failed[@]}"; do
		again="NEXTCLOUD_IMAGE=nextcloud:${run_versions[index]}-apache"
		if [[ "${databases[index]}" != sqlite ]]; then
			again="${again} E2E_DATABASE=${databases[index]}"
		fi
		if [[ "${storages[index]}" != local ]]; then
			again="${again} E2E_OBJECTSTORE=${storages[index]}"
		fi
		if [[ "${upgrades[index]}" == 1 ]]; then
			again="${again} E2E_UPGRADE=1"
		fi
		echo "  ${titles[index]}, again with: ${again} bash tests/e2e/run.sh" >&2
	done
	exit 1
fi
echo "The end-to-end tests pass against $(printf '%s, ' "${titles[@]}" | sed 's/, $//')."
