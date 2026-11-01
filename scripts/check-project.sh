#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of this app alone, which scripts/check.sh runs last: the browser of the
# end-to-end tests, the rules that decide when they run and how their two jobs share
# them, the pipes of the shell scripts,
# none of which may hand a download to a program, the icon and the hook of the
# website, the versions in the README, the description of the app, the area labels of
# pull requests, the explanations of its checks for the PR coach, the check of its
# package, the JavaScript of the settings and the translations, which have no build
# step, with the rules of ESLint, the thumbnails of the screenshots of the App Store, the language of the pictures
# of the documentation, their frozen story and the report of their visual check, and that
# the German twins of the documentation, README.de.md and
# docs/*.de.md, follow their English pages; the versions check covers README.de.md and
# docs/index.de.md as well.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# A Python 3 at least as new as the arguments: python3, or python on Windows, where
# Python installs no python3. The hook of the website runs on any Python 3; the check
# of the area labels needs 3.11 for tomllib.
find_python() {
  local candidate
  for candidate in python3 python; do
    if command -v "$candidate" >/dev/null 2>&1 &&
      "$candidate" -c 'import sys; sys.exit(sys.version_info < tuple(map(int, sys.argv[1:])))' "$@" >/dev/null 2>&1; then
      echo "$candidate"
      return
    fi
  done
}
python_any="$(find_python 3)"
python="$(find_python 3 11)"

