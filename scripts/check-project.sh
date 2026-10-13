#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of this app alone, which scripts/check.sh runs last: the browser of the
# end-to-end tests, the rules that decide when they run, the icon of the website,
# and the JavaScript of the settings and the translations, which have no build step.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

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
for path in README.md CHANGELOG.md docs/guide.md docs/images/dry-run.gif tests/e2e/README.md \
  tests/e2e/MANUAL_ACCEPTANCE_TESTS.md tests/Unit/Service/SyncServiceTest.php scripts/check.sh \
  mkdocs.yml docs/images/app.svg docs/stylesheets/brand.css .github/mkdocs_project.py \
  .github/social-cards/preview.yml \
  .github/workflows/ci.yml .github/renovate.json5 version.txt .release-please-manifest.json \
  composer.lock screenshots/01-admin-settings.png; do
  expect skip "$path"
done

# A change of appinfo/info.xml, as git diff --unified=0 shows it. A release writes
# only its version and must skip the tests; the upstream bot raises max-version, and
# that must run them, as any other change of the file.
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
if [[ "$(decision 0000000000000000000000000000000000000000 HEAD)" != affected=true ]]; then
  echo "tests/e2e/affected.sh should run the end-to-end tests when it can't compare the commits."
  problems=$((problems + 1))
fi
if git rev-parse --quiet --verify HEAD >/dev/null && [[ "$(decision HEAD HEAD)" != affected=false ]]; then
  echo "tests/e2e/affected.sh HEAD HEAD should find nothing that runs the end-to-end tests."
  problems=$((problems + 1))
fi
if ((problems > 0)); then
  exit 1
fi
echo "tests/e2e/affected.sh runs the end-to-end tests for the app and its tests, and skips them for the rest."

printf '\n== %s\n' "Icon of the website"
# The website shows the icon of the app as its logo, its favicon and on its preview
# cards, from a copy in docs/images, since MkDocs publishes only what is in docs/.
if ! cmp -s img/app.svg docs/images/app.svg; then
  echo "docs/images/app.svg differs from the icon of the app; copy it: cp img/app.svg docs/images/app.svg"
  exit 1
fi
echo "The website shows the icon of the app, img/app.svg."

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
node scripts/check-l10n.mjs
