#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Checks the package of the app that krankerl builds, before and after signing:
#
#   bash scripts/check-package.sh [archive.tar.gz] [unsigned|signed]
#
# The package holds exactly the runtime files that Git tracks in HEAD, the commit that
# krankerl clones, each with its content there, and a signed package its signature
# besides. A missing file, a stray one, a changed one or a link fails with the list of
# the differences. The runtime paths are those of this script and not of
# .nextcloudignore, so that a change of .nextcloudignore or of krankerl that drops a
# template, a translation or a migration fails here instead of on the servers of the
# users. Then every PHP file and every script of the package must parse.
#
# scripts/check.sh runs it on the package of every pull request, the release workflow
# on the package of a release before and after signing, and
# tests/package/test_check_package.sh tests it.

set -euo pipefail

APP_ID="paperless_sync"
# What Nextcloud loads of the app, and the three files that the App Store and the
# administrators read. Everything else that Git tracks stays out of the package.
RUNTIME_PATHS=(appinfo css img js l10n lib templates CHANGELOG.md LICENSE README.md)
# The file that signing adds, which a signed package needs and an unsigned one lacks.
SIGNATURE="appinfo/signature.json"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCHIVE="${1:-${ROOT}/build/artifacts/${APP_ID}.tar.gz}"
MODE="${2:-unsigned}"

if [[ "${MODE}" != "unsigned" && "${MODE}" != "signed" ]]; then
	echo "Usage: $0 [archive.tar.gz] [unsigned|signed]" >&2
	exit 2
fi

if [[ ! -s "${ARCHIVE}" ]]; then
	echo "Package does not exist or is empty: ${ARCHIVE}" >&2
	exit 1
fi
ARCHIVE="$(cd "$(dirname "${ARCHIVE}")" && pwd)/$(basename "${ARCHIVE}")"
cd "${ROOT}"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
ENTRY_LIST="${WORK}/entries.txt"
APP_ROOT="${WORK}/package/${APP_ID}"
mkdir "${WORK}/expected" "${WORK}/package"

tar --list --gzip --file "${ARCHIVE}" > "${ENTRY_LIST}"

while IFS= read -r entry; do
	if [[ -z "${entry}" || "${entry}" == /* || "/${entry}/" == *"/../"* ]]; then
		echo "Unsafe package entry: ${entry}" >&2
		exit 1
	fi
done < "${ENTRY_LIST}"

# Regular files and folders alone: a link could point anywhere on the server, and
# neither Nextcloud nor the App Store needs a device or a pipe.
special="$(tar --list --verbose --gzip --file "${ARCHIVE}" | awk '$1 !~ /^[-d]/')"
if [[ -n "${special}" ]]; then
	echo "Production package must hold only regular files and folders, no symbolic or hard links:" >&2
	echo "${special}" >&2
	exit 1
fi

tar --extract --gzip --file "${ARCHIVE}" --directory "${WORK}/package"

# The signature, which no commit holds, aside; the rest of the package is compared with
# HEAD.
if [[ "${MODE}" == "signed" ]]; then
	if [[ ! -s "${APP_ROOT}/${SIGNATURE}" ]]; then
		echo "Signed package lacks its signature: ${APP_ID}/${SIGNATURE}" >&2
		exit 1
	fi
	mv "${APP_ROOT}/${SIGNATURE}" "${WORK}/signature.json"
elif [[ -e "${APP_ROOT}/${SIGNATURE}" ]]; then
	echo "Unsigned package already holds a signature: ${APP_ID}/${SIGNATURE}" >&2
	echo "Sign the package only after this check, or check a signed package as signed." >&2
	exit 1
fi

# Each file below $3 of the side $2, expected or package, as "$1 <path>", a folder
# without files as "$1 <path>/".
list_files() {
	local file
	while IFS= read -r file; do
		if [[ -d "${WORK}/$2/${file}" ]]; then
			file="${file}/"
		fi
		echo "  $1 ${file}"
	done < <(cd "${WORK}/$2" && find "$3" ! -type d -o -type d -empty | LC_ALL=C sort)
}

# The lines of diff -r -q as one line per file: missing, stray or changed.
describe() {
	local line folder name path
	while IFS= read -r line; do
		case "${line}" in
			"Only in "*": "*)
				line="${line#Only in }"
				folder="${line%%: *}"
				name="${line#*: }"
				path="${folder#*/}"
				if [[ "${path}" == "${folder}" ]]; then
					path="${name}"
				else
					path="${path}/${name}"
				fi
				if [[ "${folder%%/*}" == expected ]]; then
					list_files "missing:" expected "${path}"
				else
					list_files "stray:  " package "${path}"
				fi
				;;
			"Files expected/"*" and package/"*" differ")
				line="${line#Files expected/}"
				echo "  changed: ${line%% and package/*}"
				;;
			*)
				echo "  ${line}"
				;;
		esac
	done
}

# Every runtime path is one of HEAD, or git archive stops without saying which.
for path in "${RUNTIME_PATHS[@]}"; do
	if ! git cat-file -e "HEAD:${path}" 2>/dev/null; then
		echo "RUNTIME_PATHS of scripts/check-package.sh lists ${path}, which HEAD doesn't hold." >&2
		echo "Remove ${path} there if the app no longer has it, or commit it if the app needs it." >&2
		exit 1
	fi
done

# The runtime files of HEAD, as krankerl would package them, side by side with the
# package: any file or folder in only one of them, and any file with other content,
# is a difference.
git archive --format=tar --prefix="${APP_ID}/" HEAD -- "${RUNTIME_PATHS[@]}" |
	tar --extract --file - --directory "${WORK}/expected"
if ! differences="$(cd "${WORK}" && LC_ALL=C diff -r -q expected package)"; then
	{
		echo "The ${MODE} package differs from the runtime files that Git tracks in HEAD (${RUNTIME_PATHS[*]}):"
		describe <<<"${differences}"
		echo "missing: in HEAD, but not in the package; stray: in the package, but no runtime file of HEAD;"
		echo "changed: in the package with other content than in HEAD."
		echo "krankerl leaves out what .nextcloudignore lists: it must keep every runtime file and leave out every"
		echo "other one. A new folder or file that the app needs at runtime belongs in RUNTIME_PATHS of"
		echo "scripts/check-package.sh as well."
	} >&2
	exit 1
fi

# PHP receives its own argv at runtime.
# shellcheck disable=SC2016
if [[ "${MODE}" == "signed" ]] &&
	! php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "${WORK}/signature.json"; then
	echo "The signature of the signed package is no valid JSON: ${APP_ID}/${SIGNATURE}" >&2
	exit 1
fi

if ! find "${APP_ROOT}" -type f -name '*.php' -print0 | xargs -0 -n1 php -l; then
	echo "A PHP file of the package doesn't parse." >&2
	exit 1
fi
if ! find "${APP_ROOT}" -type f -name '*.js' -print0 | xargs -0 -n1 node --check; then
	echo "A script of the package doesn't parse." >&2
	exit 1
fi

FILE_COUNT="$(find "${APP_ROOT}" -type f | wc -l | tr -d ' ')"
if [[ "${MODE}" == "signed" ]]; then
	echo "Validated signed production package with exactly the ${FILE_COUNT} runtime files of HEAD and their signature: ${ARCHIVE}"
else
	echo "Validated unsigned production package with exactly the ${FILE_COUNT} runtime files of HEAD: ${ARCHIVE}"
fi
