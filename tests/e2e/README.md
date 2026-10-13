# Docker end-to-end tests

The suite mounts this checkout read-only as a Nextcloud Custom App and connects it to a deterministic local Paperless API mock.

```mermaid
flowchart LR
    run["run.sh"] -->|occ, WebDAV,<br>the app's routes| nextcloud
    run -->|scenario, tasks| mock
    subgraph compose ["Docker Compose project"]
        nextcloud["Nextcloud<br>this checkout as a custom app"] -->|REST API| mock["Paperless mock<br>mock_server.py"]
        browser["Chromium in the image of Playwright<br>accessibility.mjs, screenshots.mjs"] --> nextcloud
    end
    run --> browser
```

`run-all.sh` runs the scenario against the current release of every Nextcloud version from `min-version` to `max-version` in `appinfo/info.xml`, side by side, as the CI does; `run.sh` runs it against one version, by default the one that `compose.yaml` pins, or the image in `NEXTCLOUD_IMAGE`, such as `nextcloud:35-apache`. Every week, the CI also runs it against [the coming Nextcloud](#the-coming-nextcloud).

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
- Paperless inbox-tag exclusion and empty-folder pruning
- Paperless trash mirroring and guarded permanent deletion, also after a page of Paperless came back incomplete
- successful and failed Nextcloud inbox imports, and a file that Paperless refuses, which waits in the inbox until it changes
- target-user ownership and access isolation
- background-job registration, status reporting, and clean application logs
- accessibility of the administration settings and the run report, see [Accessibility](#accessibility)

Optional environment variables:

- `E2E_PORT`: host port for Nextcloud, default `18083`
- `DOCKER_BIN`: Docker CLI path, default `docker`
- `E2E_PROJECT_NAME`: Compose project name, default `paperless_sync_e2e`
- `NEXTCLOUD_IMAGE`: pinned Nextcloud image override
- `KEEP_E2E=1`: keep containers and the disposable volume after the suite
- `E2E_IGNORE_MAX_VERSION=1`: enable the app with `--force` on a Nextcloud newer than `max-version`, as `canary.sh` does

All credentials, users, filenames, document content, and metadata are synthetic. The Paperless mock rejects every token except the explicit `e2e-only-token` fixture and records whether uploads arrived intact. Like Paperless, it refuses an upload without a `Content-Length` with `No file was submitted.`

## Every supported version at once

`run-all.sh` starts `run.sh` for every supported version at the same time, each in a Compose project of its own, `paperless_sync_e2e_<version>`, on the port `18080 + <version>`, such as `18113` for Nextcloud 33. While one version downloads its image, another one installs, so that all versions take little longer than one. Each `run.sh` also downloads the image of Playwright in the background while Nextcloud installs, and the accessibility check at the end waits for it. When all versions are done, `run-all.sh` prints the log of each, in a group of its own in the CI, with a table of the results and the Nextcloud release that each version ran, and fails if one of them failed.

- `E2E_VERSIONS`: the major versions to run instead, such as `"33 35"`
- `E2E_VERSION_TIMEOUT`: the time after which a version that hangs ends as failed, default `15m`, below the 20 minutes of the job in the CI, so that the logs of all versions are still printed

Ctrl-C ends every version, each with the cleanup of `run.sh`, and prints the logs so far.

`KEEP_E2E=1` keeps the containers of every version. In the CI, the suite leaves its containers running, as the runner is thrown away with them. The suite turns off the password policy's look-up of new passwords at Have I Been Pwned: a request to the internet that the tests don't need and that can fail.

## In the CI

The workflow `.github/workflows/e2e.yml` runs `run-all.sh` in one job, `nextcloud`, which is the required check of pull requests.

```mermaid
flowchart TB
    accTitle: When the end-to-end tests run
    accDescr: A pull request runs the end-to-end tests only when it changes the app, its tests or their workflow; otherwise the required check passes at once. Every push to main and every night run them against every supported version, and every Monday the canary runs them against the coming Nextcloud.
    pr["Pull request"] --> affected{"affected.sh:<br>can the change<br>affect the app?"}
    affected -->|"lib/, appinfo/, templates/,<br>js/, css/, l10n/, img/,<br>tests/e2e/, e2e.yml"| all["run-all.sh:<br>every supported version<br>side by side"]
    affected -->|"documentation, other workflows,<br>the version of a release"| skipped["nextcloud passes<br>in seconds"]
    main["Push to main,<br>every night, by hand"] --> all
    monday["Every Monday,<br>by hand"] --> canary["canary.sh:<br>the coming Nextcloud"]
    all --> result["nextcloud: passed<br>or failed"]
    classDef bot fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef person fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    classDef done fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    class pr person
    class affected,all,canary bot
    class skipped,result done
```

In a pull request, `affected.sh` first compares the merge commit with its base. Only a change of the code, the metadata or the assets of the app (`lib/`, `appinfo/`, `templates/`, `js/`, `css/`, `l10n/`, `img/`, `composer.json`), of the end-to-end tests other than their Markdown, or of the workflow itself can make the tests fail. In `appinfo/info.xml`, the lines that the release bot writes in every release, `<version>` and those marked `x-release-please-version`, don't count, so a release pull request passes at once, while the upstream bot's change of `max-version` runs the tests. Any other pull request passes the check in seconds, and its summary says *E2E skipped: the change cannot affect the app*. `scripts/check-project.sh` tests these rules with sample paths and changes.

Every push to `main`, a run by hand and every night run all versions in any case: the images `nextcloud:<version>-apache` move with each point release of Nextcloud, which the nightly run catches before the next pull request. A newer push to a pull request or to `main` cancels the run of the older one; a run by hand and the scheduled runs go to the end.

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
