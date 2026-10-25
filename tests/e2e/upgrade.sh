# shellcheck shell=bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The upgrade of an installation in use, from the last release to the package of the commit,
# as an administrator updates the app from the App Store. run.sh sources it with E2E_UPGRADE=1
# once Nextcloud is installed, in place of its own scenario, and ends the tests after it:
#
#   E2E_DATABASE=pgsql E2E_UPGRADE=1 bash tests/e2e/run.sh
#
# Every other run enables the checkout on empty tables, so a migration never meets a row there,
# and neither does one that moves the data of an installation in use (postSchemaChange). Here:
#
# 1. Nextcloud gets the package of the last release, signed as the App Store gives it, which
#    occ integrity:check-app must find intact; a copy with one changed file must fail it.
# 2. The release is used as an administrator does: the token, per-user ownership with a mapped
#    user, the secret of the webhook, the copies of two owners, an import, an import that waits
#    to be tried again after Paperless refused its upload for a while, and a file of Files that
#    waits to be sent.
# 3. The package of the commit replaces it, at a higher version than the release when the commit
#    still has the version of the release, as the next release will, and occ upgrade runs.
# 4. Every migration of the package must have run (those newer than the release on its rows; the
#    test names them, or says that none is newer), the version be that of the package, and the upgrade must keep
#    every row of the tables of the app, every value of its configuration, its credentials, every
#    file below the base folder with its ID, the settings and the webhook. The next run must
#    work with the stored token, download nothing again and still wait with the retry; the secret
#    of the webhook must still be accepted.
#
# The steps against the release use only what administrators and their scripts use: the settings,
# occ paperless_sync:run, WebDAV and the routes of Send to Paperless and of the webhook. A change of
# one of them keeps the old way working here until the next release, which the test then starts
# from.

# shellcheck disable=SC2154 # COMPOSE, TMP_DIR, PASSWORD, BASE_URL and the helpers are those of run.sh.

UPGRADE_USERS="sync-target e2e-other e2e-mapped"
UPGRADE_BASE="Documents/Paperless"
# What a migration of the commit changes on purpose in the data of the release, each as
# TABLE.COLUMN or appconfig.KEY, such as paperless_sync_import.status, with its reason in a
# comment; the upgrade must keep everything else as it was. Empty again after the release.
UPGRADE_CHANGES=""

read -r RELEASE_VERSION _ _ <<<"$(bash "${SCRIPT_DIR}/packages.sh" info "${E2E_RELEASE_PACKAGE}")"
read -r COMMIT_VERSION _ _ <<<"$(bash "${SCRIPT_DIR}/packages.sh" info "${E2E_PACKAGE}")"
# The version that the upgrade installs: that of the commit, or the next patch release while the
# commit still has the version of the last release, as between two releases. Nextcloud runs the
# migrations of an app only for a version newer than the installed one, as the release bot writes it.
if [[ "${COMMIT_VERSION}" == "${RELEASE_VERSION}" ]]; then
	IFS=. read -r major minor patch <<<"${RELEASE_VERSION%%[-+]*}"
	UPGRADE_VERSION="${major}.${minor}.$((patch + 1))"
elif [[ "$(printf '%s\n' "${RELEASE_VERSION}" "${COMMIT_VERSION}" | sort -V | tail -n 1)" != "${COMMIT_VERSION}" ]]; then
	echo "The commit has the version ${COMMIT_VERSION}, older than the last release ${RELEASE_VERSION}: bring the branch up to date with main." >&2
	exit 1
else
	UPGRADE_VERSION="${COMMIT_VERSION}"
fi
echo "The upgrade starts from the release ${RELEASE_VERSION} and installs the commit as ${UPGRADE_VERSION}."

# Unpacks a package into the folder of the app, in place of what was there, as an update of the
# app replaces its files.
install_package() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud \
		sh -c 'cd custom_apps && rm -rf paperless_sync && tar -xzf -' <"$1"
}

# The state of upgrade_state.php: the tables, the configuration and the credentials of the app,
# and the files of the users below the base folder with their IDs.
upgrade_state() {
	output="$1"
	# A file of its own in the container: Nextcloud refuses to boot in a script that php reads
	# from standard input.
	"${COMPOSE[@]}" exec -T --user www-data \
		--env "E2E_USERS=${UPGRADE_USERS}" --env "E2E_BASE_PATH=${UPGRADE_BASE}" \
		nextcloud sh -c 'cat >/tmp/upgrade_state.php && php /tmp/upgrade_state.php' \
		<"${SCRIPT_DIR}/upgrade_state.php" >"${output}.txt" 2>&1 || true
	sed -n 's/^STATE //p' "${output}.txt" >"${output}"
	if [[ ! -s "${output}" ]]; then
		echo "upgrade_state.php read no state of the app: $(cat "${output}.txt")" >&2
		exit 1
	fi
}

