# Releases

Releases follow the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint): nobody chooses a version or writes release notes by hand.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/from-pull-requests-to-a-published-release.dark.svg">
  <img src="images/diagrams/from-pull-requests-to-a-published-release.svg" alt="From pull requests to a published release: Pull requests titled fix, feat or feat! are merged into main, and the release bot keeps the pull request chore: release x.y.z with the version and the changelog. Once the maintainer merges it, a draft release with its tag is created, the package is built and checked, signed with the app's certificate, and gets its signature, SBOM and provenance. Then the release is published and immutable, delivered to the Nextcloud App Store, and verified, and every week again.">
</picture>

## The release pull request

Every pull request that changes something for users describes it under `## Unreleased` in `CHANGELOG.md`. The release bot (`.github/workflows/release.yml`) keeps a pull request titled `chore: release x.y.z` up to date with `main`:

- **The version** follows from the titles of the pull requests merged since the last release: `fix` makes a patch, `feat` a minor and `!` or `BREAKING CHANGE` a major version. Every merge decides it anew. A line `Release-As: x.y.z` in the description of a pull request sets it explicitly.
- **The changelog:** the text of *Unreleased* becomes the section of the release; without one, the section lists the pull requests.
- **The files of the version:** `appinfo/info.xml` (`<version>` and the screenshot URLs marked `x-release-please-version`), `version.txt` and `.release-please-manifest.json`.

The pull request needs the same required checks as every other one. Its Docker end-to-end tests pass at once, as a release changes nothing of the app but its version: they ran against every supported Nextcloud version in the pull requests of its changes and with every push to `main` ([decision 0005](decisions/0005-end-to-end-tests-where-they-can-fail.md)). A release of dependency updates alone merges itself; every other release waits for the maintainer to merge it.

## Publication

Merging the release pull request creates the release as a draft, with its tag, and then:

1. krankerl builds the package of the tagged commit; `scripts/check-package.sh` checks that it holds [exactly the runtime files of that commit](#what-the-package-holds).
2. The package is signed with the app's certificate (`occ integrity:sign-app`) and checked again, and a detached SHA-512 signature is made and verified with the public key of the certificate.
3. The release gets the package `paperless_sync.tar.gz`, its signature `paperless_sync.tar.gz.sig`, an SPDX SBOM, and the signed build provenance of every asset (`provenance.sigstore.json` and `provenance.intoto.jsonl`).
4. The complete release is published; GitHub keeps it immutable from then on.
5. The same package is submitted to the Nextcloud App Store.
6. The release verification (`.github/workflows/verify-release.yml`) checks the release as its users can: every attestation, the SBOM, the immutability, the signed commit, and the version in the App Store. It checks the latest release every week as well.

## What the package holds

The package holds what Nextcloud loads of the app, `appinfo/`, `css/`, `img/`, `js/`, `l10n/`, `lib/` and `templates/`, and the `CHANGELOG.md`, `LICENSE` and `README.md` that the App Store and administrators read; the tests, the documentation and the tools of the repository stay out. krankerl decides what it leaves out by `.nextcloudignore`, so `scripts/check-package.sh` doesn't trust that list: it compares the package, file by file, with the runtime files that Git tracks in the commit that krankerl packaged.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/what-the-check-of-the-package-compares.dark.svg">
  <img src="images/diagrams/what-the-check-of-the-package-compares.svg" alt="What the check of the package compares: krankerl packages the commit in HEAD and leaves out what .nextcloudignore lists. scripts/check-package.sh takes the runtime files of the same commit, those in appinfo, css, img, js, l10n, lib and templates and CHANGELOG.md, LICENSE and README.md, and compares the package with them file by file. A file that the package lacks, a stray file, a changed file or a link fails the check with the list of the differences. A package that holds exactly these files, and its signature once it is signed, passes if its PHP and JavaScript parse, and otherwise fails with the file that doesn't. The release workflow checks the package again after signing.">
</picture>

A package fails the check, with one line for each difference, when it

- **lacks a runtime file** (`missing:`), such as the settings page in `templates/`, the German translation or a migration of the database, which a change of `.nextcloudignore` or of krankerl could drop;
- **holds a stray file** (`stray:`), one that Git doesn't track in a runtime path or that belongs to the development, such as `composer.json` or `tests/`;
- **holds a changed file** (`changed:`), whose content differs from the commit, such as one with the line endings of Windows;
- holds a link, a device or an entry whose path leads out of the folder of the app;
- lacks its signature after signing, or holds one before it;
- or holds PHP or JavaScript that doesn't parse.

The CI runs the same check on the package of every pull request, so a change that breaks the package fails there and not in a release. A new folder that the app needs at runtime belongs in `RUNTIME_PATHS` of `scripts/check-package.sh`, and `.nextcloudignore` must not list it; a path that the app no longer has leaves `RUNTIME_PATHS`, and the check names it until then. `tests/package/test_check_package.sh` tests the check with packages that it must reject.

## Secrets and recovery

The `release` environment allows only `main`. It holds `APP_PRIVATE_KEY` (the key of the app's certificate), `APPSTORE_TOKEN` and `RELEASE_AUTOMATION_PRIVATE_KEY` (the release app, whose client ID is the repository variable `RELEASE_AUTOMATION_CLIENT_ID`). Every token is short-lived and scoped to what its job needs.

If a job fails, re-run the failed jobs of the Release workflow: a draft is completed, and the App Store accepts the same version again without a second release. A published release is never replaced.

Registering the app in the App Store, needed once and again only for a new certificate, is the manual workflow *Register app in Nextcloud App Store*.
