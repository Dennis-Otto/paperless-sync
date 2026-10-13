<!-- The first screen. The badges of the version in the App Store and of the versions of
Nextcloud read appinfo/info.xml on main, so that they follow every release and every
raise of max-version by the upstream bot; scripts/check-project.sh checks that they do. -->
<div align="center">

<img src="img/app.svg" alt="" width="72" height="72">

# Paperless Sync

**Your Paperless-ngx archive as an always-current folder tree in Nextcloud Files — plus an inbox from Nextcloud into Paperless.**

[![The version of Paperless Sync in the Nextcloud App Store](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2FDennis-Otto%2Fpaperless-sync%2Fmain%2Fappinfo%2Finfo.xml&query=%2Finfo%2Fversion&prefix=v&label=App%20Store&logo=nextcloud&logoColor=white&color=0082c9)](https://apps.nextcloud.com/apps/paperless_sync)
[![The versions of Nextcloud that Paperless Sync supports](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2FDennis-Otto%2Fpaperless-sync%2Fmain%2Fappinfo%2Finfo.xml&query=concat%28%2F%2Fdependencies%2Fnextcloud%2F%40min-version%2C%27%E2%80%93%27%2C%2F%2Fdependencies%2Fnextcloud%2F%40max-version%29&label=Nextcloud&logo=nextcloud&logoColor=white&color=0082c9)](#requirements)
[![License: AGPL-3.0-or-later](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](LICENSE)
[![Documentation](https://img.shields.io/badge/docs-website-526cfe?logo=materialformkdocs&logoColor=white)](https://dennis-otto.github.io/paperless-sync/)

[**Get it from the App Store**](https://apps.nextcloud.com/apps/paperless_sync) · [Requirements](#requirements) · [Quick start](#quick-start) · [Documentation](https://dennis-otto.github.io/paperless-sync/) · [Help](#help-and-feedback) · [Changelog](CHANGELOG.md)

![The archive in Nextcloud Files: from the folders of the correspondents through a correspondent, a document type and a year to a document of Paperless, which opens in the viewer of Nextcloud](docs/images/archive.gif)

</div>

Paperless Sync is a native Nextcloud app. It writes the finished documents of Paperless-ngx into a folder tree in the files of a Nextcloud user, by correspondent, document type and year, and keeps the tree current: a new title renames a file, a new correspondent moves it, and the Paperless trash has a folder of its own. Files that you put into an inbox folder in Nextcloud go the other way, to Paperless. Paperless remains the source of truth, and Nextcloud gives you the documents in Files, in its desktop and mobile clients, in its viewer and in shares.

Unlike a mount of the media folder of Paperless or a script that copies over WebDAV, Paperless Sync runs inside Nextcloud as one of its background jobs: it needs no extra container and no stored Nextcloud password, the copies are real files of Nextcloud with versions, shares and search, an edit in Nextcloud never reaches the originals in Paperless, and a copy is deleted only after complete scans of Paperless confirm that its document is gone.

The [documentation website](https://dennis-otto.github.io/paperless-sync/) has this guide, together with the [architecture](docs/architecture.md), the [security design](docs/security.md), the [decisions](docs/decisions/README.md) and the [roadmap](docs/roadmap.md).

<sub>💛 If Paperless Sync works for you, [rate it in the App Store](https://apps.nextcloud.com/apps/paperless_sync) so that others find it, or [support its development](https://github.com/sponsors/Dennis-Otto).</sub>

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

At the configured interval, Nextcloud's cron starts a run of the app inside Nextcloud. It reads the finished documents of Paperless and writes each one into the archive in the files of the configured Nextcloud target user, at the path that the path template builds from its metadata; other users see the archive when that user shares it with them. When the metadata changes in Paperless, the file moves; when a document goes to the Paperless trash, its copy goes to `_Gelöscht`. Files that someone puts into `Eingang` go the other way, to Paperless.

<!-- --8<-- [end:how-it-works] -->
<!-- --8<-- [start:requirements] -->

## Requirements

<!-- On the website, the hook in .github/mkdocs_project.py writes the versions of appinfo/info.xml into the fields. -->

- **Nextcloud <span data-app="nextcloud">33–35</span>**, with the system cron for its background jobs, as Nextcloud recommends: *Administration settings → Basic settings → Background jobs → Cron*. Every run of the app is one of these jobs.
- **PHP <span data-app="php">8.2+</span>** on the Nextcloud server.
- **Paperless-ngx 2.10 or newer**, the first version with a trash, which the app mirrors, at an address that the Nextcloud server reaches over HTTP or HTTPS. A Paperless at an address of the local network, such as `http://paperless:8000` in Docker or `http://192.168.1.20:8000`, works today only with the system setting `allow_local_remote_servers` of Nextcloud, which turns off its protection against server-side request forgery for the whole server; [#110](https://github.com/Dennis-Otto/paperless-sync/issues/110) narrows it to Paperless.
- **A Paperless account for the app**, with an API token. It needs to view and download the documents to mirror, to see them in the trash for the deleted folder, and the permission `documents.add_document` for the inbox; [Paperless connection](#paperless-connection) has the details.

<!-- --8<-- [end:requirements] -->
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

### Archive

- A folder tree in the files of the configured Nextcloud user, by correspondent, document type and year, from a [path template](#path-template-variables) that you can change, with base, archive, inbox, error and deleted folders of your choice
- The archive PDF of each document, or its original file
- Renames and moves when the metadata changes in Paperless, and no empty folders left behind
- Documents in the Paperless inbox wait until they are finished, and a document with a tag that you exclude stays out, its copy removed when it gets the tag
- A stable marker such as `[P123]` in every file name, which [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) understands

### Inbox

- Files in an inbox folder of Nextcloud, and in its subfolders if you like, go to Paperless as new documents
- The app follows the Paperless task of each file: after a success, it removes the file or keeps it, as you choose; after a failure, it moves the file to the error folder, next to the reason
- When Paperless can't take a file now, the upload waits and tries again, from 15 minutes up to a day

### Safety

- Synchronization stays off until you turn it on, and a dry run lists every change that a run would make, without making it
- The Paperless trash is mirrored into a folder of its own; permanent deletion is off by default and, when on, waits for the set number of complete scans of Paperless in a row
- A conflict policy for a file in the way: replace it, or skip it and report the conflict
- No WebDAV credentials and no password of a Nextcloud user: the app writes through Nextcloud's filesystem API
- The Paperless API token stays in Nextcloud's credentials manager, never reaches the browser and goes only to the address saved with it

### Operations

- Nextcloud's cron runs it at the interval that you set, and the batch size limits the changes of one run
- Runs on demand and dry runs from the settings, each with a report of its changes and errors, and the status of the last run
- Signed releases: the same package in the Nextcloud App Store and on GitHub, with an SBOM and signed build provenance

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

The API token is stored in Nextcloud's credentials manager and never returned to the browser. It goes only to the Paperless URL that it was saved with: to connect to another URL, enter the token again. This holds also when the URL changes past the settings page, with `occ` or Nextcloud's provisioning API; synchronization then waits for the token. Saving the settings and disconnecting ask for your password, unless you confirmed it in the last 30 minutes.

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
<!-- --8<-- [start:help] -->

## Help and feedback

- **A question** about the set-up, or whether the app can do something: ask in the [Q&A discussions](https://github.com/Dennis-Otto/paperless-sync/discussions/categories/q-a), where the answer stays for the next person who asks.
- **A bug**: open a [bug report](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=bug_report.yml) with the versions of the app and of Nextcloud. Report a vulnerability [privately](https://github.com/Dennis-Otto/paperless-sync/security/advisories/new) instead.
- **An idea**: suggest it in a [feature request](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=feature_request.yml).
- **If it works for you**: [rate it in the Nextcloud App Store](https://apps.nextcloud.com/apps/paperless_sync), so that others find it, star the [repository](https://github.com/Dennis-Otto/paperless-sync), or [support its development](https://github.com/sponsors/Dennis-Otto).

<!-- --8<-- [end:help] -->

## Development

Contributions are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) says how a change gets in, and [Development](docs/development.md) how to build, check and test the app: `scripts/check.sh`, the end-to-end tests in Docker against every supported Nextcloud version, the pictures of the documentation, the website and the release process. The project follows [GOVERNANCE.md](GOVERNANCE.md), [SUPPORT.md](SUPPORT.md) and the [code of conduct](CODE_OF_CONDUCT.md).

## Quality and security

[![CI](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml)
[![Docker E2E](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml)
[![CodeQL](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/codeql.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/codeql.yml)
[![SBOM](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/sbom.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/sbom.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Dennis-Otto/paperless-sync/badge)](https://scorecard.dev/viewer/?uri=github.com/Dennis-Otto/paperless-sync)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/15277/badge)](https://www.bestpractices.dev/projects/15277)
[![REUSE](https://api.reuse.software/badge/github.com/Dennis-Otto/paperless-sync)](https://api.reuse.software/info/github.com/Dennis-Otto/paperless-sync)

A change reaches `main` only when the unit tests, which run every line of the app, pass, together with Psalm, CodeQL, the secret scan, the dependency review, the license check of every file and, when the change can affect the app, the end-to-end tests in Docker against every supported Nextcloud version. Every release is signed, with an SBOM and signed build provenance. The [security design](docs/security.md) says what the app protects and how; report a vulnerability [privately](https://github.com/Dennis-Otto/paperless-sync/security/advisories/new).

## License

[AGPL-3.0-or-later](LICENSE)
