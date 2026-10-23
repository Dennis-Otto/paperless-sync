# Every change of files runs in the filesystem of their user, through the setup manager of Nextcloud

- Status: accepted
- Date: 2026-10-23
- Amends [0002](0002-the-filesystem-api-of-nextcloud.md): the app uses one class of the private API of Nextcloud

## Context

A copy that a run deletes should go to the trash bin of its user, *Deleted files*, from where the user can restore it ([decision 0004](0004-delete-only-what-is-confirmed.md)). Nextcloud's trash bin takes a deleted file there only from the default filesystem of the process, the filesystem of the first user whose files the process set up: `LegacyTrashBackend::moveToTrash()` of the app `files_trashbin` asks `Filesystem::getView()` for the path of the file, and deletes a file outside it for good. The views of Nextcloud emit the hooks of other apps, such as those of versions and of the Activity stream, only within that filesystem as well.

The background job of a scheduled run sets up the files of the target user first, so its deletions reached the trash bin. In the request of an administrator, the first filesystem was the administrator's ([decision 0018](0018-runs-by-hand-in-background-jobs.md) moved *Synchronize now* into a background job for this among other reasons). With per-user ownership ([decision 0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md)), one run changes the files of several users, and only the first of them got their trash bin; the copies that left the archive of any other user, such as for a new owner, were gone for good (#119). A background job that runs after the job of another app, an `occ` command and the webhook depend on what the process did before.

## Options

1. Keep the public API alone and document the limits. Deletions in per-user ownership stay for good for every user but one, and every caller depends on the process.
2. Move a file into the trash bin with the app's own code, as `files_trashbin` stores it. The app would copy the inner workings of another app, with its database table, its versions and its expiry, and break with each of their changes.
3. Before a change of the files of a user, make the filesystem of that user the default one of the process: tear the filesystem down and set it up for the user, with `OC\Files\SetupManager`, as Nextcloud's own background jobs do for one user after another, such as `ExpireTrash` of `files_trashbin` and `SanitizeFilenames` of `files`. Nextcloud's public API (OCP) has no way to do it.

## Decision

Option 3. `UserFilesystem` reads the root of the default filesystem, `OC\Files\Filesystem::getRoot()`, and only when it belongs to another user, tears it down and sets it up for the user, who then is the first; a run of one user does it at most once, and a run of per-user ownership once for each change of the user. `NextcloudStorageService` enters the filesystem of the user before each change of their files: preparing the folders, writing, moving, deleting and pruning. Reading leaves the filesystem as it is, so that a request of a user, such as *Send to Paperless*, keeps theirs. The class is the only place of the private API, and Psalm, which knows only OCP, is told so where it is used.

## Consequences

Every deletion of a run reaches the trash bin of its user, whoever or whatever starts the run, and the end-to-end tests check the trash bins of the target user and of the users of per-user ownership. The app depends on two methods of the private `SetupManager` and on `Filesystem::getRoot()`, which Nextcloud has kept since version 24; the end-to-end tests of every supported version from `min-version` to `max-version` find a change of them. A tear-down drops the filesystems that the process had set up, which Nextcloud sets up again on their next use; the app holds no file or folder of Nextcloud across a change of the user.
