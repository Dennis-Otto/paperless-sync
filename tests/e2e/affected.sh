#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Tells whether a change can affect the app, which the end-to-end tests of
# .github/workflows/e2e.yml ask in a pull request before they start any container: a
# change of the documentation or of other workflows alone, or a release, which only
# writes its version, can't make them fail, and the required check passes at once.
# It compares two commits, by default the merge commit of the pull request with its
# base:
#
#   bash tests/e2e/affected.sh [BASE [HEAD]]
#
# It names the changed files that reach the app and writes affected=true or
# affected=false to $GITHUB_OUTPUT, variants=true or variants=false: whether the
# change can also behave differently on another database or storage, so that
# run-all.sh runs PostgreSQL, MariaDB and the object store as well, and upgrade=true or
# upgrade=false: whether it can break the upgrade of an installation in use from the last
# release, so that run-all.sh runs that on PostgreSQL and MariaDB too (upgrade.sh). With
# E2E_QUESTION=contract, it asks instead whether the change can alter what the app asks
# Paperless or how it reads the answers, and writes contract=true or contract=false, for
# the contract tests against a real Paperless-ngx (run-contract.sh).
# scripts/check-project.sh sources it to test its rules.

# A file whose change can make the end-to-end tests pass or fail: the code, the
# metadata and the assets of the app, the tests themselves and their workflow. The
# contract tests against a real Paperless are no part of them, and neither are the
# scripts of the pictures alone, which the visual check renders (visual.yml).
# appinfo/info.xml counts only with info_xml_change_affects_app.
path_affects_app() {
	case "$1" in
		tests/e2e/*.md | tests/e2e/contract.py | tests/e2e/run-contract.sh | tests/e2e/compose.paperless.yaml) return 1 ;;
		tests/e2e/screenshots.* | tests/e2e/frozen.* | tests/e2e/compare.*) return 1 ;;
		lib/* | appinfo/* | templates/* | js/* | css/* | l10n/* | img/* | composer.json | tests/e2e/*) return 0 ;;
		.github/workflows/e2e.yml) return 0 ;;
		*) return 1 ;;
	esac
}

# A file whose change can behave differently on another database or storage than
# SQLite and local storage: the migrations and the queries of the state, the files in
# Nextcloud, the upload of a file of the inbox, whose stream on an object store has no
# size (Dennis-Otto/paperless-sync#73), and the tests themselves and their workflow.
# appinfo/info.xml, such as a new max-version, counts as for path_affects_app.
path_affects_variants() {
	case "$1" in
		tests/e2e/*.md | tests/e2e/screenshots.* | tests/e2e/frozen.* | tests/e2e/compare.*) return 1 ;;
		lib/Migration/* | lib/Service/SyncStateRepository* | lib/Service/NextcloudStorage*) return 0 ;;
		lib/Service/PaperlessApiService.php | tests/e2e/* | .github/workflows/e2e.yml) return 0 ;;
		*) return 1 ;;
	esac
}

# A file whose change can break the upgrade of an installation in use from the last release
# (upgrade.sh): the migrations, which meet the rows of the release there alone, the settings
# and the state that the release stored, the secret of the webhook, what goes into the package
# that the upgrade installs, and the upgrade test itself and its workflow; not the checks in
# the browser or the contract tests, which it doesn't run. appinfo/info.xml counts as for
# path_affects_app.
path_affects_upgrade() {
	case "$1" in
		tests/e2e/*.md | tests/e2e/*.mjs | tests/e2e/package.json | tests/e2e/package-lock.json) return 1 ;;
		tests/e2e/screenshots.* | tests/e2e/contract.py | tests/e2e/run-contract.sh | tests/e2e/compose.paperless.yaml) return 1 ;;
		lib/Migration/* | lib/Service/ConfigService.php | lib/Service/SyncStateRepository* | lib/Model/SyncConfig.php) return 0 ;;
		lib/Service/WebhookService.php | .nextcloudignore | krankerl.toml | scripts/check-package.sh) return 0 ;;
		tests/e2e/* | .github/workflows/e2e.yml) return 0 ;;
		*) return 1 ;;
	esac
}

# A file whose change can alter what the app asks Paperless or how it reads the answers:
# the client of Paperless, the connection that it takes and the checksums that it compares,
# the mock that stands in for Paperless and the answers recorded from it, which the unit
# tests read, and the contract tests themselves and their workflow.
path_affects_contract() {
	case "$1" in
		lib/Service/Paperless* | lib/Model/DocumentFingerprint.php | lib/Model/PaperlessConnection.php) return 0 ;;
		tests/fixtures/paperless-ngx-*) return 0 ;;
		tests/e2e/contract.py | tests/e2e/run-contract.sh | tests/e2e/compose.yaml | tests/e2e/compose.paperless.yaml) return 0 ;;
		tests/e2e/mock_server.py | .github/workflows/e2e.yml) return 0 ;;
		*) return 1 ;;
	esac
}

# A line with each version in it, such as the 1.1.1 of a tag v1.1.1 in an address,
# written as X.Y.Z.
without_versions() {
	local line="$1" rest="" version='([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?(\+[0-9A-Za-z.-]+)?)'
	while [[ "${line}" =~ ${version} ]]; do
		rest+="${line%%"${BASH_REMATCH[1]}"*}X.Y.Z"
		line="${line#*"${BASH_REMATCH[1]}"}"
	done
	printf '%s' "${rest}${line}"
}

# Whether a line of appinfo/info.xml, with its versions written as X.Y.Z, is one that
# the release bot writes: <version>, a line that it marks with x-release-please-version,
# or an address at a tag, such as that of a screenshot or of its thumbnail. A version
# elsewhere, such as the min-version of PHP or of Nextcloud, is not.
release_line() {
	local version='^[[:space:]]*<version>X\.Y\.Z</version>[[:space:]]*$'
	[[ "$1" =~ ${version} || "$1" == *x-release-please-version* || "$1" == *://*/vX.Y.Z/* ]]
}

# Whether a change of appinfo/info.xml can affect the app, from its diff on standard
# input (git diff --unified=0): it can, unless each hunk only writes other versions into
# lines that the release bot writes (release_line), as it does in <version> and in the
# addresses of the screenshots, both of a screenshot and of its thumbnail, which it marks with
# x-release-please-version or keeps between x-release-please-start-version and
# x-release-please-end. A line that it adds or removes, or a change of anything but a
# version, such as max-version, which the upstream bot raises, can.
info_xml_change_affects_app() {
	local line hunk=false affects=1 next=0
	local -a removed=()
	while IFS= read -r line; do
		case "${line}" in
			'diff --git '* | '@@ '*)
				if ((next < ${#removed[@]})); then
					affects=0
				fi
				removed=()
				next=0
				if [[ "${line}" == '@@ '* ]]; then
					hunk=true
				else
					hunk=false
				fi
				;;
			-*)
				if [[ "${hunk}" == true ]]; then
					removed+=("$(without_versions "${line:1}")")
				fi
				;;
			+*)
				if [[ "${hunk}" == true ]]; then
					if ((next >= ${#removed[@]})) || [[ "$(without_versions "${line:1}")" != "${removed[next]}" ]] ||
						! release_line "${removed[next]}"; then
						affects=0
					fi
					next=$((next + 1))
				fi
				;;
		esac
	done
	if ((next < ${#removed[@]})); then
		affects=0
	fi
	return "${affects}"
}

# Sourced, it only defines the rules.
if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then
	return 0
fi

set -euo pipefail
cd "$(dirname -- "${BASH_SOURCE[0]}")/../.."

BASE="${1:-HEAD^1}"
HEAD="${2:-HEAD}"
QUESTION="${E2E_QUESTION:-app}"

# The answers for the end-to-end tests, affected, variants and upgrade, or with
# E2E_QUESTION=contract the fourth one alone.
decide() {
	if [[ -z "${GITHUB_OUTPUT:-}" ]]; then
		return
	elif [[ "${QUESTION}" == contract ]]; then
		printf 'contract=%s\n' "$4" >>"${GITHUB_OUTPUT}"
	else
		printf 'affected=%s\nvariants=%s\nupgrade=%s\n' "$1" "$2" "$3" >>"${GITHUB_OUTPUT}"
	fi
}

summary() {
	if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
		printf '### %s\n\n%s\n' "$1" "$2" >>"${GITHUB_STEP_SUMMARY}"
	fi
}

if ! git rev-parse --quiet --verify "${BASE}^{commit}" >/dev/null ||
	! git rev-parse --quiet --verify "${HEAD}^{commit}" >/dev/null; then
	echo "The commits ${BASE} and ${HEAD} aren't both here, so all end-to-end tests run."
	decide true true true true
	exit 0
fi

# Names with a line break or quotes stay intact between NUL bytes. --no-renames names
# both sides of a move, such as a file that leaves lib/.
CHANGED="$(mktemp)"
trap 'rm -f "${CHANGED}"' EXIT
git diff --name-only -z --no-renames "${BASE}" "${HEAD}" >"${CHANGED}"

count=0
reaching=()
varying=()
upgrading=()
contracting=()
while IFS= read -r -d '' path; do
	count=$((count + 1))
	if [[ "${path}" == appinfo/info.xml ]]; then
		info="$(git diff --unified=0 --no-color --no-ext-diff "${BASE}" "${HEAD}" -- appinfo/info.xml)"
		if info_xml_change_affects_app <<<"${info}"; then
			reaching+=("${path}")
			varying+=("${path}")
			upgrading+=("${path}")
		fi
	else
		if path_affects_app "${path}"; then
			reaching+=("${path}")
			if path_affects_variants "${path}"; then
				varying+=("${path}")
			fi
		fi
		if path_affects_upgrade "${path}"; then
			upgrading+=("${path}")
		fi
	fi
	if path_affects_contract "${path}"; then
		contracting+=("${path}")
	fi
done <"${CHANGED}"

if [[ "${QUESTION}" == contract ]]; then
	if ((${#contracting[@]} > 0)); then
		echo "${#contracting[@]} of the ${count} changed file(s) can alter how the app talks to Paperless, so the contract tests against a real Paperless-ngx run:"
		printf '  %s\n' "${contracting[@]:0:20}"
		decide true true true true
	else
		message="None of the ${count} changed file(s) can alter what the app asks Paperless or how it reads the answers: not its client of Paperless, the mock, the recorded answers or the contract tests. They can't fail here, so they don't run; they run every Monday against the current releases of Paperless-ngx."
		echo "${message}"
		summary "Contract tests skipped: the change cannot alter how the app talks to Paperless" "${message}"
		decide true true true false
	fi
	exit 0
fi

upgrade=false
if ((${#upgrading[@]} > 0)); then
	upgrade=true
fi
if ((${#reaching[@]} > 0)); then
	echo "${#reaching[@]} of the ${count} changed file(s) can affect the app, so the end-to-end tests run:"
	printf '  %s\n' "${reaching[@]:0:20}"
	if ((${#reaching[@]} > 20)); then
		echo "  and $((${#reaching[@]} - 20)) more"
	fi
	if ((${#varying[@]} > 0)); then
		echo "${#varying[@]} of them can behave differently on another database or storage, so PostgreSQL, MariaDB and the object store run as well."
		variants=true
	else
		message="None of them can behave differently on another database or storage, so the tests run on SQLite with local storage only; main runs PostgreSQL, MariaDB and the object store after the merge, and every night."
		echo "${message}"
		summary "E2E on SQLite with local storage only" "${message}"
		variants=false
	fi
	if [[ "${upgrade}" == true ]]; then
		echo "${#upgrading[@]} of them can break the upgrade of an installation in use, so the upgrade from the last release runs on PostgreSQL and MariaDB as well."
	else
		echo "None of them can break the upgrade of an installation in use from the last release; main runs it after the merge, and every night."
	fi
	decide true "${variants}" "${upgrade}"
	exit 0
fi

if [[ "${upgrade}" == true ]]; then
	message="None of the ${count} changed file(s) can affect the app as it runs, but ${#upgrading[@]} can change the package that users install, so the upgrade from the last release runs on PostgreSQL and MariaDB, and the other end-to-end tests don't."
	echo "${message}"
	printf '  %s\n' "${upgrading[@]:0:20}"
	summary "E2E: the upgrade from the last release only" "${message}"
	decide false false true
	exit 0
fi

message="None of the ${count} changed file(s) can affect the app: no code, metadata or asset of the app, no end-to-end test and not their workflow, and in appinfo/info.xml at most the version of a release. The end-to-end tests can't fail here, so they don't run; main runs them after the merge, and every night."
echo "${message}"
summary "E2E skipped: the change cannot affect the app" "${message}"
decide false false false
