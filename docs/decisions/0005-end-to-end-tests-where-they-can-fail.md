# Run the end-to-end tests where a change can make them fail

- Status: accepted
- Date: 2026-10-13

## Context

The Docker end-to-end tests start a real Nextcloud for every supported version, from `min-version` to `max-version` in `appinfo/info.xml`, and their check `nextcloud` is required for every pull request. They ran in three jobs, one for each version, behind a job that read the versions and before a job that collected the results. Each job waited for a runner of its own, and the account shares about 20 runners across its repositories, so the end-to-end tests were the last check in 23 of 25 pull requests; nearly three quarters of their time was the wait for runners.

Two thirds of the pull requests changed nothing that the tests exercise: the documentation, other workflows, updates of the blueprint, or a release, which only writes its version into `appinfo/info.xml`. Each still waited for the full tests.

## Options

1. Keep three jobs and run them for every pull request: every pull request waits for three runners, whatever it changes.
2. Filter the workflow by paths: a skipped workflow doesn't report its required check, so the pull request can't merge, and paths can't tell a release's change of `<version>` from the upstream bot's change of `max-version` in the same file.
3. Run every version side by side in one job, and in a pull request first ask whether the change can affect the app: one wait for a runner, and a check that passes in seconds when the tests can't fail.

## Decision

Option 3. `tests/e2e/run-all.sh` runs all supported versions at once in the job `nextcloud`. In a pull request, `tests/e2e/affected.sh` compares the merge commit with its base: a change of `lib/`, `appinfo/`, `templates/`, `js/`, `css/`, `l10n/`, `img/`, `composer.json`, the end-to-end tests other than their Markdown, or their workflow runs the tests; in `appinfo/info.xml`, the lines that the release bot writes don't count. Every push to `main`, a run by hand and every night run all versions in any case.

## Consequences

A pull request that can't affect the app gets its required check in seconds, and one that can waits for one runner instead of three. A release no longer runs the tests itself: its code is that of `main`, which passed them, and the main branch requires every pull request to be up to date before it merges. A change of the Nextcloud images between two pushes, such as a point release, shows in the nightly run instead of the next pull request. A new folder of the app, or a file outside these folders that the tests read, must join the rules of `affected.sh`, which `scripts/check-project.sh` tests.
