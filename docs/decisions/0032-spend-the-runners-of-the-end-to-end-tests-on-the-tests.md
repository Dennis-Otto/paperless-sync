# Spend the runners of the end-to-end tests on the tests

- Status: accepted
- Date: 2026-11-01

## Context

After [decision 0029](0029-share-the-end-to-end-runs-between-both-required-jobs.md), the two required checks still missed the targets of [#267](https://github.com/Dennis-Otto/paperless-sync/issues/267), 6 min for `nextcloud` and 7 min for *nextcloud on PostgreSQL, MariaDB and S3*: the first took 6 min 25 s to 9 min 18 s, 8 min 27 s in the middle, and the second 8 min 25 s to 12 min 9 s, 9 min 56 s in the middle, in five runs of 2026-10-31. The same work takes a third longer on some runners than on others, so one run says little. The second runs five stacks on the four cores of its runner, and in it a scenario took twice as long as in the first.

The table of the summary pointed at the checks in the browser as the largest phase, and the issue proposed to run `npm ci` only once per job. The logs show that `npm ci` takes half a second. What costs the time is what runs beside the tests:

- Each health check starts a process in its container through Docker, every two or three seconds, for the whole of the run. Five stacks have 13 containers, which makes about five such processes a second, each with the start of a `python`, `curl` with a request to PHP, or the script of MariaDB.
- A trace of the checks in the browser keeps a snapshot of the page at each action. On one machine, with one stack for each setting at the same time, the checks took 220 s with snapshots and pictures, 213 s with snapshots alone, 180 s with pictures alone and 172 s with neither.

The split of decision 0029 counted a variant as half a version and an upgrade as a variant, though an upgrade takes about half of what a variant takes.

## Options

1. A third required job: quicker, but one more wait for a runner out of the 20 that all repositories of the account share, and a change of the required checks.
2. Fewer checks in a pull request, such as the browser checks on one version only: quicker, but the check would no longer run what `main` runs, and a version would break unnoticed until the merge.
3. `npm ci` once per job: half a second per run, so no real gain.
4. Stop paying for what isn't a test:
   - a. Health checks that check often only while a service starts, and seldom after that.
   - b. Traces without the snapshots of the page, as without them the trace still has each action with a picture of the page, its requests and what the page logged, and the failure has its screenshots.
   - c. As b, but only in the first attempt of a job: the flaky-test bot starts a second attempt of each failed job, which then keeps the snapshots, for a failure that stays.
5. A split by what a run costs: a version with its browser checks counts as five, a variant as three and an upgrade as two.
6. No more runs at once than the runner has processors, which keeps five stacks from slowing each other down on four, with the lightest run last.
7. The folder `data` of Nextcloud in memory in the CI, as the databases of the variants have been. SQLite waits for the disk at each commit, and a tmpfs of Compose is empty after each restart of the container, which the suite makes, so it is a folder in `/dev/shm` of the runner.

## Decision

Options 4a, 4c, 5, 6 and 7.

The health checks of the Compose files of the required checks use `start_interval: 1s` and `interval: 1m`, and `scripts/check-project.sh` fails when one checks often for longer than the start. A service that is up is noticed sooner than before, as the wait between checks while it starts is shorter.

`run.sh` leaves the snapshots out of the traces when `GITHUB_RUN_ATTEMPT` is 1, and `E2E_BROWSER_SNAPSHOTS` chooses otherwise. The canary keeps them, as nobody waits for it and its issue stays open, and so does a run on a machine.

`run-all.sh` weighs the runs of a shard as in option 5, which matches the phases of the table: 6 min 19 s, 4 min 15 s and 2 min 20 s in the same job. No scenario and no check goes away, in a pull request or elsewhere.

`run-all.sh` starts no more runs at once than `E2E_PARALLEL`, by default the number of processors, which `scripts/check-project.sh` checks with a script in place of `run.sh`. The jobs of the CI set `E2E_MEMORY_FOLDER=/dev/shm`. `run.sh` downloads the image of Playwright from its start, while the tests don't need the processors yet.

## Consequences

Measured in the pull request of this decision, each row one run of the first attempt with every run of the check, so the runner it got counts too:

| Run | `nextcloud` | *nextcloud on PostgreSQL, MariaDB and S3* |
| --- | --- | --- |
| Before, five runs, the middle one | 8 min 27 s | 9 min 56 s |
| Health checks and the split | 7 min 8 s | 6 min 33 s |
| And traces without snapshots | 6 min 12 s | 7 min 26 s |
| And the limit of runs and the data in memory | 6 min 54 s | 7 min 29 s |
| All of it, with four versions | 8 min 20 s | 7 min 8 s |

The setup of a run on SQLite takes half of what it took, 16 s instead of 30 s, and four versions stay below the 9 min of the issue, where the fourth was stood in for by a copy of the image of Nextcloud 35. The lines of what passes are the same in every run on the same version as on `main`. The middle of the next pull requests decides whether `nextcloud` stays within its 6 min: on the slower runners it doesn't yet. What is left of it is the checks in the browser, and of those the coverage of the JavaScript on the newest version takes about 45 s, which the floors of the coverage still need, as the accessibility check alone runs a part of `js/settings.js` and `js/files.js`.

The Compose files need Docker 25 or newer, from January 2024, for `start_interval`; an older Docker refuses them and says why. The first attempt of a failed job has no snapshot of the page to inspect in its trace, only its pictures, so a failure that needs one waits for the second attempt, which the flaky-test bot starts within minutes and *Re-run failed jobs* starts by hand. A run that gets slower still shows where in the table of the summary, which now has the phases of an upgrade too.
