# A file of the inbox goes to Paperless once, whatever its path

- Status: accepted
- Date: 2026-11-12

## Context

The state of an upload of the inbox was keyed by the path of its file alone. A file renamed or moved inside the inbox, or one whose folder was renamed, lost its state, and the run uploaded it again at its new path: one whose upload was pending, and with *Remove source after successful import* off, every file that Paperless had imported. An upload that Paperless received although its answer never came, after the timeout of 180 seconds, went up again after the wait of a failed upload, too. Paperless-ngx 3.x imports such a second upload as a second document unless `PAPERLESS_CONSUMER_DELETE_DUPLICATES` is on, and 2.x refuses it as a duplicate, which sent the imported file to the error folder ([#450](https://github.com/Dennis-Otto/paperless-sync/issues/450)). *Send to Paperless* already asked Paperless for the content of a file before its upload.

## Options

1. **Ask Paperless for the checksum of the file before each upload,** as *Send to Paperless* does. It catches every file whose content Paperless has, whatever happened to it, but not one whose first upload Paperless is still importing, and it costs one read of the file and one request for each upload.
2. **Let the state follow its file by the file ID,** which Nextcloud keeps when a file is renamed or moved inside its storage and which the listing of the inbox has already. A pending upload goes on at the new path, but a state that an earlier version saved has no ID, a file from another storage gets a new one, and an upload without an answer has no task to follow.
3. **Both.**

## Decision

Option 3. The table `paperless_sync_import` keeps the file ID of each upload. A state whose path is gone moves to the file of the inbox with its ID that has no state of its own, so a pending upload, a kept file and a file that waits for its next attempt keep theirs; a file that Paperless refused gets a new chance with its new name, as before, since a name may be what Paperless refused. Before every upload, also the next attempt after a failed one, the run asks Paperless for a document with the checksum of the file, and when Paperless has one, the file ends as imported without an upload: it leaves the inbox, or stays as imported, as the settings say, and the document keeps its owner. In per-user ownership, the account of the app sees the documents of every user, so only a document of the inbox's user of Paperless counts, or one that the account owns, as after an upload whose answer never came, which then gets the inbox's user as its owner; a file with the content of another user's document goes up as before, so that it never leaves the inbox for a document that its user can't see. When Paperless can't answer, the file waits as after a failed upload, since an upload could make the duplicate that the question is there to prevent.

## Consequences

A file of the inbox may be renamed or sorted into a folder for its tags while it waits, and it goes to Paperless once. Each upload reads its file once more and asks Paperless once more; uploads are the rare part of a run, and the upload reads the file anyway. A file that comes from another storage while Paperless is still importing its first upload can still go up twice, as its state has no ID that matches it and Paperless has no document with its checksum yet.
