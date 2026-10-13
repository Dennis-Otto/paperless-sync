# FAQ

[← README](https://github.com/Dennis-Otto/paperless-sync) · [Guide](guide.md) · [Troubleshooting](troubleshooting.md)

The questions that come up when Paperless Sync meets a real archive, with short answers and links to the details. An error message or a run that fails is a case for [troubleshooting](troubleshooting.md).

## Paperless and your documents

### Does Paperless Sync ever change anything in Paperless?

No document that is already there. The app reads the documents, their files and checksums, the trash, the tags, correspondents, document types and storage paths, and it never edits, tags, moves or deletes a document of Paperless. Its only write is the upload of the files of the inbox folder, each as a new document, and it reads the task of Paperless that follows each upload. With the inbox off, the account of the app needs no permission beyond viewing.

### Do my changes in Nextcloud reach Paperless?

No. Paperless is the source of the archive, and the app sends nothing back from it: not an edit, not a rename, not a deletion. A two-way synchronization is [not planned](roadmap.md#not-planned). The only way into Paperless is the inbox folder, whose files become new documents.

### What does the Paperless account of the app need?

A dedicated account with an API token, which can view and download the documents to mirror; when it owns them as well, their trash reaches the deleted folder right away. The inbox needs the permission `documents.add_document` as well. Don't use a superuser: it would see the whole trash, but its token controls all of Paperless. The [guide](guide.md#paperless-connection) shows how to make the account the owner without taking away the access of the people who own them now, and the [security design](security.md) explains why a dedicated account.

## The archive in Nextcloud

### What happens when I edit a mirrored file?

The files of the archive are a mirror of Paperless, and they are read-only by intent. An edit stays while its document stays the same in Paperless. When the document changes there, a run may write the file anew from Paperless, depending on the change, and the [conflict policy](guide.md#conflict-policy) decides about your edit:

- **Replace synchronized file**, the default: the file of Paperless takes the place of your edit. A scheduled run puts your edited file, with its versions, into the trash bin of the target user; a run started with *Synchronize now* deletes it for good ([#119](https://github.com/Dennis-Otto/paperless-sync/issues/119)).
- **Skip and report conflict:** your edit stays, and every run reports a conflict for the document until you delete or move the file; meanwhile, the copy stays outdated.

A copy that you rename or move stays where you put it, as a file of your own, and the next run writes the document anew at its path; one that you delete comes back the same way. To keep a version of your own, copy the file out of the archive, and edit documents in Paperless.

### Where do deleted files go?

It depends on what deletes them:

| What happens | Where the copy goes |
| --- | --- |
| The document goes to the Paperless trash | Into the deleted folder, below the date of the trash, such as `_Gelöscht/2026-08-27/Example GmbH/Invoice/2026/…`, or it stays in the archive with the trash behavior *Keep archive file in place* |
| It is restored in Paperless | Back into the archive |
| It is deleted for good in Paperless | Deleted in Nextcloud after the configured number of complete scans, only when permanent deletion is on; otherwise it stays in the deleted folder |
| It gets an excluded tag, or the inbox tag again | Deleted in Nextcloud in the next run |
| You delete it in Nextcloud | Into your trash bin, and the next run writes it anew |
| An inbox file after its import | Deleted in Nextcloud, when *Remove source after successful import* is on |

Every copy that the app deletes in Nextcloud, and every folder that it empties, takes this way:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/where-a-copy-goes-that-paperless-sync-deletes.dark.svg">
  <img src="images/diagrams/where-a-copy-goes-that-paperless-sync-deletes.svg" alt="Where a copy goes that Paperless Sync deletes: A copy that Paperless Sync deletes in Nextcloud is gone for good when the app Deleted files of Nextcloud is off. Otherwise, a scheduled run moves it into the trash bin of the target user, who can restore it until the retention of the trash bin ends. A run started with Synchronize now deletes it for good, which issue 119 tracks.">
</picture>

Only the target user sees that trash bin, under *Deleted files* in *Files*. Nextcloud empties it after its retention, 30 days by default, or earlier when the quota of the user needs the space. The [deletion safety](guide.md#deletion-safety) describes when the app deletes at all.

### Can I change the path template later?

Yes. The next runs move every file to the path of the new template, within the batch size, without downloading it again, so a large archive shows the old and the new layout side by side for a while: 5,000 documents take 50 runs with a batch of 100. Start a dry-run first, which lists a move for every file. The app removes the folders that the moves leave empty when *Remove empty folders after moves and deletions* is on. A new archive or base folder moves the files of the archive as well, but not the copies in the deleted folder, and the old folders stay behind; remove them once the moves are done. Share the archive folder, which the template doesn't change, rather than the folders that it builds: a folder that a move empties disappears with its shares. A share of the base folder would include the inbox and error folders as well.

### Can several users see the archive?

Yes, through Nextcloud: the target user shares the folder of the archive with them, or with a group of them, as [decision 0002](decisions/0002-the-filesystem-api-of-nextcloud.md) intends. Share it read-only, since nothing there goes back to Paperless, and a run may replace an edited copy or write a deleted one anew; share the inbox folder only with those who may send documents to Paperless. An archive of its own for every user, with the documents that each one may see in Paperless, is the feature request [#84](https://github.com/Dennis-Otto/paperless-sync/issues/84); add your 👍 or your use there.

### Can I change the target user?

Yes, but the app remembers what it wrote for each target user. Another target user starts a new archive, which the runs write anew from Paperless, and the archive of the previous user stays as it is, no longer updated. To move an archive to another user, share it with them instead, or delete the old one once the new one is complete.

### Does the archive count against a quota?

Yes, the files belong to the target user, so the quota of that user applies. When it is full, the run stops writing at the first failure and reports `EXPORT STOPPED`; it goes on once there is room.

### Does it work with object storage such as S3?

Yes. The app writes through the file API of Nextcloud, which hides where the files are, so the archive and the inbox use the primary storage of Nextcloud, local or object storage such as S3, as any other file does. Version 0.1.10 fixed the upload of inbox files from object storage, and [#93](https://github.com/Dennis-Otto/paperless-sync/issues/93) adds S3 to the end-to-end tests.

## Setup and operation

### How long does the first synchronization take?

About the number of documents divided by the batch size, times the interval: with the defaults, a batch of 100 and 5 minutes, 5,000 documents take 50 runs, four hours or more. A larger batch, up to 1,000, makes it faster; then every run downloads more at once. Each run takes the documents that Paperless added last first, so new documents don't wait behind the backlog, and its report counts the documents that wait for the next run as skipped. Every later run changes only what changed in Paperless.

### How often does it run?

At the interval of the settings, 5 minutes by default, as a background job of Nextcloud. Nextcloud starts that job every five minutes at most, so a shorter interval acts as five minutes, and only when its system cron runs. A run that is still busy holds back the next one. *Synchronize now* starts a run at once, and a dry-run shows what a run would change without changing it.

### Can I connect several Paperless instances?

No, one Nextcloud server connects to one Paperless, with one target user. Whether more are worth it is a question for a [feature request](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=feature_request.yml).

### Does Paperless work behind a sub-path or a reverse proxy?

Yes. Enter the URL as you open Paperless in the browser, with its sub-path, such as `https://example.com/paperless`. The proxy must pass the requests to `/api/` with their token, without a login of its own, and accept uploads as large as the files of the inbox. [Troubleshooting](troubleshooting.md#behind-a-reverse-proxy) has the details.

### Does Paperless in Docker or in my home network work?

Yes, with the setting **Paperless runs in my local network**, which allows the address of Paperless for the requests of this app only, without turning off Nextcloud's protection against server-side request forgery for everything else. The [guide](guide.md#paperless-in-your-local-network) explains it.

## Other apps and removal

### How does it relate to Paperless Unified Search?

[Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) is a separate app, by the same author, that brings the full-text search of Paperless into the search of Nextcloud and opens a result in the file of the archive. It finds a document for a user who can open its file, through the marker `[P123]` in the name, so the shares of the archive decide who finds what. Paperless Sync works without it, and it writes the files with these markers that Paperless Unified Search looks for.

### How do I uninstall Paperless Sync?

1. Choose **Disconnect** in its settings, which deletes the settings and the API token.
2. Remove the app under *Apps*, or with `occ app:remove paperless_sync`.

Paperless stays as it is, and the archive stays in the files of the target user as ordinary files, which you can keep, move or delete. Nextcloud leaves the two tables of the app in its database, `paperless_sync_export` and `paperless_sync_import`, with the prefix of your installation, such as `oc_`: a later installation goes on from them. To remove them for good, an administrator of the database drops both tables and also deletes the records of the app, which tell Nextcloud that the tables exist: `DELETE FROM oc_migrations WHERE app = 'paperless_sync'` and `DELETE FROM oc_appconfig WHERE appid = 'paperless_sync'`, with your prefix. Dropping only the tables breaks a later installation, which then skips creating them.
