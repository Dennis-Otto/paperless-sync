# Mirrored files keep their identity: moved, and updated in place

- Status: accepted
- Date: 2026-10-13

## Context

Nextcloud ties shares and public links, comments, tags, favorites and the versions of a file to its file ID, not to its path. Other users see the archive through shares ([decision 0002](0002-the-filesystem-api-of-nextcloud.md)), so the copy of a document has to stay the same file for as long as the document exists. A change of the metadata already moves the file and keeps its ID ([decision 0009](0009-checksums-of-paperless-2-and-3.md)). A change of the content did not: the app wrote the new content to a temporary `.part` file next to the copy, deleted the copy and moved the temporary file into its place. That gave the copy a new file ID, so its shares and public links broke, its comments, tags and favorites were lost, the previous content went to the trash bin of the target user instead of its versions, and the desktop and mobile clients downloaded it as a new file. A document that Paperless restored from its trash while the keep policy had left its copy in place was downloaded and written again in the same way, and with the conflict policy *Skip and report conflict*, the app reported its own copy as a conflict in every run and never updated it (#98).

## Options

1. Keep the temporary file and the move. Readers never see a partial file, but every change of the content gives the file a new ID.
2. Move the temporary file onto the copy without deleting it first. Nextcloud treats such a rename as a write only for the uploads of its own WebDAV server, and the cache of other storages may keep the old size and ETag, so this depends on internals of Nextcloud.
3. Write an existing copy in place with `File::putContent()`, the public API for changing the content of a file. Nextcloud keeps the file ID, holds an exclusive lock on the file while it writes, sends the events of a write, on which the versions app keeps the previous content, and applies the quota. A new file is still written to a temporary file first and moved into place.

## Decision

Option 3. A copy whose path and content change moves first, which keeps its ID, and then gets its new content in place. The state records the new path right after the move, so that a download that fails then leaves the file known at its new path. A copy at the path of a document with the content of the document stays as it is, whatever the state of the document, so a restored document under the keep policy downloads nothing. With *Skip and report conflict*, the copy that the app wrote is not in the way: the state records its path, and the file still has the content of the last download. A file of a user, and a copy that someone changed in Nextcloud, stay untouched and are reported.

## Consequences

Shares, public links, comments, tags, favorites and versions of a mirrored file survive every change of its document, and a change of the content no longer fills the trash bin. The versions app of Nextcloud looks for a written file among the files of the user who is logged in, so it keeps the previous content as a version in a scheduled run, which runs without a user, and in a run by hand only when the administrator who starts it is the target user; once *Synchronize now* starts the background job instead, every run keeps it. On local storage, Nextcloud writes the file in place: a write that fails halfway, such as on a disk that runs full, leaves the file incomplete until the next run writes it again; the document is already complete in a temporary file of the server at that time, so the window is as short as a local copy. With *Skip and report conflict*, a change of the content reads the copy once to recognize it. The end-to-end tests check the file ID through every change, a share of the file after a new content, and the version that the background job leaves.
