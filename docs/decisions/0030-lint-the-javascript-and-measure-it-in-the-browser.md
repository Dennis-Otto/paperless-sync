# Lint the JavaScript with ESLint, and measure its coverage in the checks in the browser

- Status: accepted
- Date: 2026-10-25

## Context

The scripts of the app, `js/settings.js` with about 2,300 lines in one function, `js/files.js` and `js/flow.js`, run in the browser as they are written: the app has no build ([decision 0023](0023-file-actions-in-files-without-a-build.md)). The PHP has Psalm at its strictest level, every line covered and mutation tests; the JavaScript had only `node --check`, which finds a syntax error and nothing else. An unused variable, an accidental global or a comparison with `==` landed without a signal, and the scripts have no unit tests: the checks in the browser of the end-to-end tests, `settings.mjs`, `files.mjs` and `flow.mjs`, are their tests, and nobody knew which of their branches they reach.

The first ESLint run found 15 findings in the scripts of the app: 14 unused `catch` bindings and an assignment that nothing read.

## Options

1. ESLint at the root of the repository, with `package.json` and `eslint.config.mjs` there, as most projects have it. krankerl packs every file at the root into the package of the app, `scripts/check-package.sh` would fail on them as stray files, and `.nextcloudignore`, which would leave them out, belongs to the blueprint.
2. ESLint in a folder of its own, `scripts/eslint/`, which the package leaves out, with a wrapper, `scripts/eslint.sh`, that installs it with `npm ci` and runs it with its config from the root.
3. Unit tests of the scripts in Node, with a DOM like happy-dom: the scripts are one function each that reads the page of Nextcloud and its globals, so a test would need most of that page mocked, and would still not show what the real page does.
4. The coverage of the checks in the browser that exist: V8 in Chromium counts every function and block that a page runs, and Playwright reads it per page.

## Decision

Options 2 and 4.

ESLint 10 runs with its recommended rules and `eqeqeq`, `no-unused-vars`, `no-implicit-globals`, `prefer-const` and `no-var` on the scripts of the app, `l10n/de.js`, the end-to-end tests and the scripts, in the step *JavaScript and translations* of `scripts/check-project.sh`, which first checks that the rules find each of the five in a seeded script. Its autofix, `bash scripts/eslint.sh --fix`, lets the fix bot turn a `var` and a `let` that never changes into a `const`. Renovate updates its three packages together.

On the newest Nextcloud, `tests/e2e/browser.mjs` records the coverage of every page of the five checks in the browser, and `tests/e2e/coverage.mjs` merges it per script with `@bcoe/v8-coverage` and turns it into the coverage of Istanbul with `v8-to-istanbul`, as c8 does. The summary of the job shows the share of the lines, branches and functions of each script with the lines that no check runs, the artifact *e2e-coverage* keeps `lcov.info`, and a script below its floor in `tests/e2e/coverage-floor.json` fails the run. The floor is the share that the checks reached, less a point, so that a run that takes another way through a page stays above it, and a pull request that adds a test raises it. Chromium runs with RenderDocument for crashed frames alone: by default it gives each document of a page a frame of its own, the coverage of V8 ends with the frame, and `settings.mjs`, which loads the settings four times, counted only its last load.

## Consequences

The first measurement on Nextcloud 35: `js/settings.js` runs 91 % of its lines and 80 % of its branches in the checks in the browser, `js/files.js` 88 % and 63 %, `js/flow.js` 95 % and 83 %; without the switch of RenderDocument, `js/settings.js` showed 77 % and 68 %. The floors start a point below. A change of a script that no check in the browser runs now shows in the summary of the pull request, and one that leaves it below its floor fails; the floor rises as the tests grow.

ESLint lives beside its config in `scripts/eslint/`, so an editor finds it with the setting of its plugin for that config file, not by itself. Once the blueprint leaves a `package.json` at the root out of the package of a Nextcloud app, ESLint can move there.

V8 counts a branch for each block of a function that runs, so a function that no check calls counts in the lines and the functions but adds no branches; the share of the branches is that of the functions that run. The coverage runs on the newest version alone, in the job that runs its checks in the browser anyway.
