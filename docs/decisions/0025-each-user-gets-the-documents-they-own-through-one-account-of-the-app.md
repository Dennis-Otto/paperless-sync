# Each user gets the documents that they own in Paperless, through one account of the app

- Status: accepted
- Date: 2026-10-19
- Amends [0002](0002-the-filesystem-api-of-nextcloud.md): besides the one target user who owns the archive, per-user ownership gives each user of Nextcloud the documents of their user of Paperless

## Context

Until now, one target user owned the whole archive, and the others saw it through shares ([0002](0002-the-filesystem-api-of-nextcloud.md)). A household or a team with one Paperless-ngx in which every document has an owner wants each person to find their own documents in their own files, and to send their own files to Paperless as theirs ([#84](https://github.com/Dennis-Otto/paperless-sync/issues/84)). Many such setups sign their users in to both through single sign-on, such as OpenID Connect, so a user has the same ID in Paperless and in Nextcloud.

A document must never land in the folder of a user who isn't meant to see it, and the security design of the app rests on one token of one account of Paperless, which only administrators set and which Nextcloud's credentials manager keeps ([security](../security.md)).

## Options

1. Each user of Nextcloud connects their own Paperless, with their own URL and token, in their personal settings. Every user could then point the server of Nextcloud at any address with a token of their own: a request forgery that the app would have to guard per user, tokens of every user to keep, and runs, checks and troubleshooting per user.
2. The administrator connects one Paperless with the token of one account that sees the documents of every user, as today, and a new setting routes each document to the user of Nextcloud whose user of Paperless owns it: by the same ID, whatever its case, or by a table of the administrator, with the target user for the rest.

## Decision

Option 2, as the issue proposed it second and its comments asked for. *Who gets the documents* in *1 · Connection and ownership* chooses between:

- **The target user, all of them** (`single`), the default, which keeps the archive of every installation as it was: an update changes no file and no state.
- **Each user, those they own in Paperless** (`per-user`). A user of Paperless gets the user of Nextcloud that the table *Paperless users with another Nextcloud ID* names, or else the one with its ID, whatever the case; with *Only members of this group*, only a member of it. The target user gets the documents without an owner, those whose owner has no user of Nextcloud and those of a user of Paperless whose user of Nextcloud is outside the group, as every document went to the target user before, so that none is lost.

Nothing is guessed: two users of Paperless with one user of Nextcloud, such as `Alice` and `alice`, get neither, and the connection checklist names them and every other case, with the number of documents of each user of Paperless. A document whose owner Paperless didn't list, or whose user's archive the run can't reach, stays where its copy is.

A run reads Paperless once, and then goes through the archive of each user, below the same base folder of their own files, with their own states, which the database keeps by user and document already, their own batch, their own entries in the Activity stream and their own notifications; the report of the run takes in the report of each user, with the user at each line and the counts of each user. A document whose user changes, because its owner changed in Paperless or the settings changed, is exported into the archive of its new user, and its copy leaves the archive of the old one as that of a document with an excluded tag does, into the trash bin of Nextcloud; so does every copy of a user who gets no documents now, such as one who left the group.

The inbox of each user sends their files with the token of the app, and then makes the user of Paperless of that user the owner of the document that the task of the upload names, with `PATCH /api/documents/<id>/`, which Paperless-ngx 2.x and 3.x both take, and so does *Send to Paperless* for the document of its sender. A document that Paperless had already, which a search by the content of a file found, keeps its owner: it may be someone else's. While Paperless refuses the owner, the upload stays pending, and the next run asks again.

## Consequences

Each user finds their documents in their own files, with the shares, versions and search of Nextcloud, and the files of their inbox come back into their own archive. The administrator keeps one connection, one token and one place for the checks, and no user can point Nextcloud at another server.

The account of the app must see the documents of every user, as a superuser does, or through permissions that a workflow of Paperless grants it, and its token then reads what every user owns: [the security design](../security.md#per-user-ownership) explains it, and the connection checklist counts what the account sees of each user. A run of per-user ownership needs the permission to view the users of Paperless, and fails without it before a copy changes.

A run with many users does more work than one with a single archive, as each archive has its own batch. A user's own Paperless server or token remains out of scope; it would be a decision of its own.
