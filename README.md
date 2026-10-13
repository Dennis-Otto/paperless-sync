# Paperless Sync

[![Documentation](https://img.shields.io/badge/docs-website-blue)](https://dennis-otto.github.io/paperless-sync/)
[![CI](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml)
[![Docker E2E](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml)
[![CodeQL](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/codeql.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/codeql.yml)
[![SBOM](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/sbom.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/sbom.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Dennis-Otto/paperless-sync/badge)](https://scorecard.dev/viewer/?uri=github.com/Dennis-Otto/paperless-sync)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/15277/badge)](https://www.bestpractices.dev/projects/15277)
[![REUSE](https://api.reuse.software/badge/github.com/Dennis-Otto/paperless-sync)](https://api.reuse.software/info/github.com/Dennis-Otto/paperless-sync)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%99%A5-db61a2?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/Dennis-Otto)

Paperless Sync is a native Nextcloud app that mirrors finalized Paperless-ngx documents into a structured Nextcloud archive and can optionally submit files from a Nextcloud inbox to Paperless.

Paperless remains the source of truth. Nextcloud provides convenient access through Files, desktop and mobile clients, sharing, and its viewer.

![The archive in Nextcloud Files: from the folders of the correspondents through a correspondent, a document type and a year to a document of Paperless, which opens in the viewer of Nextcloud](docs/images/archive.gif)

The [documentation website](https://dennis-otto.github.io/paperless-sync/) has this documentation as a guide, together with the architecture, the security design and the roadmap.

<sub>💛 If Paperless Sync is useful to you, you can [support its development](https://github.com/sponsors/Dennis-Otto).</sub>

[Quick start](#quick-start) · [Screenshots](#screenshots) · [Architecture](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/architecture.md) · [Security design](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/security.md) · [Roadmap](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/roadmap.md) · [Releases](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/releases.md) · [Changelog](https://github.com/Dennis-Otto/paperless-sync/blob/main/CHANGELOG.md)

<!-- --8<-- [start:how-it-works] -->

## How it works

```mermaid
---
config:
  flowchart:
    nodeSpacing: 24
---
flowchart TB
    accTitle: How Paperless Sync moves documents
    accDescr: Paperless Sync reads the finished documents and the trash of Paperless-ngx through its REST API. It writes, renames and moves their files in the folder Archiv of Nextcloud, by correspondent, type and year, and mirrors the trash into Archiv/_Gelöscht. The other way, it picks up the files of the folder Eingang and uploads them to Paperless-ngx as new documents; a failed import goes to the folder Fehler. Paperless-ngx is green, the folders of Nextcloud are blue.

    documents["Paperless-ngx<br>finished documents"]
    trash["Paperless-ngx<br>trash"]
    export(["Paperless Sync"])
    archive["📁 Archiv<br>correspondent /<br>type / year"]
    deleted["📁 Archiv/<br>_Gelöscht"]
    inbox["📁 Eingang"]
    import(["Paperless Sync"])
    consume["Paperless-ngx<br>new documents"]
    errors["📁 Fehler"]

    documents -- "read through<br>the REST API" --> export
    trash -- "read" --> export
    export -- "write, rename,<br>move" --> archive
    export -- "mirror<br>the trash" --> deleted
    %% An invisible link puts the import below the export, so that the diagram stays narrow.
    archive ~~~ inbox
    inbox -- "pick up" --> import
    import -- "upload through<br>the REST API" --> consume
    import -- "failed<br>imports" --> errors

    classDef paperless fill:#17803d2e,stroke:#17803d,stroke-width:2px
    classDef nextcloud fill:#0082c92e,stroke:#0082c9,stroke-width:2px
    class documents,trash,consume paperless
    class archive,deleted,inbox,errors nextcloud
```

At the configured interval, Nextcloud's cron starts a run of the app inside Nextcloud. It reads the finished documents of Paperless and writes each one into the archive of the Nextcloud user who owns it, at the path that the path template builds from its metadata. When the metadata changes in Paperless, the file moves; when a document goes to the Paperless trash, its copy goes to `_Gelöscht`. Files that someone puts into `Eingang` go the other way, to Paperless.

<!-- --8<-- [end:how-it-works] -->
<!-- --8<-- [start:quick-start-section] -->

## Quick start

<!-- --8<-- [start:quick-start] -->

1. Install **Paperless Sync** from the [Nextcloud App Store](https://apps.nextcloud.com/apps/paperless_sync) under *Apps*, or with `occ app:install paperless_sync`. Nextcloud's system cron must run.
2. In Paperless-ngx, create a dedicated account with view and download access to the documents to mirror, and an API token for it.
3. In Nextcloud, open **Administration settings → Paperless Sync**. Enter the Paperless URL, the token and the Nextcloud user who owns the archive, leave synchronization disabled, and save; saving tests the connection to Paperless and the folder in Nextcloud.
4. Start a **dry run** and read its summary: it lists what a run would change, without changing anything.
5. Enable synchronization and save. Nextcloud's cron runs it at the configured interval, and the documents appear in `Dokumente/Paperless/Archiv` of that user.

<!-- --8<-- [end:quick-start] -->
<!-- --8<-- [end:quick-start-section] -->
<!-- --8<-- [start:features] -->

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

<!-- --8<-- [end:features] -->

## Screenshots

Following the quick start: a dry-run lists every file that a run would write, move or delete, without changing anything, and *Synchronize now* makes the changes.

![The settings of Paperless Sync: Run dry-run lists the documents that a run would export, then Synchronize now exports them and the status turns to completed](docs/images/dry-run.gif)

A few days later, a dry-run shows every kind of change at once: new documents, a new title, a document in the Paperless trash, one with an excluded tag and a finished import from the inbox.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/report-dark.png">
  <img alt="The report of a dry-run: the counts of exported, moved, trashed and excluded documents and of finished imports, and a list with one line for every file change" src="docs/images/report-light.png">
</picture>

The archive in Files, one folder per correspondent, document type and year, with the ID of each document in its name:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/archive-dark.png">
  <img alt="Nextcloud Files with the folder tree of the archive open down to City Utilities, Invoice, 2026, which holds two electricity bills named by date, title and Paperless ID" src="docs/images/archive-light.png">
</picture>

The settings of the app in the administration settings of Nextcloud:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/settings-dark.png">
  <img alt="The administration settings of Paperless Sync: the status of the last run, the connection to Paperless and the owner of the archive" src="docs/images/settings-light.png">
</picture>

The [guide](https://dennis-otto.github.io/paperless-sync/guide.html#the-settings-section-by-section) shows every section of the settings.

<!-- --8<-- [start:usage] -->

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

Each part of the template becomes a folder. The base folder holds the inbox folder, with the files for Paperless, and the error folder, with failed imports, next to the archive; the deleted folder in the archive holds the copies of the documents in the Paperless trash:

```mermaid
---
config:
  flowchart:
    nodeSpacing: 24
---
flowchart TB
    accTitle: The folders of the default path template
    accDescr: The base folder Dokumente/Paperless holds three folders: Eingang, the inbox folder; Archiv, the archive folder; and Fehler, the error folder. Archiv holds a folder per correspondent, such as Example GmbH, and _Gelöscht, the deleted folder. In the folder of a correspondent, a folder per document type, such as Invoice, holds a folder per year, such as 2026, which holds the file 2026-08-26 - Example invoice [P123].pdf, built from created, title, the marker with the id and the extension.

    base["📁 Dokumente/Paperless<br><i>base folder</i>"]
    inbox["📁 Eingang<br><i>inbox folder</i>"]
    archive["📁 Archiv<br><i>archive folder</i>"]
    errors["📁 Fehler<br><i>error folder</i>"]
    correspondent["📁 Example GmbH<br><i>{{ correspondent }}</i>"]
    deleted["📁 _Gelöscht<br><i>deleted folder</i>"]
    type["📁 Invoice<br><i>{{ document_type }}</i>"]
    year["📁 2026<br><i>{{ created_year }}</i>"]
    file["📄 2026-08-26 -<br>Example invoice<br>[P123].pdf<br><i>{{ created }} -<br>{{ title }} [P{{ id }}]<br>{{ extension }}</i>"]

    base --> inbox & archive & errors
    archive --> correspondent & deleted
    correspondent --> type --> year --> file

    classDef nextcloud fill:#0082c92e,stroke:#0082c9,stroke-width:2px
    class base,archive,inbox,errors,correspondent,deleted,type,year,file nextcloud
```

Stable markers such as `[P123]` are compatible with the independent [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) app.

<!-- --8<-- [end:usage] -->
<!-- --8<-- [start:configuration] -->

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

The template must contain `{{ id }}`. A variable may be written with or without spaces, such as `{{id}}`, and is saved as `{{ id }}`. Every value goes into the path as plain text, so a `$1` or a `{{ title }}` in a title stays as it is.

Every file name keeps the marker `[P{{ id }}]` and ends with `{{ extension }}`: when the last part of the template leaves them out, the app adds them at its end. The marker keeps the paths of two documents apart and ties each file to its document.

Each part of the path is cleaned for Nextcloud, macOS and Windows: control characters and `< > : " / \ | ? *` become `_`, runs of spaces become one, spaces and dots at the ends go, and the names of Windows devices, such as `CON`, `COM1` or `LPT¹`, get a leading `_`.

Nextcloud accepts names of up to 250 bytes, and a character of Cyrillic takes two bytes, of Chinese three and an emoji four. So a folder name has at most 200 bytes and a file name at most 217, which leaves room for the temporary name of a write. A name that would be longer is shortened in its free text alone: the title, the correspondent, the document type, the storage path and the original file name, the longest first. The text of the template, the dates, the marker and the extension stay whole:

```mermaid
flowchart TB
    accTitle: How a part of the path is built
    accDescr: The values go into the part of the template as plain text and the part is cleaned. While it has more bytes than Nextcloud allows, the longest of the free-text values is shortened, so that the text of the template, the dates, the marker and the extension stay whole. The file name then ends with the extension.

    part["A part of the template<br><code>{{ created }} -<br>{{ title }}<br>[P{{ id }}]</code>"]
    values["The values as plain text,<br>cleaned for Nextcloud,<br>macOS and Windows"]
    fits{"At most 200 bytes<br>for a folder, 217<br>for the file name?"}
    shorten["Shorten the longest<br>free text: title,<br>correspondent,<br>document type,<br>storage path,<br>original file name"]
    name["📄 2026-08-26 -<br>Example invoice<br>[P123].pdf"]

    part --> values --> fits
    fits -- "no" --> shorten --> fits
    fits -- "yes" --> name

    classDef done fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    class name done
```

### Nextcloud inbox

Files in the inbox folder, and in its subfolders when *Scan inbox subfolders* is on, go to Paperless, at most as many in one run as the batch size allows. A file stays in the inbox until its Paperless task reports success:

```mermaid
---
config:
  flowchart:
    nodeSpacing: 24
---
flowchart TB
    accTitle: The way of a file from the inbox to Paperless
    accDescr: In the next run, a file in the folder Eingang is uploaded to Paperless. When Paperless is unreachable or overloaded, the file waits 15 minutes, then twice as long each time, up to a day, and is uploaded again. When Paperless refuses it, such as a file of an unsupported type, it stays in Eingang and is skipped until it is saved anew, renamed or replaced. When Paperless accepts it, the next runs follow its Paperless task while it is running: after success, the file is removed from Eingang or kept, as configured; after a failure, it moves to the folder Fehler, next to a .error.txt with the reason.

    %% The wait comes first, so that it is drawn above the upload and the diagram stays narrow.
    retry["Paperless<br>unreachable or<br>overloaded: waits<br>15 minutes, then<br>twice as long,<br>up to a day"]
    file["📄 A file in<br>📁 Eingang"]
    upload{"Upload to<br>Paperless"}
    task{"Its task,<br>in the<br>next runs"}
    refused["Refused, such as<br>an unsupported<br>file type: stays<br>in Eingang,<br>skipped until<br>it changes"]
    done["Success:<br>removed from<br>Eingang, or kept,<br>as configured"]
    failed["Failure:<br>moved to<br>📁 Fehler, next<br>to a .error.txt<br>with the reason"]

    retry -- "again" --> upload
    file -- "next run" --> upload
    upload --> retry
    upload -- "accepted" --> task
    upload --> refused
    refused -- "saved anew,<br>renamed or<br>replaced" --> upload
    task --> done
    task --> failed

    classDef nextcloud fill:#0082c92e,stroke:#0082c9,stroke-width:2px
    classDef paperless fill:#17803d2e,stroke:#17803d,stroke-width:2px
    class file,refused,done,failed nextcloud
    class upload,task paperless
```

A failed file keeps its subfolder below the error folder.

Paperless may refuse a file right away: one of a type it doesn't take, an empty one, or one too large for a proxy in front of it, such as the 100 MB of the free plan of Cloudflare. The report of that run lists the file once as `IMPORT REJECTED`, with the reason that Paperless gave, and the Nextcloud log records it as a warning. The runs after it leave the file in the inbox and skip it until it changes; save it anew, rename or replace it to submit it again.

When Paperless can't be reached, or fails with a server error such as `502 Bad Gateway`, the run uploads no further files and tries this one again later: after 15 minutes, then after twice as long each time, at most once a day. The other files follow in the next runs.

### Deletion safety

Moving Paperless documents to its trash can be mirrored into the configured `_Gelöscht` folder; when the trash behavior keeps archive files in place, the copy stays where it is. Permanent Nextcloud deletion is disabled by default. When enabled, a document must be absent from both the active Paperless API and its trash for the configured number of consecutive complete scans.

```mermaid
stateDiagram-v2
    direction TB
    accTitle: What happens to the copy of a document
    accDescr: An exported document is in the archive. Moved to the trash in Paperless, or gone from Paperless without the trash for N complete scans, its copy moves to _Gelöscht; restored in Paperless, it moves back to the archive. Deleted for good in Paperless, the copy in _Gelöscht is deleted in Nextcloud after N complete scans, when permanent deletion is on. A copy in the archive whose document is gone from Paperless without the trash for N complete scans is deleted directly when permanent and direct deletion are on.
    state "In the archive" as archive
    state "In _Gelöscht" as deleted
    state "Deleted in Nextcloud" as gone
    [*] --> archive: exported
    archive --> deleted: moved to the trash<br>in Paperless, or gone<br>without the trash<br>for N complete scans
    deleted --> archive: restored<br>in Paperless
    deleted --> gone: deleted for good<br>in Paperless, after<br>N complete scans, with<br>permanent deletion on
    archive --> gone: gone without the trash<br>for N complete scans,<br>with permanent and<br>direct deletion on
```

N is the number of *Required consecutive missing scans*, three by default. With the trash behavior *Keep archive file in place*, the copy stays in the archive instead of moving to `_Gelöscht`, and it is deleted from there when the rules allow it.

The copy of a document that disappears from Paperless without passing through its trash moves to the deleted folder after the same number of scans. It stays in place when the trash behavior keeps archive files in place, and it is deleted when direct deletion is allowed as well.

Only a copy that is still there is moved or deleted: a document whose copy is already gone, or that was never exported, is only marked as trashed or missing. Moves to the deleted folder and deletions count against the batch size like every other change, also when they fail: what does not fit into a run waits for the next one. A copy that cannot be moved or deleted is reported as an error of its document, and the run carries on with the others.

Only a complete scan counts. Paperless lists its documents page by page, and a page may come back short or empty, from a proxy in front of Paperless or a glitch of its permissions, or a document may be added or deleted while the run reads the pages. Then documents that still exist would look deleted. So a scan counts only when every page came back full, with the same count of Paperless, and the run received every document that Paperless listed; the run reads the documents in the order of their IDs, so that a new one joins the last page. After an incomplete scan of the documents or of the trash, the report says `SCAN INCOMPLETE`, the Nextcloud log has a warning, and the run counts no document as missing; the next complete scan carries on. A document that a scan lists again starts its count of missing scans anew. The lists of tags, correspondents, document types and storage paths are checked the same way: when one of them comes back incomplete, the run fails before it changes anything in the archive, so that a document is never exported for want of its excluded tag, and never moves for want of its correspondent.

```mermaid
flowchart TB
    accTitle: When a document counts as missing
    accDescr: Every run reads all pages of the documents and of the trash of Paperless. When a page came back short or empty, or the list changed during the scan, the run reports SCAN INCOMPLETE and counts no document as missing. Otherwise, a document in one of the lists starts its count anew, and one in neither list counts one more missing scan; after N scans in a row, its copy moves to the deleted folder, or is deleted when that is allowed, within the batch size.

    scan["A run reads every page<br>of the documents and the trash"] --> complete{"Every page full<br>and the list unchanged?"}
    complete -->|no| incomplete["SCAN INCOMPLETE:<br>no document counts<br>as missing in this run"]
    complete -->|yes| listed{"Is the document<br>in one of the lists?"}
    listed -->|yes| reset["Its count of missing<br>scans starts anew"]
    listed -->|no| scans{"Missing in N<br>scans in a row?"}
    scans -->|not yet| wait["WAIT: one more<br>missing scan"]
    scans -->|yes| change["Moved to _Gelöscht,<br>or deleted when allowed,<br>within the batch size"]
    classDef check fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef kept fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef changed fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    class scan,complete,listed,scans check
    class incomplete,reset,wait kept
    class change changed
```

### Batch size

The batch size limits the changes of one run: exports, moves, removals of excluded copies, moves to the deleted folder and deletions. A change that fails takes its place as well, so a run that can't write, such as one against a full quota or a file name that Nextcloud refuses, downloads no more documents than the batch size allows. An export that fails takes its place only among the exports, though, so documents whose export keeps failing never hold up the moves of trashed or missing documents. What doesn't fit into a run waits for the next one, and the report counts it as skipped. Uploads from the inbox have a batch of the same size of their own, so a run uploads at most that many files to Paperless as well.

```mermaid
flowchart TB
    accTitle: How a run spends its batch
    accDescr: A document to export or move is skipped when the batch is full or Nextcloud cannot store files. Otherwise it takes a place in the batch. With the conflict policy skip, a file in the way is reported as a conflict without a download. Otherwise the document is downloaded and written, or moved. A failure is reported, and the document waits behind the others in the next run; a full quota, a read-only or an unavailable storage also ends the exports of the run.

    document["A new, changed or<br>renamed document"] --> full{"Batch full, or<br>exports stopped?"}
    full -->|yes| skipped["Skipped: waits<br>for the next run"]
    full -->|no| place["Takes a place<br>in the batch"]
    place --> conflict{"Conflicts skipped and<br>a file in the way?"}
    conflict -->|yes| reported["ERROR: the conflict,<br>without a download"]
    conflict -->|no| write["Downloaded and written,<br>or moved"]
    write -->|done| done["EXPORT or MOVE"]
    write -->|fails| failed["ERROR: waits behind<br>the others next run"]
    failed -->|"full quota, read-only or<br>unavailable storage"| stopped["EXPORT STOPPED:<br>the others wait<br>for the next run"]
    classDef check fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef waits fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    classDef result fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    class full,place,conflict,write check
    class skipped,reported,failed,stopped waits
    class done result
```

The run takes the most recently added documents first, so a new document doesn't wait behind a backlog, such as the first export of a large archive. A document whose last export or move failed waits behind the others, the one that failed longest ago first, so documents that keep failing don't hold up the rest. When Nextcloud can't store files in the archive at all, because the quota is full or the archive is read-only or its storage unavailable, the run stops exporting and moving at the first failure and reports `EXPORT STOPPED`; the other documents follow in the next run, and removing copies, which frees space, goes on. Nextcloud applies no quota to the temporary file of a write, so the app checks the free space before every write itself. A path that Nextcloud refuses on its own, such as a forbidden file name or a read-only subfolder, concerns only its document. With the conflict policy *Skip and report conflict*, a file in the way is reported as a conflict before the document is downloaded, and a dry-run reports it too.

### Background jobs

The app uses Nextcloud's native cron scheduler. System cron must run reliably. The configured interval is enforced by the app; each run limits its changes in Nextcloud, and its uploads to Paperless, to the configured [batch size](#batch-size) each.

<!-- --8<-- [end:configuration] -->

## Testing

Unit tests cover path generation, state transitions, export, metadata moves, exclusions, trash handling, guarded deletion, incomplete scans of Paperless, the batch size with failing writes, inbox success and failure, dry-run behavior, and release version management.

The Docker end-to-end suite mounts this checkout into real Nextcloud containers and uses a deterministic Paperless API mock. `run-all.sh` runs it against the current release of every Nextcloud version from `min-version` to `max-version` in `appinfo/info.xml`, side by side; `run.sh` runs it against one version:

```bash
bash tests/e2e/run-all.sh
bash tests/e2e/run.sh
```

CI runs `run-all.sh` on every push to `main`, every night, and in every pull request that can affect the app; a pull request that changes only the documentation, other workflows or the version of a release passes the check at once. See [`tests/e2e/README.md`](https://github.com/Dennis-Otto/paperless-sync/blob/main/tests/e2e/README.md) for details and [`tests/e2e/MANUAL_ACCEPTANCE_TESTS.md`](https://github.com/Dennis-Otto/paperless-sync/blob/main/tests/e2e/MANUAL_ACCEPTANCE_TESTS.md) for the release matrix.

## Development

Requirements: PHP 8.2+, Composer, Node.js and Docker; the dev container in `.devcontainer/` has them ready.

```bash
composer install
bash scripts/check.sh
bash tests/e2e/run.sh
```

The pictures of the documentation and of the App Store come from the same Docker setup, with a synthetic household archive; take them anew after a change of the interface:

```bash
bash tests/e2e/screenshots.sh
```

`scripts/check.sh` runs the checks of the CI: Composer, `appinfo/info.xml` against the schema of the App Store, PHP syntax, the coding standard, Psalm, PHPUnit, the package that krankerl builds with `scripts/check-package.sh`, and the checks of this app alone in `scripts/check-project.sh`: the version of Playwright in the end-to-end tests, the rules of `tests/e2e/affected.sh`, the icon of the website, and the JavaScript and the translations.

The [website](https://dennis-otto.github.io/paperless-sync/) takes its look from the app. The icon of the app is its logo, its favicon and the logo of its preview cards, from a copy in `docs/images/app.svg` that `scripts/check-project.sh` keeps equal to `img/app.svg`. `docs/stylesheets/brand.css` holds the colours of the icon, in shades that pass WCAG AA in the light and the dark palette. A link to the home page shows the social preview of the repository, `.github/social-preview.png`, which `bash scripts/social-preview.sh` renders from `.github/social-preview.html`, and the versions of Nextcloud and PHP on the home page come from `appinfo/info.xml` through the hook `.github/mkdocs_project.py`:

```mermaid
flowchart TB
    accTitle: Where the website of Paperless Sync takes its look from
    accDescr: The icon of the app, img/app.svg, is copied to docs/images/app.svg, which scripts/check-project.sh compares with it; the website shows the copy as its logo, its favicon and on the preview cards of its pages. The social preview of the repository, rendered from .github/social-preview.html, is the preview card of the home page. The colours come from docs/stylesheets/brand.css, and the hook .github/mkdocs_project.py writes the versions of appinfo/info.xml on the home page. The files of the repository are green, the parts of the website blue.

    icon["img/app.svg<br>the icon of the app"]
    copy["docs/images/app.svg"]
    preview["social-preview.html<br>→ social-preview.png"]
    colours["docs/stylesheets/<br>brand.css"]
    info["appinfo/info.xml"]
    logo(["Logo, favicon and<br>preview cards"])
    home(["Preview card of<br>the home page"])
    palette(["Colours, light<br>and dark"])
    chips(["Versions on<br>the home page"])

    icon -- "copied, compared by<br>check-project.sh" --> copy
    copy --> logo
    preview --> home
    colours --> palette
    info -- "mkdocs_project.py" --> chips

    classDef source fill:#17803d2e,stroke:#17803d,stroke-width:2px
    classDef website fill:#0082c92e,stroke:#0082c9,stroke-width:2px
    class icon,copy,preview,colours,info source
    class logo,home,palette,chips website
```

The app ID is `paperless_sync` and the PHP namespace is `OCA\PaperlessSync`. Psalm analyzes the PHP code, CodeQL scans the JavaScript, and the SBOM workflow continuously inventories dependencies.

## Release process

The protected `main` branch requires the checks of the CI, the Docker end-to-end tests against every supported Nextcloud version, the dependency review, CodeQL, the secret scan, the licenses of every file (REUSE), the sign-off of every commit and a Conventional Commit title. Dependabot keeps the dependencies current; routine updates merge on their own once every check passes.

The release bot keeps a pull request for the next release. Its version follows from the titles of the merged pull requests, and what they wrote under *Unreleased* in `CHANGELOG.md` becomes its notes. Merging it publishes the release: the package, checked before and after signing with the app's certificate, its detached signature, an SPDX SBOM and signed build provenance, then the same package in the Nextcloud App Store, verified afterwards as users can verify it. See [the release guide](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/releases.md).

Project decisions and support expectations are documented in [GOVERNANCE.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/GOVERNANCE.md), [SUPPORT.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/SUPPORT.md), and [CODE_OF_CONDUCT.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/CODE_OF_CONDUCT.md).

## License

AGPL-3.0-or-later
