# The tags of Paperless become tags of Nextcloud that the app alone changes

- Status: accepted
- Date: 2026-10-18

## Context

A run reads the tags of Paperless for the excluded tags and the tags to mirror, but the copies showed none of them in Nextcloud: Files could neither filter nor search the archive by a tag, and none of the other ways to connect Paperless and Nextcloud shows them. Nextcloud has collaborative tags (`ISystemTagManager`, `ISystemTagObjectMapper`), which Files filters by, its search finds, and Flow and the retention app use. They belong to the whole instance: Nextcloud keeps one tag of a name, whatever its case, users make and assign their own, and a tag that is visible but not assignable can be assigned by administrators alone. A tag belongs to the ID of a file, which the copies keep through moves and new content ([decision 0010](0010-mirrored-files-keep-their-identity.md)).

## Options

1. Take the tag of Nextcloud of each name, whoever made it, and assign and remove it on the copies. A run would remove a tag that a user put on a copy, and a tag of a user would follow Paperless from then on.
2. Tags of the app, which it makes for the names of Paperless and alone assigns, removes and deletes, visible to every user and assignable by administrators alone, with their IDs in the config of the app. A name whose tag someone else made already has no tag of the app.
3. Personal tags of the target user (`ITagManager`), which Files of the other users doesn't show, also not through a share.
4. A column with the tags of each copy in the table of the state of the exports, which a migration adds, so that a run knows which tags it assigned to which copy.

## Decision

Option 2, behind the setting *Show Paperless tags as Nextcloud tags*, which is off by default. The config of the app keeps the ID of each of its tags with the name of the tag of Paperless that it stands for, so a run finds its tags also after an administrator renamed one, and forgets one that an administrator deleted. A run compares the tags of the app on every copy that it exports, moves or finds unchanged with the tags of its document, by name and whatever the case, adds those that the copy lacks and removes those that its document lost; it never assigns, removes, renames or deletes a tag that someone else made, and the report names a name whose tag someone else made, so that an administrator renames one of them. A run of the whole archive deletes a tag of the app whose tag of Paperless is gone, once no file has it, not even a copy in the deleted folder, so a tag renamed in Paperless leaves no empty tag behind. A run changes the tags of at most as many copies as its batch size, and a dry-run reports the same changes. Nextcloud lets only users and the command line create tags: a run without either, such as a call of the webhook or background jobs by AJAX or Webcron, assigns the tags that exist and says which wait for the next run by cron.

## Consequences

Files filters and searches the archive by the tags of Paperless, for every user who can open the copies, and the tags follow Paperless without a download, also of a document whose copy stays as it is. The tags of the users stay theirs, on the copies too. A tag of a user with the name of a tag of Paperless keeps the copies from getting that tag until one of them is renamed. The Activity stream shows each tag that a run adds or removes, as Nextcloud shows every change of a tag, so the first run in a large archive adds many entries, within its batch size. It needs no migration of the database; the cost is a question for the tags of all copies and one for the file ID of each copy in every run. Turning the setting off leaves the tags of the copies as they are, until an administrator deletes the tags of the app.
