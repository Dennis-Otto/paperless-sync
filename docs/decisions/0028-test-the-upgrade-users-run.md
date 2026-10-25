# Test the upgrade that users run: from the last release, on PostgreSQL and MariaDB, with the packages they install

- Status: accepted
- Date: 2026-10-25

## Context

Every end-to-end run mounted the checkout into a new Nextcloud and enabled it, so the migrations of `lib/Migration/` always ran in order on empty tables. Three of them exist only to move the data of an installation in use in their `postSchemaChange`: one binds a token that was stored on its own to the configured URL, one makes the stored settings and status lazy, and one keeps the German folder names of an installation that has them ([decision 0017](0017-folder-names-in-the-language-of-nextcloud.md)). Their unit tests use doubles; on a real PostgreSQL or MariaDB with rows, none of them had ever run. A migration that fails there fails for every user at once, during the update from the App Store, and leaves Nextcloud in maintenance mode or the app disabled.

Nor did any run install what users install. The release workflow builds the package with krankerl and `.nextcloudignore` and signs it, and `scripts/check-package.sh` compares its files with the commit, but no Nextcloud enabled a package or checked the signature of a release.

## Options

1. Keep testing migrations with doubles. Cheap, and blind to what a database does with rows, such as refusing a column `NOT NULL` without a default on a table that has some.
2. Fill the tables of a new installation with rows of the commit, then run the migrations again. The rows would have the shape of the commit, not that of a release, and Nextcloud doesn't run a migration twice.
3. Install the last release, use it, and upgrade it to the package of the commit with `occ upgrade`, as an administrator does, then compare what the upgrade kept.
4. Upgrade from every earlier release. Each would need the setup of its own era, and only the step from the last release is the one that the next release ships.

## Decision

Option 3, on PostgreSQL and on MariaDB, as the variants `pgsql+upgrade` and `mysql+upgrade` of `tests/e2e/run-all.sh` in the required check *nextcloud on PostgreSQL, MariaDB and S3* ([decision 0007](0007-end-to-end-tests-on-real-databases-and-storage.md)), described in [the end-to-end tests](https://github.com/Dennis-Otto/paperless-sync/blob/main/tests/e2e/README.md) under *The upgrade from the last release*.

- **The packages users get.** `tests/e2e/packages.sh` downloads `paperless_sync.tar.gz` of the latest release, which `occ integrity:check-app` must find intact; a copy with one changed file must fail that check, so that its pass means something. The package of the commit is the one that krankerl builds, with the krankerl of the CI and of the release, which the job reads from `.github/workflows/ci.yml`, so that the blueprint's updates of it reach the test too.
- **An installation in use.** The release gets the token, per-user ownership with a mapped user and the secret of the webhook, and runs until the tables hold the copies of two owners, an import whose task is pending, an import that waits to be tried again and a file to send. Its steps use only what administrators use, the settings, `occ`, WebDAV and the routes of the app, so that they speak to the release, not to the commit.
- **A newer version.** While the commit still has the version of the last release, the test gives its package the next patch version, as the next release will have: Nextcloud runs the migrations of an app only for a newer version.
- **What must hold.** `occ upgrade` passes and leaves maintenance mode, the version is that of the package, the table `migrations` holds exactly the migrations of the package, and every row, column, value, credential, migration and file ID of the release is there with its value. New ones may come; `installed_version` and `types`, which Nextcloud writes with every update, may change, and what a migration of the commit names as changed on purpose. The next run works with the stored token, downloads and uploads nothing and leaves the retry waiting; the import of the release ends with the commit, and the webhook accepts its secret.
- **When.** On every push to `main`, every night and in a pull request that changes the migrations, the stored settings and state, the secret of the webhook, the package or the tests (`tests/e2e/affected.sh`); a documentation change still skips it.

## Consequences

A migration that works on empty tables but not on the rows of a release fails a pull request before any user runs it; a test-only migration that adds a column `NOT NULL` without a default turned the upgrade red on PostgreSQL. A data migration that changes a value on purpose names it in `UPGRADE_CHANGES` of `tests/e2e/upgrade.sh`, which makes such a change visible in review. The steps against the release must keep the old way of a route or a command that a pull request changes until the next release. The upgrade runs on the newest Nextcloud that the release supports, so a raised `max-version` doesn't break it before the release that supports it. The job runs two more installations beside the two variants on the four cores of its runner, each about three minutes; their Nextcloud has a quarter of the weight of the CPU (`tests/e2e/compose.upgrade.yaml`), so that they yield to the longer scenario of the variants and the job keeps to about eight minutes.
