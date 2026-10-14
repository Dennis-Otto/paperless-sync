#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of this app alone, which scripts/check.sh runs last: the browser of the
# end-to-end tests, the rules that decide when they run, the icon and the hook of the
# website, the versions in the README, the description of the app, the area labels of
# pull requests, the check of its package, and the JavaScript of the settings and the
# translations, which have no build step.
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
  .github/social-cards/preview.yml tests/website/test_mkdocs_project.py \
  .github/workflows/ci.yml .github/renovate.json5 version.txt .release-please-manifest.json \
  composer.lock screenshots/01-admin-settings.png scripts/check-package.sh \
  tests/package/test_check_package.sh; do
  expect skip "$path"
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
  tests/e2e/README.md README.md; do
  expect_variants skip "$path"
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
if [[ "$(decision 0000000000000000000000000000000000000000 HEAD)" != $'affected=true\nvariants=true' ]]; then
  echo "tests/e2e/affected.sh should run the end-to-end tests when it can't compare the commits."
  problems=$((problems + 1))
fi
if git rev-parse --quiet --verify HEAD >/dev/null && [[ "$(decision HEAD HEAD)" != $'affected=false\nvariants=false' ]]; then
  echo "tests/e2e/affected.sh HEAD HEAD should find nothing that runs the end-to-end tests."
  problems=$((problems + 1))
fi
if ((problems > 0)); then
  exit 1
fi
echo "tests/e2e/affected.sh runs the end-to-end tests for the app and its tests, and skips them for the rest; the other databases and the object store run for the state, the files and the uploads."

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
# release and every raise of max-version by the upstream bot without an edit. The
# fields such as <span data-app="nextcloud">33–35</span> in README.md, docs/index.md and
# docs/alternatives.md are text on GitHub, which the hook of the website fills with the
# versions of info.xml. They may lag behind a max-version that the upstream bot raised,
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
for query in '%2Finfo%2Fversion' \
  'concat%28%2F%2Fdependencies%2Fnextcloud%2F%40min-version%2C%27%E2%80%93%27%2C%2F%2Fdependencies%2Fnextcloud%2F%40max-version%29'; do
  if ! grep -qF "https://img.shields.io/badge/dynamic/xml?url=$info_xml&query=$query&" README.md; then
    echo "README.md has no badge that reads $query from appinfo/info.xml on main: https://img.shields.io/badge/dynamic/xml?url=$info_xml&query=$query&label=..."
    problems=$((problems + 1))
  fi
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
done < <(grep -oE 'data-app="[^"]*">[^<]*<' README.md docs/index.md docs/alternatives.md || true)
if ! grep -qF 'data-app="nextcloud">' README.md || ! grep -qF 'data-app="php">' README.md; then
  echo "The requirements in README.md name the versions in the fields <span data-app=\"nextcloud\"> and <span data-app=\"php\">; one of them is missing."
  problems=$((problems + 1))
fi
if ((problems > 0)); then
  exit 1
fi
echo "The badges of the README read appinfo/info.xml on main, and the $fields field(s) of versions in README.md, docs/index.md and docs/alternatives.md agree with Nextcloud $nextcloud_text and PHP $php_text."

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
node scripts/check-l10n.mjs

# And the check finds what it must in broken copies of the catalog: a text that the code
# translates but the catalog lacks, one that no code translates, a German text without the
# placeholder of its English one, a text twice, and a copy for the browser that differs; --fix
# then drops the unused text and writes the copy for the browser anew.
l10n_copy="$(mktemp -d)"
trap 'rm -rf "$l10n_copy"' EXIT
# Runs the JavaScript of the argument on the translations, t, of the copy of l10n/de.json.
edit_catalog() {
  node -e 'const fs = require("fs"); const [file, change] = process.argv.slice(1);
    const catalog = JSON.parse(fs.readFileSync(file, "utf8")); new Function("t", change)(catalog.translations);
    fs.writeFileSync(file, JSON.stringify(catalog, null, "\t") + "\n")' "$l10n_copy/de.json" "$1"
}
broken_catalog() {
  local expected="$1" output
  if output="$(node scripts/check-l10n.mjs "$l10n_copy" 2>&1)" || [[ "$output" != *"$expected"* ]]; then
    echo "scripts/check-l10n.mjs should report \"$expected\" for a broken copy of l10n/, but says: $output"
    exit 1
  fi
}
cp l10n/de.json l10n/de.js "$l10n_copy/"
edit_catalog 'delete t["Paperless Sync"]'
broken_catalog 'translates a text that l10n/de.json lacks: "Paperless Sync"'
cp l10n/de.json l10n/de.js "$l10n_copy/"
edit_catalog 't["A text that no code translates"] = "Ein Text, den kein Code übersetzt"'
broken_catalog 'translates a text that no code translates: "A text that no code translates"'
broken_catalog 'l10n/de.js, which the browser loads, and l10n/de.json, which PHP loads, differ.'
node scripts/check-l10n.mjs --fix "$l10n_copy" >/dev/null
node scripts/check-l10n.mjs "$l10n_copy" >/dev/null
if ! cmp -s l10n/de.json "$l10n_copy/de.json" || ! cmp -s l10n/de.js "$l10n_copy/de.js"; then
  echo "scripts/check-l10n.mjs --fix should drop the unused text and write l10n/de.js as it was."
  exit 1
fi
edit_catalog 't["Nextcloud user %s does not exist."] = "Den Nextcloud-Benutzer gibt es nicht."'
broken_catalog 'has the placeholders none, but "Nextcloud user %s does not exist." has argument 1.'
cp l10n/de.json l10n/de.js "$l10n_copy/"
sed -n '3p' l10n/de.json >"$l10n_copy/line"
sed -i "3r $l10n_copy/line" "$l10n_copy/de.json"
broken_catalog 'l10n/de.json has a text twice'
echo "scripts/check-l10n.mjs reports a missing, an unused, a repeated text and a wrong placeholder, and --fix writes l10n/de.js anew."
