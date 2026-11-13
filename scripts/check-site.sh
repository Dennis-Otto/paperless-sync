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

# The home page in each language describes the picture of its social preview in its own
# language, which .github/social-cards/preview.yml chooses by the locale of the page: the
# German home page, de/index.html, in German, and twice the same text, for Open Graph and
# for X. A screen reader reads the text where a link to the page is shared.
image_alt() {
  grep -o "<meta property=\"$2\" content=\"[^\"]*\"" "$1" | sed 's/.*content="//; s/"$//' || true
}
social_alt_in_language() {
  local folder="$1" english german
  english="$(image_alt "$folder/index.html" og:image:alt)"
  german="$(image_alt "$folder/de/index.html" og:image:alt)"
  if [[ -z "$english" || -z "$german" ]]; then
    echo "The home pages lack the text of their social preview picture (og:image:alt): English \"$english\", German \"$german\"."
    return 1
  fi
  if [[ "$german" == "$english" || "$german" != *' für '* ]]; then
    echo "The German home page describes its social preview picture in English: \"$german\". Give .github/social-cards/preview.yml a German text for the locale de."
    return 1
  fi
  if [[ "$(image_alt "$folder/de/index.html" twitter:image:alt)" != "$german" || "$(image_alt "$folder/index.html" twitter:image:alt)" != "$english" ]]; then
    echo "The home pages describe their social preview picture differently for X (twitter:image:alt) than for Open Graph (og:image:alt)."
    return 1
  fi
}
# The social plugin of Material draws the previews only where CI is set, as on GitHub's
# runners, since it needs the library Cairo (.github/mkdocs-blueprint.yml): a build of
# scripts/check.sh on a computer has none, and the check docs of the CI checks them.
if [[ -z "${CI:-}" ]] && ! grep -q '<meta property="og:image"' "$site/index.html"; then
  echo "The website has no social previews, which only a build with CI set draws; the check docs of the CI checks their texts."
else
  social_alt_in_language "$site"
  echo "The home pages describe their social preview picture in their own language."
fi

# And the check finds a German home page that describes its picture in English.
mkdir -p "$sample/de"
for page in "$sample/index.html" "$sample/de/index.html"; do
  printf '<meta property="og:image:alt" content="Paperless Sync for Nextcloud" />\n<meta property="twitter:image:alt" content="Paperless Sync for Nextcloud" />\n' >"$page"
done
if output="$(social_alt_in_language "$sample")" || [[ "$output" != *'in English'* ]]; then
  echo "The check of the social preview should report a German home page in English, but says: ${output:-nothing}"
  exit 1
fi
echo "The check of the social preview reports a German home page that describes its picture in English."
