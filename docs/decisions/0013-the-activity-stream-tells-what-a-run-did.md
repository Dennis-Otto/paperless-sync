# The Activity stream tells the target user what a run did, one entry per run and per import

- Status: accepted
- Date: 2026-10-14

## Context

The archive and the inbox belong to the target user, but only an administrator saw what a run did: on the settings page, in the report of the last run, and in the Nextcloud log. The target user learned of a new document, a document that moved to the deleted folder or an inbox file that Paperless refused only by looking through the folders. Nextcloud's own entries of the *Files* app don't help, as the end-to-end environment shows on Nextcloud 33: the app writes through the filesystem API without a session of the target user ([decision 0002](0002-the-filesystem-api-of-nextcloud.md)), so a run by hand, in the session of an administrator, leaves no entry at all, and a scheduled run, without a session, leaves entries such as *"remote account" changed a.pdf*, which name no Paperless and read like a stranger at work. A run may change hundreds of files, up to its batch size, and the inbox may hold as many.

## Options

1. One entry for every file that a run writes, moves or deletes. It matches the entries of the *Files* app, but a first synchronization, or a change of the path template, floods the stream with hundreds of entries.
2. One entry for each run that changed something, with the counts of its changes and the links of its first five files, and one entry for each import that Paperless finished, failed or refused. A run stays one line however large it is, and an import, which the user started by putting a file into the inbox, gets an answer of its own, with the new document in Paperless or the reason of a failure.
3. Notifications of the *Notifications* app instead. They demand attention and stay until dismissed, which suits failures that need the user, but not the routine of a scheduled run every few minutes.

## Decision

Option 2, through the public activity API of Nextcloud: a provider, a setting and a filter, registered in `appinfo/info.xml`. A dry-run and a run without changes add no entry, and a run that fails after its first changes still adds the entry of those. The entries go to the target user, who owns the files. They store the IDs and paths of the files and the ID of the new document, and the provider puts them into words, in the language of the reader, when the Activity app shows them, so that a link leads to its file also after a later move, and a new address of Paperless reaches the links of earlier entries. An entry that Nextcloud refuses is logged as a warning and leaves the run alone, since its changes are done.

## Consequences

The target user sees the work of the app in the Activity app, on the dashboard and in the *Activity* tab of the archive folder in *Files*, and can have the entries sent by mail or as notifications from the personal activity settings; the Activity app of Nextcloud 33 always shows them in the stream. Users with whom the archive is shared see no entries; publishing to them as well is a later step. Each run looks up the IDs of at most five files and two folders, and of the file of each import result. The Paperless client reads the ID of the new document from `related_document` of the task, or from the text of its result. The end-to-end tests read the entries through the API of the Activity app.