printf '\n== %s\n' "Browser of the end-to-end tests"
# A version of playwright-core drives the browsers in the image of the same version.
image="$(grep -oE 'mcr\.microsoft\.com/playwright:v[0-9]+\.[0-9]+\.[0-9]+' tests/e2e/run.sh | head -n 1 || true)"
image="${image##*:v}"
package="$(grep -oE '"playwright-core": "[^"]+"' tests/e2e/package.json | cut -d '"' -f 4 || true)"
if [[ -z "$image" || "$image" != "$package" ]]; then
  echo "tests/e2e/run.sh starts the image of Playwright ${image:-?}, but tests/e2e/package.json asks for playwright-core ${package:-?}; keep them equal."
  exit 1
fi
echo "The image of Playwright and playwright-core are both at version $package."

# The job variants of e2e.yml reads the krankerl that builds the package of the commit
# for the upgrade test from ci.yml, the one of the CI and of the release, whose package
# users get. It needs exactly one of each pin there, and no copy of its own that the
# blueprint's updates of ci.yml would leave behind.
for pin in VERSION SHA256; do
  count="$(grep -cE "^[[:space:]]*KRANKERL_$pin:[[:space:]]*[^[:space:]]+" .github/workflows/ci.yml || true)"
  if [[ "$count" != 1 ]]; then
    echo ".github/workflows/ci.yml names KRANKERL_$pin $count times, but the upgrade test in .github/workflows/e2e.yml reads exactly one pin from it; ci.yml belongs to the blueprint, so fix it there."
    exit 1
  fi
  if grep -qE "^[[:space:]]*KRANKERL_$pin:" .github/workflows/e2e.yml; then
    echo ".github/workflows/e2e.yml pins KRANKERL_$pin itself; remove that pin, as the job variants reads the one of .github/workflows/ci.yml, which the blueprint keeps up to date."
    exit 1
  fi
done
echo "The upgrade test builds the package of the commit with the krankerl of the CI and of the release."

printf '\n== %s\n' "Changes that run the end-to-end tests"
# The rules of tests/e2e/affected.sh, with which a pull request skips the end-to-end
# tests when it can't affect the app. ShellCheck reads that script anyway, as
# scripts/check.sh hands it every script.
# shellcheck source=tests/e2e/affected.sh disable=SC1091
source tests/e2e/affected.sh
problems=0
expect() {
  local expected="$1" path="$2" actual=skip
  if path_affects_app "$path"; then
    actual=run
  fi
  if [[ "$actual" != "$expected" ]]; then
    echo "A change of $path should $expected the end-to-end tests, but tests/e2e/affected.sh says $actual."
    problems=$((problems + 1))
  fi
}
for path in lib/Service/SyncService.php appinfo/routes.php templates/settings.php js/settings.js \
  css/settings.css l10n/de.json img/app.svg composer.json tests/e2e/run.sh tests/e2e/mock_server.py \
  tests/e2e/package-lock.json .github/workflows/e2e.yml; do
  expect run "$path"
done
for path in README.md README.de.md CHANGELOG.md docs/guide.md docs/guide.de.md docs/images/dry-run.gif \
  docs/diagrams/how-paperless-sync-moves-documents.de.mmd tests/e2e/README.md \
  tests/e2e/MANUAL_ACCEPTANCE_TESTS.md tests/Unit/Service/SyncServiceTest.php scripts/check.sh \
  mkdocs.yml docs/images/app.svg docs/stylesheets/brand.css .github/mkdocs_project.py \
  .github/social-cards/preview.yml tests/website/test_mkdocs_project.py \
  .github/workflows/ci.yml .github/renovate.json5 version.txt .release-please-manifest.json \
  composer.lock screenshots/01-admin-settings.png scripts/check-package.sh \
  tests/package/test_check_package.sh; do
  expect skip "$path"
done
# The contract tests against a real Paperless are a job of their own.
for path in tests/e2e/contract.py tests/e2e/run-contract.sh tests/e2e/compose.paperless.yaml \
  tests/fixtures/paperless-ngx-3/documents.json; do
  expect skip "$path"
done
# The scripts of the pictures alone are the visual check's (visual.yml), whose story shares
# the mock and the helpers of the browser with the tests.
for path in tests/e2e/screenshots.sh tests/e2e/screenshots.mjs tests/e2e/frozen.mjs   tests/e2e/frozen.test.mjs tests/e2e/compare.mjs tests/e2e/compare.test.mjs; do
  expect skip "$path"
done
for path in tests/e2e/files-address.mjs tests/e2e/showcase.py tests/e2e/compose.yaml; do
  expect run "$path"
done

# Of those, a change that can behave differently on another database or storage runs
# PostgreSQL, MariaDB and the object store as well.
expect_variants() {
  local expected="$1" path="$2" actual=skip
  if path_affects_variants "$path"; then
    actual=run
  fi
  if [[ "$actual" != "$expected" ]]; then
    echo "A change of $path should $expected the other databases and the object store, but tests/e2e/affected.sh says $actual."
    problems=$((problems + 1))
  fi
}
for path in lib/Migration/Version000110Date20261008000000.php lib/Service/SyncStateRepository.php \
  lib/Service/SyncStateRepositoryInterface.php lib/Service/NextcloudStorageService.php \
  lib/Service/NextcloudStorageInterface.php lib/Service/PaperlessApiService.php \
  tests/e2e/compose.s3.yaml tests/e2e/run-all.sh .github/workflows/e2e.yml; do
  expect_variants run "$path"
done
for path in lib/Service/SyncService.php lib/Service/PathTemplateService.php \
  lib/Controller/SettingsController.php templates/settings.php js/settings.js l10n/de.json \
  tests/e2e/README.md README.md tests/e2e/screenshots.mjs tests/e2e/frozen.mjs tests/e2e/compare.mjs; do
  expect_variants skip "$path"
done

# The upgrade from the last release runs for a change of the migrations, of the settings and
# the state that a release stored, of the package that users install, and of the tests.
expect_upgrade() {
  local expected="$1" path="$2" actual=skip
  if path_affects_upgrade "$path"; then
    actual=run
  fi
  if [[ "$actual" != "$expected" ]]; then
    echo "A change of $path should $expected the upgrade from the last release, but tests/e2e/affected.sh says $actual."
    problems=$((problems + 1))
  fi
}
for path in lib/Migration/Version000130Date20261014120000.php lib/Service/ConfigService.php \
  lib/Service/SyncStateRepository.php lib/Model/SyncConfig.php lib/Service/WebhookService.php \
  .nextcloudignore krankerl.toml scripts/check-package.sh tests/e2e/upgrade.sh \
  tests/e2e/upgrade_state.php tests/e2e/assert_upgrade.py tests/e2e/packages.sh tests/e2e/run.sh \
  tests/e2e/mock_server.py tests/e2e/compose.upgrade.yaml .github/workflows/e2e.yml; do
  expect_upgrade run "$path"
done
for path in lib/Service/SyncService.php lib/Service/PaperlessApiService.php templates/settings.php \
  js/settings.js l10n/de.json README.md tests/e2e/README.md tests/e2e/accessibility.mjs \
  tests/e2e/package-lock.json tests/e2e/screenshots.sh tests/e2e/contract.py; do
  expect_upgrade skip "$path"
done

# The contract tests against a real Paperless-ngx run for a change of what the app asks
# Paperless or of how it reads the answers.
expect_contract() {
  local expected="$1" path="$2" actual=skip
  if path_affects_contract "$path"; then
    actual=run
  fi
  if [[ "$actual" != "$expected" ]]; then
    echo "A change of $path should $expected the contract tests against a real Paperless, but tests/e2e/affected.sh says $actual."
    problems=$((problems + 1))
  fi
}
for path in lib/Service/PaperlessApiService.php lib/Service/PaperlessClientInterface.php \
  lib/Service/PaperlessClientFactory.php lib/Model/PaperlessConnection.php \
  lib/Model/DocumentFingerprint.php tests/fixtures/paperless-ngx-2/metadata.json tests/e2e/contract.py \
  tests/e2e/run-contract.sh tests/e2e/compose.paperless.yaml tests/e2e/compose.yaml tests/e2e/mock_server.py \
  .github/workflows/e2e.yml; do
  expect_contract run "$path"
done
for path in lib/Service/SyncService.php lib/Service/PathTemplateService.php templates/settings.php \
  tests/e2e/run.sh tests/e2e/README.md tests/Unit/Service/PaperlessApiServiceTest.php README.md; do
  expect_contract skip "$path"
done

# A change of appinfo/info.xml, as git diff --unified=0 shows it. A release writes
# only its version, also into both addresses of a screenshot, and must skip the tests;
# the upstream bot raises max-version, and that must run them, as any other change of
# the file.
expect_info() {
  local expected="$1" actual=skip
  if info_xml_change_affects_app; then
    actual=run
  fi
  if [[ "$actual" != "$expected" ]]; then
    echo "The change \"$2\" of appinfo/info.xml should $expected the end-to-end tests, but tests/e2e/affected.sh says $actual."
    problems=$((problems + 1))
  fi
}
expect_info skip "a release" <<'DIFF'
diff --git a/appinfo/info.xml b/appinfo/info.xml
--- a/appinfo/info.xml
+++ b/appinfo/info.xml
@@ -69 +69 @@
-  <version>0.1.9</version>
+  <version>0.1.10</version>
@@ -83 +83 @@
-  <screenshot>https://example.com/v0.1.9/01.png</screenshot><!-- x-release-please-version -->
+  <screenshot>https://example.com/v0.1.10/01.png</screenshot><!-- x-release-please-version -->
DIFF
expect_info skip "a release of the screenshots with their thumbnails" <<'DIFF'
diff --git a/appinfo/info.xml b/appinfo/info.xml
--- a/appinfo/info.xml
+++ b/appinfo/info.xml
@@ -79 +79 @@
-  <version>1.1.1</version>
+  <version>1.2.0-rc.1</version>
@@ -98,4 +98,4 @@
-  <screenshot small-thumbnail="https://example.com/v1.1.1/01-small.png"><!--
-    -->https://example.com/v1.1.1/01.png</screenshot>
-  <screenshot small-thumbnail="https://example.com/v1.1.1/02-small.png"><!--
-    -->https://example.com/v1.1.1/02.png</screenshot>
+  <screenshot small-thumbnail="https://example.com/v1.2.0-rc.1/01-small.png"><!--
+    -->https://example.com/v1.2.0-rc.1/01.png</screenshot>
+  <screenshot small-thumbnail="https://example.com/v1.2.0-rc.1/02-small.png"><!--
+    -->https://example.com/v1.2.0-rc.1/02.png</screenshot>
DIFF
expect_info run "a raise of the version of PHP" <<'DIFF'
diff --git a/appinfo/info.xml b/appinfo/info.xml
--- a/appinfo/info.xml
+++ b/appinfo/info.xml
@@ -107 +107 @@
-    <php min-version="8.2.0"/>
+    <php min-version="8.3.0"/>
DIFF
expect_info run "a raise of the versions of Nextcloud" <<'DIFF'
diff --git a/appinfo/info.xml b/appinfo/info.xml
--- a/appinfo/info.xml
+++ b/appinfo/info.xml
@@ -108 +108 @@
-    <nextcloud min-version="33.0.0" max-version="35.0.0"/>
+    <nextcloud min-version="33.0.4" max-version="35.0.0"/>
DIFF
expect_info run "a version and another screenshot" <<'DIFF'
@@ -98 +98 @@
-  <screenshot small-thumbnail="https://example.com/v1.1.1/01-small.png"><!--
+  <screenshot small-thumbnail="https://example.com/v1.2.0/04-small.png"><!--
DIFF
expect_info run "a line that a release writes, and a new one" <<'DIFF'
@@ -98 +98,2 @@
-  <screenshot small-thumbnail="https://example.com/v1.1.1/01-small.png"><!--
+  <screenshot small-thumbnail="https://example.com/v1.2.0/01-small.png"><!--
+  <!-- x-release-please-end -->
DIFF
expect_info run "a line that a release writes, and a removed one" <<'DIFF'
@@ -79,2 +79 @@
-  <version>1.1.1</version>
-  <licence>AGPL-3.0-or-later</licence>
+  <version>1.2.0</version>
DIFF
expect_info run "max-version" <<'DIFF'
@@ -88 +88 @@
-    <nextcloud min-version="33" max-version="35"/>
+    <nextcloud min-version="33" max-version="36"/>
DIFF
expect_info run "a new line" <<'DIFF'
@@ -69,0 +70 @@
+  <namespace>PaperlessSync</namespace>
DIFF
expect_info run "a removed line" <<'DIFF'
@@ -92 +91,0 @@
-    <job>OCA\PaperlessSync\Cron\SyncJob</job>
DIFF

# The whole script: a commit that isn't here runs the tests, to be safe, and a commit
# compared with itself changes nothing.
decision() {
  local output result
  output="$(mktemp)"
  GITHUB_OUTPUT="$output" GITHUB_STEP_SUMMARY='' bash tests/e2e/affected.sh "$@" >/dev/null
  result="$(cat "$output")"
  rm -f "$output"
  echo "$result"
}
if [[ "$(decision 0000000000000000000000000000000000000000 HEAD)" != $'affected=true\nvariants=true\nupgrade=true' ]]; then
  echo "tests/e2e/affected.sh should run the end-to-end tests when it can't compare the commits."
  problems=$((problems + 1))
fi
if git rev-parse --quiet --verify HEAD >/dev/null && [[ "$(decision HEAD HEAD)" != $'affected=false\nvariants=false\nupgrade=false' ]]; then
  echo "tests/e2e/affected.sh HEAD HEAD should find nothing that runs the end-to-end tests."
  problems=$((problems + 1))
fi
if [[ "$(E2E_QUESTION=contract decision 0000000000000000000000000000000000000000 HEAD)" != contract=true ]]; then
  echo "E2E_QUESTION=contract bash tests/e2e/affected.sh should run the contract tests when it can't compare the commits."
  problems=$((problems + 1))
fi
if git rev-parse --quiet --verify HEAD >/dev/null && [[ "$(E2E_QUESTION=contract decision HEAD HEAD)" != contract=false ]]; then
  echo "E2E_QUESTION=contract bash tests/e2e/affected.sh HEAD HEAD should find nothing that runs the contract tests."
  problems=$((problems + 1))
fi
if ((problems > 0)); then
  exit 1
fi
echo "tests/e2e/affected.sh runs the end-to-end tests for the app and its tests, and skips them for the rest; the other databases and the object store run for the state, the files and the uploads, the upgrade from the last release for the migrations, the stored settings and state and the package, and the contract tests against a real Paperless for the client of Paperless."

printf '\n== %s\n' "The shards of the end-to-end tests"
# The two required jobs of .github/workflows/e2e.yml each run one shard of
# tests/e2e/run-all.sh (E2E_SHARD): together they start every run of the whole suite,
# each once, whatever the versions and the variants, and the job nextcloud, shard 1, the
# oldest and the newest version. E2E_PLAN=1 prints the runs without starting them.
runs_of() {
  local versions="$1" variants="$2" shard="$3"
  E2E_PLAN=1 E2E_VERSIONS="$versions" E2E_VARIANTS="$variants" E2E_SHARD="$shard" \
    bash tests/e2e/run-all.sh | grep -v '^The shard .* has nothing to run' || true
}
problems=0
for versions in "" "33 34 35 36 37" "33 34" none; do
  for variants in "" none pgsql "pgsql mysql+s3 mysql sqlite+s3"; do
    if [[ "$versions" == none && "$variants" == none ]]; then
      continue
    fi
    whole="$(runs_of "$versions" "$variants" "" | sort)"
    halves="$({
      runs_of "$versions" "$variants" 1
      runs_of "$versions" "$variants" 2
    } | sort)"
    if [[ "$whole" != "$halves" ]]; then
      echo "E2E_VERSIONS=\"$versions\" E2E_VARIANTS=\"$variants\": the shards 1 and 2 of tests/e2e/run-all.sh start \"${halves//$'\n'/;}\" instead of every run once: \"${whole//$'\n'/;}\"."
      problems=$((problems + 1))
    fi
  done
