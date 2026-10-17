# Guide

How Paperless Sync works, what it needs, how to install and set it up, what it does, how to use it, and what each part of its configuration means.

--8<-- "README.md:how-it-works"

--8<-- "README.md:requirements"

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

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-checklist-dark.png">
  <img alt="The connection checklist after Test connection: Paperless-ngx answers, the account sees the documents, correspondents, document types and storage paths, the trash has a note that the account sees a document of another owner, which the trash of Paperless leaves out, and the tags, the upload from the inbox and the tags of the inbox failed, each with the permissions to grant in Paperless" src="images/settings-checklist-light.png">
</picture>

--8<-- "README.md:configuration-checklist"

## The settings, section by section

The settings are in **Administration settings → Paperless Sync**. Above the sections, the status shows how the last run ended, when it finished, the last successful run and the next scheduled run, and the errors of the last run when it had any; below them, in a bar that stays at the bottom of the window, the buttons save the settings, start a dry-run or a run, and disconnect Paperless, with the note about unsaved changes and the result of each action next to them. *Saving, pausing and disconnecting* above explains when saving tests the connection, how to pause synchronization and what Disconnect keeps. The *Help* link of each section leads to its part of this guide.

**1 · Connection and ownership:** the address of Paperless, its API token, the Nextcloud user who owns the archive, the base folder in the files of that user, which a new installation names in the default language of Nextcloud, like the other folders, as *Folder names* above describes, and whether Paperless runs in your local network, at an address that Nextcloud otherwise refuses. **Test connection** below them checks these settings without saving them, and lists every Paperless permission that the enabled modules need, as *Connection checklist* above describes.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-connection-dark.png">
  <img alt="The section Connection and ownership: Paperless URL, Paperless API token, Nextcloud target user ID and base folder" src="images/settings-connection-light.png">
</picture>

**2 · Schedule and modules:** the scheduled synchronization, the export to Nextcloud and the import from the inbox, each on its own, the [interval](guide.md#background-jobs), from 5 minutes to a day, and the most changes of one run.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-schedule-dark.png">
  <img alt="The section Schedule and modules: switches for the scheduled synchronization, the archive export and the inbox import, the interval in minutes and the maximum changes per module and run" src="images/settings-schedule-light.png">
</picture>

**3 · Structured archive:** the archive folder, the [conflict policy](guide.md#conflict-policy) for a file in the way, the [path template](guide.md#path-template-variables) with a button for each of its variables and the paths of the newest documents with it while you type, the archive version or the original, and which documents stay out.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-archive-dark.png">
  <img alt="The section Structured archive: archive folder, file conflict policy, archive path template, the choice of the archive version, skipping inbox documents and the excluded tags" src="images/settings-archive-light.png">
</picture>

**4 · Nextcloud inbox:** the inbox folder, the error folder, the subfolders of the inbox, whether an imported file leaves the inbox, and the [tags of the inbox](guide.md#tags-of-the-inbox): one for each subfolder, tags for every import, and whether the app creates those that Paperless lacks.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-inbox-dark.png">
  <img alt="The section Nextcloud inbox: inbox folder, error folder, the switches for scanning the subfolders, removing the source after a successful import, subfolders becoming tags, which is on, and creating missing tags, and the field of the tags for every import" src="images/settings-inbox-light.png">
</picture>

**5 · Trash and deletion safety:** what a document in the Paperless trash does to its copy, the deleted folder, the scans before a missing document counts as gone, and the switches of permanent deletion.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/settings-deletion-dark.png">
  <img alt="The section Trash and deletion safety: Paperless trash behavior, deleted folder, required consecutive missing scans and the switches of permanent and direct deletion and of empty folders" src="images/settings-deletion-light.png">
</picture>

**6 · Changes within seconds:** the address of the webhook that a workflow of Paperless calls when it adds or changes a document, its secret, which the page shows once after **Create secret**, the last call and what came of it, whether a call mirrors its document at once, and the steps in Paperless, as [Changes within seconds](guide.md#changes-within-seconds) describes and shows.

## The report of a run

A dry-run, a run by hand or a run of `occ paperless_sync:run` ends with its report: how many documents each kind of change concerned, and a line for every file that the run writes, moves or deletes, or would in a dry-run, up to 100 lines with the errors and deletions first. The settings page shows it in your language, with a colored badge for each kind of change, the errors on top, a filter for each kind and, when you are the target user, a link from each file to its folder; **Copy report** copies it for a bug report. The page keeps the report of the last run, also of a scheduled one, for the next visit. A file of the inbox that Paperless refuses appears once, as *Refused* with the reason of Paperless (`IMPORT REJECTED` on the command line), and counts as a failed import; until it changes, the runs after it count it as skipped. This dry-run comes a few days after the first synchronization, when Paperless has two new documents, a new title, a document in its trash, one with the excluded tag *Private* and a finished import:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/report-dark.png">
  <img alt="The report of a dry-run under the heading Dry-run — nothing was changed, with the button Copy report: the counts of the changes that are not 0, the documents that stay unchanged in a sentence, a filter for each kind of change, and a list with a colored badge, the document and the file of every change" src="images/report-light.png">
</picture>

## Status, report and log

--8<-- "README.md:status"

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/status-error-dark.png">
  <img alt="The status of Paperless Sync after a run with an error: the state Completed with errors in red, the times of the last run, of the last successful run and of the next scheduled run, and below them a box that names the document whose download failed" src="images/status-error-light.png">
</picture>

--8<-- "README.md:status-report"

### Activity

--8<-- "README.md:activity"

### Being notified

--8<-- "README.md:notifications"

## Command line

--8<-- "README.md:command-line"

## FAQ and troubleshooting

The [FAQ](faq.md) answers the common questions, such as what happens to an edit of a mirrored file, where deleted files go and how long the first synchronization takes. [Troubleshooting](troubleshooting.md) leads from an error message, a run that fails or a file that is missing to its cause and its fix.

--8<-- "README.md:help"
