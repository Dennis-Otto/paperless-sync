# In per-user ownership, a document of the account counts only as the user's own upload

- Status: accepted
- Date: 2026-11-23
- Amends [0039](0039-a-file-of-the-inbox-goes-to-paperless-once.md): in per-user ownership, a document of the account counts only as the user's own upload whose answer never came
- Amends [0564](0564-a-document-found-by-the-md5-alone-is-confirmed-by-its-original.md): in per-user ownership, the unfinished upload, *Send to Paperless* and a rule of Flow count only a document of the user, or their own lost upload

## Context

Before an upload, the app asks Paperless whether it has a document with the content of the file: a file of the inbox ([0039](0039-a-file-of-the-inbox-goes-to-paperless-once.md)), an upload that Paperless has not finished a day later, and a file of *Send to Paperless* or of a rule of Flow ([0564](0564-a-document-found-by-the-md5-alone-is-confirmed-by-its-original.md)). In per-user ownership, the account of the app sees the documents of every user, so the answer can be a document of any owner. Decision 0039 counted a document of the inbox's user of Paperless and one that the account owns, as after an upload whose answer never came, and gave that one the inbox's user as its owner; the unfinished upload and *Send to Paperless* counted every document with the content.

A document of the account is not always the upload of the file, though. Per-user ownership gives every document of the account to the target user, and the app makes such documents itself: every upload of the inbox of a target user without a user of Paperless, every file of a sender without one, and every document of an installation that mirrored in single ownership first, as `docs/security.md` advises the account as their owner. A rule that counts a document only when it is the user's has to tell the upload of the file from the other documents of the account.

## Options

1. **Count no document of the account.** A file whose upload lost its answer goes up again: Paperless-ngx 3.x imports it as a second document, and 2.x refuses it as a duplicate, which sends the imported file to the error folder, what 0039 fixed.
2. **Count a document of the account only with the proof of an upload of the file.** The state of an import and of a send keeps the time of the first upload that may have reached Paperless, and a document of the account counts when Paperless added it at or after that time. It costs a column in each table and no request: the owner and the time of `added` are in the answer that is read for the owner already.
3. **Option 2, and compare the original name of the document with the name of the file.** It tells two documents of the same content apart, but a file may be renamed or sorted into a folder while it waits (0039), and Paperless may store another name, so it would refuse uploads of the file itself.

## Decision

Option 2, for the inbox, the unfinished upload and *Send to Paperless* alike, in one place, `KnownContent`. In per-user ownership a document counts as the file's in two cases: it is the user's by its owner, as `Owners::userOf()` gives its owner to that user, which for the target user includes the documents without an owner, those of the account and those of owners without a user of Nextcloud, and its owner stays; or it is provably the user's own upload whose answer never came, a document of the account that Paperless added at or after the first upload of the file, which then gets the user's user of Paperless as its owner, if they have one, and not in a dry-run. While an upload of another user waits for its owner, a document of the account may be that upload's, so it counts for nobody, and the file waits, neither uploaded nor deleted, and since nothing failed, the wait uses up none of the attempts of a sent file; the runs read the uploads that wait before the inboxes, and an upload of an inbox holds them for the inboxes after it in the same run, as one of *Send to Paperless* does for the rest of its round. Any other document keeps its owner and costs no download, and the file goes up. Single ownership reads no owner, as before.

The first upload that may have reached Paperless is the one that the app sent, also when it failed for a reason that may pass; neither a failure before the upload nor a refusal of Paperless counts. The time stays across the attempts and with the file ID when the state follows its file (0039), and a file that changed since loses it. A state that an earlier version saved has none (`first_attempt_at` 0): Dennis decided on 2026-11-23 that a file that waits for its next upload then counts as having no proof, so it goes up again, rather than a migration that guesses a time. An upload that waits for its task has the time of that upload, which reached Paperless, and the unfinished upload takes it as its first.

Paperless-ngx 2.x names the title of the document that a duplicate has the content of. In per-user ownership that document may be another user's, so the reason that the app passes on names it by its ID alone, in the words of Paperless-ngx 3.x: *It is a duplicate of document #12*.

## Consequences

A file in per-user ownership ends on a document only when that document is its user's; the owner of another document never changes, and a document of the target user never gets another user as its owner. A file with the content of another user's document goes up: on Paperless-ngx 3.x as a document of its own, on 2.x into the error folder or into the notification of the sender, with the reason by the ID of the document. A file that waited for its next upload during the update goes up again once, and may become a duplicate in the same way.

A known rest, which Dennis accepted on 2026-11-23: when two users upload files of the same content, and neither upload gets its answer, both documents belong to the account and were added after both first uploads, so the next attempt of each file may take the document of the other for its own. Comparing names (option 3) would narrow it at the cost of the renames that 0039 allows. The proof also rests on the clocks of Nextcloud and Paperless: a Paperless whose clock runs behind can show a document as added before the upload that created it, and the file then goes up again as in option 1.
