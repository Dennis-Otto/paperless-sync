# Run the end-to-end tests on the databases and the storage of real installations

- Status: accepted
- Date: 2026-10-13

## Context

The end-to-end tests installed every supported Nextcloud on SQLite, with its files on the disk of the container. Few installations run that way: most keep their tables in MariaDB, MySQL or PostgreSQL, and larger ones keep their files in an object store with the API of S3. The state of the app lives in two tables of its own, which its migrations create and its queries read and write, and only the unit tests checked those queries, against a query builder written for them.

On S3 primary storage, Nextcloud reads every file as a stream of no known size. The app handed that stream to its upload, which then went to Paperless without a `Content-Length`, and Paperless refused every file of the inbox, 22,800 times a day in one installation ([#73](https://github.com/Dennis-Otto/paperless-sync/issues/73)). The end-to-end tests passed all the while, as the file on a disk has a size. The tests also never ran the background job, which is how the app runs for its users.

## Options

1. Keep SQLite and the disk, and test the other databases and storage only with unit tests and doubles: fast, but the doubles show only what their author knew, as with #73.
2. Run every supported version on every database and storage: three versions times four combinations, twelve installations in one job, or more jobs and more waiting for runners.
3. Run every supported version on SQLite with local storage, and the newest version besides on PostgreSQL, and on MariaDB with an S3 object store; in a pull request, those two only when the change can behave differently there.
   - a. All five installations in the one job of the required check: in the CI, that job took 5 min 19 s and 5 min 31 s instead of about 3 min 15 s, as five installations share the four cores of a runner.
   - b. The two variants in a second job, on a runner of its own, which is a required check as well: neither job waits for the other, the variants finish before the versions, and a failure of a variant blocks the merge like that of a version.
   - c. The second job of 3b, but no required check: a failure of a variant shows in the pull request without blocking it. The pull requests of the project merge on their own once their required checks pass, though, and nothing else reports a red job, so a change that breaks the uploads from S3, as #73 did, would merge unseen.

## Decision

Option 3b. `tests/e2e/run.sh` installs Nextcloud on the database of `E2E_DATABASE` and with the storage of `E2E_OBJECTSTORE`, in containers that `compose.pgsql.yaml`, `compose.mysql.yaml` and `compose.s3.yaml` add, and fails if Nextcloud then runs on another one. `run-all.sh` runs the versions, the variants or both; `.github/workflows/e2e.yml` runs the versions in the required check `nextcloud` and the variants in the required check *nextcloud on PostgreSQL, MariaDB and S3* next to it, which `.github/repository.project.toml` names for the settings bot. In a pull request, `tests/e2e/affected.sh` adds them for a change of the migrations, the queries of the state, the files in Nextcloud, the uploads to Paperless, `appinfo/info.xml`, the end-to-end tests or their workflow; every push to `main` and every night runs them in any case. The scenario also starts the background job with `occ background-job:execute`, as system cron would, and checks that it runs once its interval has passed and waits when started again at once.

## Consequences

The queries and migrations of the app run on the three databases that Nextcloud supports in its images, and the inbox uploads from an object store; with the fix of #73 undone, the S3 variant fails with *No file was submitted.* A pull request that changes such code takes a second runner, but doesn't wait longer for its required checks, and a failure of a variant blocks its merge. Any other pull request passes the second check in seconds. Renovate keeps three more images current. A change elsewhere in the app that behaves differently only on another database or storage shows on `main` or in the nightly run instead of its pull request. Should the variants become cheap enough, or a runner with more cores free, they can join the job of the versions again. MySQL itself, Oracle and other object stores than S3 stay untested.
