# Manual release acceptance matrix

Run this matrix in an isolated Nextcloud/Paperless lab before a release that changes synchronization, deletion, path generation, permissions, or background jobs. Use only synthetic accounts and documents.

The column *Docker E2E* names what `run.sh` already checks on its own, on every supported Nextcloud version, and on PostgreSQL and on MariaDB with S3 object storage as well ([README](README.md)). The rest, and real Paperless and clients, stays for the lab.

| Area | Required behavior | Docker E2E |
| --- | --- | --- |
| Configuration | Connection test succeeds with a least-privilege Paperless service account; the saved token is never returned to the browser or written to logs. | Partly: the token is in no response and no log line; the service account is for the lab |
| Dry-run | Reports all expected actions without creating, moving, uploading, or deleting a file. | Yes |
| Export | Archive PDF and original-file modes create the expected correspondent-first hierarchy and stable `[P123]` marker. | Partly: archive PDFs |
| Metadata | Title, correspondent, document type, storage path, and date changes move the existing file without leaving duplicate or empty paths. | Partly: a new title |
| Exclusions | Paperless inbox and configured exclusion tags prevent export and remove an existing synchronized copy. | Partly: the inbox tag |
| Trash | Moving a document to the Paperless trash moves the Nextcloud copy to the configured deleted folder; restoring it moves the same file back. | Yes |
| Missing documents | A document that disappears from Paperless without passing through its trash moves the Nextcloud copy to the deleted folder after the configured number of complete scans, also when a run reaches its batch size first: the copy then moves in a later run. | Partly: without the batch size, which the unit tests check |
| Permanent deletion | No Nextcloud file is permanently deleted before the configured number of complete missing-document scans. Direct deletion remains blocked unless explicitly enabled. | Yes |
| Inbox import | Recursive import submits each source once, keeps it while the task is pending, removes it after success, and moves failures plus diagnostics to the error folder. | Yes, also from S3 object storage |
| Conflicts | `skip` preserves an unrelated Nextcloud file and reports the conflict without downloading the document; `replace` changes only the managed destination. | Yes |
| Full storage | With the quota of the target user used up, a run reports one export error and `EXPORT STOPPED`, downloads no further document, and exports the rest once space is free again. | No: the unit tests check it |
| Permissions | The configured target user owns the files; an unrelated Nextcloud user cannot read them unless they are explicitly shared. | Partly: without shares |
| Scheduling | System cron invokes the registered background job, respects the configured interval, and does not run in parallel. | Partly: the job runs and respects the interval; `occ` starts it instead of system cron |
| Clients | Files remain readable through Nextcloud Web, desktop sync, iOS, and Android after exports, moves, and trash restoration. | No |

Record the Paperless version, Nextcloud version, app version, client versions, chosen configuration, and sanitized result summaries. Never attach a production token, hostname, document, path, or log containing personal metadata to an issue.
