# Docker end-to-end tests

The suite mounts this checkout read-only as a Nextcloud Custom App and connects it to a deterministic local Paperless API mock. CI runs the same scenario against the current release of every Nextcloud version from `min-version` to `max-version` in `appinfo/info.xml`, and every week against [the coming Nextcloud](#the-coming-nextcloud); set `NEXTCLOUD_IMAGE`, such as `nextcloud:35-apache`, to choose one locally.

Run locally:

```bash
bash tests/e2e/run.sh
```

The scenario verifies:

- administrator configuration and server-side token handling
- dry-run behavior without filesystem mutations
- archive export through the Paperless download API
- metadata-only moves without downloading the PDF again
- Paperless inbox-tag exclusion and empty-folder pruning
- Paperless trash mirroring and guarded permanent deletion
- successful and failed Nextcloud inbox imports
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

All credentials, users, filenames, document content, and metadata are synthetic. The Paperless mock rejects every token except the explicit `e2e-only-token` fixture and records whether uploads arrived intact.

## Accessibility

At the end of the scenario, `accessibility.mjs` checks the pages of the app in Chromium with [axe-core](https://github.com/dequelabs/axe-core) against WCAG 2.1 at levels A and AA, in the light and the dark theme of Nextcloud: the administration settings with every section open, and the report of a dry-run over a larger archive of the mock, whose list of changes scrolls. It signs in through the login form and looks only into `#paperless-sync-settings`, the element that holds the markup of `templates/`, `js/` and `css/`, so that what Nextcloud draws around it doesn't count. A serious or critical violation fails the suite; the others are listed in the log.

The browser runs in the image of Playwright that `run.sh` names, inside the network of the Compose project, and reaches Nextcloud as `http://nextcloud`; the suite turns off the first-run wizard of Nextcloud, which would cover the pages. `package.json` and `package-lock.json` pin axe-core and playwright-core. Keep playwright-core at the version of the image; `scripts/check-project.sh` compares them.

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
