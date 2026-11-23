# A document that the MD5 alone found counts only with the SHA-256 of its original

- Status: accepted
- Date: 2026-11-23
- Amended by [0568](0568-a-document-of-the-account-counts-only-as-the-users-own-upload.md): in per-user ownership, a document of the account counts only as the user's own lost upload, proven by the time of its first upload

## Context

Before an upload, the app asks Paperless whether it has a document with the content of the file: a file of the inbox ([0039](0039-a-file-of-the-inbox-goes-to-paperless-once.md)), an upload that Paperless has not finished a day later, and a file of *Send to Paperless* or of a rule of Flow. It asks by the SHA-256 of the file first, then by its MD5, since Paperless-ngx keeps the SHA-256 of an original since 3.0 and only its MD5 before ([0009](0009-checksums-of-paperless-2-and-3.md)), and it took the first document that either answer named as the file's own. On Paperless-ngx 2.x, the SHA-256 never finds anything, so the MD5 decided alone, and so it does on 3.x for a document that still carries an MD5, such as one whose original was missing at the migration of its checksums, or one that came in from an export of 2.x. The MD5 alone doesn't tell every two files apart, and a document that it found counted without a look at its content: the file of the inbox left the inbox as imported, an account's document got the inbox's user as its owner, and a sent file ended as one that Paperless has.

## Options

1. **Keep the search as it is.** No download, but a file can end as imported as a document of other content, and leave the inbox.
2. **Treat every document that the MD5 alone found as unknown,** and send the file to the error folder with a note, without a download. It costs nothing, but every file that Paperless-ngx 2.x already has, such as after an upload whose answer never came, would end in the error folder, which is what 0039 fixed.
3. **Confirm a document that the MD5 alone found by the SHA-256 of its original,** with one download of the original, which the app reads in parts of 1 MB into a temporary file and hashes in the same pass.

## Decision

Option 3. `PaperlessClientInterface::findDocument()` says which checksum found the document, and `KnownContent`, which the inbox, the end of an unfinished upload and *Send to Paperless* use, accepts a document that the SHA-256 found as before and one that the MD5 alone found only when the SHA-256 of its original is that of the file. The original decides, not the archived version that Paperless made of it, since the original has the bytes that went up. In per-user ownership, the inbox reads the owner of the document first, so that a document of another user costs no download, and gives a document of the account its new owner only after the confirmation. A file whose document has other content counts as one that Paperless doesn't have: the inbox uploads it, and the refusal of Paperless-ngx 2.x moves it to the error folder with its reason; a sent file is uploaded and ends with the answer of Paperless; an unfinished upload goes on to its other ends. When Paperless answers the download of the original with 404, as for a document whose original was missing at the migration of its checksums, the original confirms nothing, and the file goes on as one whose document has other content. When the original can't be downloaded for another reason, the file waits as when Paperless can't answer the search, and nothing is uploaded or deleted.

## Consequences

A file counts as imported, or as already in Paperless, only when Paperless has its content. It costs one download of an original, only when the SHA-256 found nothing and the MD5 found a document: on 2.x when Paperless already has the file, as after an upload whose answer never came, a file that came back to the inbox or a sent file that Paperless has, and on 3.x only for a document that still carries an MD5. A large document costs its transfer time, but no memory, and a dry-run makes the same download, since it only reads. A download that keeps failing makes the file wait again in each run. The export side stays as it is: it compares the SHA-256 wherever it decides which file is the app's own copy.
