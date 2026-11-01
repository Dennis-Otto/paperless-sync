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
# the variants, on max-version. E2E_SHARD=1 or 2 runs one half of the runs, for the two
# jobs of the CI, which each take one: shard 1 holds the oldest and the newest version,
# shard 2 the variants, and each version between goes, from the oldest, to the shard
# with the lighter load, where a version with its browser checks counts as five, a variant
# as three and an upgrade as two, to 2 when both weigh as much. Both jobs get the same
# versions and variants, so they split them the same way. A shard without anything to run
# passes.
# E2E_PLAN=1 only prints the runs that it would start.
# KEEP_E2E=1 keeps the containers of each, as with run.sh.
# E2E_VERSION_TIMEOUT, by default 15m from its own start, ends a run that hangs before the
# job of the CI ends after 25 minutes, also a run that first waited for a processor, so
# that the logs of all are still printed. The table of the
# results gives the time of each phase of each run, from the lines of its log, so that a
# run that gets slower shows where.
# E2E_PARALLEL, by default the number of processors, limits the runs at once: a run that
# would have to share a processor with another takes longer without saving any time, so the
# next one starts when one ends, in the order of the plan, which has the lightest last.
# E2E_MEMORY_FOLDER, a folder in memory such as /dev/shm, keeps the files and the database of
# SQLite of each run there instead of on the disk; the CI sets it.
# E2E_ARTIFACTS, a folder, gets a folder for each run that failed, named by its key, such
# as 35 or 35-pgsql-upgrade, with its log, run.log, its title, title.txt, and the evidence
# that run.sh keeps; tests/e2e/failure.py names the first error of each from there.
# The run on the newest version measures the coverage of the JavaScript of the app in the
# checks in the browser (E2E_COVERAGE=1 of run.sh), whose table follows that of the runs in
# the summary of the job; its folder in E2E_ARTIFACTS gets the reports, also when it passes.

set -euo pipefail

