#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The tests of scripts/check-package.sh, in a few seconds and without krankerl, which
# scripts/check-project.sh runs: in a throwaway Git repository with a small app, whose
# package they pack as krankerl does, files alone, and as the release workflow packs
# it again after signing, with its folders. The package of that app passes; a package
# that lacks a runtime file, or holds a stray one, a changed one, a link, an unsafe
# entry or the wrong signature, fails and names the difference, and so does a runtime
# path of the check that HEAD doesn't hold.
#
#   bash tests/package/test_check_package.sh
#
# The cases that get past the comparison parse PHP and JavaScript and need PHP and
# Node.js; where they are missing, the CI runs these cases.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
APP_ID="paperless_sync"
REPO="${WORK}/repo"
STAGE="${WORK}/stage"
checks=0
failures=0
left=0

# Git of these tests alone, without the configuration of the machine, such as signed
# commits or hooks, and without the repository that runs them: GIT_DIR or GIT_WORK_TREE,
# such as for a worktree in a container, would point every command of the tests there,
# so that git init rewrote its configuration and its branch got the commits of the tests.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=test@example.com
export GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=test@example.com

if command -v php >/dev/null 2>&1 && command -v node >/dev/null 2>&1; then
	parsers=true
else
	parsers=false
fi

# A file of the app, with its lines.
write() {
	mkdir -p "$(dirname "${REPO}/$1")"
	printf '%s\n' "${@:2}" > "${REPO}/$1"
}

# The app: a file in every runtime path, the second migration among them, and files
# of its development, which stay out of its package.
RUNTIME_FILES=(
	appinfo/info.xml
	css/settings.css
	img/app.svg
	js/settings.js
	l10n/de.js
	l10n/de.json
	lib/Cron/SyncJob.php
	lib/Migration/Version000110Date20261008000000.php
	templates/settings.php
	CHANGELOG.md
	LICENSE
	README.md
	README.de.md
)
write appinfo/info.xml '<?xml version="1.0"?>' '<info><id>paperless_sync</id><version>1.0.0</version></info>'
write css/settings.css '.paperless-sync { display: grid; }'
write img/app.svg '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'
write js/settings.js '"use strict";'
write l10n/de.js 'OC.L10N.register("paperless_sync", {}, "nplurals=2; plural=(n != 1);");'
write l10n/de.json '{ "translations": {}, "pluralForm": "nplurals=2; plural=(n != 1);" }'
write lib/Cron/SyncJob.php '<?php' 'namespace OCA\PaperlessSync\Cron;' 'final class SyncJob {}'
write lib/Migration/Version000110Date20261008000000.php '<?php' 'namespace OCA\PaperlessSync\Migration;' \
	'final class Version000110Date20261008000000 {}'
write templates/settings.php '<?php' '// The settings page.'
write CHANGELOG.md '# Changelog'
write LICENSE 'The license of the app.'
write README.md '# Paperless Sync'
write README.de.md '# Paperless Sync'
write composer.json '{}'
write docs/index.md '# Paperless Sync'
write tests/Unit/SyncJobTest.php '<?php'
mkdir -p "${REPO}/scripts"
cp "${ROOT}/scripts/check-package.sh" "${REPO}/scripts/check-package.sh"
git -C "${REPO}" init --quiet
git -C "${REPO}" add --all
git -C "${REPO}" commit --quiet --message "The app"

# The runtime files of HEAD in the folder of the app, as krankerl clones them; a case
# changes them before packing.
stage() {
	rm -rf "${STAGE}"
	mkdir -p "${STAGE}/${APP_ID}"
	git -C "${REPO}" archive --format=tar HEAD -- "${RUNTIME_FILES[@]}" |
		tar --extract --file - --directory "${STAGE}/${APP_ID}"
}

# Packs the stage into $2.tar.gz in the working folder: as krankerl does, with the
# files alone ($1 = files), or as the release workflow does after signing, with the
# folders too ($1 = folders).
pack() {
	local names
	if [[ "$1" == files ]]; then
		(cd "${STAGE}" && find . ! -type d | sed 's|^\./||' | sort) > "${WORK}/names.txt"
		tar --create --gzip --file "${WORK}/$2.tar.gz" --directory "${STAGE}" --no-recursion \
			--files-from "${WORK}/names.txt"
	else
		mapfile -t names < <(ls -A "${STAGE}")
		tar --create --gzip --file "${WORK}/$2.tar.gz" --directory "${STAGE}" "${names[@]}"
	fi
}

