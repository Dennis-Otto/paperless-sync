# Docker end-to-end tests

The suite mounts this checkout read-only as a Nextcloud Custom App and connects it to a deterministic local Paperless API mock.

```mermaid
flowchart TB
    accTitle: The containers of the end-to-end tests
    accDescr: run.sh drives Nextcloud through occ, WebDAV and the routes of the app, and the Paperless mock through its scenarios. Nextcloud runs this checkout as a custom app and calls the REST API of the mock. On request, Nextcloud keeps its tables in MariaDB or PostgreSQL and its files in an S3 object store. Chromium in the image of Playwright checks the pages of the app.
    run["run.sh"] -->|"occ, WebDAV,<br>the app's routes"| nextcloud
    run -->|"scenario, tasks"| mock
    run --> browser
    subgraph compose ["Docker Compose project"]
        nextcloud["Nextcloud<br>this checkout as a custom app"] -->|REST API| mock["Paperless mock<br>mock_server.py"]
        nextcloud -.->|"E2E_DATABASE"| database[("MariaDB or PostgreSQL<br>compose.mysql.yaml,<br>compose.pgsql.yaml")]
        nextcloud -.->|"E2E_OBJECTSTORE=s3"| s3[("SeaweedFS with the API of S3<br>compose.s3.yaml")]
        browser["Chromium in the image of Playwright<br>accessibility.mjs, screenshots.mjs"] --> nextcloud
    end
    classDef bot fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef data fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    class run,browser bot
    class database,s3 data
```

