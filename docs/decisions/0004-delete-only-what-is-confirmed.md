# Delete only what Paperless has confirmed gone, and only what fits into a run

- Status: accepted
- Date: 2026-10-08
- Updated: 2026-10-13, with what makes a scan complete and what takes a place in the batch; 2026-10-19, with the question to Paperless before a missing scan counts ([#97](https://github.com/Dennis-Otto/paperless-sync/issues/97))

## Context

A sync that mirrors deletions can destroy an archive when the source answers wrongly once: a failing request, an empty page, a wrong token. Paperless lists its documents page by page, so a page that comes back short or empty, or a document added or deleted while the run reads the pages, leaves documents out of a scan without any error. And a run that cannot write, because the quota is full or the storage read-only, would download every pending document again in every run.

## Options

1. Mirror every deletion at once.
2. Move copies to a deleted folder first, delete for good only when allowed and confirmed by several complete scans, and limit every run to a batch of changes.

## Decision

Option 2. A document in the trash of Paperless moves its copy to the `_Gelöscht` folder, or keeps it in place, as configured. A document that is gone without the trash waits for the configured number of complete scans in a row. Permanent deletion is off by default. Only a copy that is still there is moved or deleted, and every move and deletion counts against the batch size; an error concerns only its own document.

A scan is complete only when every page carries the same count of Paperless, every page but the last is full, and the run received as many documents with different IDs as Paperless listed. The run reads the documents in the order of their IDs, so that a new document joins the last page instead of shifting the others. When the scan of the documents or of the trash is incomplete, the run counts no document as missing: no missing scan, no move, no deletion. Its report says `SCAN INCOMPLETE`, and the Nextcloud log has a warning. A document that a scan lists again starts its count of missing scans anew.

A complete scan still doesn't prove that a document is gone: one document deleted and another one added while the run reads the pages keep the count of Paperless, and the pages can shift so that a document that still exists is left out. So before a scan counts a document as missing, the run asks Paperless for it alone, with `GET /api/documents/<id>/`. Only `404` counts the missing scan. A document that Paperless returns starts its count anew, as if the scan had listed it, and keeps its copy. Any other answer counts nothing for the document in this run and is an error of the document in the report. A scan that would change nothing asks nothing: a document whose move or deletion doesn't fit into the batch, and one that a run has marked missing already, whose copy has moved or stays, until a deletion would remove its copy or forget it. So the questions of a run grow with the documents that went missing lately, not with the history of the archive.

The lists of tags, correspondents, document types and storage paths are checked the same way, and an incomplete one fails the run before it changes anything, because without every tag a document with an excluded tag would be exported.

Every change that a run tries takes its place in the batch, also one that fails, so that a run that cannot write downloads no more documents than the batch size allows. A failed export takes its place only among the exports, so that documents whose export keeps failing cannot hold up the moves of trashed and missing documents. The most recently added documents come first, and a document whose last change failed waits behind the others, the one that failed longest ago first, so that documents that keep failing cannot hold up the rest either. When conflicts are skipped, a file in the way is reported before the download. Nextcloud applies no quota to the temporary file of a write, so the app checks the free space before every write. A full quota, an archive folder that is no longer writable, or an unavailable storage ends the exports and the moves of the run at its first failure; a path that Nextcloud refuses on its own concerns only its document.

## Consequences

A wrong answer of Paperless can't empty the archive in one run, and a deleted document can be restored from the deleted folder. Mirroring a large deletion takes several runs, and only runs whose scans are complete count towards it. A document that a shifted page left out keeps its copy, because Paperless answers for it alone, also with one required missing scan; the scans in a row, three by default, remain as a margin against a Paperless that answers `404` for a document it still has, such as after a glitch of its permissions. Each document that a run would count as missing costs one more request to Paperless, and a Paperless that fails these requests holds its copies back, with an error in the report of every run, until it answers again. Paperless answers `404` for a document in its trash as well, so the question can't tell whether a document left out of the scan of the trash is still there; such a document is deleted only when permanent deletion is on, after the scans in a row.