# Checks the package $2.tar.gz in mode $3, from the working folder and not from the
# repository, and expects the exit status $4 and, in the output, the text $5.
check() {
	local what="$1" archive="$2.tar.gz" mode="$3" status="$4" text="$5" output actual=0
	checks=$((checks + 1))
	output="$(cd "${WORK}" && bash "${REPO}/scripts/check-package.sh" "${archive}" "${mode}" 2>&1)" || actual=$?
	if [[ "${actual}" != "${status}" || "${output}" != *"${text}"* ]]; then
		printf 'FAIL: %s: expected exit status %s and "%s", got %s:\n%s\n\n' \
			"${what}" "${status}" "${text}" "${actual}" "${output}" >&2
		failures=$((failures + 1))
	fi
}

# The same, for a case that gets past the comparison and parses PHP and JavaScript.
check_parsed() {
	if [[ "${parsers}" == true ]]; then
		check "$@"
	else
		left=$((left + 1))
	fi
}

# The package of the app passes, packed either way, also when the working tree holds
# changes that HEAD doesn't, which krankerl doesn't package either.
stage
pack files krankerl
check_parsed "the package of krankerl" krankerl unsigned 0 \
	"Validated unsigned production package with exactly the 13 runtime files of HEAD"
pack folders folders
check_parsed "the package with its folders" folders unsigned 0 "exactly the 13 runtime files of HEAD"
write templates/settings.php '<?php' '// A change that is not committed.'
write lib/Untracked.php '<?php'
check_parsed "a package of HEAD, not of the working tree" krankerl unsigned 0 "exactly the 13 runtime files of HEAD"
git -C "${REPO}" checkout --quiet -- templates/settings.php
rm "${REPO}/lib/Untracked.php"

# A signed package holds its signature besides.
stage
printf '{"hashes": {}, "signature": "c2lnbmF0dXJl", "certificate": "certificate"}\n' > \
	"${STAGE}/${APP_ID}/appinfo/signature.json"
pack folders signed
check_parsed "a signed package" signed signed 0 \
	"Validated signed production package with exactly the 13 runtime files of HEAD and their signature"
check "a signed package checked as unsigned" signed unsigned 1 	"Unsigned package already holds a signature: ${APP_ID}/appinfo/signature.json"
stage
pack folders unsigned
check "an unsigned package checked as signed" unsigned signed 1 \
	"Signed package lacks its signature: ${APP_ID}/appinfo/signature.json"
: > "${STAGE}/${APP_ID}/appinfo/signature.json"
pack folders empty-signature
check "an empty signature" empty-signature signed 1 "Signed package lacks its signature"
printf '{"hashes": \n' > "${STAGE}/${APP_ID}/appinfo/signature.json"
pack folders broken-signature
check_parsed "a signature that is no JSON" broken-signature signed 1 "is no valid JSON: ${APP_ID}/appinfo/signature.json"

# A runtime file that the package lacks.
for missing in templates/settings.php css/settings.css l10n/de.json \
	lib/Migration/Version000110Date20261008000000.php appinfo/info.xml; do
	stage
	rm "${STAGE}/${APP_ID}/${missing}"
	pack files missing
	check "a package without ${missing}" missing unsigned 1 "missing: ${APP_ID}/${missing}"
done
stage
rm -r "${STAGE}/${APP_ID}/templates"
pack folders missing-folder
check "a package without templates/" missing-folder unsigned 1 "missing: ${APP_ID}/templates/settings.php"

# A stray file or folder, in the folder of the app or beside it.
stage
cp "${REPO}/composer.json" "${STAGE}/${APP_ID}/composer.json"
pack files stray
check "a package with composer.json" stray unsigned 1 "stray:   ${APP_ID}/composer.json"
stage
mkdir -p "${STAGE}/${APP_ID}/tests/Unit"
cp "${REPO}/tests/Unit/SyncJobTest.php" "${STAGE}/${APP_ID}/tests/Unit/SyncJobTest.php"
pack folders stray-folder
check "a package with tests/" stray-folder unsigned 1 "stray:   ${APP_ID}/tests/Unit/SyncJobTest.php"
stage
printf '<?php\n' > "${STAGE}/${APP_ID}/lib/Untracked.php"
pack files stray-runtime
check "a package with a file of lib/ that Git doesn't track" stray-runtime unsigned 1 "stray:   ${APP_ID}/lib/Untracked.php"
stage
mkdir "${STAGE}/${APP_ID}/vendor"
pack folders empty-folder
check "a package with an empty folder" empty-folder unsigned 1 "stray:   ${APP_ID}/vendor/"
stage
printf '{}\n' > "${STAGE}/composer.json"
pack folders beside
check "a package with a file beside the folder of the app" beside unsigned 1 "stray:   composer.json"

