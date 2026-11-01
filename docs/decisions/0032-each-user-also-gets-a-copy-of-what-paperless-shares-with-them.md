# Each user also gets a copy of the documents that Paperless shares with them

- Status: accepted
- Date: 2026-11-01
- Amends [0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md): besides the owner, a new option gives a document to every mapped user who may view it in Paperless

## Context

Per-user ownership gives each user of Nextcloud the documents that their user of Paperless owns ([0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md)). Paperless also has permissions of its own: the owner of a document can let other users and groups view it, which its interface calls *Shared with me*. In a household, bills, insurance and tax papers typically belong to one person and are shared with the other, who finds them in Paperless but not in Nextcloud, unless the owner shares each file or folder by hand. The request in [#84](https://github.com/Dennis-Otto/paperless-sync/issues/84) assumes that the view in Paperless decides.

A document must still never land in the folder of a user who isn't meant to see it, and a run that can't tell must not take a copy from someone who may keep it.

## Options

1. **Nextcloud shares of the copy of the owner.** One file on disk, and no quota for the others. A household with hundreds of shared documents gets hundreds of single-file shares, which flood *Shared with you* and say nothing about the folders of the path template. A share hangs on the copy of the owner, so it breaks when that copy moves away, such as when the owner of the document changes, and the app would have to create and clean up shares in the name of a user in a background job, as the only place in which it would write outside the files of a user.
2. **A copy in the archive of each user who may view the document.** The files of every user look the same, at the path of the template, with shares, versions and search of their own. Each copy takes the space of a file, and the planner already treats each archive by itself, with its own state, batch, report and Activity entries.

## Decision

Option 2, as a third answer to *Who gets the documents*: **Each user, those they own or may view in Paperless** (`per-user-shared`). The two others stay as they are, and only this one asks Paperless for the permissions of the documents, with `full_perms=true` beside the list of the fields, without which Paperless leaves them out; it costs Paperless more work for each document, so nobody pays for it who doesn't use it.

- A document goes to the archive of its owner, or of the target user, by the rules of [0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md), and to that of every user of Nextcloud whose user of Paperless may view it, by itself or through a group of Paperless, at the same path. The table and the group of the settings decide who that is, as for an owner.
- `/api/groups/` and the field `groups` of `/api/users/` name the members of a group. The account of the app needs **View** for Groups, which the connection checklist checks.
- Paperless gives whoever may change a document the permission to view it too, so the list of view names everyone, in 2.x and 3.x alike; the contract tests hold both to that.
- **A user of Paperless without a user of Nextcloud gets no copy, and the target user gets none in their place.** For an owner, the target user takes over so that no document is lost; a copy for someone who may view it is one more copy, and the target user may not be allowed to see the document.
- **A document without an owner goes to the target user and to whom its permissions name, not to everyone.** Paperless shows it to every user, which says nothing about whether every user wants it in their files, and a copy for each user of a large installation would be a surprise that fills their quota.
- A removed permission removes that copy in the next run, into the trash bin of that user, as for a document with an excluded tag. So does turning the option off.
- **A run that can't tell takes nothing away.** An answer that doesn't say who may view a document, or that names a group that Paperless didn't list, gives the document to its owner, leaves every copy where it is, and counts as an error of the run. A document whose owner the run can't tell stays where its copies are, whoever may view it. Without the list of the groups a run fails before a copy changes: the copies of the members of a group would look as if the document was no longer shared.

The run of one document, which the webhook asks for, reads the permissions of that document in the same way and reaches the archives that get it or have a copy of it. The dry run and the report name the user of each change, and the connection checklist says who also gets how many documents from the archive of whom, which reads every document once.

## Consequences

Each person of a household finds the documents shared with them in their own files, with what Paperless already says about who may see them, and nobody has to share anything by hand a second time. A change in Paperless reaches Nextcloud with the next run or the webhook, and until then the old copy stays.

Every copy counts against the quota of its user, and a document shared with four people takes the space of four files. A change to a copy stays in that copy, as for any other copy of the archive: nothing goes back to Paperless, and a run treats it by the conflict policy.

The token of the app could read all of this before, so nothing about its reach changes, but the app now passes the permissions of Paperless on to Nextcloud: a mistake in the table or in the permissions gives a copy to whoever they name, which [the security design](../security.md#per-user-ownership) spells out. The upgrade changes no file and no state, as nobody has the new option until an administrator chooses it.