`run-all.sh` runs the scenario against the current release of every Nextcloud version from `min-version` to `max-version` in `appinfo/info.xml`, and against the newest of them on [other databases and object storage](#databases-and-object-storage), side by side, as the CI does; `run.sh` runs it against one version, by default the one that `compose.yaml` pins, or the image in `NEXTCLOUD_IMAGE`, such as `nextcloud:35-apache`. Every week, the CI also runs it against [the coming Nextcloud](#the-coming-nextcloud).

Run locally:

```bash
bash tests/e2e/run-all.sh
bash tests/e2e/run.sh
```

Both run in Git Bash on Windows too, with Docker Desktop.

The scenario verifies:

- administrator configuration and server-side token handling
- dry-run behavior without filesystem mutations
- archive export through the Paperless download API, and a full quota of the target user, which stops the exports of a run
- metadata-only moves without downloading the PDF again
- titles and names that the path template keeps as plain text, such as `$1` or `{{ correspondent }}`, and long names in Chinese, emoji and Cyrillic, which are shortened to the bytes that Nextcloud accepts
- Paperless inbox-tag exclusion and empty-folder pruning
- Paperless trash mirroring and guarded permanent deletion, also after a page of Paperless came back incomplete
- successful and failed Nextcloud inbox imports, and a file that Paperless refuses, which waits in the inbox until it changes
- target-user ownership and access isolation
- background-job registration, status reporting, and clean application logs
- the background job as system cron starts it: once synchronization is enabled and the interval since the last run has passed, it runs, and started again at once, it waits
- a document restored from the Paperless trash, whose copy moves back from the deleted folder without a download
- conflicts: with `skip`, a file of the user at the path of a document stays and the run reports it; with `replace`, the document takes its place
- a document that vanishes from Paperless without passing through its trash: its copy waits for the grace runs, then moves to the deleted folder, and stays there, as direct deletion is not allowed
- accessibility of the administration settings and the run report, see [Accessibility](#accessibility)

Optional environment variables:

- `E2E_PORT`: host port for Nextcloud, default `18083`
- `DOCKER_BIN`: Docker CLI path, default `docker`
- `E2E_PROJECT_NAME`: Compose project name, default `paperless_sync_e2e`
- `NEXTCLOUD_IMAGE`: pinned Nextcloud image override
- `E2E_DATABASE`: the database of Nextcloud, `sqlite` by default, `mysql` for MariaDB or `pgsql` for PostgreSQL, see [Databases and object storage](#databases-and-object-storage)
- `E2E_OBJECTSTORE`: the primary storage of Nextcloud, `local` by default, or `s3` for an object store
- `E2E_ACCESSIBILITY=0`: leave out the accessibility check at the end
- `KEEP_E2E=1`: keep containers and the disposable volume after the suite
- `E2E_IGNORE_MAX_VERSION=1`: enable the app with `--force` on a Nextcloud newer than `max-version`, as `canary.sh` does

All credentials, users, filenames, document content, and metadata are synthetic. The Paperless mock rejects every token except the explicit `e2e-only-token` fixture and records whether uploads arrived intact. Like Paperless, it refuses an upload without a `Content-Length` with `No file was submitted.`

## Every supported version at once

`run-all.sh` starts `run.sh` for every supported version at the same time, each in a Compose project of its own, `paperless_sync_e2e_<version>`, on the port `18080 + <version>`, such as `18113` for Nextcloud 33. Besides, it runs the newest version on PostgreSQL, and on MariaDB with an S3 object store, in the projects `paperless_sync_e2e_<version>_pgsql` and `paperless_sync_e2e_<version>_mysql_s3`, on ports 100 and 200 higher. While one downloads its images, another one installs, so that all take little longer than one. Each `run.sh` of a version also downloads the image of Playwright in the background while Nextcloud installs, and the accessibility check at the end waits for it; the variants of a database or a storage leave that check out, as it doesn't depend on them. When all are done, `run-all.sh` prints the log of each, in a group of its own in the CI, with a table of the results, the database, the storage and the Nextcloud release that each ran, and fails if one of them failed.

```mermaid
flowchart TB
    accTitle: What run-all.sh runs side by side
    accDescr: Every supported Nextcloud version, from min-version to max-version, runs on SQLite with local storage and with the accessibility check. The newest version also runs on PostgreSQL, and on MariaDB with an S3 object store.
    all["run-all.sh"] --> versions["Every version from min-version to max-version<br>SQLite, local storage, accessibility"]
    all --> pgsql["max-version<br>PostgreSQL, local storage"]
    all --> mysql["max-version<br>MariaDB, S3 object store"]
    classDef bot fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef done fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    class all bot
    class versions,pgsql,mysql done
```

- `E2E_VERSIONS`: the major versions to run instead, such as `"33 35"`; `none` runs only the variants, on `max-version`
- `E2E_VARIANTS`: the variants to run besides, on the newest of the versions, by default `"pgsql mysql+s3"`: each a database, `sqlite`, `mysql` or `pgsql`, with `+s3` for an object store; `none` runs none
- `E2E_VERSION_TIMEOUT`: the time after which a run that hangs ends as failed, default `15m`, below the 20 minutes of the job in the CI, so that the logs of all runs are still printed

Ctrl-C ends every run, each with the cleanup of `run.sh`, and prints the logs so far. A failed run names the command that runs it again on its own.

`KEEP_E2E=1` keeps the containers of every run. In the CI, the suite leaves its containers running, as the runner is thrown away with them. The suite turns off the password policy's look-up of new passwords at Have I Been Pwned: a request to the internet that the tests don't need and that can fail.

## In the CI

The workflow `.github/workflows/e2e.yml` runs `run-all.sh` in two jobs side by side, each on a runner of its own. `nextcloud`, the required check of pull requests, runs every supported version (`E2E_VARIANTS=none`). *nextcloud on PostgreSQL, MariaDB and S3* runs the newest version on [the other databases and S3](#databases-and-object-storage) (`E2E_VERSIONS=none`), and is no required check. In one job, the five installations took more than five minutes on the four cores of a runner; split, the required check stays as fast as with the versions alone ([decision 0007](../../docs/decisions/0007-end-to-end-tests-on-real-databases-and-storage.md)).

```mermaid
flowchart TB
    accTitle: When the end-to-end tests run
    accDescr: A pull request runs the end-to-end tests only when it changes the app, its tests or their workflow; otherwise the required check passes at once. The job of PostgreSQL, MariaDB and S3 runs only when the change can behave differently on another database or storage. Every push to main and every night run both jobs, and every Monday the canary runs the tests against the coming Nextcloud.
    pr["Pull request"] --> affected{"affected.sh:<br>can the change<br>affect the app?"}
    affected -->|"documentation, other workflows,<br>the version of a release"| skipped["both jobs pass<br>in seconds"]
    affected -->|"lib/, appinfo/, templates/,<br>js/, css/, l10n/, img/,<br>tests/e2e/, e2e.yml"| versions["nextcloud, required:<br>every supported version<br>on SQLite"]
    affected --> variants{"can it behave<br>differently on another<br>database or storage?"}
    variants -->|"lib/Migration/, the state,<br>the storage, the uploads,<br>appinfo/info.xml, tests/e2e/, e2e.yml"| databases["nextcloud on PostgreSQL,<br>MariaDB and S3:<br>the newest version"]
    variants -->|"the rest of the app"| later["after the merge,<br>on main"]
    main["Push to main,<br>every night, by hand"] --> versions
    main --> databases
    monday["Every Monday,<br>by hand"] --> canary["canary.sh:<br>the coming Nextcloud"]
    classDef bot fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef person fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    classDef done fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    class pr person
    class affected,variants,versions,databases,canary bot
    class skipped,later done
```

In a pull request, `affected.sh` first compares the merge commit with its base. Only a change of the code, the metadata or the assets of the app (`lib/`, `appinfo/`, `templates/`, `js/`, `css/`, `l10n/`, `img/`, `composer.json`), of the end-to-end tests other than their Markdown, or of the workflow itself can make the tests fail. In `appinfo/info.xml`, the lines that the release bot writes in every release, `<version>` and those marked `x-release-please-version`, don't count, so a release pull request passes at once, while the upstream bot's change of `max-version` runs the tests. Any other pull request passes the check in seconds, and its summary says *E2E skipped: the change cannot affect the app*.

Of the changes that run the tests, those that can behave differently on another database or storage also run [PostgreSQL, and MariaDB with S3](#databases-and-object-storage): the migrations (`lib/Migration/`), the queries of the state (`lib/Service/SyncStateRepository*`), the files in Nextcloud (`lib/Service/NextcloudStorage*`), the uploads to Paperless (`lib/Service/PaperlessApiService.php`), `appinfo/info.xml` as above, the end-to-end tests and their workflow. For any other change of the app, that job passes at once, and its summary says *E2E on SQLite with local storage only*. `scripts/check-project.sh` tests these rules with sample paths and changes.

Every push to `main`, a run by hand and every night run both jobs in any case: the images `nextcloud:<version>-apache` move with each point release of Nextcloud, which the nightly run catches before the next pull request. A newer push to a pull request or to `main` cancels the run of the older one; a run by hand and the scheduled runs go to the end.

## Databases and object storage

Few installations of Nextcloud run on SQLite: most keep their tables in MariaDB, MySQL or PostgreSQL, and larger ones keep their files in an object store with the API of S3. The state of the app lives in two tables of its own, which its migrations create, and on an object store Nextcloud reads every file as a stream of no known size, which once made Paperless refuse every upload of the inbox ([#73](https://github.com/Dennis-Otto/paperless-sync/issues/73)). `run.sh` runs the same scenario on them as well:

```bash
E2E_DATABASE=pgsql bash tests/e2e/run.sh
E2E_DATABASE=mysql E2E_OBJECTSTORE=s3 bash tests/e2e/run.sh
```

`compose.pgsql.yaml` (PostgreSQL 17), `compose.mysql.yaml` (MariaDB 11.4, with the settings that Nextcloud asks for) and `compose.s3.yaml` join `compose.yaml`. The databases keep their data in memory, for speed. [SeaweedFS](https://github.com/seaweedfs/seaweedfs) serves the API of S3 and accepts only the key of the tests; Nextcloud creates its bucket and keeps every file there, through the variables `OBJECTSTORE_S3_*` of its image. `run.sh` installs Nextcloud on that database and fails if Nextcloud then runs on another database or storage, so that a variant can't pass on SQLite and its disk by mistake. Renovate keeps their images current, each pinned by its digest.

## Accessibility

At the end of the scenario, `accessibility.mjs` checks the pages of the app in Chromium with [axe-core](https://github.com/dequelabs/axe-core) against WCAG 2.1 at levels A and AA, in the light and the dark theme of Nextcloud: the administration settings with every section open, and the report of a dry-run over a larger archive of the mock, whose list of changes scrolls. It signs in through the login form and looks only into `#paperless-sync-settings`, the element that holds the markup of `templates/`, `js/` and `css/`, so that what Nextcloud draws around it doesn't count. A serious or critical violation fails the suite; the others are listed in the log.

The browser runs in the image of Playwright that `run.sh` names, inside the network of the Compose project, and reaches Nextcloud as `http://nextcloud`; the suite turns off the first-run wizard of Nextcloud, which would cover the pages. `package.json` and `package-lock.json` pin axe-core and playwright-core. Keep playwright-core at the version of the image: Renovate updates both together (`.github/renovate.json5`), and `scripts/check-project.sh` compares them.

## Screenshots

The pictures of the documentation in `docs/images/` and of the App Store in `screenshots/` come from the same containers:

```bash
bash tests/e2e/screenshots.sh
```

The Paperless mock serves a small household archive of invented documents (`showcase.py`): fourteen documents of seven correspondents, one still in the Paperless inbox, each a one-page PDF. `screenshots.mjs` then follows the quick start in Chromium: a dry-run and the first synchronization, recorded as `dry-run.gif`; the settings, each of their sections, and the archive in Files, in the light and the dark theme; a walk through the archive to a document in the viewer, recorded as `archive.gif`; and a dry-run a few days later, when the archive has a new title, a document in the trash, one with the excluded tag *Private*, two new documents and a finished import.

The GIFs show a drawn pointer, since a screenshot has none, and share one palette across their frames. The image of Playwright has no font for the interface of Nextcloud, so the script installs Inter (`fonts.conf`). `E2E_PORT` (default `18084`), `E2E_PROJECT_NAME` and `KEEP_E2E=1` work as for `run.sh`. Look at every picture before committing it.

## The coming Nextcloud

Every Monday, and when started by hand, the workflow also runs the suite against the coming Nextcloud:

```bash
bash tests/e2e/canary.sh
```

The coming Nextcloud is the newest beta or release candidate of the next major version while Nextcloud publishes one, in `https://download.nextcloud.com/server/prereleases/`, and otherwise the daily build of its master branch, `https://download.nextcloud.com/server/daily/latest-master.tar.bz2`. Nextcloud no longer publishes Docker images of betas and release candidates, so the script checks the signature of the package against the release key of Nextcloud, as the official image does, and puts its code into the image of the newest release, `nextcloud:apache`, which keeps its PHP, Apache and start script. The suite enables the app with `--force`, as `max-version` of `appinfo/info.xml` doesn't name that version yet; the upstream bot raises `max-version` once Nextcloud releases the version.

The canary is no required check and blocks nothing. When it fails, it opens the issue *The coming Nextcloud breaks the app* with a link to the run, adds every further failed run to it, and closes it once the tests pass again.

Optional environment variables, besides those of `run.sh`:

- `CANARY_BASE_IMAGE`: the image whose code the coming Nextcloud replaces, default `nextcloud:apache`
- `CANARY_IMAGE`: the local image the script builds, default `paperless-sync-e2e:coming`
