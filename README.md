# Paperless Sync

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

## Development

Requirements: PHP 8.2+, Composer, and a Nextcloud 33 development instance.

```bash
composer install
composer lint
composer l10n:check
composer test
composer cs:check
composer psalm
composer version:check
```

The app ID is `paperless_sync` and the PHP namespace is `OCA\\PaperlessSync`.

## Release process

The **Release** GitHub workflow accepts `patch`, `minor`, or `major`. It validates the project, updates every versioned location, builds and signs the archive, commits the release version with a DCO sign-off, creates the GitHub release, and publishes it to the Nextcloud App Store.

Private signing material and App Store credentials exist only as protected GitHub environment secrets and are never committed.

## License

AGPL-3.0-or-later