done
# The split of samples: the oldest and the newest version in shard 1, the variants in
# shard 2, and each version between in the one with the lighter load, where a version,
# with its browser checks, counts as five, a variant as three and an upgrade as two.
expect_shard() {
  local versions="$1" variants="$2" shard="$3" expected="$4" actual
  actual="$(runs_of "$versions" "$variants" "$shard")"
  if [[ "${actual//$'\n'/;}" != "$expected" ]]; then
    echo "E2E_VERSIONS=\"$versions\" E2E_VARIANTS=\"$variants\" E2E_SHARD=$shard bash tests/e2e/run-all.sh starts \"${actual//$'\n'/;}\" instead of \"$expected\"."
    problems=$((problems + 1))
  fi
}
expect_shard "33 34 35" "pgsql mysql+s3" 1 "Nextcloud 33;Nextcloud 35"
expect_shard "33 34 35" "pgsql mysql+s3" 2 "Nextcloud 34;Nextcloud 35 on PostgreSQL;Nextcloud 35 on MariaDB with S3"
expect_shard "33 34 35" none 2 "Nextcloud 34"
expect_shard "33 34 35 36" "pgsql mysql+s3" 1 "Nextcloud 33;Nextcloud 35;Nextcloud 36"
expect_shard "33 34 35 36" "pgsql mysql+s3" 2 "Nextcloud 34;Nextcloud 36 on PostgreSQL;Nextcloud 36 on MariaDB with S3"
expect_shard "33 34 35" "pgsql mysql+s3 mysql sqlite+s3" 1 "Nextcloud 33;Nextcloud 34;Nextcloud 35"
expect_shard "33 34 35" "" 1 "Nextcloud 33;Nextcloud 35"
expect_shard "33 34 35" "" 2 "Nextcloud 34;Nextcloud 35 on PostgreSQL;Nextcloud 35 on MariaDB with S3;Nextcloud 35 on PostgreSQL, upgraded from the last release;Nextcloud 35 on MariaDB, upgraded from the last release"
expect_shard "33 34 35 36" "" 1 "Nextcloud 33;Nextcloud 35;Nextcloud 36"
expect_shard "33 34 35" "pgsql+upgrade mysql+upgrade" 2 "Nextcloud 34;Nextcloud 35 on PostgreSQL, upgraded from the last release;Nextcloud 35 on MariaDB, upgraded from the last release"
expect_shard "36 35 34 33" none 1 "Nextcloud 33;Nextcloud 36"
expect_shard "33 34 35 36" none 2 "Nextcloud 34;Nextcloud 35"
expect_shard "33 34" none 2 ""
if ((problems > 0)); then
  exit 1
fi
echo "The shards 1 and 2 of tests/e2e/run-all.sh start every run of the suite once, the oldest and the newest version in the job nextcloud and the variants in the other, and each version between in the shard with the lighter load, where a version counts as five, a variant as three and an upgrade as two."

printf '\n== %s\n' "The runs at once of the end-to-end tests"
# tests/e2e/run-all.sh starts every run, and no more at once than E2E_PARALLEL names. A
# script in place of run.sh counts the runs that are going at each start.
limit_folder="$(mktemp -d)"
cat >"$limit_folder/run.sh" <<'EOF'
#!/usr/bin/env bash
: >"$LIMIT_FOLDER/$E2E_PORT.going"
ls "$LIMIT_FOLDER" | grep -c '\.going$' >>"$LIMIT_FOLDER/counts"
python3 -c 'import time; time.sleep(0.5)' 2>/dev/null || python -c 'import time; time.sleep(0.5)'
rm "$LIMIT_FOLDER/$E2E_PORT.going"
EOF
if ! GITHUB_STEP_SUMMARY='' LIMIT_FOLDER="$limit_folder" E2E_RUN_SCRIPT="$limit_folder/run.sh" E2E_PARALLEL=2 E2E_VERSIONS="33 34 35 36 37" E2E_VARIANTS=none \
  bash tests/e2e/run-all.sh >"$limit_folder/log" 2>&1; then
  cat "$limit_folder/log"
  echo "E2E_PARALLEL=2 bash tests/e2e/run-all.sh fails with a script that passes in place of run.sh."
  exit 1
fi
started="$(wc -l <"$limit_folder/counts" | tr -d ' ')"
most="$(sort -n "$limit_folder/counts" | tail -n 1)"
rm -rf "$limit_folder"
if [[ "$started" != 5 || "$most" != 2 ]]; then
  echo "With E2E_PARALLEL=2, tests/e2e/run-all.sh started $started of 5 runs, with at most $most at once instead of 2."
  exit 1
fi
echo "tests/e2e/run-all.sh starts every run, and no more at once than E2E_PARALLEL names."

printf '\n== %s\n' "The folder in memory of the end-to-end tests"
# down doesn't touch the folder of E2E_DATA_FOLDER, so a second run on a machine would find
# the files of the first and fail to install. The function of run.sh runs here with a script
# in place of Compose, and run.sh must call it after the down at its start and its end.
memory_folder="$(mktemp -d)"
empties() {
  (
    eval "$(sed -n '/^empty_data_folder() {$/,/^}$/p' tests/e2e/run.sh)"
    # The function from run.sh, which ShellCheck doesn't see in the eval, uses both.
    # shellcheck disable=SC2317,SC2329
    compose() { echo "$*" >>"$memory_folder/calls"; }
    # shellcheck disable=SC2034
    COMPOSE=(compose)
    E2E_DATA_FOLDER="$1" empty_data_folder
  )
}
empties ""
empties "$memory_folder/absent"
if [[ -e "$memory_folder/calls" ]]; then
  echo "empty_data_folder in tests/e2e/run.sh starts a container without a folder to empty, as on every runner of the CI."
  exit 1
fi
empties "$memory_folder"
if ! grep -q -- "run --rm --no-deps --entrypoint sh nextcloud -c find /var/www/html/data -mindepth 1 -delete" "$memory_folder/calls" ||
  [[ "$(tail -n 1 "$memory_folder/calls")" != "down --volumes"* ]]; then
  cat "$memory_folder/calls"
  echo "empty_data_folder in tests/e2e/run.sh should empty the folder of a run through the container of Nextcloud, and then remove what that started."
  exit 1
fi
rm -rf "$memory_folder"
if [[ "$(grep -A1 -E '^\s*"\$\{COMPOSE\[@\]\}" down ' tests/e2e/run.sh | grep -cE '^\s*empty_data_folder')" != 2 ]]; then
  echo "tests/e2e/run.sh should call empty_data_folder right after the down at its start and in its cleanup, or a second run on a machine fails to install."
  exit 1
fi
echo "A run empties the folder in memory after the down at its start and at its end, and starts no container where there is no such folder."

printf '\n== %s\n' "The health checks of the end-to-end tests"
# Each check of the health of a container starts a process in it through Docker. With up to
# five runs of two or three containers on the four cores of a runner, a check every two or
# three seconds takes a good part of a core from the tests, so each one checks often only
# while its service starts (start_interval) and seldom once it runs (interval). At that
# pace, a single late answer would mark a container as unhealthy for a minute and fail each
# later up --wait of the run, so it takes at least two in a row (retries).
problems=0
for file in tests/e2e/compose.yaml tests/e2e/compose.pgsql.yaml tests/e2e/compose.mysql.yaml tests/e2e/compose.s3.yaml; do
  checks="$(grep -cE '^ +healthcheck:$' "$file" || true)"
  quick="$(grep -cE '^ +start_interval: [0-9]+s$' "$file" || true)"
  # Up to 29 seconds, the interval is too short for a service that runs.
  seldom="$(grep -cE '^ +interval: ([0-9]+m|[3-9][0-9]s|[0-9]{3,}s)$' "$file" || true)"
  patient="$(grep -cE '^ +retries: ([2-9]|[0-9]{2,})$' "$file" || true)"
  if [[ "$checks" == 0 || "$quick" != "$checks" || "$seldom" != "$checks" || "$patient" != "$checks" ]]; then
    echo "$file: of its $checks health checks, $quick have a start_interval, $seldom an interval of at least 30 s and $patient at least two retries. Check often only while a service starts, seldom once it runs, and let one late answer pass: tests/e2e/README.md#how-the-suite-stays-quick."
    problems=$((problems + 1))
  fi
done
if ((problems > 0)); then
  exit 1
