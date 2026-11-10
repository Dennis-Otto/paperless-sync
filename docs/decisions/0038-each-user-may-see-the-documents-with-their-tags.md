# Each user may see the documents with their tags, as the administrator sets them

- Status: accepted
- Date: 2026-11-10
- Amends [0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md) and [0033](0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md): for a user with tags, the tags decide which documents they get, not who owns or may view a document in Paperless

## Context

In a household that runs one Paperless, every document often has the same owner, or none, and tags say whom a document concerns, such as *Katja* for all of hers. When Katja gets a Nextcloud account of her own, the administrator wants her to find her documents there, and only those, while the administrator, the target user, keeps seeing every document ([#426](https://github.com/Dennis-Otto/paperless-sync/issues/426)). Dennis asked for it in his words: the administrator sets for each user which tags of Paperless they may see; without a setting, they may see everything; with tags, only the documents with at least one of them, everywhere, in Files and in Paperless Unified Search.

Neither ownership helps: with *The target user, all of them*, the others see what the target user shares with them, a whole folder or each file by hand, and with *Each user*, the owners in Paperless decide. Unified Search counts the files of the archive account that a user may only read and the files of the owner of a document ([its decision 0014](https://dennis-otto.github.io/paperless-unified-search/decisions/0014-count-the-copies-of-the-owners-and-check-them.html)), not a copy for someone else, since a user can give a file of their own the marker of any document.

## Options

1. **A read-only share of a folder of the target user for each such user, with copies of their documents.** No quota for the user, and Unified Search's rule for a file of the archive account shared with them would hold. But the target user would keep a second archive for each such user, with its own states beside the main archive of the same user, which the state of the app, by user and document, doesn't hold, and the app would create, change and delete shares in the name of the target user in a background job, which [0033](0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md) declined for its copies.
2. **An archive of their own for each such user,** as the copies of [0033](0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md): the planner already treats each archive by itself, with its own state, batch, report, Activity and trash bin, in every ownership. Each copy counts against the quota of its user, who can change it as any copy of the archive. Unified Search checks the tags of each result in Paperless against the same lines, so a renamed file gains nothing.
3. **Filter only, as a narrowing of what a user gets today.** A user would get nothing more: with *The target user, all of them*, Katja would still need a share of the whole archive, which no filter can narrow.

## Decision

Option 2. The setting **Tags that a user may see** in *1 · Connection and ownership* takes one line for each such user, `user = Tag, Tag`, in every choice of *Who gets the documents*:

- **No line, or a line without tags, means every document, as before.** The target user always gets every document: a save refuses a line for the target user, as it does a user twice and a user that Nextcloud doesn't have, whom someone could create later under that name. A save writes each line with the ID of the user as Nextcloud spells it and each tag once, whatever its case.
- **A user with tags gets every document with at least one of them, and no other,** whoever owns it or may view it in Paperless, whatever the case of the names. A document that their user of Paperless owns without one of their tags goes to the target user, as one whose owner has no user of Nextcloud, so that none is lost; one that they may only view goes to nobody in their place. The filter of the archive, the excluded tags, the tags to mirror and the inbox of Paperless, applies to their archive too.
- **Their archive lies below the same base folder of their own files,** with the same path template, and has no inbox, unless they get the documents of their user of Paperless too in per-user ownership: in single ownership, their files would go nowhere.
- **A copy follows the tags.** A document that gains one of their tags reaches them with the next run or the webhook, and one that loses the last of them leaves their archive into their trash bin, as for a document with an excluded tag. In single ownership, which reads the archive of the target user alone ([0033](0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md)), the runs also reach the archives that the tags filled, which the app keeps in the value `tag_archives`, until their copies are gone, so that a line that goes takes its copies along.
- **A tag that Paperless doesn't have stops the run before a copy moves,** as a tag to mirror does: after a typo or a rename, the run would take the copies of the documents that a user may see.
- **One place for the administrator.** Paperless Sync keeps the lines, as it decides who gets which documents, and Paperless Unified Search reads them as the value `user_tags` of the app `paperless_sync`, as it reads the target user and the table of the owners already: the lines, as a save writes them, are the contract of both apps. Without Paperless Sync, Unified Search has no lines and shows each user what it showed before; its own decision says how it checks a result.

## Consequences

An administrator can give a person exactly the documents with their tags, in Files and in Unified Search, without changing the owners or the permissions in Paperless, and keeps seeing every document. Every installation without a line stays as it was: an update changes no file and no state.

Each copy counts against the quota of its user, and a document with the tags of three users takes the space of four files with the target user's. A change to a copy stays in that copy, and nothing goes back to Paperless. A run with users with tags goes through the archive of each, as in per-user ownership, with a report, Activity and notifications for each user, also in single ownership.
