# Delete only what Paperless has confirmed gone, and only what fits into a run

- Status: accepted
- Date: 2026-10-08
- Updated: 2026-10-13, with what makes a scan complete and what takes a place in the batch

## Context

A sync that mirrors deletions can destroy an archive when the source answers wrongly once: a failing request, an empty page, a wrong token. Paperless lists its documents page by page, so a page that comes back short or empty, or a document added or deleted while the run reads the pages, leaves documents out of a scan without any error. And a run that cannot write, because the quota is full or the storage read-only, would download every pending document again in every run.

## Options

1. Mirror every deletion at once.
2. Move copies to a deleted folder first, delete for good only when allowed and confirmed by several complete scans, and limit every run to a batch of changes.

## Decision

Option 2. A document in the trash of Paperless moves its copy to the `_Gelöscht` folder, or keeps it in place, as configured. A document that is gone without the trash waits for the configured number of complete scans in a row. Permanent deletion is off by default. Only a copy that is still there is moved or deleted, and every move and deletion counts against the batch size; an error concerns only its own document.

A scan is complete only when every page carries the same count of Paperless, every page but the last is full, and the run received as many documents with different IDs as Paperless listed. The run reads the documents in the order of their IDs, so that a new document joins the last page instead of shifting the others. When the scan of the documents or of the trash is incomplete, the run counts no document as missing: no missing scan, no move, no deletion. Its report says `SCAN INCOMPLETE`, and the Nextcloud log has a warning. A document that a scan lists again starts its count of missing scans anew. The lists of tags, correspondents, document types and storage paths are checked the same way, and an incomplete one fails the run before it changes anything, because without every tag a document with an excluded tag would be exported.

Every change that a run tries takes its place in the batch, also one that fails, so that a run that cannot write downloads no more documents than the batch size allows. A failed export takes its place only among the exports, so that documents whose export keeps failing cannot hold up the moves of trashed and missing documents. The most recently added documents come first, and a document whose last change failed waits behind the others, the one that failed longest ago first, so that documents that keep failing cannot hold up the rest either. When conflicts are skipped, a file in the way is reported before the download. Nextcloud applies no quota to the temporary file of a write, so the app checks the free space before every write. A full quota, an archive folder that is no longer writable, or an unavailable storage ends the exports and the moves of the run at its first failure; a path that Nextcloud refuses on its own concerns only its document.

## Consequences

A wrong answer of Paperless can't empty the archive in one run, and a deleted document can be restored from the deleted folder. Mirroring a large deletion takes several runs, and only runs whose scans are complete count towards it. One document deleted and another added while the same scan reads the pages keep the count of Paperless, so that scan may still miss a document; more than one complete scan in a row, three by default, covers this rare case. Asking Paperless for each missing document directly would close it ([#97](https://github.com/Dennis-Otto/paperless-sync/issues/97)).