fi
echo "The health checks of the end-to-end tests check often only while their services start, and let one late answer pass."

printf '\n== %s\n' "Downloads that a script runs"
# Scorecard, which the workflow Findings runs on main, reports a pipe that hands a
# download to a program, such as curl … | bash, as a download that runs without a pinned
# hash (Pinned-Dependencies, downloadThenRun), and Findings fails on main until the pipe
# is gone. It names the program by the first word of the command after the pipe that is
# plain text, other than sudo: the exec of "${COMPOSE[@]}" exec … python counts as well,
# also when the program only reads the download as data. This check finds such a pipe in
# the shell scripts before a pull request merges, in the lines that a backslash or a pipe
# at their end continues, without comments and the text in single quotes, which the shell
# hands on as it is. Scorecard parses the code of sh -c '…' (and of exec, su, bash, …)
# again as a script, so the check also looks into the text in single quotes after a -c
# word. It first tests itself with samples.
# Prints FILE:LINE: COMMAND for each such pipe in the files.
download_runs() {
  local s='[[:space:]]' path='([^[:space:]|;&]*/)?' assignment='[A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*'
  local start="(^|[;&|({\`]|(^|$s)(then|do|else|elif|if|while|until|time|!)$s)$s*"
  local leading="((sudo|$assignment)$s+)*"
  local download="${path}(curl|wget)($s|\$)([^|;&]|&[0-9>-])*[|]&?$s*"
  local skipped="((\"[^\"]*\"|'[^']*'|[\$][^[:space:]]*|sudo|$assignment)$s+)*"
  local program="${path}(exec|su|sh|bash|dash|ksh|mksh|zsh|python[0-9.]*|perl|ruby|php|node|nodejs|java)($s|[;&|)]|\$)"
  PATTERN="$start$leading$download$skipped$program" awk -v q="'" '
    BEGIN { quoted = q "[^" q "]*" q }
    FNR == 1 { text = "" }
    text == "" && /^[[:space:]]*#/ { next }
    {
      if (text == "") start = FNR
      line = $0
      continued = sub(/\\$/, "", line)
      text = text line
      if (continued) next
      if (text ~ /(^|[^|])[|][[:space:]]*$/) { text = text " "; next }
      plain = text
      gsub(quoted, q q, plain)
      found = plain ~ ENVIRON["PATTERN"]
      rest = text
      while (!found && match(rest, quoted)) {
        code = substr(rest, RSTART + 1, RLENGTH - 2)
        found = substr(rest, 1, RSTART - 1) ~ /(^|[[:space:]])-c[[:space:]]+$/ && code ~ ENVIRON["PATTERN"]
        rest = substr(rest, RSTART + RLENGTH)
      }
      if (found) print FILENAME ":" start ": " substr(text, 1, 160)
      text = ""
    }' "$@"
}
problems=0
# A sample, in which the check finds 1 or 0 pipes of a download into a program.
expect_download_runs() {
  local expected="$1" sample="$2" found
  found="$(printf '%s\n' "$sample" | download_runs - | wc -l | tr -d '[:space:]')"
  if [[ "$found" != "$expected" ]]; then
    echo "The check finds $found pipe(s) of a download into a program instead of $expected in: $sample"
    problems=$((problems + 1))
  fi
}
# shellcheck disable=SC2016 # The samples are scripts, whose variables stay as written.
{
  expect_download_runs 1 'curl -fsSL https://example.com/install.sh | bash'
  expect_download_runs 1 'wget -qO- https://example.com/install.sh | sudo sh -s -- --yes'
  expect_download_runs 1 'answer="$(curl --silent "${URL}" | python3 -c "import sys; print(sys.stdin.read())")"'
  expect_download_runs 1 $'\tcurl --fail-with-body --silent \\\n\t\t"${URL}" \\\n\t\t| "${COMPOSE[@]}" exec -T paperless-mock python -c \'\nimport sys\nprint(sys.stdin.read())\''
  expect_download_runs 1 $'if curl --silent "${URL}" 2>&1 |\n\t/usr/bin/node -; then'
  expect_download_runs 1 $'"${COMPOSE[@]}" exec -T nextcloud sh -c \'curl -s "$U" | sh\''
  expect_download_runs 0 $'"${COMPOSE[@]}" exec -T nextcloud sh -c \'echo "curl -s $U | sh" > notes\''
  expect_download_runs 0 $'curl --silent --output "${answer}" "${URL}"\n"${COMPOSE[@]}" exec -T paperless-mock python -c \'print(1)\' < "${answer}"'
  expect_download_runs 0 $'printf \'%s\\n\' "${content}" | curl --upload-file - "${URL}"'
  expect_download_runs 0 $'curl --dump-header - "${URL}" | tr -d \'\\r\' | sed -n \'s|^Location: ||p\''
  expect_download_runs 0 'curl --silent "${URL}" | grep python'
  expect_download_runs 0 'curl --fail --silent "${URL}" || bash -c "echo offline"'
  expect_download_runs 0 'curl --silent "${URL}"; cat install.sh | bash'
  expect_download_runs 0 $'"${COMPOSE[@]}" exec -T nextcloud sh -c \'cat nextcloud.log\' \\\n\t| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py'
  expect_download_runs 0 '# curl https://example.com/install.sh | bash'
}
mapfile -t shell_scripts < <(git ls-files --cached --others --exclude-standard --deduplicate -- '*.sh' '*.bash' '.githooks/*')
found="$(download_runs "${shell_scripts[@]}")"
if [[ -n "$found" ]]; then
  echo "A pipe hands a download to a program, which Scorecard reports as a download that runs without a pinned hash. Save the download into a file and give that file to the program, as file_tags in tests/e2e/run.sh does:"
  printf '%s\n' "$found"
  problems=$((problems + 1))
fi
if ((problems > 0)); then
  exit 1
fi
echo "No pipe of the ${#shell_scripts[@]} shell scripts hands a download to a program, and the check finds each of its samples."

printf '\n== %s\n' "Icon of the website"
# The website shows the icon of the app as its logo, its favicon and on its preview
# cards, from a copy in docs/images, since MkDocs publishes only what is in docs/.
if ! cmp -s img/app.svg docs/images/app.svg; then
  echo "docs/images/app.svg differs from the icon of the app; copy it: cp img/app.svg docs/images/app.svg"
  exit 1
fi
echo "The website shows the icon of the app, img/app.svg."

printf '\n== %s\n' "Hook of the website"
# The hook .github/mkdocs_project.py writes the versions of appinfo/info.xml into the
# pages of the website. Its tests need Python alone, no MkDocs.
if [[ -n "$python_any" ]]; then
  "$python_any" -B tests/website/test_mkdocs_project.py
else
  echo "Python 3 is not installed here; the CI tests the hook of the website."
fi

printf '\n== %s\n' "Versions in the README"
# The first screen of the README shows the version in the App Store and the versions of
# Nextcloud in badges that read appinfo/info.xml on main, so that they follow every
# release and every raise of max-version by the upstream bot without an edit, and so
# does that of README.de.md. The fields such as <span data-app="nextcloud">33–35</span>
# in both READMEs and in the home pages and comparisons of the website are text on
# GitHub, which the hook of the website fills with the versions of info.xml. They may lag behind a max-version that the upstream bot raised,
# so that its pull request passes on its own, but they never name another minimum or
# more than info.xml.
dash="$(printf '\342\200\223')"
info_versions() {
  local lowest="$1" highest="$2"
  if [[ -z "$highest" ]]; then
    echo "$lowest+"
  elif [[ "$highest" == "$lowest" ]]; then
    echo "$lowest"
  else
    echo "$lowest$dash$highest"
  fi
}
attribute() {
  sed -nE "s/.* $1=\"([^\"]*)\".*/\1/p" <<<"$2"
}
nextcloud="$(grep -oE '<nextcloud [^>]*>' appinfo/info.xml | head -n 1 || true)"
php="$(grep -oE '<php [^>]*>' appinfo/info.xml | head -n 1 || true)"
nextcloud_min="$(attribute min-version "$nextcloud")"
nextcloud_max="$(attribute max-version "$nextcloud")"
php_min="$(attribute min-version "$php")"
if [[ -z "$nextcloud_min" || -z "$php_min" ]]; then
  echo "appinfo/info.xml names no min-version of Nextcloud or of PHP."
  exit 1