# A runtime file with other content than in HEAD, such as line endings of Windows.
stage
printf '// Added.\n' >> "${STAGE}/${APP_ID}/lib/Cron/SyncJob.php"
pack files changed
check "a package with a changed file" changed unsigned 1 "changed: ${APP_ID}/lib/Cron/SyncJob.php"
stage
sed 's/$/\r/' "${REPO}/README.md" > "${STAGE}/${APP_ID}/README.md"
pack files crlf
check "a package with line endings of Windows" crlf unsigned 1 "changed: ${APP_ID}/README.md"

# Links, which tar can only make where the system can.
stage
ln -s README.md "${STAGE}/${APP_ID}/link.md" 2>/dev/null || true
if [[ -L "${STAGE}/${APP_ID}/link.md" ]]; then
	pack files symlink
	check "a package with a symbolic link" symlink unsigned 1 "no symbolic or hard links"
else
	echo "This system makes no symbolic links; the CI tests the package with one."
fi
stage
ln "${STAGE}/${APP_ID}/README.md" "${STAGE}/${APP_ID}/README.copy.md" 2>/dev/null || true
pack files hardlink
if tar --list --verbose --gzip --file "${WORK}/hardlink.tar.gz" | grep -q '^h'; then
	check "a package with a hard link" hardlink unsigned 1 "no symbolic or hard links"
else
	echo "This system makes no hard links in a tar; the CI tests the package with one."
fi

# Entries that would land outside the folder of the extraction.
stage
tar --create --gzip --file "${WORK}/absolute.tar.gz" --directory "${STAGE}" --absolute-names \
	--transform 's,^,/,' "${APP_ID}/README.md"
check "a package with an absolute path" absolute unsigned 1 "Unsafe package entry: /${APP_ID}/README.md"
tar --create --gzip --file "${WORK}/parent.tar.gz" --directory "${STAGE}" --absolute-names \
	--transform "s,^${APP_ID}/,${APP_ID}/../," "${APP_ID}/README.md"
check "a package with ../" parent unsigned 1 "Unsafe package entry: ${APP_ID}/../README.md"

# The arguments and the archive itself.
check "an unknown mode" krankerl sideloaded 2 "Usage:"
: > "${WORK}/nothing.tar.gz"
check "an empty archive" nothing unsigned 1 "Package does not exist or is empty: nothing.tar.gz"
check "no archive" absent unsigned 1 "Package does not exist or is empty: absent.tar.gz"

# PHP or JavaScript that doesn't parse, committed as it is.
write lib/Broken.php '<?php' 'final class Broken {'
git -C "${REPO}" add lib/Broken.php
git -C "${REPO}" commit --quiet --message "PHP that doesn't parse"
stage
cp "${REPO}/lib/Broken.php" "${STAGE}/${APP_ID}/lib/Broken.php"
pack files broken-php
check_parsed "a package with PHP that doesn't parse" broken-php unsigned 1 "A PHP file of the package doesn't parse."
git -C "${REPO}" rm --quiet lib/Broken.php
write js/broken.js 'function broken( {'
git -C "${REPO}" add js/broken.js
git -C "${REPO}" commit --quiet --message "JavaScript that doesn't parse"
stage
cp "${REPO}/js/broken.js" "${STAGE}/${APP_ID}/js/broken.js"
pack files broken-js
check_parsed "a package with JavaScript that doesn't parse" broken-js unsigned 1 "A script of the package doesn't parse."

# A runtime path that the app no longer has, which the check names instead of failing
# in git archive.
git -C "${REPO}" rm -r --quiet css
git -C "${REPO}" commit --quiet --message "The app without CSS"
check "a runtime path that HEAD doesn't hold" krankerl unsigned 1 	"RUNTIME_PATHS of scripts/check-package.sh lists css, which HEAD doesn't hold."

if ((left > 0)); then
	echo "PHP or Node.js is not installed here; the CI runs the ${left} case(s) that parse the package."
fi
if ((failures > 0)); then
	echo "${failures} of ${checks} case(s) of scripts/check-package.sh failed." >&2
	exit 1
fi
echo "scripts/check-package.sh passed all ${checks} case(s): the package of the app passes, and every other one fails with its difference."
