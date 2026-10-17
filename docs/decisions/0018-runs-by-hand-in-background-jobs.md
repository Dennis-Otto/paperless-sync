# Runs by hand run as background jobs, and occ runs them in the shell

- Status: accepted
- Date: 2026-10-17

## Context

*Synchronize now* ran the whole synchronization inside the HTTP request of the button. A full batch of up to 1,000 downloads, each with a timeout of 180 seconds, outlasts the timeouts of reverse proxies, 60 seconds for nginx and 100 for Cloudflare: the proxy answered for the app, and when PHP stopped with the request, the status stayed *running*. A run inside a request also differs from a scheduled run, because Nextcloud sets up the files of the administrator who clicked first: the versions app kept no previous content of an updated copy, and copies that the run deleted skipped the trash bin of the target user ([decision 0010](0010-mirrored-files-keep-their-identity.md), #119). And the app had no command line: an administrator of a Docker or All-in-One installation, who automates through `occ`, could neither start a run nor read the status, and a large first import took one batch per interval, about eight hours for 10,000 documents with the defaults.

## Options

1. Keep the run in the request, with a longer timeout of PHP. Proxies in front of Nextcloud keep their own timeouts, which the app can't change, and the run still differs from a scheduled one.
2. Answer the request first and go on in the same PHP process, such as with `fastcgi_finish_request()`. It depends on the server API of PHP, doesn't exist for every setup, and the process still counts against the limits of the web server.
3. Queue a `QueuedJob` of Nextcloud and answer at once with `202 Accepted`. Nextcloud's background jobs run it as they run the scheduled job, in the same context, as long as it takes. A dry-run stays in the request, because it changes nothing, and the batch size bounds it.

For the command line, the app registers commands for `occ`, which run in the shell, without the limits of a request.

## Decision

Option 3, and three commands: `paperless_sync:run` (a run or a dry-run, with another batch size or none for this run), `paperless_sync:status` and `paperless_sync:forget` (the next runs write copies again). Forgetting marks a copy as one that the app was writing, so that the next run takes it as its own and writes it in place; it forgets only the copies of documents that Paperless has, so that a copy in the deleted folder keeps the fingerprint that tells an edit of a user before a deletion ([decision 0004](0004-delete-only-what-is-confirmed.md)). The status says *queued* until the job starts, and *interrupted* for a run that started longer ago than Nextcloud keeps a lock and whose lock is gone; the settings page follows both. The commands print the report and the status as text or as the JSON of the settings page, and say by their exit code how a run ended: 0 completed, 1 with errors of documents, 2 failed, 3 another run is active.

## Consequences

Every run that changes files runs as a background job or in the shell, so a proxy can no longer cut it off, and every run keeps the previous content of an updated copy as a version. The job keeps the administrator who queued it, whom the Activity stream names as the author of the run, as it did when the run ran in the request. *Synchronize now* starts with the next background jobs, within five minutes with the system cron, instead of at once; an administrator who wants it at once runs a worker of Nextcloud for the job, as a service, or once with `occ background-job:worker --once 'OCA\PaperlessSync\Cron\ManualSyncJob'`. A job that finds another run holding the lock, such as a dry-run, queues itself again for the next background jobs. With AJAX as the mode of the background jobs, the run waits for the next visit of a page. A run without a limit can outlast the lock of Nextcloud, which expires after an hour by default, so an active run writes a sign of life every minute: while the last one is less than 15 minutes old, no other run starts, and the status shows a run as *Interrupted* only once its lock and its signs of life are gone. The end-to-end tests make their runs with `occ`, one through the queued job, and check the exit codes.
