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
# affected=false to $GITHUB_OUTPUT, and variants=true or variants=false: whether the
# change can also behave differently on another database or storage, so that
# run-all.sh runs PostgreSQL, MariaDB and the object store as well.
# scripts/check-project.sh sources it to test its rules.

# A file whose change can make the end-to-end tests pass or fail: the code, the
# metadata and the assets of the app, the tests themselves and their workflow.
# appinfo/info.xml counts only with info_xml_change_affects_app.
path_affects_app() {
	case "$1" in
		tests/e2e/*.md) return 1 ;;
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
		tests/e2e/*.md) return 1 ;;
		lib/Migration/* | lib/Service/SyncStateRepository* | lib/Service/NextcloudStorage*) return 0 ;;
		lib/Service/PaperlessApiService.php | tests/e2e/* | .github/workflows/e2e.yml) return 0 ;;
		*) return 1 ;;
	esac
}

# A line of appinfo/info.xml that the release bot writes in every release: <version>,
# and the lines that it marks with x-release-please-version, such as the addresses of
# the screenshots.
release_line() {
	local version='^[[:space:]]*<version>[^<]*</version>[[:space:]]*$'
	[[ "$1" =~ ${version} || "$1" == *x-release-please-version* ]]
}

# Whether a change of appinfo/info.xml can affect the app, from its diff on standard
# input (git diff --unified=0): it can, if it changes a line that the release bot
# doesn't write, such as max-version, which the upstream bot raises.
info_xml_change_affects_app() {
	local line hunk=false affects=1
	while IFS= read -r line; do
		case "${line}" in
			'diff --git '*) hunk=false ;;
			'@@ '*) hunk=true ;;
			[-+]*)
				if [[ "${hunk}" == true ]] && ! release_line "${line:1}"; then
					affects=0
				fi
				;;
		esac
	done
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

decide() {
	if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
		printf 'affected=%s\nvariants=%s\n' "$1" "$2" >>"${GITHUB_OUTPUT}"
	fi
}

if ! git rev-parse --quiet --verify "${BASE}^{commit}" >/dev/null ||
	! git rev-parse --quiet --verify "${HEAD}^{commit}" >/dev/null; then
	echo "The commits ${BASE} and ${HEAD} aren't both here, so all end-to-end tests run."
	decide true true
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
while IFS= read -r -d '' path; do
	count=$((count + 1))
	if [[ "${path}" == appinfo/info.xml ]]; then
		info="$(git diff --unified=0 --no-color --no-ext-diff "${BASE}" "${HEAD}" -- appinfo/info.xml)"
		if info_xml_change_affects_app <<<"${info}"; then
			reaching+=("${path}")
			varying+=("${path}")
		fi
	elif path_affects_app "${path}"; then
		reaching+=("${path}")
		if path_affects_variants "${path}"; then
			varying+=("${path}")
		fi
	fi
done <"${CHANGED}"

if ((${#reaching[@]} > 0)); then
	echo "${#reaching[@]} of the ${count} changed file(s) can affect the app, so the end-to-end tests run:"
	printf '  %s\n' "${reaching[@]:0:20}"
	if ((${#reaching[@]} > 20)); then
		echo "  and $((${#reaching[@]} - 20)) more"
	fi
	if ((${#varying[@]} > 0)); then
		echo "${#varying[@]} of them can behave differently on another database or storage, so PostgreSQL, MariaDB and the object store run as well."
		decide true true
	else
		message="None of them can behave differently on another database or storage, so the tests run on SQLite with local storage only; main runs PostgreSQL, MariaDB and the object store after the merge, and every night."
		echo "${message}"
		if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
			printf '### E2E on SQLite with local storage only

%s
' "${message}" >>"${GITHUB_STEP_SUMMARY}"
		fi
		decide true false
	fi
	exit 0
fi

message="None of the ${count} changed file(s) can affect the app: no code, metadata or asset of the app, no end-to-end test and not their workflow, and in appinfo/info.xml at most the version of a release. The end-to-end tests can't fail here, so they don't run; main runs them after the merge, and every night."
echo "${message}"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	printf '### E2E skipped: the change cannot affect the app\n\n%s\n' "${message}" >>"${GITHUB_STEP_SUMMARY}"
fi
decide false false
