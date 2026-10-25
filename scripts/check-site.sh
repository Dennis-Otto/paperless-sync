#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of the built website that this app alone makes, which the check docs runs
# after the build with the folder of the website:
#
#   bash scripts/check-site.sh site
#
# Every address of the website in appinfo/info.xml, such as the page for the people who
# use the archive, which the App Store links as the documentation for users, leads to a
# page of the built website, and its anchor, after a #, to an element of that page with
# that id. lychee leaves the addresses of the website out, as they show what main
# published, not what a pull request changes; and the App Store takes the links from the
# release, so a page that a pull request renames, or an anchor that it drops, would break
# them for every installation of that version.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

site="${1:?Name the folder of the built website, such as: bash scripts/check-site.sh site}"
website='https://dennis-otto.github.io/paperless-sync/'

# Whether the address $2 of the website leads to a page in the folder $1 of a built website,
# and to its anchor; if not, it says why.
leads_to_page() {
  local folder="$1" address="$2" path anchor=""
  path="${address#"$website"}"
  if [[ "$path" == *'#'* ]]; then
    anchor="${path#*#}"
    path="${path%%#*}"
  fi
  if [[ -z "$path" || "$path" == */ ]]; then
    path="${path}index.html"
  fi
  if [[ ! -f "$folder/$path" ]]; then
    echo "appinfo/info.xml links $address, but the website has no page $path; link a page of docs/ as it is built, docs/NAME.md as NAME.html."
    return 1
  fi
  if [[ -n "$anchor" ]] && ! grep -qF "id=\"$anchor\"" "$folder/$path"; then
    echo "appinfo/info.xml links $address, but $path has no element with the id \"$anchor\"; link a heading of the page, or no anchor."
    return 1
  fi
}

# The addresses of the website in the text on stdin, one on each line. An address ends at
# a quote, a tag, a space or the end of a Markdown link, as the <description> is Markdown.
addresses_in() {
  { grep -oE "${website//./\\.}[^<\"')[:space:]]*" || true; } | sort -u
}

mapfile -t addresses < <(addresses_in <appinfo/info.xml)
if ((${#addresses[@]} == 0)); then
  echo "appinfo/info.xml links no page of the website, ${website}; the App Store shows its <documentation> as the links of the app."
  exit 1
fi
problems=0
for address in "${addresses[@]}"; do
  if ! leads_to_page "$site" "$address"; then
    problems=$((problems + 1))
  fi
done
if ((problems > 0)); then
  exit 1
fi
echo "The ${#addresses[@]} address(es) of the website in appinfo/info.xml lead to its pages and anchors."

# And the check finds what it must: a page that the website lacks, and an anchor that its
# page lacks, in a website of one page.
sample="$(mktemp -d)"
trap 'rm -rf "$sample"' EXIT
printf '<h2 id="usage">Usage</h2>\n' >"$sample/use.html"
expect_broken() {
  local address="$1" expected="$2" output
  if output="$(leads_to_page "$sample" "$address")" || [[ "$output" != *"$expected"* ]]; then
    echo "The check of the addresses in appinfo/info.xml should report \"$expected\" for $address, but says: ${output:-nothing}"
    exit 1
  fi
}
leads_to_page "$sample" "${website}use.html#usage"
found="$(printf '%s\n' "[the guide](${website}use.html#usage), <a href=\"${website}faq.html\">" | addresses_in)"
if [[ "$found" != "${website}faq.html"$'\n'"${website}use.html#usage" ]]; then
  echo "The check of the addresses in appinfo/info.xml should find the address of a Markdown link and of a tag, but finds: ${found:-nothing}"
  exit 1
fi
expect_broken "${website}guide.html" 'has no page guide.html'
expect_broken "${website}use.html#configuration" 'use.html has no element with the id "configuration"'
echo "The check of the addresses finds them in Markdown links and in tags, and reports a page that the website lacks and an anchor that its page lacks."