# A relative folder is one of the directory that the script started in.
[[ -z "${E2E_ARTIFACTS:-}" || "${E2E_ARTIFACTS}" == /* ]] || E2E_ARTIFACTS="${PWD}/${E2E_ARTIFACTS}"

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
oldest=0
for version in "${versions[@]}"; do
	if [[ ! "${version}" =~ ^[0-9]+$ ]]; then
		echo "${version} is no major version of Nextcloud, such as 33." >&2
		exit 1
	fi
	if ((version > newest)); then
		newest="${version}"
	fi
	if ((oldest == 0 || version < oldest)); then
		oldest="${version}"
	fi
done

variants=()
case "${E2E_VARIANTS:-}" in
	"") variants=(pgsql mysql+s3 pgsql+upgrade mysql+upgrade) ;;
	none) ;;
	*) read -ra variants <<<"${E2E_VARIANTS}" ;;
esac

# The shard of the runs: 1 the oldest and the newest version, 2 the variants, and each
# version between to the one with the lighter load. The load of a run is what it costs a
# runner, measured in the phases of the table of the summary: a version counts as five, with
# its browser checks, a variant, which has none, as three, and an upgrade, which runs far less
# of the scenario, as two. The variants still run on the newest of all versions.
LOAD_OF_VERSION=5
load_of_variant() {
	if [[ "$1" == *+upgrade ]]; then
		echo 2
	else
		echo 3
	fi
}
case "${E2E_SHARD:-}" in
	"") ;;
	1 | 2)
		first=()
		second=()
		if ((oldest > 0)); then
			first+=("${oldest}")
		fi
		if ((newest != oldest)); then
			first+=("${newest}")
		fi
		first_load=$((LOAD_OF_VERSION * ${#first[@]}))
		second_load=0
		for variant in "${variants[@]}"; do
			second_load=$((second_load + $(load_of_variant "${variant}")))
		done
		mapfile -t between < <(printf '%s\n' "${versions[@]}" | sort -n -u)
		for version in "${between[@]}"; do
			if [[ -z "${version}" ]] || ((version == oldest || version == newest)); then
				continue
			fi
			if ((second_load <= first_load)); then
				second+=("${version}")
				second_load=$((second_load + LOAD_OF_VERSION))
			else
				first+=("${version}")
				first_load=$((first_load + LOAD_OF_VERSION))
			fi
		done
		if [[ "${E2E_SHARD}" == 1 ]]; then
			mapfile -t versions < <(printf '%s\n' "${first[@]}" | sort -n | grep -v '^$' || true)
			variants=()
		else
			versions=("${second[@]}")
		fi
		;;
	*)
		echo "E2E_SHARD=${E2E_SHARD} is neither 1 nor 2." >&2
		exit 1
		;;
esac
if ((${#versions[@]} + ${#variants[@]} == 0)); then
	if [[ -n "${E2E_SHARD:-}" ]]; then
		# Two supported versions leave nothing between them, and a pull request that can't
		# behave differently on another database runs no variant.
		message="The shard ${E2E_SHARD} has nothing to run: no version of Nextcloud and no variant."
		echo "${message}"
		if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
			printf '### Docker E2E\n\n%s\n' "${message}" >>"${GITHUB_STEP_SUMMARY}"
		fi
		exit 0
	fi
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
# E2E_PLAN=1 downloads nothing: its upgrades start from "the last release", on the newest
# version.
release_version=""
upgrade_newest="${newest}"
if [[ "${E2E_PLAN:-0}" == "1" ]]; then
	release_version="the last release"
fi
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

if [[ "${E2E_PLAN:-0}" == "1" ]]; then
	printf '%s\n' "${titles[@]}"
	exit 0
fi

# The limit of one run: timeout of coreutils, which Git Bash has too, ends run.sh,
# whose cleanup still shows the state of its containers. Where there is none, as on
# macOS, they run without a limit.
limit=()
if timeout --version >/dev/null 2>&1; then
	limit=(timeout --kill-after=30s "${E2E_VERSION_TIMEOUT:-15m}")
fi

# The script of one run, which scripts/check-project.sh replaces to check the limit.
RUN_SCRIPT="${E2E_RUN_SCRIPT:-${SCRIPT_DIR}/run.sh}"
# The runs at once, or 0 without a limit where nothing names the number of processors.
parallel="${E2E_PARALLEL:-$(nproc 2>/dev/null || echo 0)}"

# One run, with its log and its result in LOG_DIR: the exit code of run.sh and the
# seconds it took. Its process ID, of run.sh or of the timeout around it, lets stop()
# end it.
run() {
	local index="$1" key="${keys[$1]}" started="${SECONDS}" status=0 coverage=0
	# The run on the newest version measures the coverage of the JavaScript in the browser.
	if [[ "${key}" == "${newest}" ]]; then
		coverage=1
	fi
	NEXTCLOUD_IMAGE="nextcloud:${run_versions[index]}-apache" \
		E2E_PORT="${ports[index]}" \
		E2E_PROJECT_NAME="paperless_sync_e2e_${key//-/_}" \
		E2E_DATA_FOLDER="${E2E_MEMORY_FOLDER:+${E2E_MEMORY_FOLDER}/paperless_sync_e2e_${key//-/_}}" \
		E2E_DATABASE="${databases[index]}" \
		E2E_OBJECTSTORE="${storages[index]}" \
		E2E_ACCESSIBILITY="${accessibility[index]}" \
		E2E_COVERAGE="${coverage}" \
		E2E_UPGRADE="${upgrades[index]}" \
		E2E_ARTIFACTS="${E2E_ARTIFACTS:+${E2E_ARTIFACTS}/${key}}" \
		"${limit[@]}" bash "${RUN_SCRIPT}" >"${LOG_DIR}/${key}.log" 2>&1 &
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

# The phases of a run as cells of the table, from the seconds that its log names at their
# ends: until its containers run, the setup of Nextcloud and the app, the scenarios, and
# the checks in the browser. A phase that the run didn't reach shows as ?, and the browser
# of a variant, which leaves it out, as –. The scenarios are the rest of the run, also
# those after the browser, and those up to the failure of a run that failed.
phases() {
	local log="$1" total="$2" browsing="$3" containers setup browser scenarios="?"
	containers="$(sed -nE 's/^The containers run after ([0-9]+) s\.$/\1/p' "${log}" 2>/dev/null | head -n 1)"
	setup="$(sed -nE 's/^Nextcloud and the app are set up after ([0-9]+) s\.$/\1/p' "${log}" 2>/dev/null | head -n 1)"
	browser="$(sed -nE 's/^The checks in the browser took ([0-9]+) s\.$/\1/p' "${log}" 2>/dev/null | head -n 1)"
	if [[ -n "${setup}" ]]; then
		scenarios="$(duration "$((total - setup - ${browser:-0}))")"
		if [[ -n "${containers}" ]]; then
			setup="$(duration "$((setup - containers))")"
		fi
	fi
	if [[ -n "${browser}" ]]; then
		browser="$(duration "${browser}")"
	elif [[ "${browsing}" == 0 ]]; then
		browser="–"
	fi
	if [[ -n "${containers}" ]]; then
		containers="$(duration "${containers}")"
	fi
	echo "${containers:-?} | ${setup:-?} | ${scenarios} | ${browser:-?}"
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
	if ((parallel > 0)); then
		while (($(jobs -rp | wc -l) >= parallel)); do
			wait -n || true
		done
	fi
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
	rows+=("| ${run_versions[index]} | $(database_name "${databases[index]}") | ${storage} | ${app} | ${release:-?} | ${result} | $(phases "${LOG_DIR}/${key}.log" "${seconds}" "${accessibility[index]}") | $(duration "${seconds}") |")

	title="${titles[index]} (${release:-?}): ${result} after $(duration "${seconds}")"
	show "${key}" "${title}"
	if [[ -n "${E2E_ARTIFACTS:-}" && "${result}" != passed ]]; then
		mkdir -p "${E2E_ARTIFACTS}/${key}"
		cp "${LOG_DIR}/${key}.log" "${E2E_ARTIFACTS}/${key}/run.log" 2>/dev/null || true
		echo "${title}" >"${E2E_ARTIFACTS}/${key}/title.txt"
	fi
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
		echo "| Nextcloud | Database | Storage | App | Release | Result | Containers | Setup | Scenarios | Browser | Time |"
		echo "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |"
		printf '%s\n' "${rows[@]}"
	} >>"${GITHUB_STEP_SUMMARY}"
	# The coverage of the JavaScript in the browser, of the run on the newest version.
	for key in "${keys[@]}"; do
		if [[ -n "${E2E_ARTIFACTS:-}" && -f "${E2E_ARTIFACTS}/${key}/coverage/summary.md" ]]; then
			{
				echo
				cat "${E2E_ARTIFACTS}/${key}/coverage/summary.md"
			} >>"${GITHUB_STEP_SUMMARY}"
		fi
	done
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
