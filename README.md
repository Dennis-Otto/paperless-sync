# Paperless Sync

[![CI](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml)
[![Docker E2E](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Dennis-Otto/paperless-sync/badge)](https://scorecard.dev/viewer/?uri=github.com/Dennis-Otto/paperless-sync)

Paperless Sync is a native Nextcloud app that mirrors finalized Paperless-ngx documents into a structured Nextcloud archive and can optionally submit files from a Nextcloud inbox to Paperless.

Paperless remains the source of truth. Nextcloud provides convenient access through Files, desktop and mobile clients, sharing, and its viewer.

## Features

- Native Nextcloud filesystem operations without WebDAV credentials
- Configurable target user and base, archive, inbox, error, and deleted folders
- Configurable archive path template
- Correspondent-first folder hierarchy by default
- Archive PDF or original-file export
- Metadata-aware renames and moves
- Paperless inbox detection and configurable excluded tags, including removal of previously mirrored copies
- Recursive Nextcloud inbox import with Paperless task tracking
- Paperless trash mirroring and optional permanent deletion
- Configurable missing-document confirmation runs
- Empty-folder pruning
- Conflict policy, batch size, and interval controls
- Dry-run, manual execution, status, and error summaries
- Server-side token storage through Nextcloud's credentials manager
- Automated semantic releases and signed App Store packages

## Usage

After installation, open **Administration settings → Paperless Sync**. Configure and test the connection while synchronization remains disabled. Run a dry-run, review the summary, and only then enable scheduled synchronization.

The default archive path template is:

```text
{{ correspondent }}/{{ document_type }}/{{ created_year }}/{{ created }} - {{ title }} [P{{ id }}]{{ extension }}
```

This produces paths such as:

```text
Dokumente/Paperless/Archiv/Example GmbH/Invoice/2026/2026-08-26 - Example invoice [P123].pdf
```

Stable markers such as `[P123]` are compatible with the independent [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) app.

## Configuration

### Paperless connection

Use a dedicated Paperless service account. It needs view and download access to every document that should be exported. Enable `documents.add_document` only when Nextcloud inbox import is used. Trash synchronization requires visibility of the corresponding trashed documents.

The API token is stored in Nextcloud's credentials manager and never returned to the browser.

### Folder ownership

The configured Nextcloud target user owns the synchronized folders. The app operates through Nextcloud's internal filesystem API and therefore does not need that user's password or an app password.

### Path template variables

- `{{ id }}`
- `{{ title }}`
- `{{ correspondent }}`
- `{{ document_type }}`
- `{{ storage_path }}`
- `{{ created }}`, `{{ created_year }}`, `{{ created_month }}`
- `{{ added }}`, `{{ added_year }}`
- `{{ original_filename }}`
- `{{ extension }}`

The template must contain `{{ id }}`. Path components are normalized and sanitized for Nextcloud, macOS, and Windows clients.

### Deletion safety

Moving Paperless documents to its trash can be mirrored into the configured `_Gelöscht` folder. Permanent Nextcloud deletion is disabled by default. When enabled, a document must be absent from both the active Paperless API and its trash for the configured number of consecutive complete scans.

### Background jobs

The app uses Nextcloud's native cron scheduler. System cron must run reliably. The configured interval is enforced by the app; each run limits modifications to the configured batch size.

## Testing

Unit tests cover path generation, state transitions, export, metadata moves, exclusions, trash handling, guarded deletion, inbox success and failure, dry-run behavior, and release version management.

The Docker end-to-end suite mounts this checkout into real Nextcloud containers and uses a deterministic Paperless API mock:

```bash
bash tests/e2e/run.sh
```

CI runs the suite against Nextcloud 33 and the current stable Nextcloud 34 release. See [`tests/e2e/README.md`](tests/e2e/README.md) for details and [`tests/e2e/MANUAL_ACCEPTANCE_TESTS.md`](tests/e2e/MANUAL_ACCEPTANCE_TESTS.md) for the release matrix.

## Development

Requirements: PHP 8.2+, Composer, Node.js, Docker, Krankerl, and a Nextcloud 33+ development instance.

```bash
composer install
composer lint
composer l10n:check
composer test
composer cs:check
composer psalm
composer version:check
bash tests/e2e/run.sh
krankerl package
composer package:check
```

The app ID is `paperless_sync` and the PHP namespace is `OCA\\PaperlessSync`.

## Release process

The protected `main` branch accepts changes only through pull requests after CI, dependency review, Docker E2E, and secret scanning succeed. Dependabot checks Composer, GitHub Actions, and Docker Compose weekly. Grouped patch and minor updates are queued for automatic squash merge only after those protected checks pass; major updates remain manual. Dependency maintenance never starts a release.

The manually dispatched **Release** workflow accepts `patch`, `minor`, or `major`. It validates the project, prepares a signed-off version commit on `release/vX.Y.Z`, opens a protected pull request, explicitly starts every required check, and waits for GitHub auto-merge. Only the exact merged commit is then built, package-checked, signed, supplied with a GitHub build-provenance attestation, tagged, published as a GitHub release, and submitted to the Nextcloud App Store.

An interrupted run resumes an existing release branch, merged release PR, tag, or incomplete GitHub release instead of incrementing again.

Dependency Review blocks newly introduced vulnerable or unapproved dependencies. OpenSSF Scorecard audits the repository's supply-chain security every week.

Private signing material and App Store credentials exist only as protected GitHub environment secrets and are never committed.

## License

AGPL-3.0-or-later
