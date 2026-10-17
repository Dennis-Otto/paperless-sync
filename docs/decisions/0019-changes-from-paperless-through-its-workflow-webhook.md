# Changes from Paperless arrive through the webhook of its workflows, as runs of one document

- Status: accepted
- Date: 2026-10-18

## Context

A change in Paperless reached Nextcloud with the next run, after the interval of the settings, 5 minutes at the shortest, once Nextcloud's cron started the job. The strongest other way to bring Paperless into Nextcloud, the container paperless-nextcloud-sync, copies every change within seconds, but it runs privileged next to Paperless, reads its media folder and needs the password of a Nextcloud account. Paperless-ngx has workflows with the triggers *Document Added* and *Document Updated* and the action *Webhook*, a `POST` with headers and parameters of their own, such as the ID of the document, `{{ doc_id }}` since 3.0 and `{{ doc_url }}` before. Paperless gives up after five seconds, follows no redirect and tries a call that answered with an error three more times.

## Options

1. A shorter interval. Nextcloud starts background jobs every five minutes at most, and a run reads every document of Paperless, so a shorter interval costs Paperless a full scan for every change.
2. Watch the media folder of Paperless, as paperless-nextcloud-sync does. It needs Paperless on the same server, or a share of its media folder, and the names of its files instead of the path template of the app.
3. A webhook of the app that a workflow of Paperless calls with the ID of a document, and a run of that document alone: the app reads the document from Paperless with its own token, and the rules of a run decide what happens to its copy.

## Decision

Option 3. `POST /apps/paperless_sync/api/v1/webhook` is the one route of the app without a login of Nextcloud and without its CSRF check, because Paperless has neither. It opens only with a secret that an administrator creates on the settings page, 48 random letters and digits, which the page shows once and Nextcloud keeps as its SHA-256 in the credentials manager; Paperless sends it as `Authorization: Bearer <secret>`. A call without it answers 401 and counts in Nextcloud's brute-force protection, and Nextcloud accepts 120 calls a minute from an address. The call names a document, as its ID or its address in Paperless, and nothing else.

The run of one document takes the way of a run through the export, with a batch of one: the same path, exclusion, conflict policy and fingerprints. It reads that document alone, with the fields of a scan, and leaves the trash, the deleted documents and the inbox to the scheduled runs, which see the whole of Paperless and count the missing scans of [deletion safety](0004-delete-only-what-is-confirmed.md). It holds the lock of the runs, but it is no run of the status. It follows the switches of the runs: while scheduled synchronization or the export is off, a call changes nothing.

It runs during the call, so that the copy changes before Paperless has its answer, and a call that takes longer than Paperless waits finishes all the same. While another run holds the lock, or when the setting *Mirror the document during the call* is off, the document waits for the next background jobs, in a `QueuedJob` that Nextcloud queues once for each document, however often Paperless calls, and that tries again a minute later while another run is active.

## Consequences

A new or changed document reaches Nextcloud within seconds, with no container, no media folder and no password of Nextcloud, and with the archive that the path template builds. The schedule, the setup checks, the status API and the notifications keep following the scheduled runs: calls of the webhook can't keep the run that finds trashed and deleted documents from starting, and a run of one document that fails is not a failing mirror. Scheduled synchronization stays necessary for the trash and the deletions, and as the safety net for a call that never came, such as while Nextcloud was down; Paperless doesn't call when a document goes to its trash. Paperless keeps the secret in the headers of its workflow, readable by everyone who may change workflows there, and whoever has it can make the app mirror documents sooner, nothing more; a new secret replaces it at once. The webhook answers 401, not 404, so whoever reaches Nextcloud can tell that the app is installed. [Security](../security.md#the-webhook-of-paperless) lists the threats and their tests.
