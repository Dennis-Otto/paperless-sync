# Development

How to build, check and test Paperless Sync, where the pictures and the look of the website come from, and how a change becomes a release. [CONTRIBUTING.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/CONTRIBUTING.md) has the rules for a change: its tests, the sign-off of every commit and the title of its pull request.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-a-change-into-a-release.dark.svg">
  <img src="images/diagrams/the-way-of-a-change-into-a-release.svg" alt="The way of a change into a release: A change on a branch passes bash scripts/check.sh and the end-to-end tests in Docker on the machine of its author. Its pull request, titled as a Conventional Commit, passes the required checks: the CI, the end-to-end tests against every supported Nextcloud version when the change can affect the app, CodeQL, the secret scan, the licenses of every file and the sign-off. A failed check sends it back to the branch. Once every check passes, it is squashed into main, and the release bot adds it to the pull request of the next release; merging that one publishes the signed package on GitHub and in the Nextcloud App Store. Your steps are orange, the checks and the bots blue, and the release green.">
</picture>

## Set-up

You need PHP 8.2+, Composer, Node.js and Docker; the dev container in `.devcontainer/` has them ready, for VS Code and for GitHub Codespaces.

```bash
composer install
bash scripts/check.sh
bash tests/e2e/run.sh
```

`scripts/check.sh` runs the checks of the CI: Composer, `appinfo/info.xml` against the schema of the App Store, PHP syntax, the coding standard, Psalm, PHPUnit, the package that krankerl builds, which `scripts/check-package.sh` compares file by file with the runtime files of the commit ([what the package holds](releases.md#what-the-package-holds)), and the checks of this app alone in `scripts/check-project.sh`: the version of Playwright in the end-to-end tests, the rules of `tests/e2e/affected.sh`, the icon of the website, the tests of its hook, the versions in the README, the same description of the app on GitHub, on the website and in `composer.json`, an [area label](automation.md#a-pull-request) for every file of the app and of its unit tests, the tests of `scripts/check-package.sh`, and the JavaScript and the translations.

## Tests

Unit tests cover path generation, state transitions, export, metadata moves, exclusions, trash handling, guarded deletion, incomplete scans of Paperless, the batch size with failing writes, inbox success and failure, dry-run behavior, and release version management. `scripts/check.sh` fails when a line of `lib/` runs in no test.

`tests/package/test_check_package.sh` tests the check of the release package in a throwaway repository, in a few seconds and without krankerl: the package of a small app passes, packed as krankerl packs it and again as the release workflow packs it after signing, and every package that lacks a runtime file, holds a stray, changed or linked one, or the wrong signature fails with the difference. `scripts/check-project.sh` runs it.

The Docker end-to-end suite mounts this checkout into real Nextcloud containers and uses a deterministic Paperless API mock. It configures the app, runs dry-runs, exports, moves, the trash and its restore, conflicts, documents that vanish, guarded deletion and the inbox, and starts the background job as system cron would. `run-all.sh` runs it against the current release of every Nextcloud version from `min-version` to `max-version` in `appinfo/info.xml`, side by side, and the newest of them also on PostgreSQL, and on MariaDB with S3 object storage; `run.sh` runs it against one version, on SQLite by default:

```bash
bash tests/e2e/run-all.sh
bash tests/e2e/run.sh
E2E_DATABASE=mysql E2E_OBJECTSTORE=s3 bash tests/e2e/run.sh
```

CI runs `run-all.sh` on every push to `main`, every night, and in every pull request that can affect the app; a pull request that changes only the documentation, other workflows or the version of a release passes the check at once. PostgreSQL, MariaDB and S3 run in a job of their own, a required check as well, on every push to `main`, every night, and in a pull request that changes the migrations, the state, the files in Nextcloud, the uploads to Paperless or the tests. See [`tests/e2e/README.md`](https://github.com/Dennis-Otto/paperless-sync/blob/main/tests/e2e/README.md) for details and [`tests/e2e/MANUAL_ACCEPTANCE_TESTS.md`](https://github.com/Dennis-Otto/paperless-sync/blob/main/tests/e2e/MANUAL_ACCEPTANCE_TESTS.md) for the release matrix.

## Pictures

The pictures of the documentation and of the App Store come from the same Docker setup, with a synthetic household archive; take them anew after a change of the interface:

```bash
bash tests/e2e/screenshots.sh
```

## The README and the website

The first screen of the README shows the version in the App Store and the versions of Nextcloud in badges that read `appinfo/info.xml` on `main`, so that they follow every release and every raise of `max-version` by the upstream bot without an edit. `scripts/check-project.sh` fails when one of them reads anything else, and when a version in the text of the README, of the home page or of the [comparison](alternatives.md), such as `<span data-app="nextcloud">33–35</span>`, names another lowest version or more than `appinfo/info.xml`; text that still names a lower `max-version` after the upstream bot raised it only gets a note, so that the pull request of the bot passes on its own.

The [website](index.md) takes its look from the app. The icon of the app is its logo, its favicon and the logo of its preview cards, from a copy in `docs/images/app.svg` that `scripts/check-project.sh` keeps equal to `img/app.svg`. `docs/stylesheets/brand.css` holds the colors of the icon, in shades that pass WCAG AA in the light and the dark palette. A link to the home page shows the social preview of the repository, `.github/social-preview.png`, which `bash scripts/social-preview.sh` renders from `.github/social-preview.html`. The versions of Nextcloud and PHP on the website come from `appinfo/info.xml` through the hook `.github/mkdocs_project.py`, which writes them into the fields such as `<span data-app="php">8.2+</span>` of every page, those of the requirements that the guide takes from the README included; GitHub shows the text of the fields as written:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/where-the-website-of-paperless-sync-takes-its-look-from.dark.svg">
  <img src="images/diagrams/where-the-website-of-paperless-sync-takes-its-look-from.svg" alt="Where the website of Paperless Sync takes its look from: The icon of the app, img/app.svg, is copied to docs/images/app.svg, which scripts/check-project.sh compares with it; the website shows the copy as its logo, its favicon and on the preview cards of its pages. The social preview of the repository, rendered from .github/social-preview.html, is the preview card of the home page. The colors come from docs/stylesheets/brand.css, and the hook .github/mkdocs_project.py writes the versions of appinfo/info.xml into the pages. The files of the repository are green, the parts of the website blue.">
</picture>

The same hook shows an alert of GitHub, a quote that begins with `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]` or `[!CAUTION]`, as an admonition of the website. So a page, or a part of the README that the website takes by a snippet, writes an alert once for both, such as the warning about edits of the archive in the [conflict policy](guide.md#conflict-policy).

## Code

The app ID is `paperless_sync` and the PHP namespace is `OCA\PaperlessSync`. Psalm analyzes the PHP code, CodeQL scans the JavaScript, and the SBOM workflow continuously inventories dependencies. The [architecture](architecture.md) describes the parts of the app, and the [decisions](decisions/README.md) why it works as it does.

## Release process

The protected `main` branch requires the checks of the CI, the Docker end-to-end tests against every supported Nextcloud version, the dependency review, CodeQL, the secret scan, the licenses of every file (REUSE), the sign-off of every commit and a Conventional Commit title. Renovate keeps the dependencies, the actions and the images current, and Dependabot the Features of the dev container; routine updates merge on their own once every check passes. [Automation](automation.md) shows every workflow and bot, and when it runs.

The release bot keeps a pull request for the next release. Its version follows from the titles of the merged pull requests, and what they wrote under *Unreleased* in `CHANGELOG.md` becomes its notes. Merging it publishes the release: the package, checked before and after signing with the app's certificate, its detached signature, an SPDX SBOM and signed build provenance, then the same package in the Nextcloud App Store, verified afterwards as users can verify it. See [the release guide](releases.md).

Project decisions and support expectations are documented in [GOVERNANCE.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/GOVERNANCE.md), [SUPPORT.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/SUPPORT.md), and [CODE_OF_CONDUCT.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/CODE_OF_CONDUCT.md).
