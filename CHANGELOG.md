# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## [0.1.7](https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.6...v0.1.7) (2026-10-07)

### Bug fixes

- The copy of a document that disappeared from Paperless now moves to the deleted folder after the confirmation runs also when a run reaches its batch size first: it waits for the next run. Before, it stayed where it was for good.
- *Moved to trash* and *Deleted* in the summary of a run count only copies that were really moved or deleted, no longer documents whose copy was already gone.
- A copy of a missing document that cannot be moved or deleted is reported as an error of that document, and the run carries on with the others. Before, the whole run failed.
- A document in the Paperless trash whose copy is already gone no longer counts as *Moved to trash* in every run, and the path recorded for its copy no longer grows by one more deleted folder and date in each run.
- A document in the Paperless trash that was never exported no longer causes an error in every run.
- With the trash behavior *Keep archive file in place*, a dry run no longer reports and counts a move to the trash that the real run doesn't make.

## 0.1.6 - 2026-10-07

### Fixed

- Saving, resetting, running and refreshing the administration settings no longer fails with empty request URLs when Nextcloud renders the settings page before it has loaded the app (the same cause as [paperless-unified-search#25](https://github.com/Dennis-Otto/paperless-unified-search/issues/25)). The app now declares its routes as attributes on the controllers, which Nextcloud 29 and later register for every enabled app.

<!-- Release notes generated using configuration in .github/release.yml at f579d34122588298decbbd1ad82ca996e0b00a26 -->

### What's Changed
### Fixes
* fix: register the routes as attributes by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/34
### Other changes
* ci: look after issues with the issue assistant by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/33


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.5...v0.1.6

## 0.1.5 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at 0215bf94461794ed1f345e856d14bb1946cbd5cf -->

### What's Changed
### Dependencies
* chore(deps): Bump anchore/sbom-action from 0.24.2 to 0.24.3 in the actions-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/30
* chore(deps): Bump nextcloud from 33.0.9-apache to 33.0.9-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/31


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.4...v0.1.5

## 0.1.4 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at 603966a71d3d95695c17c4b8e589444bab7f9fd8 -->

### What's Changed
### Dependencies
* chore(deps-dev): Bump vimeo/psalm from 6.18.1 to 6.19.1 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/28


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.3...v0.1.4

## 0.1.3 - 2026-09-28

<!-- Release notes generated using configuration in .github/release.yml at 2f7471bc9b6964221eefe90c1b076351081b9b6b -->

### What's Changed
### Dependencies
* chore(deps-dev): Bump vimeo/psalm from 6.17.2 to 6.18.1 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/23
* chore(deps): Bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/24
* chore(deps): Bump nextcloud from 33.0.9-apache to 33.0.9-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/26


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.2...v0.1.3

## 0.1.2 - 2026-09-21

<!-- Release notes generated using configuration in .github/release.yml at eb19bcabfbee4d333095f5cee134a20c2d3ee84c -->

### What's Changed
### Dependencies
* chore(deps-dev): Bump vimeo/psalm from 6.17.0 to 6.17.2 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/20
* chore(deps): Bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/21
### Other changes
* ci: pin Dependabot auto-merge to the inspected commit by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/19


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.1...v0.1.2

## 0.1.1 - 2026-09-19

- Publish checked, signed Nextcloud maintenance releases automatically after merged Dependabot updates.
- Generate categorized release notes, with an optional maintainer introduction for manual releases.
- Require successful PR and main checks on the exact release commit, without cancelling other CI runs.
- Resume interrupted GitHub and App Store publication without duplicate versions or replacing public assets.

- Publish the configured app version unchanged for the first GitHub release.
- Move release commits before reproducible packaging while keeping commit and tag publication atomic.
- Pin checkout, PHP setup, and Gitleaks actions to immutable Node 24-compatible revisions.
- Verify DCO sign-offs, translations, dependencies, app metadata, and production-package boundaries automatically.
- Validate unsigned and signed release archives and exclude development-only files from packages.
- Add reproducible Docker end-to-end coverage for Nextcloud 33 and 34 with a deterministic Paperless API mock.
- Exercise dry-run, export, metadata moves, exclusions, trash, guarded deletion, inbox success and failure, pruning, permissions, and background-job registration.
- Expand contribution, security, testing, release, and manual acceptance documentation.
- Protect `main` behind required CI, E2E, secret-scan, linear-history, and pull-request rules, including release version commits.
- Add weekly grouped Dependabot updates with protected automatic squash merges for patch and minor changes while keeping major updates subject to maintainer approval.
- Add CodeQL analysis and continuous SPDX SBOM generation.
- Publish detached signatures, SBOMs, and public Sigstore provenance with releases.
- Document project governance, conduct, and support.

<!-- Release notes generated using configuration in .github/release.yml at 5ce005ddfe9a014e551056f4192fc986de9862ca -->

### What's Changed
### Dependencies
* chore(deps): Bump nextcloud from 33.0.8-apache to 33.0.8-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/12
* chore(deps-dev): Bump the composer-routine group with 2 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/14
* chore(deps): Bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/15
* chore(deps): Bump nextcloud from 33.0.8-apache to 33.0.9-apache in /tests/e2e in the containers-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/16
* chore(deps): Bump python from 3.14-alpine to 3.14-alpine in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/13
### Other changes
* ci: automate protected dependency releases and recover publication by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/17


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.0...v0.1.1

## 0.1.0 - 2026-08-26

- Add configurable one-way Paperless archive synchronization using Nextcloud's native filesystem API.
- Add optional Nextcloud inbox import with Paperless task tracking and error handling.
- Add metadata-aware moves, configurable path templates, Paperless trash mirroring, optional permanent deletion, and empty-folder cleanup.
- Add dry-run support, synchronization status, safe defaults, persistent database state, and native background jobs.
- Add automated testing, secret scanning, semantic versioning, signed packaging, and App Store release workflows.
