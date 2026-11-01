# Share the end-to-end runs between both required jobs, and make each call of the suite quick

- Status: accepted
- Amended by [0032](0032-spend-the-runners-of-the-end-to-end-tests-on-the-tests.md): the split weighs a version as five, a variant as three and an upgrade as two
- Date: 2026-10-25

## Context

The required check `nextcloud` ran every supported version side by side on one runner, and was the slowest check of every pull request that touched the app: 604 to 636 s in the pull requests #244, #245 and #246, while *nextcloud on PostgreSQL, MariaDB and S3* took 350 to 404 s and every other check 2 min or less ([#267](https://github.com/Dennis-Otto/paperless-sync/issues/267)). Its three versions shared the four cores of the runner: their scenarios ended after 330 s, where those of the two variants, alone on their runner, ended after 270 s, and the checks in the browser took another 210 s. The upstream bot raises `max-version` with each major release of Nextcloud, and a fourth version in the same job would have taken 13 to 14 min, close to the limit of 15 min of a run and the 20 min of the job.

Each run of `run.sh` also made about 150 calls of `docker compose exec` into the mock, to check an answer with Python there or to control the mock, and about 50 into Nextcloud for `occ`. Each of them took about half a second, most of it for Compose, which reads its files again on every call, and every `occ` compiled the code of Nextcloud again, as the official images keep the compiled code only for the web server.

## Options

1. Keep the jobs as they are, and accept that each new version adds about three minutes to every pull request.
2. A job for each version, in a matrix: as quick as one version, but each job waits for a runner of its own, out of the 20 that all repositories of the account share, and the required checks change with every version.
3. Share the runs of both jobs, with no new job and no change of the required checks: the job `nextcloud` runs the oldest and the newest version, the other job the databases and S3, and each version between goes to the job with the lighter load.
   - a. The versions between always in the second job: simple, but a fourth version puts four runs into it and leaves two in the first.
   - b. Each version between to the job with fewer runs, from the oldest, to the second when both have as many: three runs in each with four versions, two and three with three. But a variant has no browser checks and takes about four minutes, a version with them six; with the four variants of a coming upgrade test, all three versions would land in the first job again.
   - c. As b, but weighted: a version, with its browser checks, counts as two runs and a variant as one. With four versions, the oldest and the newest in the first job, the two between and both variants in the second, so that no job runs more than two versions with browser checks; with four variants and three versions, two versions in the first job, one and the variants in the second.
4. Quicker calls only: `docker exec` by the ID of the container, Python on the host for the answers, `curl` for the controls of the mock, and the compiled code of Nextcloud in files for `occ`.

## Decision

Option 3c, together with option 4. `tests/e2e/run-all.sh` takes `E2E_SHARD=1` or `2` and splits the runs by the rule of 3c; both jobs get the same versions and variants, so that they split them the same way, and only the second runs the variants. `scripts/check-project.sh` checks that the two shards start every run of the whole suite once, whatever the versions and the variants. In a pull request, the second job runs whenever the change can affect the app, with the variants only when it can behave differently on another database or storage; every push to `main` and every night still run everything.

`run.sh` finds the IDs of its containers after each start and calls them with `docker exec`, checks the answers with the Python of the machine that runs it, controls the mock with `curl` on a port of `127.0.0.1` that Docker chooses, and runs `occ` with `opcache.file_cache_only`, without the JIT of the image, which would compile the code once more for each short process. Measured on one machine: a call of `occ` took about 600 ms before and 500 ms after, of which `docker exec` itself takes about 360 ms, a check of an answer 590 ms in the mock and 60 ms on the host. The web server keeps the settings of the image, so the tests still run Nextcloud as an installation does. The table of the summary of each job gives the time of each phase of each run, so that the next run that gets slower shows where.

## Consequences

A pull request that changes the app waits for two jobs of two to four runs each, of no more than two versions with browser checks while four versions are supported, instead of one of three versions and one of two variants, and the slowest no longer grows by a whole version with each release of Nextcloud. The name of the second check, *nextcloud on PostgreSQL, MariaDB and S3*, stays as the settings bot requires it, though the job now runs the versions between as well; its summary names each run. A shard can be empty, as the second job of a pull request that can't behave differently on another database while only two versions are supported, and passes at once. Running the tests on a machine now takes Python 3.11 or newer next to Docker. Decision 0007 stays: the variants still run on the newest version, in a required check. A new variant or a new kind of run, such as one with an upgrade, rechecks the weights and adds its sample to `scripts/check-project.sh`.
