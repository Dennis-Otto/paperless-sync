# Guide

How to install and set up Paperless Sync, what it does, how to use it, and what each part of its configuration means.

--8<-- "README.md:how-it-works"

--8<-- "README.md:quick-start-section"

The dry-run and the first synchronization of the quick start:

![The settings of Paperless Sync: Run dry-run lists the documents that a run would export, then Synchronize now exports them and the status turns to completed](images/dry-run.gif)

--8<-- "README.md:features"

--8<-- "README.md:usage"

The archive in Files, one folder per correspondent, document type and year:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/archive-dark.png">
  <img alt="Nextcloud Files with the folder tree of the archive open down to City Utilities, Invoice, 2026, which holds two electricity bills named by date, title and Paperless ID" src="images/archive-light.png">
</picture>

--8<-- "README.md:configuration"

## The settings, section by section

The settings are in **Administration settings → Paperless Sync**. Above the sections, the status shows the state and the end of the last run; below them, the buttons save the settings after a test of the connection, start a dry-run or a run, and disconnect Paperless.

**1 · Connection and ownership:** the address of Paperless, its API token, the Nextcloud user who owns the archive, and the base folder in the files of that user.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-connection-dark.png">
  <img alt="The section Connection and ownership: Paperless URL, Paperless API token, Nextcloud target user ID and base folder" src="images/settings-connection-light.png">
</picture>

**2 · Schedule and modules:** the scheduled synchronization, the export to Nextcloud and the import from the inbox, each on its own, the interval, and the most changes of one run.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-schedule-dark.png">
  <img alt="The section Schedule and modules: switches for the scheduled synchronization, the archive export and the inbox import, the interval in minutes and the maximum changes per module and run" src="images/settings-schedule-light.png">
</picture>

**3 · Structured archive:** the archive folder, what happens when a file is in the way, the path template, the archive version or the original, and which documents stay out.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-archive-dark.png">
  <img alt="The section Structured archive: archive folder, file conflict policy, archive path template, the choice of the archive version, skipping inbox documents and the excluded tags" src="images/settings-archive-light.png">
</picture>

**4 · Nextcloud inbox:** the inbox folder, the error folder, the subfolders of the inbox, and whether an imported file leaves the inbox.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-inbox-dark.png">
  <img alt="The section Nextcloud inbox: inbox folder, error folder, scanning the subfolders and removing the source after a successful import" src="images/settings-inbox-light.png">
</picture>

**5 · Trash and deletion safety:** what a document in the Paperless trash does to its copy, the deleted folder, the scans before a missing document counts as gone, and the switches of permanent deletion.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-deletion-dark.png">
  <img alt="The section Trash and deletion safety: Paperless trash behavior, deleted folder, required consecutive missing scans and the switches of permanent and direct deletion and of empty folders" src="images/settings-deletion-light.png">
</picture>

## The report of a run

A dry-run or a run by hand ends with its report: how many documents each kind of change concerned, and for a dry-run a line for every file that a run would write, move or delete. A file of the inbox that Paperless refuses appears once, as `IMPORT REJECTED` with the reason of Paperless, and counts as a failed import; until it changes, the runs after it count it as skipped. This dry-run comes a few days after the first synchronization, when Paperless has two new documents, a new title, a document in its trash, one with the excluded tag *Private* and a finished import:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/report-dark.png">
  <img alt="The report of a dry-run: the counts of exported, moved, trashed and excluded documents and of finished imports, and a list with one line for every file change" src="images/report-light.png">
</picture>