# The stored settings and the state of the webhook as the settings page gets them, from its
# initial state.
settings_page() {
	output="$1"
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--output "${output}.html" \
		"${BASE_URL}/index.php/settings/admin/paperless_sync"
	for name in config webhook; do
		value="$(grep --only-matching "id=\"initial-state-paperless_sync-${name}\" value=\"[^\"]*\"" "${output}.html" \
			| sed 's/.*value="//; s/"$//' | base64 --decode || true)"
		if [[ -z "${value}" ]]; then
			echo "The settings page has no initial state ${name}." >&2
			exit 1
		fi
		printf '%s' "${value}" >"${output}.${name}"
	done
	{
		printf '{"config": '
		cat "${output}.config"
		printf ', "webhook": '
		cat "${output}.webhook"
		printf '}'
	} >"${output}"
}

# Checks one state, or one before and one after the upgrade, with a mode of assert_upgrade.py.
assert_upgrade() {
	mode="$1"
	if (($# == 2)); then
		"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_upgrade.py "${mode}" <"$2"
		return
	fi
	{
		printf '{"before": '
		cat "$2"
		printf ', "after": '
		cat "$3"
		printf '}'
	} | "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_upgrade.py "${mode}"
}

# A run with occ, as an administrator's script starts it: it must end with an exit code of at
# most $2, 1 after errors of single documents.
upgrade_run() {
	output="$1"
	code=0
	occ paperless_sync:run --output=json >"${output}" 2>&1 || code=$?
	if ((code > ${2:-0})); then
		echo "occ paperless_sync:run ended with exit code ${code}: $(cat "${output}")" >&2
		exit 1
	fi
}

restart_nextcloud() {
	"${COMPOSE[@]}" restart nextcloud >/dev/null
	"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null
	# The IDs of the containers and the port of the mock, should Compose have made them anew.
	containers
}

# 1. The release, as the App Store gives it.
if ! tar --list --gzip --file - paperless_sync/appinfo/signature.json <"${E2E_RELEASE_PACKAGE}" >/dev/null 2>&1; then
	echo "The package of the release ${RELEASE_VERSION} is not signed: it holds no appinfo/signature.json." >&2
	exit 1
fi
install_package "${E2E_RELEASE_PACKAGE}"
# integrity:check-app says "No errors found" for an intact app, when asked to, and passes one
# without a signature, saying that it skips it.
INTEGRITY_CODE=0
occ integrity:check-app --verbose paperless_sync >"${TMP_DIR}/integrity.json" 2>&1 || INTEGRITY_CODE=$?
if [[ "${INTEGRITY_CODE}" != 0 ]] || ! grep --fixed-strings 'No errors found' "${TMP_DIR}/integrity.json" >/dev/null ||
	grep --ignore-case --fixed-strings 'skipping' "${TMP_DIR}/integrity.json" >/dev/null; then
	echo "occ integrity:check-app finds the package of the release ${RELEASE_VERSION} changed, or its signature invalid: $(cat "${TMP_DIR}/integrity.json")" >&2
	exit 1
fi
# The same check of a copy with one changed file must fail, or its pass proves nothing.
"${COMPOSE[@]}" exec -T --user www-data nextcloud sh -c \
	'rm -rf /tmp/paperless_sync-changed && cp -R custom_apps/paperless_sync /tmp/paperless_sync-changed && echo "// changed" >>/tmp/paperless_sync-changed/lib/AppInfo/Application.php'
INTEGRITY_CODE=0
# Git Bash would turn the path inside the container into one of Windows.
MSYS_NO_PATHCONV=1 occ integrity:check-app --output=json --path=/tmp/paperless_sync-changed paperless_sync \
	>"${TMP_DIR}/integrity-changed.json" 2>&1 || INTEGRITY_CODE=$?
if [[ "${INTEGRITY_CODE}" == 0 ]] || ! grep --fixed-strings 'INVALID_HASH' "${TMP_DIR}/integrity-changed.json" >/dev/null ||
	! grep --fixed-strings 'Application.php' "${TMP_DIR}/integrity-changed.json" >/dev/null; then
	echo "occ integrity:check-app passes a copy of the release with a changed file (exit code ${INTEGRITY_CODE}): $(cat "${TMP_DIR}/integrity-changed.json")" >&2
	exit 1
fi
occ app:enable paperless_sync >/dev/null
occ background:cron >/dev/null
"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
	php occ user:add --password-from-env e2e-mapped >/dev/null
restart_nextcloud
echo "The release ${RELEASE_VERSION} is installed and intact after ${SECONDS} s."

# 2. The release in use: the documents of e2e-owner of Paperless go to e2e-mapped, those of
# E2E-Other to e2e-other.
control /control/scenario/per-user >/dev/null
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'settings[paperless_url]=http://paperless-mock:8080' \
	--data-urlencode 'settings[paperless_local_network]=1' \
	--data-urlencode 'settings[enabled]=1' \
	--data-urlencode 'settings[target_user]=sync-target' \
	--data-urlencode 'settings[ownership]=per-user' \
	--data-urlencode 'settings[user_map]=e2e-owner = e2e-mapped' \
	--data-urlencode 'token=e2e-only-token' \
	--output "${TMP_DIR}/release-settings.json" \
	"${BASE_URL}/apps/paperless_sync/settings"
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--output "${TMP_DIR}/release-webhook.json" \
	"${BASE_URL}/apps/paperless_sync/settings/webhook"
UPGRADE_SECRET="$(json_value secret <"${TMP_DIR}/release-webhook.json")"
upgrade_run "${TMP_DIR}/release-export.json"
# A file of the inbox whose import Paperless hasn't finished at the upgrade.
dav_upload sync-target "${UPGRADE_BASE}/Inbox/letter.pdf" '%PDF-1.4 Synthetic inbox PDF letter.'
upgrade_run "${TMP_DIR}/release-upload.json"
# One whose upload Paperless refuses for a while, which waits to be tried again.
control /control/uploads/unavailable >/dev/null
dav_upload sync-target "${UPGRADE_BASE}/Inbox/later.pdf" '%PDF-1.4 Synthetic inbox PDF later.'
upgrade_run "${TMP_DIR}/release-retry.json" 1
control /control/uploads/available >/dev/null
# A file that e2e-admin sends from Files, which waits for the background job.
dav_upload e2e-admin 'receipt.pdf' '%PDF-1.4 Synthetic inbox PDF receipt.'
SEND_STATUS="$(curl --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data "{\"fileIds\": [$(file_id e2e-admin 'receipt.pdf')]}" \
	--output "${TMP_DIR}/release-send.json" \
	--write-out '%{http_code}' \
	"${BASE_URL}/apps/paperless_sync/api/v1/send")"
if [[ "${SEND_STATUS}" != "200" ]]; then
	echo "The release did not queue the file to send: HTTP ${SEND_STATUS}, $(cat "${TMP_DIR}/release-send.json")" >&2
	exit 1
fi
upgrade_state "${TMP_DIR}/state-release.json"
assert_upgrade release "${TMP_DIR}/state-release.json"
settings_page "${TMP_DIR}/settings-release.json"
mock_state >"${TMP_DIR}/mock-release.json"
echo "The release ${RELEASE_VERSION} is in use after ${SECONDS} s."

# 3. The package of the commit, and occ upgrade.
install_package "${E2E_PACKAGE}"
if [[ "${UPGRADE_VERSION}" != "${COMMIT_VERSION}" ]]; then
	# The versions reach the shell of the container as its arguments $1 and $2.
	# shellcheck disable=SC2016
	"${COMPOSE[@]}" exec -T --user www-data nextcloud sh -c \
		'sed -i "s|<version>$1</version>|<version>$2</version>|" custom_apps/paperless_sync/appinfo/info.xml' \
		sh "${COMMIT_VERSION}" "${UPGRADE_VERSION}"
fi
if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"needsDbUpgrade":true' >/dev/null; then
	echo "Nextcloud doesn't take the package of the commit, ${UPGRADE_VERSION}, for an update of ${RELEASE_VERSION}." >&2
	exit 1
fi
UPGRADE_CODE=0
occ upgrade >"${TMP_DIR}/upgrade.txt" 2>&1 || UPGRADE_CODE=$?
if [[ "${UPGRADE_CODE}" != 0 ]] || ! grep --fixed-strings "Updated <paperless_sync> to ${UPGRADE_VERSION}" "${TMP_DIR}/upgrade.txt" >/dev/null; then
	echo "occ upgrade from ${RELEASE_VERSION} to ${UPGRADE_VERSION} failed with exit code ${UPGRADE_CODE}, which leaves the servers of the users in maintenance mode or with the app disabled:" >&2
	cat "${TMP_DIR}/upgrade.txt" >&2
	exit 1
fi
STATUS="$(occ status --output=json)"
if ! grep --fixed-strings '"maintenance":false' <<<"${STATUS}" >/dev/null || ! grep --fixed-strings '"needsDbUpgrade":false' <<<"${STATUS}" >/dev/null; then
	echo "Nextcloud is still in maintenance mode or waits for an upgrade after occ upgrade: ${STATUS}" >&2
	exit 1
fi
INSTALLED_VERSION="$(occ app:list --output=json | json_value enabled paperless_sync)"
if [[ "${INSTALLED_VERSION}" != "${UPGRADE_VERSION}" ]]; then
	echo "occ app:list shows the app at ${INSTALLED_VERSION} after the upgrade, not at ${UPGRADE_VERSION}." >&2
	exit 1
fi
# Every migration of the package ran, and none that it lacks.
MIGRATIONS="$(tar --list --gzip --file - <"${E2E_PACKAGE}" |
	sed -nE 's|^paperless_sync/lib/Migration/Version([^/]+)\.php$|\1|p' | sort | tr '\n' ' ')"
upgrade_state "${TMP_DIR}/state-upgraded.json"
# It names the migrations newer than the release, the ones that met the rows of the release.
NEWER="$({
	printf '{"before": '
	cat "${TMP_DIR}/state-release.json"
	printf ', "after": '
	cat "${TMP_DIR}/state-upgraded.json"
	printf '}'
} | "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_upgrade.py migrations "${MIGRATIONS}")"
# The web server loads the new code, as after the next request of a server.
restart_nextcloud
echo "occ upgrade to ${UPGRADE_VERSION} ran after ${SECONDS} s, and Nextcloud has executed every migration of the package, ${MIGRATIONS% }. ${NEWER}"

