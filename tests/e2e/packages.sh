#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The two packages of the app that the upgrade test installs one after the other
# (upgrade.sh): that of the last release, as the App Store gave it to the users, and that
# of this commit, as the next release will ship it.
#
#   bash tests/e2e/packages.sh release DIR   # DIR/release/paperless_sync.tar.gz
#   bash tests/e2e/packages.sh commit DIR    # DIR/commit/paperless_sync.tar.gz
#   bash tests/e2e/packages.sh info PACKAGE  # 1.2.0 33 35
#
# Each of the first two prints the path of its package; info prints the version of a
# package and the major versions of Nextcloud from its min-version to its max-version.
# The release is paperless_sync.tar.gz of the latest release of E2E_RELEASE_REPOSITORY, by
# default Dennis-Otto/paperless-sync, signed as the App Store has it. The package of the commit is krankerl's, as build-release.yml builds
# it, when krankerl is installed, as in the CI. Without krankerl, it holds the runtime
# files of HEAD that scripts/check-package.sh names, which is what krankerl packs: that
# script checks it in every pull request. Both take HEAD, not the working tree, so commit
# a change before the upgrade test can see it.

set -euo pipefail

APP_ID="paperless_sync"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

usage() {
	echo "Usage: $0 release|commit DIR, or $0 info PACKAGE" >&2
	exit 2
}
if (($# != 2)); then
	usage
fi
KIND="$1"
DIR="$2"

case "${KIND}" in
	info)
		info="$(tar --extract --gzip --to-stdout --file - "${APP_ID}/appinfo/info.xml" <"${DIR}")"
		version="$(sed -nE 's|^[[:space:]]*<version>([^<]+)</version>.*|\1|p' <<<"${info}")"
		nextcloud="$(grep -oE '<nextcloud [^>]*>' <<<"${info}" || true)"
		low="$(sed -nE 's/.* min-version="([0-9]+)[^"]*".*/\1/p' <<<"${nextcloud}")"
		high="$(sed -nE 's/.* max-version="([0-9]+)[^"]*".*/\1/p' <<<"${nextcloud}")"
		if [[ -z "${version}" || -z "${low}" || -z "${high}" ]]; then
			echo "The appinfo/info.xml of ${DIR} names no version, min-version or max-version of Nextcloud." >&2
			exit 1
		fi
		echo "${version} ${low} ${high}"
		exit 0
		;;
	release)
		mkdir -p "${DIR}/release"
		package="${DIR}/release/${APP_ID}.tar.gz"
		repository="${E2E_RELEASE_REPOSITORY:-Dennis-Otto/paperless-sync}"
		# Into a file, never into a pipe: Scorecard counts a download that a program reads
		# from a pipe as code that runs without a pinned hash.
		curl --fail --location --silent --show-error --retry 5 --retry-all-errors \
			--connect-timeout 15 --max-time 300 --output "${package}" \
			"https://github.com/${repository}/releases/latest/download/${APP_ID}.tar.gz"
		;;
	commit)
		mkdir -p "${DIR}/commit"
		package="${DIR}/commit/${APP_ID}.tar.gz"
		cd "${REPOSITORY_ROOT}"
		if command -v krankerl >/dev/null 2>&1; then
			# krankerl clones HEAD and packs it without what .nextcloudignore lists.
			krankerl package >&2
			cp "build/artifacts/${APP_ID}.tar.gz" "${package}"
		else
			# The runtime paths of scripts/check-package.sh, the same list that it compares
			# the package of krankerl with.
			paths="$(sed -nE 's/^RUNTIME_PATHS=\((.*)\)$/\1/p' scripts/check-package.sh)"
			if [[ -z "${paths}" ]]; then
				echo "scripts/check-package.sh names no RUNTIME_PATHS=(…) to pack." >&2
				exit 1
			fi
			read -ra runtime <<<"${paths}"
			echo "krankerl is not installed here, so the package holds the runtime files of HEAD that scripts/check-package.sh names, as krankerl's does." >&2
			git archive --format=tar.gz --prefix="${APP_ID}/" --output="${package}" HEAD -- "${runtime[@]}"
		fi
		;;
	*) usage ;;
esac

# From standard input, so that tar takes no drive letter, such as C:, for a host.
if ! tar --list --gzip --file - "${APP_ID}/appinfo/info.xml" <"${package}" >/dev/null 2>&1; then
	echo "The package ${package} holds no ${APP_ID}/appinfo/info.xml." >&2
	exit 1
fi
echo "${package}"