fi
nextcloud_text="$(info_versions "$nextcloud_min" "$nextcloud_max")"
php_text="$(info_versions "$php_min" "$(attribute max-version "$php")")"
problems=0
info_xml='https%3A%2F%2Fraw.githubusercontent.com%2FDennis-Otto%2Fpaperless-sync%2Fmain%2Fappinfo%2Finfo.xml'
readmes=(README.md README.de.md)
version_pages=("${readmes[@]}" docs/index.md docs/index.de.md docs/alternatives.md docs/alternatives.de.md)
for readme in "${readmes[@]}"; do
  for query in '%2Finfo%2Fversion' \
    'concat%28%2F%2Fdependencies%2Fnextcloud%2F%40min-version%2C%27%E2%80%93%27%2C%2F%2Fdependencies%2Fnextcloud%2F%40max-version%29'; do
    if ! grep -qF "https://img.shields.io/badge/dynamic/xml?url=$info_xml&query=$query&" "$readme"; then
      echo "$readme has no badge that reads $query from appinfo/info.xml on main: https://img.shields.io/badge/dynamic/xml?url=$info_xml&query=$query&label=..."
      problems=$((problems + 1))
    fi
  done
done
fields=0
while IFS= read -r field; do
  file="${field%%:*}"
  name="$(sed -E 's/^[^:]*:data-app="([^"]*)">.*/\1/' <<<"$field")"
  text="$(sed -E 's/^[^:]*:data-app="[^"]*">([^<]*)<$/\1/' <<<"$field")"
  fields=$((fields + 1))
  case "$name" in
    php) expected="$php_text" label=PHP ;;
    nextcloud) expected="$nextcloud_text" label=Nextcloud ;;
    *)
      echo "$file has the field data-app=\"$name\", which the hook of the website doesn't know."
      problems=$((problems + 1))
      continue
      ;;
  esac
  if [[ "$text" == "$expected" ]]; then
    continue
  fi
  # Only the highest version of Nextcloud may lag, and only behind info.xml.
  highest="${text#"$nextcloud_min$dash"}"
  if [[ "$name" == nextcloud && -n "$nextcloud_max" && "$highest" != "$text" && "$highest" =~ ^[0-9]+$ ]] &&
    ((highest >= nextcloud_min && highest < nextcloud_max)); then
    echo "Note: $file names Nextcloud $text, appinfo/info.xml $expected; the website shows $expected, and the next edit of $file can catch up."
    continue
  fi
  echo "$file names $label $text, but appinfo/info.xml says $expected; write $expected."
  problems=$((problems + 1))
done < <(grep -oE 'data-app="[^"]*">[^<]*<' "${version_pages[@]}" || true)
for readme in "${readmes[@]}"; do
  if ! grep -qF 'data-app="nextcloud">' "$readme" || ! grep -qF 'data-app="php">' "$readme"; then
    echo "The requirements in $readme name the versions in the fields <span data-app=\"nextcloud\"> and <span data-app=\"php\">; one of them is missing."
    problems=$((problems + 1))
  fi
done
if ((problems > 0)); then
  exit 1
fi
echo "The badges of both READMEs read appinfo/info.xml on main, and the $fields field(s) of versions in ${version_pages[*]} agree with Nextcloud $nextcloud_text and PHP $php_text."

printf '\n== %s\n' "Description of the app"
# GitHub, the website and Composer describe the app in the same words, those that people
# search for: the answer description of .copier-answers.yml, which the blueprint writes
# into .github/repository.toml for the settings bot. Change it with
#   copier update --defaults --vcs-ref <_commit of .copier-answers.yml> --data description=...
# and then in mkdocs.yml and composer.json.
description="$(sed -n 's/^description = "\(.*\)"$/\1/p' .github/repository.toml)"
website="$(sed -n 's/^site_description: "\(.*\)"$/\1/p' mkdocs.yml)"
composer="$(sed -n 's/^[[:space:]]*"description": "\(.*\)",$/\1/p' composer.json)"
if [[ -z "$description" || "$website" != "$description" || "$composer" != "$description" ]]; then
  echo "The repository has the description \"${description:-none}\", mkdocs.yml the site_description \"${website:-none}\" and composer.json the description \"${composer:-none}\"; give all three the same."
  exit 1
fi
echo "GitHub, the website and Composer describe the app in the same words."

