#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# ESLint with the rules of this app, scripts/eslint/eslint.config.mjs, on the JavaScript of
# the folder that it runs in, by default the whole of it:
#
#   bash scripts/eslint.sh           # what the rules find; a warning fails as well
#   bash scripts/eslint.sh --fix     # and fix what a rule fixes by itself
#   bash scripts/eslint.sh js/flow.js
#
# scripts/check-project.sh runs it in the step "JavaScript and translations", and the fix
# bot runs it with --fix. It installs the locked packages of scripts/eslint/ first, with
# npm ci, when they are missing or older than their lock file; they stay in
# scripts/eslint/node_modules/, which Git ignores.
set -euo pipefail

tools="$(cd "$(dirname "${BASH_SOURCE[0]}")/eslint" && pwd)"
if [[ ! -f "${tools}/node_modules/.package-lock.json" ||
	"${tools}/package-lock.json" -nt "${tools}/node_modules/.package-lock.json" ]]; then
	npm ci --prefix "${tools}" --ignore-scripts --no-audit --no-fund --loglevel=error >&2
fi
exec node "${tools}/node_modules/eslint/bin/eslint.js" --config "${tools}/eslint.config.mjs" --max-warnings 0 "$@"
