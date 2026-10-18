# Actions in the menu of Files, without a build, and uploads in the background

- Status: accepted
- Date: 2026-10-18

## Context

People want to send a single file or folder to Paperless from Files, and to open the document of a mirrored copy in Paperless. The app of Nextcloud for that, *Paperless integration* (`integration_paperless`), offers one action, *Upload to Paperless*, which every user sets up with a Paperless token of their own, and is maintained "with limited effort"; so an installation of Paperless Sync needed a second app, and one token for each person, to send a file by hand, while Paperless Sync already has an upload that knows the answers of Paperless: its refusals, a lost task, a retry that waits longer each time.

Files of Nextcloud 33 to 35 reads its file actions from `@nextcloud/files` 4. An app usually bundles that library with Vite and commits the bundle or builds it in the release. The settings page of this app is plain JavaScript without a build, and the package of the app holds only what Nextcloud loads (`scripts/check-package.sh`): a `package.json`, a lock file and the sources of a bundle would have to stay out of it, through `.nextcloudignore` and `.gitignore`, which belong to the blueprint. A committed bundle would also go stale with every update of a dependency, and its check would fail every pull request of Renovate until a person builds it again.

Sending uses the token of the app, the account of Paperless that the administrator set up, not an account of the user. An upload of a large file, or of the files of a folder, may take longer than a web server waits for an answer.

## Options

1. A Vite build of `@nextcloud/files`, committed as `js/`: the documented way, but a bundle of third-party code in the package, a build in every check, a lock file that Renovate updates, and a pull request of the blueprint before the package can leave out the sources.
2. Build the bundle in the release workflow: nothing stale in the repository, but the release workflow and krankerl's settings belong to the blueprint, and no check would show the code that ships.
3. A plain script, `js/files.js`, that registers its actions as `registerFileAction()` of `@nextcloud/files` 4 does: into the registry of that version, `window._nc_files_scope.v4_0.fileActions`, which every Files of Nextcloud 33 to 35 reads, with the shape of the public interface `IFileAction`. A bundled library would register into the same registry: the version of the library decides it, and a Files on `@nextcloud/files` 5 would read neither.

And for the upload: in the request of the click, or queued for a background job.

## Decision

Option 3, with the uploads queued. `js/files.js` registers *Send to Paperless* for the members of the group of the setting *Group that may send files*, `admin` by default, and *Open in Paperless* for every user, for a file whose name carries a marker such as `[P123]`, which every copy of the archive keeps ([0006](0006-every-file-name-keeps-its-marker.md)). It loads as an init script when Files loads the scripts of other apps, only for a user who may use one of them.

A click queues the files, and the files below the folders, in the table `paperless_sync_send`, at most a batch at once and each file once. The background job `SendJob` runs with every round of cron: it uploads what is due, with the tags for every import and the tags of the subfolders below the sent folder, follows the tasks, and notifies the sender how each import ended. A file whose content Paperless has already isn't uploaded again, as Paperless-ngx 3.x would import it twice; the files of the folder of the app, which the runs look after, and files with a marker stay out. The server checks the group, that the user may read each file, and the folder of the app, whatever the menu offered.

## Consequences

The package holds one small script that its readers can review, nothing needs a build, and no update of a dependency can leave it stale. The end-to-end tests open Files of every supported Nextcloud and check that the actions appear for the right users and that a click queues the file, so a Files that reads its actions elsewhere fails there, and so does the pull request of the upstream bot that raises `max-version` to a Nextcloud on `@nextcloud/files` 5; the script then takes the registry of that version.

A click answers at once, and the result arrives within a few rounds of cron as a notification, also for a large folder. Everyone who may send files uploads with the account of the app, so the administrator chooses that group with care; the address of Paperless reaches every user of Files, for *Open in Paperless*, as the metadata next to the archive already names it.
