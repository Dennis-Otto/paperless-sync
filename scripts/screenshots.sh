#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Renders the pictures of this repository in place, for the screenshot bot: the social
# preview, .github/social-preview.png, and the screenshots and animations of the
# documentation and the App Store, in English and German (tests/e2e/screenshots.sh):
# docs/images/, docs/images/de/ and screenshots/.
#
# After a change of main that touches a file of .github/screenshots-paths.txt, after every
# release and every week, the Screenshots workflow (.github/workflows/screenshot-bot.yml)
# runs this script and opens a pull request with each picture whose pixels changed, which
# merges itself; a picture whose pixels stayed the same keeps its file. The pictures show a
# frozen story (tests/e2e/frozen.mjs), so two runs draw the same pixels.
#
# To see what a change of yours changes, run it, then
# `python3 .github/blueprint.py screenshots restore`, which gives every picture that looks
# the same its committed file back and lists the others. It needs Docker, and Pillow for
# the comparison: `python3 -m pip install --require-hashes -r .github/screenshots-requirements.txt`.
# The arguments go to tests/e2e/screenshots.sh: `bash scripts/screenshots.sh de` renders
# the social preview and the German pictures alone.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

bash scripts/social-preview.sh
bash tests/e2e/screenshots.sh "$@"
