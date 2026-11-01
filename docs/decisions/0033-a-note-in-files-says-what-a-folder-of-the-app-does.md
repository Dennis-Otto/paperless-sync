# A note in Files says what a folder of the app does, from one answer for each user

- Status: accepted
- Date: 2026-11-01

## Context

The FAQ explains that the copies of the archive are read-only by intent, but in Files they are ordinary writable files: an edit gives way to the content of Paperless with the next change of the document, a renamed or moved copy becomes a file of the user's own and the document is written anew, and a deleted copy comes back. People who reach the archive through a share or through per-user ownership never read the FAQ. The inbox looks like any folder, although its files leave at the next run, and the failed imports hold `.error.txt` files without a word about them.

Files of Nextcloud 33 to 35 has a place for this: the headers of its list of files, in the same registry of `@nextcloud/files` 4 that its file actions use ([0023](0023-file-actions-in-files-without-a-build.md)). They render above the list, also in an empty folder, which an empty inbox usually is.

The script must learn which folders are the app's for the user who looks. That depends on the settings and on the shares of that user, and it must tell nobody about the folders of another.

## Options

1. A setting in the initial state of Files: no request of its own, but a lookup of folders in the filesystems of two users on every page of Files, before the page draws, for everyone, also for the users whom no folder of the app concerns.
2. A route for the folder that Files shows, asked with each change of folder: it also knows a share of a folder below the archive, but makes one request for each folder that anyone opens in Files, and most of those have nothing to do with the app.
3. A route that answers once which folders are the app's for this user, and a script that compares every folder with that answer: one request as Files opens, and none for each folder, at the price of a share of a single folder below the archive, which the answer doesn't name.

And for hiding the note: for each browser, or as a setting of the user. A share of a folder below the archive could also be found through the mounts of the user, with one lookup for each share on each page of Files.

## Decision

Option 3. `GET /api/v1/folder-info`, with `#[NoAdminRequired]`, answers for the user of the session alone: the archive, the inbox and the error folder among their own files when they are the target user or in per-user ownership, and those of the target user that they find through a share, of the folder or of one above it, by file ID, at the path of that share. A user who finds none gets none of the times of the runs either. It leaves out an archive that no run writes and an inbox that no run reads, and any failure of a lookup: a note is a hint, and Files goes on without it.

`js/files.js` registers the note in `fileListHeaders` of `@nextcloud/files` 4, as `registerFileListHeader()` does, and asks again after 5 minutes when Files changes its folder, as a page of Files stays open for hours. It draws the note of the innermost folder that holds the folder of Files, so also below the archive, and compares only among the files of the user, not in the trash bin. It says what it says in the language of the user, with the times in the words of the browser for that language, as they stand in its sentences, whatever locale the user chose. It measures them from the moment of the answer, so that they change only with a new answer, and drawing a folder again draws the same note.

Each kind of the note can be hidden for each user, in a lazy user setting that follows them to other browsers and that no other page loads. What is left of a hidden note is the question *What is this folder?*, which brings it back, at the same place, so the note needs no entry in a menu.

## Consequences

Whoever opens a folder of the app learns what it does there, in the words of the settings page, also on a phone. A user whom no folder of the app concerns pays one small request as Files opens, and sees nothing.

A share of a single folder below the archive shows no note. The documentation already advises against such a share, on the page *Using the archive*: a folder that the path template builds disappears with its share when its copies move. The share of a folder of another user in per-user ownership, whom the app can't name without a lookup for each user, shows none either; the archive of the target user is the one that a household shares.

The script reads no private API beyond the registry that its actions already use, so a Files on `@nextcloud/files` 5 fails the end-to-end tests for both together, as [0023](0023-file-actions-in-files-without-a-build.md) expects. Once a user hid a note, a change of what it says stays hidden from them until they ask.
