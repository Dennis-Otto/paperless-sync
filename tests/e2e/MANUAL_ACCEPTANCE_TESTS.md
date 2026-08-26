# Manual release acceptance matrix

Run this matrix in an isolated Nextcloud/Paperless lab before a release that changes synchronization, deletion, path generation, permissions, or background jobs. Use only synthetic accounts and documents.

| Area | Required behavior |
| --- | --- |
| Configuration | Connection test succeeds with a least-privilege Paperless service account; the saved token is never returned to the browser or written to logs. |
| Dry-run | Reports all expected actions without creating, moving, uploading, or deleting a file. |
| Export | Archive PDF and original-file modes create the expected correspondent-first hierarchy and stable `[P123]` marker. |
| Metadata | Title, correspondent, document type, storage path, and date changes move the existing file without leaving duplicate or empty paths. |
| Exclusions | Paperless inbox and configured exclusion tags prevent export and remove an existing synchronized copy. |
| Trash | Moving a document to the Paperless trash moves the Nextcloud copy to the configured deleted folder; restoring it moves the same file back. |
| Permanent deletion | No Nextcloud file is permanently deleted before the configured number of complete missing-document scans. Direct deletion remains blocked unless explicitly enabled. |
| Inbox import | Recursive import submits each source once, keeps it while the task is pending, removes it after success, and moves failures plus diagnostics to the error folder. |
| Conflicts | `skip` preserves an unrelated Nextcloud file and reports the conflict; `replace` changes only the managed destination. |
| Permissions | The configured target user owns the files; an unrelated Nextcloud user cannot read them unless they are explicitly shared. |
| Scheduling | System cron invokes the registered background job, respects the configured interval, and does not run in parallel. |
| Clients | Files remain readable through Nextcloud Web, desktop sync, iOS, and Android after exports, moves, and trash restoration. |

Record the Paperless version, Nextcloud version, app version, client versions, chosen configuration, and sanitized result summaries. Never attach a production token, hostname, document, path, or log containing personal metadata to an issue.