# 4. What the upgrade keeps, and the release's installation working on.
{
	printf '{"before": '
	cat "${TMP_DIR}/state-release.json"
	printf ', "after": '
	cat "${TMP_DIR}/state-upgraded.json"
	printf '}'
} | "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_upgrade.py state "${UPGRADE_CHANGES}"
settings_page "${TMP_DIR}/settings-upgraded.json"
assert_upgrade settings "${TMP_DIR}/settings-release.json" "${TMP_DIR}/settings-upgraded.json"
# The stored token reaches Paperless without being entered again, the copies stay as they are,
# the import that waits to be tried again is not due yet, and the task of the other one is still
# pending.
upgrade_run "${TMP_DIR}/upgraded-run.json"
mock_state >"${TMP_DIR}/mock-upgraded.json"
assert_upgrade mock "${TMP_DIR}/mock-release.json" "${TMP_DIR}/mock-upgraded.json"
upgrade_state "${TMP_DIR}/state-upgraded-run.json"
assert_upgrade retry "${TMP_DIR}/state-release.json" "${TMP_DIR}/state-upgraded-run.json"
# The import that the release submitted ends with the commit, once Paperless finished it.
control /control/task-of/letter.pdf/success >/dev/null
upgrade_run "${TMP_DIR}/upgraded-import.json"
assert_dav_missing sync-target "${UPGRADE_BASE}/Inbox/letter.pdf"
# The secret of the webhook, which the workflows of Paperless send, still opens it.
WEBHOOK_STATUS="$(webhook "${TMP_DIR}/upgraded-webhook.json" "Bearer ${UPGRADE_SECRET}" '{"document": 124}')"
if [[ "${WEBHOOK_STATUS}" != "200" ]]; then
	echo "The webhook refuses its secret after the upgrade: HTTP ${WEBHOOK_STATUS}, $(cat "${TMP_DIR}/upgraded-webhook.json")" >&2
	exit 1
fi
echo "Docker E2E passed on ${DATABASE_NAME} with ${STORAGE_NAME}: the upgrade from the release ${RELEASE_VERSION} to ${UPGRADE_VERSION} after ${SECONDS} s, with the package of the release intact and a changed copy refused, every migration newer than the release run on the rows of an installation in use, every row, setting, credential and file ID kept, the stored token working, nothing downloaded again, the retry of the inbox still waiting, the import of the release finished and the secret of the webhook accepted."
