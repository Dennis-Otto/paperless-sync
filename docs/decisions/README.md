# Decisions

The decisions that shape this project, each with its reasons, so that a later change can see why things are as they are. A decision that changes an earlier one gets a new record, and the earlier one says which record replaces it.

Copy [the template](0000-template.md) to the next free number and describe the decision in the pull request that makes it.

| Record | Decision | Status |
| --- | --- | --- |
| [0001](0001-record-decisions.md) | Record the decisions that shape the project | accepted |
| [0002](0002-the-filesystem-api-of-nextcloud.md) | Write the archive through the filesystem API of Nextcloud | accepted |
| [0003](0003-paperless-is-the-source.md) | Paperless is the source of the archive | accepted |
| [0004](0004-delete-only-what-is-confirmed.md) | Delete only what Paperless has confirmed gone, and only what fits into a run | accepted |
| [0005](0005-end-to-end-tests-where-they-can-fail.md) | Run the end-to-end tests where a change can make them fail | accepted |
| [0006](0006-every-file-name-keeps-its-marker.md) | Every file name keeps its marker and its extension, within the bytes of Nextcloud | accepted |
| [0007](0007-end-to-end-tests-on-real-databases-and-storage.md) | Run the end-to-end tests on the databases and the storage of real installations | accepted |
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