printf '\n== %s\n' "German documentation"
# README.de.md and each German page of the website, such as docs/guide.de.md, translate
# their English twin, README.md or docs/guide.md, which the language switches of GitHub
# and of the website lead between. So both have the same outline: the same headings at
# the same levels in the same order, the same snippets of the README for the website,
# README.de.md in place of README.md, and the same diagrams, the German one NAME.de for
# each NAME. A section that a change adds to one of them, moves or drops shows here, with
# the place in both files; the words are for the translator, not for this check.
#
# The outline of a page, one entry per line: what it is, a tab, and its line. A heading
# is its level, a snippet its marker or its include, and a diagram its source. Front
# matter and code blocks have no entries. With "twin" as the second argument, the outline
# that the German twin of the page must have: README.de.md for README.md in an include,
# and docs/diagrams/NAME.de.mmd for NAME.mmd.
outline() {
  awk -v twin="${2:-}" '
    FNR == 1 && /^---[ \t]*$/ { front = 1; next }
    front { if (/^---[ \t]*$/) front = 0; next }
    /^[ \t]*(```|~~~)/ { fenced = !fenced; next }
    fenced { next }
    match($0, /^#+[ \t]/) && RLENGTH <= 7 {
      printf "heading of level %d (\"%s\")\t%d\n", RLENGTH - 1, $0, FNR
    }
    match($0, /<!-- --8<-- \[(start|end):[^]]+\] -->/) {
      printf "snippet marker %s\t%d\n", substr($0, RSTART + 12, RLENGTH - 16), FNR
    }
    /^--8<-- "/ {
      text = $0
      if (twin != "") sub(/"README\.md/, "\"README.de.md", text)
      printf "snippet include %s\t%d\n", text, FNR
    }
    match($0, / src="[^"]*images\/diagrams\/[^"\/]+\.svg"/) {
      name = substr($0, RSTART, RLENGTH)
      sub(/.*\//, "", name)
      sub(/\.svg"$/, "", name)
      printf "diagram docs/diagrams/%s%s.mmd\t%d\n", name, (twin != "" ? ".de" : ""), FNR
    }
    match($0, /srcset="[^"]*images\/diagrams\/[^"\/]+\.dark\.svg"/) {
      name = substr($0, RSTART, RLENGTH)
      sub(/.*\//, "", name)
      sub(/\.dark\.svg"$/, "", name)
      printf "dark diagram docs/diagrams/%s%s.mmd\t%d\n", name, (twin != "" ? ".de" : ""), FNR
    }
  ' "$1"
}
# Compares the outline of the German page $2 with the one that its English twin $1 asks
# for. A heading compares by its level alone, as its words differ, so the message names
# the first entry where they part and the last two that agree, around the change. A
# heading added or removed among others of its level shows only at the end of their run,
# so for a heading the message also names the entry before that run: the change is
# somewhere between the two.
german_twin() {
  local english="$1" german="$2"
  awk -F '\t' -v english="$english" -v german="$german" '
    function kind(entry) { sub(/ \(.*/, "", entry); return entry }
    function level(entry) { return entry ~ /^heading of level / ? substr(entry, 18, 1) + 0 : 0 }
    NR == FNR { want[FNR] = $1; wline[FNR] = $2; wanted = FNR; next }
    { have[FNR] = $1; hline[FNR] = $2; had = FNR }
    END {
      for (i = 1; i <= wanted || i <= had; i++) {
        if (kind(want[i]) != kind(have[i])) break
      }
      if (i > wanted && i > had) exit 0
      printf "%s doesn'\''t follow the outline of %s: ", german, english
      if (i > had) {
        printf "it ends where line %d of %s asks for the %s.", wline[i], english, want[i]
      } else if (i > wanted) {
        printf "line %d has the %s, which %s lacks.", hline[i], have[i], english
      } else {
        printf "line %d has the %s where line %d of %s asks for the %s.", hline[i], have[i], wline[i], english, want[i]
      }
      if (i > 1) {
        printf " Before it, both agree up to line %d, the %s, and line %d of %s, the %s.", hline[i - 1], have[i - 1], wline[i - 1], english, want[i - 1]
      }
      depth = level(want[i]) > level(have[i]) ? level(want[i]) : level(have[i])
      for (j = i - 1; depth && j >= 1 && level(have[j]) >= depth; j--) {}
      if (depth && j < i - 1) {
        printf " A heading of level %d was added or removed somewhere between there and ", depth
        if (j < 1) printf "the start of both files."
        else printf "line %d, the %s, and line %d of %s, the %s.", hline[j], have[j], wline[j], english, want[j]
      }
      printf "\n"
      exit 1
    }
  ' <(outline "$english" twin) <(outline "$german")
}
problems=0
pages=0
if [[ ! -f README.de.md ]]; then
  echo "README.md switches to its German twin, README.de.md, which is missing; translate README.md into it."
  problems=$((problems + 1))
fi
while IFS= read -r -d '' german; do
  english="${german%.de.md}.md"
  pages=$((pages + 1))
  if [[ ! -f "$english" ]]; then
    echo "$german is the German twin of $english, which doesn't exist; rename it after the page it translates."
    problems=$((problems + 1))
  elif ! german_twin "$english" "$german"; then
    problems=$((problems + 1))
  fi
done < <(git ls-files -z --cached --others --exclude-standard -- '*.de.md')
if ((problems > 0)); then
  echo "A German page has the headings, the snippets and the diagrams of its English twin, in the same order; translate the change of the English page into the German one, a German diagram into docs/diagrams/NAME.de.mmd (bash scripts/diagrams.sh draws it)."
  exit 1
fi
echo "The $pages German page(s) follow the headings, snippets and diagrams of their English twins."

# And the check finds what it must in broken German twins: a heading that is gone, one of
# another level, one too many, one gone among others of its level, an English diagram,
# light or dark, a snippet of README.md in a German page, and a German page that ends
# early.
twin_copy="$(mktemp -d)"
trap 'rm -rf "$twin_copy"' EXIT
broken_twin() {
  local english="$1" expected="$2" output
  shift 2
  sed "$@" "${english%.md}.de.md" >"$twin_copy/twin.de.md"
  if output="$(german_twin "$english" "$twin_copy/twin.de.md")" || [[ "$output" != *"$expected"* ]]; then
    echo "The check of the German twins should report \"$expected\" for a broken copy of ${english%.md}.de.md, but says: ${output:-nothing}"
    exit 1
  fi
}
broken_twin README.md 'of README.md asks for the heading of level 3 ("### Operations")' -e '/^### Eingang$/d'
broken_twin README.md 'has the heading of level 4 ("#### Archiv") where line' -e 's/^### Archiv$/#### Archiv/'
# shellcheck disable=SC2016 # The $ of sed, the last line, which the shell must not expand.
broken_twin docs/faq.md 'has the heading of level 2 ("## Mehr"), which docs/faq.md lacks' -e '$a ## Mehr'
broken_twin README.md 'has the diagram docs/diagrams/how-paperless-sync-moves-documents.mmd where line' \
  -e 's/how-paperless-sync-moves-documents\.de\.svg/how-paperless-sync-moves-documents.svg/'
broken_twin README.md 'has the dark diagram docs/diagrams/how-paperless-sync-moves-documents.mmd where line' \
  -e 's/how-paperless-sync-moves-documents\.de\.dark\.svg/how-paperless-sync-moves-documents.dark.svg/'
# The last entry before the headings of level 3 that follow it is the diagram of the first
# synchronization, whose line follows the page, which grows with its answers.
first_line="$(grep -n 'src="images/diagrams/the-first-synchronization.de.svg"' docs/faq.de.md | cut -d: -f1)"
broken_twin docs/faq.md "removed somewhere between there and line $first_line, the diagram docs/diagrams/the-first-synchronization.de.mmd, and line" \
  -e '/^### Wie oft läuft es?$/d'
broken_twin docs/guide.md 'has the snippet include --8<-- "README.md:features" where line' \
  -e 's/README\.de\.md:features/README.md:features/'
# shellcheck disable=SC2016 # The $ of sed, the last line, which the shell must not expand.
broken_twin docs/faq.md 'it ends where line' -e '/^### Wie deinstalliere ich Paperless Sync?$/,$d'
rm -rf "$twin_copy"
trap - EXIT
echo "The check of the German twins reports a heading that is gone, of another level, too many or gone among others, an English diagram, light or dark, an English snippet and a page that ends early."

printf '\n== %s\n' "Area labels of pull requests"
# The labeler bot of .github/workflows/area-labels.yml gives a pull request the area
# labels of .github/labeler.yml by the files that it changes, and takes them away when
# it no longer changes them. So every file of lib/, js/, css/, templates/ and
# tests/Unit/ belongs to an area, every glob names a file that exists, and every area
# of .github/labels.toml has a rule. The globs are read as the labeler reads them:
# * and ? stay within a folder, ** spans folders. The argument names the file of the
# rules, or - for rules on stdin.
# shellcheck disable=SC2016
area_labels='
import os, pathlib, re, subprocess, sys, tomllib

name = sys.argv[1]
rules_text = sys.stdin.buffer.read() if name == "-" else pathlib.Path(name).read_bytes()
name = "the copy of .github/labeler.yml" if name == "-" else name
problems = []


def regex(glob):
    if re.search(r"[][{}()!+@]", glob):
        problems.append(f"{name}: the glob {glob} uses more than *, ** and ?, which this check reads.")
    if re.search(r"[^/]\*\*|\*\*[^/]", glob):
        problems.append(f"{name}: the glob {glob} has ** inside a name, where the labeler reads it as *; write * there, or ** alone as a whole folder.")
    parts = {"**/": "(?:.*/)?", "/**": "/.*", "**": ".*", "*": "[^/]*", "?": "[^/]"}
    tokens = re.findall(r"\*\*/|/\*\*$|\*\*|\*|\?|[^*?/]+|/", glob)
    return re.compile("".join(parts.get(token, re.escape(token)) for token in tokens))


rules = {}
label = None
for number, line in enumerate(rules_text.decode("utf-8").splitlines(), 1):
    if not line.strip() or line.lstrip().startswith("#"):
        continue
    if match := re.fullmatch(r"\"([^\"]+)\":\s*", line):
        label = match[1]
        rules[label] = []
    elif label and re.fullmatch(r"\s+- (changed-files|any-glob-to-any-file):\s*", line):
        continue
    elif label and (match := re.fullmatch(r"\s+- ([^\s:#\"]+)\s*", line)):
        rules[label].append((match[1], regex(match[1])))
    else:
        problems.append(f"{name}:{number}: this check reads only labels, changed-files, any-glob-to-any-file and globs, not: {line.strip()}")

with open(".github/labels.toml", "rb") as labels:
    areas = {entry["name"] for entry in tomllib.load(labels)["label"] if entry.get("group") == "area"}
problems += [f"{name} gives the label \"{label}\", which is no area of .github/labels.toml." for label in sorted(rules.keys() - areas)]
problems += [f"The area \"{label}\" of .github/labels.toml has no rule in {name}." for label in sorted(areas - rules.keys())]

listed = subprocess.run(["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"], capture_output=True, check=True)
files = [path for path in listed.stdout.decode("utf-8").split("\0") if os.path.isfile(path)]
globs = [glob for label in rules for glob in rules[label]]
problems += [f"{name}: the glob {glob} matches no file; correct or remove it." for glob, pattern in globs if not any(pattern.fullmatch(path) for path in files)]
sources = [path for path in files if re.match(r"(lib|js|css|templates|tests/Unit)/", path)]
problems += [f"{path} belongs to no area; add it to the rule of its area in {name}." for path in sources if not any(pattern.fullmatch(path) for _, pattern in globs)]

print("\n".join(problems) if problems else f"Each of the {len(sources)} file(s) of lib/, js/, css/, templates/ and tests/Unit/ belongs to an area of {name}, each of its {len(globs)} glob(s) names a file, and each of the {len(areas)} area(s) of .github/labels.toml has a rule.")
sys.exit(1 if problems else 0)
'
if [[ -n "$python" ]]; then
  "$python" -B -c "$area_labels" .github/labeler.yml
  # And the check finds a file without an area, a glob without a file, a ** inside a
  # name and an area without a rule, in copies of the rules with one of them broken.
  broken() {
    local expected="$1" output
    if output="$("$python" -B -c "$area_labels" - 2>&1)" ||
      [[ "$output" != *"$expected"* ]]; then
      echo "The check of the area labels should report \"$expected\" for a broken copy of .github/labeler.yml, but says: $output"
      exit 1
    fi
  }
  grep -v -- '- templates/settings\.php$' .github/labeler.yml |
    broken 'templates/settings.php belongs to no area'
  { cat .github/labeler.yml && echo '          - lib/Service/Missing*.php'; } |
    broken 'the glob lib/Service/Missing*.php matches no file'
  { cat .github/labeler.yml && echo '          - lib/Service/Paperless**.php'; } |
    broken 'the glob lib/Service/Paperless**.php has ** inside a name'
  sed 's/^"area: inbox":/"area: other":/' .github/labeler.yml |
    broken 'The area "area: inbox" of .github/labels.toml has no rule'
  echo "The check of the area labels reports a file without an area, a glob without a file, a ** inside a name and an area without a rule."
else
  echo "Python 3.11 or newer is not installed here; the CI checks the area labels of pull requests."
fi

printf '\n== %s\n' "Explanations of the checks"
# When a check of this app alone fails, the PR coach explains it from
# .github/checks.project.toml, also on a pull request from a fork, and the fix bot runs
# the commands of its autofix. tests/coach/test_checks_project.py checks that every
# required check, every step of scripts/check.sh and every check of this script has its
# explanation, that every job of e2e.yml that runs the code explains its failure, that
# the files and headings that the explanations name exist, and that the commands of
# autofix fix what they promise.
if [[ -n "$python" ]]; then
  "$python" -B tests/coach/test_checks_project.py
else
  echo "Python 3.11 or newer is not installed here; the CI checks the explanations of the checks."
fi

printf '\n== %s\n' "Checks of the upgrade test"
# tests/e2e/assert_upgrade.py, which tells whether the upgrade from the last release kept
# the rows, the settings, the credentials, the files and the retries of an installation in
# use: with samples of upgrades that lose each of them, which it must find.
if [[ -n "$python" ]]; then
  "$python" -B tests/e2e/test_assert_upgrade.py
else
  echo "Python 3.11 or newer is not installed here; the CI checks the checks of the upgrade test."
fi

printf '\n== %s\n' "First error of a failed end-to-end run"
# tests/e2e/failure.py, which names the first error of each run that failed in the summary
# of the job and in the issue of the canary: with a sample of the log of each kind of
# failure, a check in the browser, an assertion, a message of run.sh, the time limit and a
# broken contract.
if [[ -n "$python" ]]; then
  "$python" -B tests/e2e/test_failure.py
else
  echo "Python 3.11 or newer is not installed here; the CI checks the first error of a failed end-to-end run."
fi

printf '\n== %s\n' "Check of the package"
# scripts/check-package.sh, which scripts/check.sh runs on the package of krankerl and
# the release workflow on the package of every release, with the packages it must
# reject: one that lacks a runtime file, or holds a stray, changed or linked one, or the
# wrong signature. In a throwaway repository, without krankerl.
bash tests/package/test_check_package.sh

printf '\n== %s\n' "JavaScript and translations"
if ! command -v node >/dev/null 2>&1; then
  echo "Node.js is not installed here; the CI checks the JavaScript and the translations."
  exit 0
fi
mapfile -t scripts < <(git ls-files 'js/*.js' 'l10n/*.js' 'tests/e2e/*.mjs')
for script in "${scripts[@]}"; do
  node --check "$script"
done
echo "${#scripts[@]} JavaScript file(s) parse."
# The rules of ESLint, scripts/eslint/eslint.config.mjs, for the JavaScript of the app, of its
# tests and of its scripts: the recommended ones, and eqeqeq, no-unused-vars,
# no-implicit-globals, prefer-const and no-var. scripts/eslint.sh installs ESLint with npm ci
# on its first run.
bash scripts/eslint.sh
echo "ESLint finds nothing in the JavaScript of the app, of its tests and of its scripts."
# And ESLint finds what it must in a seeded script of the app: a variable that nothing uses,
# a comparison with ==, a function that becomes a global of the page, a var and a let that
# never changes; --fix then makes the var and the let a const, and leaves the rest to a person.
root="$PWD"
eslint_copy="$(mktemp -d)"
trap 'rm -rf "$eslint_copy"' EXIT
mkdir "$eslint_copy/js"
cat >"$eslint_copy/js/seeded.js" <<'SEEDED'
(function() {
	'use strict'
	var count = 1
	let total = count + 1
	const unused = total
	if (total == '2') {
		window.console.log(total)
	}
})()
function leaked() {}
SEEDED
if output="$(cd "$eslint_copy" && bash "$root/scripts/eslint.sh" 2>&1)"; then
  echo "scripts/eslint.sh should fail on the seeded script, but passes: $output"
  exit 1
fi
for rule in no-unused-vars eqeqeq no-implicit-globals no-var prefer-const; do
  if [[ "$output" != *" $rule"* ]]; then
    echo "scripts/eslint.sh should report $rule in the seeded script, but says: $output"
    exit 1
  fi
done
(cd "$eslint_copy" && bash "$root/scripts/eslint.sh" --fix >/dev/null 2>&1) || true
fixed="$(sed -n '3,5p' "$eslint_copy/js/seeded.js")"
if [[ "$fixed" != $'\tconst count = 1\n\tconst total = count + 1\n\tconst unused = total' ]]; then
  echo "scripts/eslint.sh --fix should make the var and the let of the seeded script a const, but leaves:"
  cat "$eslint_copy/js/seeded.js"
  exit 1
fi
rm -rf "$eslint_copy"
echo "ESLint reports an unused variable, ==, an accidental global, var and a let that never changes, and --fix makes the last two a const."
# The minimatch under ESLint: the lock file keeps the one with the fix of an advisory, which
# ESLint doesn't ask for yet, and ESLint still matches its globs with it.
node --test --test-reporter=dot scripts/eslint/minimatch.test.mjs
echo "The lock file of ESLint holds a minimatch with the fix of GHSA-g3hv-75wg-m249, and ESLint matches its globs with it."
# The thumbnails of the screenshots of the App Store, which tests/e2e/screenshots.mjs
# shrinks with tests/e2e/thumbnail.mjs.
node --test --test-reporter=dot tests/e2e/thumbnail.test.mjs
echo "tests/e2e/thumbnail.mjs shrinks a screenshot into the box of its thumbnail, each pixel the average of those it covers."
# The language of the pictures: the German ones find every text of the app that they look
# for in its catalog, l10n/de.json, and the languages without pictures have their folders.
node --test --test-reporter=dot tests/e2e/language.test.mjs
echo "tests/e2e/language.mjs takes the English pictures to docs/images/ and the German ones to docs/images/de/, with every text that they look for in German."
# The animations of the documentation: each that the script records is there in both
# languages and both themes, and stays below 1 MB.
node --test --test-reporter=dot tests/e2e/screenshots.test.mjs
echo "Every animation of tests/e2e/screenshots.mjs is there in English and German, in the light and the dark theme, and stays below 1 MB."
# The address of Files in the browser tests, which Files of Nextcloud 36 routes only as
# Nextcloud links it.
node --test --test-reporter=dot tests/e2e/files-address.test.mjs
echo "tests/e2e/files-address.mjs opens Files at the address that Nextcloud links, and no browser test opens it at an address of its own."
# What the checks of the accessibility in the browser judge by, with a seeded finding of every
# kind that must fail them: a button without a name, a box wider than a phone, a focus that
# can't be seen and a focus against the order of the page.
node --test --test-reporter=dot tests/e2e/a11y.test.mjs
echo "tests/e2e/a11y.mjs fails a button without a name, a box wider than 320 px, a faint focus and a focus against the order of the page."
# The browser of the checks in the browser, which keeps the traces and the screenshots of a
# check that fails, and nothing of one that passes.
node --test --test-reporter=dot tests/e2e/browser.test.mjs
echo "tests/e2e/browser.mjs keeps the traces and screenshots of a check in the browser that fails, also of a window that it closed, records the coverage of the scripts of the app in every page, and every check starts its browser through it."
# The coverage of the JavaScript in the browser, which the end-to-end tests on the newest
# Nextcloud report in the summary of the job and hold to the floor of
# tests/e2e/coverage-floor.json.
node --test --test-reporter=dot tests/e2e/coverage.test.mjs
echo "tests/e2e/coverage.mjs reports the lines, branches and functions of each script that the checks in the browser run, and fails a script below its floor, which every script of js/ has."
# The frozen story of the pictures, so that two runs of tests/e2e/screenshots.sh draw the
# same pixels, and the report of the visual check, which renders on the paths of the
# screenshot bot.
node --test --test-reporter=dot tests/e2e/frozen.test.mjs
echo "tests/e2e/frozen.mjs shows the moments of the story, an example secret and files that changed a few seconds ago in every picture."
node --test --test-reporter=dot tests/e2e/compare.test.mjs
echo "tests/e2e/compare.mjs draws the changed pixels in red, and the visual check renders on the paths of .github/screenshots-paths.txt."
node scripts/check-l10n.mjs

# And the check finds what it must in broken copies of each catalog, in German and in French,
# whose plurals have a third form: a text that the code translates but the catalog lacks, also the
# text of an error that a run keeps, one that no code translates, a translation without the
# placeholder of its English one or with a lone %, a text twice, a plural with too few forms or
# with another form for millions, and a copy for the browser that differs; --fix then drops the
# unused text and writes the copy for the browser anew.
l10n_copy="$(mktemp -d)"
trap 'rm -rf "$l10n_copy"' EXIT
fresh_catalogs() {
  rm -f "$l10n_copy"/*.json "$l10n_copy"/*.js
  cp l10n/*.json l10n/*.js "$l10n_copy/"
}
# Runs the JavaScript of the second argument on the catalog of the language of the first, with its
# translations as t and the whole catalog as c.
edit_catalog() {
  node -e 'const fs = require("fs"); const [file, change] = process.argv.slice(1);
    const catalog = JSON.parse(fs.readFileSync(file, "utf8")); new Function("t", "c", change)(catalog.translations, catalog);
    fs.writeFileSync(file, JSON.stringify(catalog, null, "\t") + "\n")' "$l10n_copy/$1.json" "$2"
}
broken_catalog() {
  local expected="$1" output
  if output="$(node scripts/check-l10n.mjs "$l10n_copy" 2>&1)" || [[ "$output" != *"$expected"* ]]; then
    echo "scripts/check-l10n.mjs should report \"$expected\" for a broken copy of l10n/, but says: $output"
    exit 1
  fi
}
for language in de fr; do
  catalog="l10n/$language.json"
  fresh_catalogs
  edit_catalog "$language" 'delete t["Paperless Sync"]'
  broken_catalog "translates a text that $catalog lacks: \"Paperless Sync\""
  # The errors that a run keeps count too, as the settings page translates them later.
  fresh_catalogs
  edit_catalog "$language" 'delete t["Tags: %s"]'
  broken_catalog "translates a text that $catalog lacks: \"Tags: %s\""
  fresh_catalogs
  edit_catalog "$language" 't["A text that no code translates"] = "Words"'
  broken_catalog "$catalog translates a text that no code translates: \"A text that no code translates\""
  broken_catalog "l10n/$language.js, which the browser loads, and $catalog, which PHP loads, differ."
  node scripts/check-l10n.mjs --fix "$l10n_copy" >/dev/null
  node scripts/check-l10n.mjs "$l10n_copy" >/dev/null
  if ! cmp -s "$catalog" "$l10n_copy/$language.json" || ! cmp -s "l10n/$language.js" "$l10n_copy/$language.js"; then
    echo "scripts/check-l10n.mjs --fix should drop the unused text and write l10n/$language.js as it was."
    exit 1
  fi
  edit_catalog "$language" 't["Nextcloud user %s does not exist."] = "Words without the user."'
  broken_catalog 'has the placeholders none, but "Nextcloud user %s does not exist." has argument 1.'
  # PHP stops at a % that starts no placeholder, such as in 100 %.
  fresh_catalogs
  edit_catalog "$language" 't["Paperless Sync"] = "100 % Paperless Sync"'
  broken_catalog 'has the placeholders a lone %, but "Paperless Sync" has none.'
  fresh_catalogs
  edit_catalog "$language" 't["_%n minute_::_%n minutes_"] = ["%n x"]'
  broken_catalog "$catalog has 1 form(s) for \"_%n minute_::_%n minutes_\""
  fresh_catalogs
  sed -n '3p' "$catalog" >"$l10n_copy/line"
  sed -i "3r $l10n_copy/line" "$l10n_copy/$language.json"
  broken_catalog "$catalog has a text twice"
  rm -f "$l10n_copy/line"
done
# The browser shows the form for millions for every count but one, so it repeats the other form.
fresh_catalogs
edit_catalog fr 't["_%n minute_::_%n minutes_"][1] = "%n de minutes"'
broken_catalog 'has the form "%n de minutes" for millions of "_%n minute_::_%n minutes_"'
# A rule of the plurals that names no number of forms, or a form beyond it.
fresh_catalogs
edit_catalog fr 'c.pluralForm = "plural=(n > 1);"'
broken_catalog 'l10n/fr.json has no "pluralForm" that names the number of its forms'
fresh_catalogs
edit_catalog fr 'c.pluralForm = "nplurals=3; plural=n + 2;"'
broken_catalog 'l10n/fr.json has no "pluralForm" that names the number of its forms'
# A translator's typo, a copy for the browser that is missing, and one without its catalog.
fresh_catalogs
printf '{' >"$l10n_copy/es.json"
broken_catalog 'l10n/es.json is no valid JSON'
fresh_catalogs
rm "$l10n_copy/es.js"
broken_catalog 'l10n/es.js, which the browser loads, and l10n/es.json, which PHP loads, differ.'
node scripts/check-l10n.mjs --fix "$l10n_copy" >/dev/null
cmp -s l10n/es.js "$l10n_copy/es.js" || { echo 'scripts/check-l10n.mjs --fix should write a missing l10n/es.js.'; exit 1; }
fresh_catalogs
cp l10n/es.js "$l10n_copy/xx.js"
broken_catalog 'l10n/xx.js has no l10n/xx.json'
# A label that a message names in quotes is the same words in both.
fresh_catalogs
edit_catalog fr 't["Create missing tags"] = "Autres mots"'
broken_catalog 'names "Create missing tags" in quotes, but its translation doesn'"'"'t hold "Autres mots"'
# --new writes an empty catalog in the format of Nextcloud that the check lists, and refuses what
# it can't or must not do; a folder without a catalog fails as well.
fresh_catalogs
node scripts/check-l10n.mjs --new it "$l10n_copy" >/dev/null
broken_catalog 'l10n/it.json has no words for "Paperless Sync"'
if ! grep -qF '"_%n minute_::_%n minutes_": [' "$l10n_copy/it.json" \
  || [[ "$(node -p 'JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")).translations["_%n minute_::_%n minutes_"].length' "$l10n_copy/it.json")" != 3 ]]; then
  echo 'scripts/check-l10n.mjs --new it should write three forms for a plural, as Italian has.'
  exit 1
fi
node scripts/check-l10n.mjs --new sv --plural-form 'nplurals=2; plural=(n != 1);' "$l10n_copy" >/dev/null
# The codes of Nextcloud also have digits, as Spanish of Latin America has, and a script. The
# App Store knows only the language itself.
for language in es_419 sr@latin; do
  node scripts/check-l10n.mjs --new "$language" --plural-form 'nplurals=2; plural=(n != 1);' "$l10n_copy" >/dev/null
  broken_catalog "l10n/$language.json has no words for \"Paperless Sync\""
done
broken_catalog 'appinfo/info.xml has no <summary lang="sr">'
for refused in 'it|exists already' 'sv|exists already' 'tlh|need their rule' 'Not a code|is no code of a language'; do
  if output="$(node scripts/check-l10n.mjs --new "${refused%%|*}" "$l10n_copy" 2>&1)" || [[ "$output" != *"${refused##*|}"* ]]; then
    echo "scripts/check-l10n.mjs --new ${refused%%|*} should say \"${refused##*|}\", but says: $output"
    exit 1
  fi
done
rm -f "$l10n_copy"/*.json "$l10n_copy"/*.js
broken_catalog 'holds no catalog, such as de.json.'
# A text that the check can't read, such as a variable, would make its translation look unused,
# and --fix would drop it: the check names the call instead, and --fix leaves the catalog alone.
mkdir -p "$l10n_copy/app/scripts" "$l10n_copy/app/lib/Model"
cp -r appinfo js templates l10n "$l10n_copy/app/"
cp scripts/check-l10n.mjs "$l10n_copy/app/scripts/"
# shellcheck disable=SC2016 # PHP code, whose variables the shell must not expand.
printf '%s\n' '<?php' 'ConnectionCheck::failed(ConnectionCheck::PAPERLESS, $message, $details);' >"$l10n_copy/app/lib/Model/Unreadable.php"
if output="$(node "$l10n_copy/app/scripts/check-l10n.mjs" --fix 2>&1)" \
  || [[ "$output" != *"lib/Model/Unreadable.php:2 passes a text that the check can't read, \$message"* ]] \
  || ! cmp -s l10n/de.json "$l10n_copy/app/l10n/de.json"; then
  echo "scripts/check-l10n.mjs --fix should refuse a text that it can't read and keep the catalog, but says: $output"
  exit 1
fi
# The App Store shows a language only with its own summary and description.
rm "$l10n_copy/app/lib/Model/Unreadable.php"
sed -i '/<summary lang="fr">/d' "$l10n_copy/app/appinfo/info.xml"
if output="$(node "$l10n_copy/app/scripts/check-l10n.mjs" 2>&1)" || [[ "$output" != *'appinfo/info.xml has no <summary lang="fr">'* ]]; then
  echo "scripts/check-l10n.mjs should report a language without its summary in appinfo/info.xml, but says: $output"
  exit 1
fi
echo "scripts/check-l10n.mjs reports a missing, an unused, a repeated, an unreadable text, a wrong placeholder, a lone %, the wrong forms of a plural, a language without its texts for the App Store and a broken catalog in every language, --fix writes each l10n/<language>.js anew, and --new starts a language."
