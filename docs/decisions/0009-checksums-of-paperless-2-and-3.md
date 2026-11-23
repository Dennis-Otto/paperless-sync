# Keep the SHA-256 and the MD5 of every download, for Paperless-ngx 2 and 3

- Status: accepted
- Date: 2026-10-13
- Amended by [0564](0564-a-document-found-by-the-md5-alone-is-confirmed-by-its-original.md): before an upload, a document that the MD5 alone found counts as the file's only with the SHA-256 of its original

## Context

A run moves the file of a document whose metadata changed, and downloads it again only when its content changed. A change of the title, the tags, the correspondent or the type gives the document a new `modified` time in Paperless, so the run can't tell the two apart by the time alone: it asks Paperless for the checksum of the file and compares it with the one it saved at the download. Paperless-ngx computes these checksums with MD5 up to 2.20 and with SHA-256 since 3.0.0 (paperless-ngx#12432). The app saved the SHA-256 alone, so on Paperless-ngx 2 no checksum ever matched: every change of the metadata downloaded the document again and replaced its file, which got a new file ID in Nextcloud and lost its shares, comments and favorites, left the old copy in the trash bin and made every desktop and mobile client download it again. The end-to-end tests and the unit tests could not see it, because their Paperless answered with SHA-256.

The checksum also came from a string of the whole document, so a document larger than the free `memory_limit` of PHP ended the run with a fatal error before it saved its state, and every following run died on the same document.

## Options

1. Require Paperless-ngx 3. Nothing to build, but most installations still run 2.x, and the app would break for them without a word.
2. Ask Paperless which version it runs and compute the matching checksum. A request more per run, and a guess about versions that a backport or a fork can make wrong.
3. Compute both checksums at every download and keep both, as `"<sha256> <md5>"` in the existing column `fingerprint` (97 of its 128 characters). A checksum of Paperless matches when it is one of the two. A SHA-256 that an earlier version saved still matches on Paperless-ngx 3. On 2, the next change computes both checksums of the mirrored file once: when its SHA-256 is the saved one, the file still has the content of that download, so its MD5 completes the saved checksums, and the change moves the file without a download.

## Decision

Option 3. The Paperless client downloads to a temporary file as before, computes both checksums in one pass of 1 MB reads over it, and hands the file itself to the write to Nextcloud instead of a copy, so that no document is in memory as a whole, whatever its size. A file that an earlier version exported gains its MD5 from a read of the mirrored file, which costs one read of a local file instead of a download, and only when Paperless reports an MD5 for a new revision. The column keeps its size, so no migration is needed, and no request asks Paperless for its version.

## Consequences

Paperless Sync supports Paperless-ngx from 2.10, the first version with a trash, through 3.x, and a change of the metadata moves the file on both. MD5 serves only to recognize a file that Paperless already identifies with it. For the export, the MD5 that Paperless reports for a revision tells only whether the content of its document has changed. Before an upload, a match decides more: whether the file counts as one that Paperless has, which then never goes up and may leave the inbox. A file of the inbox, of *Send to Paperless* or of a rule of Flow comes from people who need no access to Paperless, and whoever makes two different files can give them the same MD5 with little effort. So a document that the MD5 alone found counts as the file's only when one download of its original has the SHA-256 of the file ([0564](0564-a-document-found-by-the-md5-alone-is-confirmed-by-its-original.md)); otherwise the file goes up, and Paperless decides. The test double of Paperless and the mock of the end-to-end tests answer with MD5 in a mode of their own, so that both kinds of checksum stay tested. When Paperless-ngx 2 is no longer supported, a later change may drop the MD5 again.
