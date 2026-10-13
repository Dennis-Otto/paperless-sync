# Manual release acceptance matrix

Run this matrix in an isolated Nextcloud/Paperless lab before a release that changes synchronization, deletion, path generation, permissions, or background jobs. Use only synthetic accounts and documents.

| Area | Required behavior |
| --- | --- |
| Configuration | Connection test succeeds with a least-privilege Paperless service account; the saved token is never returned to the browser or written to logs. |
| Local network | With `allow_local_remote_servers` off, a Paperless at an address of the local network is refused with the advice to turn on *Paperless runs in my local network*, and works once it is on; other apps still can't reach local addresses. |
| Dry-run | Reports all expected actions without creating, moving, uploading, or deleting a file. |
| Export | Archive PDF and original-file modes create the expected correspondent-first hierarchy and stable `[P123]` marker. |
| Metadata | Title, correspondent, document type, storage path, and date changes move the existing file without leaving duplicate or empty paths, and without a new download, on Paperless-ngx 2.x and 3.x alike. |
| File identity | A new archive version of a shared document updates the mirrored file in place: its share, public link, comments, tags and favorites stay, and after a scheduled run its versions list the previous content. |
| Exclusions | Paperless inbox and configured exclusion tags prevent export and remove an existing synchronized copy. |
| Trash | Moving a document to the Paperless trash moves the Nextcloud copy to the configured deleted folder; restoring it moves the same file back. With *Keep archive file in place*, restoring it downloads nothing and leaves the same file in place. |
| Missing documents | A document that disappears from Paperless without passing through its trash moves the Nextcloud copy to the deleted folder after the configured number of complete scans, also when a run reaches its batch size first: the copy then moves in a later run. |
| Permanent deletion | No Nextcloud file is permanently deleted before the configured number of complete missing-document scans. Direct deletion remains blocked unless explicitly enabled. |
| Inbox import | Recursive import submits each source once, keeps it while the task is pending, removes it after success, and moves failures plus diagnostics to the error folder. |
| Conflicts | `skip` preserves an unrelated Nextcloud file, and a mirrored copy that someone changed, and reports the conflict without downloading the document, while the app's own copy still gets a new content; `replace` changes only the managed destination. |
| Full storage | With the quota of the target user used up, a run reports one export error and `EXPORT STOPPED`, downloads no further document, and exports the rest once space is free again. |
| Permissions | The configured target user owns the files; an unrelated Nextcloud user cannot read them unless they are explicitly shared. |
| Scheduling | System cron invokes the registered background job, respects the configured interval, and does not run in parallel. |
| Run visibility | After a scheduled run with an error of a document, a reload of the settings shows the error, the report and *Completed with errors*; the Nextcloud log has one warning for the error, and none for the same error in the runs after it. |
| Clients | Files remain readable through Nextcloud Web, desktop sync, iOS, and Android after exports, moves, and trash restoration. |

Record the Paperless version, Nextcloud version, app version, client versions, chosen configuration, and sanitized result summaries. Never attach a production token, hostname, document, path, or log containing personal metadata to an issue.
