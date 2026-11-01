# Decisions

The decisions that shape this project, each with its reasons, so that a later change can see why things are as they are. A decision that changes an earlier one gets a new record, and the earlier one says which record replaces it.

Copy [the template](0000-template.md) to the next free number and describe the decision in the pull request that makes it.

| Record | Decision | Status |
| --- | --- | --- |
| [0001](0001-record-decisions.md) | Record the decisions that shape the project | accepted |
| [0002](0002-the-filesystem-api-of-nextcloud.md) | Write the archive through the filesystem API of Nextcloud | accepted, amended by [0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md) and [0026](0026-every-change-of-files-in-the-filesystem-of-their-user.md) |
| [0003](0003-paperless-is-the-source.md) | Paperless is the source of the archive | accepted |
| [0004](0004-delete-only-what-is-confirmed.md) | Delete only what Paperless has confirmed gone, and only what fits into a run | accepted |
| [0005](0005-end-to-end-tests-where-they-can-fail.md) | Run the end-to-end tests where a change can make them fail | accepted, amended by [0029](0029-share-the-end-to-end-runs-between-both-required-jobs.md) |
| [0006](0006-every-file-name-keeps-its-marker.md) | Every file name keeps its marker and its extension, within the bytes of Nextcloud | accepted |
| [0007](0007-end-to-end-tests-on-real-databases-and-storage.md) | Run the end-to-end tests on the databases and the storage of real installations | accepted, amended by [0029](0029-share-the-end-to-end-runs-between-both-required-jobs.md) |
| [0008](0008-local-network-per-request.md) | Reach a Paperless in the local network per request, never for the whole server | accepted |
| [0009](0009-checksums-of-paperless-2-and-3.md) | Keep the SHA-256 and the MD5 of every download, for Paperless-ngx 2 and 3 | accepted |
| [0010](0010-mirrored-files-keep-their-identity.md) | Mirrored files keep their identity: moved, and updated in place | accepted |
| [0011](0011-check-every-permission-before-a-run.md) | Check every permission that a run needs before the settings are saved, and block only what no run survives | accepted |
| [0012](0012-ask-for-version-9-of-the-paperless-api.md) | Ask for version 9 of the Paperless API in every request, and need Paperless-ngx 2.16 | accepted |
| [0013](0013-the-activity-stream-tells-what-a-run-did.md) | The Activity stream tells the target user what a run did, one entry per run and per import | accepted |
| [0014](0014-try-unsaved-settings-with-the-saved-connection.md) | Try unsaved settings with the saved connection, and keep the paths the same in every run | accepted |
| [0015](0015-check-the-mock-against-a-real-paperless.md) | Check the app and the answers of its unit tests against a real Paperless-ngx every week | accepted |
| [0016](0016-translate-where-the-reader-is-known.md) | Translate a message where its reader is known, and check the German catalog against the code | accepted |
| [0017](0017-folder-names-in-the-language-of-nextcloud.md) | Name new folders in the default language of Nextcloud, and keep the names of every installation in use | accepted |
| [0018](0018-runs-by-hand-in-background-jobs.md) | Runs by hand run as background jobs, and occ runs them in the shell | accepted |
| [0019](0019-changes-from-paperless-through-its-workflow-webhook.md) | Changes from Paperless arrive through the webhook of its workflows, as runs of one document | accepted |
| [0020](0020-one-connection-to-paperless-for-each-run.md) | One connection to Paperless for each run, through a client for that connection | accepted |
| [0021](0021-metadata-next-to-the-archive-in-sidecars-and-an-index.md) | The metadata of Paperless goes next to the archive, as a sidecar for each copy and one index | accepted |
| [0022](0022-a-dry-run-takes-the-plan-of-a-run.md) | A dry-run takes the plan of a run: planners decide, executors apply or only report | accepted |
| [0023](0023-file-actions-in-files-without-a-build.md) | Actions in the menu of Files, without a build, and uploads in the background | accepted |
| [0024](0024-the-tags-of-paperless-as-tags-of-nextcloud-that-the-app-alone-changes.md) | The tags of Paperless become tags of Nextcloud that the app alone changes | accepted |
| [0025](0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md) | Each user gets the documents that they own in Paperless, through one account of the app | accepted, amended by [0033](0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md) |
| [0026](0026-every-change-of-files-in-the-filesystem-of-their-user.md) | Every change of files runs in the filesystem of their user, through the setup manager of Nextcloud | accepted |
| [0027](0027-the-account-of-the-app-from-one-token-of-a-superuser.md) | The settings create the account of the app in Paperless with a token of a superuser that they use once | accepted |
| [0028](0028-test-the-upgrade-users-run.md) | Test the upgrade that users run: from the last release, on PostgreSQL and MariaDB, with the packages they install | accepted |
| [0029](0029-share-the-end-to-end-runs-between-both-required-jobs.md) | Share the end-to-end runs between both required jobs, and make each call of the suite quick | accepted, amended by [0032](0032-spend-the-runners-of-the-end-to-end-tests-on-the-tests.md) |
| [0030](0030-lint-the-javascript-and-measure-it-in-the-browser.md) | Lint the JavaScript with ESLint, and measure its coverage in the checks in the browser | accepted |
| [0031](0031-the-inbox-sets-aside-videos-programs-and-archives.md) | The inbox sets aside videos, programs and archives by their extensions, and leaves every other file to Paperless | accepted |
| [0032](0032-spend-the-runners-of-the-end-to-end-tests-on-the-tests.md) | Spend the runners of the end-to-end tests on the tests: health checks that check often only while a service starts, traces without snapshots in a first attempt, no more runs than processors, the data in memory, and a split by what a run costs | accepted |
| [0033](0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md) | Each user also gets a copy of the documents that Paperless shares with them | accepted |
| [0034](0034-a-note-in-files-says-what-a-folder-of-the-app-does.md) | A note in Files says what a folder of the app does, from one answer for each user | accepted |
