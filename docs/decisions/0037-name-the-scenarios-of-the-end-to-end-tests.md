# Name the scenarios of the end-to-end tests, each with what it needs

- Status: accepted
- Date: 2026-11-01

## Context

`tests/e2e/run.sh` ran its scenarios as top-level code, one after the other, on one installation of Nextcloud. No switch chose among them, so trying a fix of one scenario, or of a locator of `files.mjs` against a new Nextcloud as in [#226](https://github.com/Dennis-Otto/paperless-sync/issues/226), took a whole run of about 5 minutes alone and more under load, with the checks in the browser last.

## Options

1. A fresh installation for each scenario: every scenario would stand alone, but a setup takes about 40 to 90 seconds, so a full run of some 35 scenarios would take most of an hour, and the required checks are already what a pull request waits for.
2. A snapshot of the installation after the setup, restored before each scenario: quicker, but a snapshot differs on each database and storage, and scenarios that pass only on a clean state stop testing what a real installation goes through, such as a document that comes back after its deletion.
3. Named functions in the same order on one installation, as before, where each scenario names those that leave what it needs, and a selection runs them with it.

The functions could live in `tests/e2e/scenarios/*.sh` as well. ShellCheck looks at each file by itself, so these files and `run.sh` would each have to turn off its findings of unknown variables and of functions that nothing calls, which find real mistakes in a script of this size.

## Decision

Option 3, with the functions in `run.sh`. The list `SCENARIOS` holds the order of a full run and, for each scenario, what it needs. `E2E_ONLY` selects scenarios and `E2E_BROWSER` checks in the browser; a check in the browser runs with the scenario of its name. Without either, a run does what it did before, with the same order and the same lines of what passed, so the CI needs no change, and `scripts/check-project.sh` fails when a workflow selects.

Each start of `run.sh` compares the list with the functions and stops when one lacks the other, or when a scenario comes before what it needs. A function that the list left out would otherwise never run, and nothing would fail. `scripts/check-project.sh` starts it for that, with `E2E_PLAN=1`, which ends before Docker, and also tries copies with such a change.

Each scenario also prints a line as it starts, and a run that fails names the scenario and the command that runs it alone, after the line that `failure.py` reads as the end of the output of the tests.

## Consequences

`E2E_ONLY=files bash tests/e2e/run.sh` passes in under 3 minutes on a machine, with `files.mjs`. Scenarios still depend on what ran before them, so the list can be wrong in one way that no check finds without Docker: a scenario that needs more than its entry names fails alone and passes in a full run. That costs a developer a moment and no coverage, as the CI runs them all.

The floors of the coverage of the JavaScript hold only for all of the checks in the browser, so a selection reports the coverage without them.
