# Troubleshooting

[← README](https://github.com/Dennis-Otto/paperless-sync) · [Guide](guide.md) · [FAQ](faq.md)

When something doesn't work, Paperless Sync says why, and this page leads from what you see to its cause and its fix. The [FAQ](faq.md) answers the questions about what the app does, such as where deleted files go. Where to look first:

- **Saving the settings fails:** the message next to the buttons, in the bar at the bottom of *Administration settings → Paperless Sync*, names the cause.
- **A run fails or ends with errors:** the status at the top of the settings shows the state of the last run, also of a scheduled one, with a box of its errors, and the [Nextcloud log](#read-the-log) has an entry of the app `paperless_sync` for each of them. On the server, `occ paperless_sync:status` shows the same.
- **No run starts at all:** the [background jobs](#no-run-starts) of Nextcloud don't run.
- **A file is missing or in an unexpected place:** the report of the last run, or of a dry-run, has a line for every file that the run writes, moves or deletes.

## The way of a request to Paperless

**Test connection** in the settings follows this way, and so does every save that tests the connection: its [connection checklist](guide.md#connection-checklist) names the step that failed and what to do. Every run starts with the same request for the first document. Each step on its way can fail with an error of its own:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-a-request-from-nextcloud-to-paperless-and-the-error-of-each.dark.svg">
  <img src="images/diagrams/the-way-of-a-request-from-nextcloud-to-paperless-and-the-error-of-each.svg" alt="The way of a request from Nextcloud to Paperless, and the error of each step: A request of Paperless Sync passes seven steps before Paperless answers with the documents. An address of the local network needs the setting Paperless runs in my local network, or Nextcloud blocks it. The name must resolve and Paperless must answer at its port, or the message has cURL error 6 or 7. A certificate of HTTPS must be one that Nextcloud trusts, or the message has cURL error 60. The URL must lead to the API of Paperless, or the answer is 404 Not Found, or a page of a login that is no JSON. Paperless must serve version 9 of its API, as Paperless-ngx 2.16 and newer do, or the answer is 406 Not Acceptable. The token must be valid, or the answer is 401 Unauthorized. Finally, the account must see every list that a run reads, or the answer is 403 Forbidden: Test connection marks that list as failed, with the permission to grant.">
</picture>

## Messages and their fixes

| What you see | Cause and fix |
| --- | --- |
| `Nextcloud blocks connections to local addresses such as paperless` | Paperless runs at an address of your network, which Nextcloud refuses against server-side request forgery. **Fix:** Turn on **Paperless runs in my local network** and save; [details](#a-paperless-in-your-network). |
| `Nextcloud found no DNS record for paperless` | The name is set only in the hosts file of the server, such as with `extra_hosts` in Docker, or nowhere. **Fix:** Turn on **Paperless runs in my local network**, or correct the name. |
| `cURL error 6: Could not resolve host` | The Nextcloud server can't find the name of Paperless. **Fix:** Use the name or address under which the Nextcloud server, not your browser, reaches Paperless; [details](#paperless-in-docker). |
| `cURL error 7: Failed to connect` | Nothing answers at that address and port. **Fix:** Check the port, that Paperless runs and that a firewall or a Docker network doesn't stand in between. |
| `cURL error 60: SSL certificate problem` | Paperless has a certificate that Nextcloud doesn't trust, such as one that you signed yourself. **Fix:** Import its certificate into Nextcloud; [details](#a-certificate-of-your-own). |
| `cURL error 35` with `wrong version number` | The URL says `https://` at a port that speaks HTTP. **Fix:** Use `http://`, or the port of HTTPS. |
| `404 Not Found` | The URL lacks the sub-path under which Paperless runs, such as `/paperless`. **Fix:** Enter the URL as you open Paperless in the browser, sub-path included; [details](#behind-a-reverse-proxy). |
| `Paperless returned invalid JSON.` | The URL leads to a web page instead of the API, such as the login of a proxy in front of Paperless. **Fix:** Let the proxy pass the requests to `/api/` with their token; [details](#behind-a-reverse-proxy). |
| `Paperless does not serve version 9 of its API` | Paperless answered `406 Not Acceptable`: it runs a version before Paperless-ngx 2.16, which doesn't know version 9 of its API yet, or one so new that it no longer serves it. **Fix:** Update Paperless-ngx to 2.16 or newer; with a newer Paperless-ngx, update Paperless Sync. |
| `401 Unauthorized` | The token is wrong, was deleted in Paperless, or its account is inactive. **Fix:** Create a token in Paperless and enter it again. |
| `403 Forbidden` for `/api/tags/`, `/api/correspondents/`, `/api/document_types/` or `/api/storage_paths/` | The account may see the documents, but not that list, which every run reads. **Test connection**, and every save that tests the connection, marks that list as failed in the connection checklist, with the permission to grant; the save keeps the settings. **Fix:** Give the account the permission to view that list in Paperless. |
| `Nextcloud user … does not exist.` or `Nextcloud has no user with the ID …` | The target user is no Nextcloud user. **Fix:** Pick the user from the list of the field, or enter the account name, as *Accounts* in the administration lists it, not the display name. |
| `The configured Nextcloud base folder is not a writable folder.` | The base folder is a file, or a share that the target user may only read. **Fix:** Choose a folder that the target user owns. |
| `Save the connection to Paperless first; then its tags appear here.` | The fields of the excluded tags, the tags to mirror and the tags for every import read the tags with the saved URL and token, and there are none yet, or the URL changed past the settings page. **Fix:** Enter the URL and the token, and save. Until then, type the names. |
| `Paperless did not list its tags` | Paperless refused the list of tags, such as with `HTTP 403` when the account may not view tags, or can't be reached. Every run reads that list too. **Fix:** Give the account the permission to view tags in Paperless, or see the other messages of this table. |
| A tag in the settings says *not in Paperless* | Paperless has no tag of that name that its account may view: a typo, a tag that you create later, or one that belongs to someone else. **Fix:** Remove it with its button and pick the tag from the list, or create the tag in Paperless or share it with the account; for the tags for every import, *Create missing tags* lets a run create it. A tag to mirror marked this way stops every export, as the next row says. |
| `Paperless has no tag named "…", which "Only mirror documents with these tags" names` | A tag to mirror is not a tag of Paperless that its account may view: a typo, a tag renamed or deleted in Paperless, or one that belongs to someone else. The export stops before a file changes, because a run would otherwise remove the copies of the documents that should stay; the inbox goes on. **Fix:** Remove the tag with its button and pick it from the list of the field, or create the tag in Paperless or share it with the account. |
| `Enter the API token again to connect to another Paperless URL.` | The URL changed, and the token goes only to the URL that it was saved with. **Fix:** Enter the token with the new URL. |
| `Paperless Sync is not completely configured.` | No URL, token or target user, or the URL changed past the settings page, with `occ` for example, and waits for its token. **Fix:** Enter the missing value, or the token again, and save. |
| `Nextcloud file conflict at …` | Conflicts are skipped, and a file of someone else is in the way of the document, or someone edited its copy in Nextcloud. **Fix:** Move or delete that file, or choose **Replace synchronized file**; see the [conflict policy](guide.md#conflict-policy). |
| `EXPORT STOPPED`, on the settings page the notice *Nextcloud cannot store files in the archive*, and `Not enough free space in Nextcloud` | The quota of the target user is full, or the archive is read-only or its storage unavailable. **Fix:** Raise the quota or free space; the next run carries on. |
| `SCAN INCOMPLETE`, on the settings page the notice that a list of Paperless *came back incomplete or changed* | A page of Paperless came back short, or documents were added or deleted while the run read them. The run counted no document as missing. **Fix:** Nothing, if the next run is complete. If it stays, look for a proxy that cuts long answers, or permissions that change. |
| `Paperless answered the upload with HTTP 403` | The account may not add documents, which the inbox needs. The upload waits and tries again later. **Fix:** Give the account the permission `documents.add_document` in Paperless, **Add** for Documents. |
| `Import task …` with `HTTP 403` | The account may add documents, but not view the tasks of Paperless that import them, so the inbox never learns whether a file arrived, and keeps it. **Fix:** Give the account the permission **View** for Paperless Tasks, `documents.view_paperlesstask`; the line *Upload from the inbox* of the connection checklist names it. |
| `IMPORT REJECTED`, on the settings page *Refused*, with `HTTP 413` | A proxy in front of Paperless refuses large files, such as the 100 MB of the free plan of Cloudflare. **Fix:** Raise the limit of the proxy, then save the file anew to submit it again. |
| `IMPORT REJECTED`, *Refused*, with another reason | Paperless refused the file, such as one of a type that it doesn't take. **Fix:** Convert the file, or remove it from the inbox. |
| A file in the error folder with `It is a duplicate of document #123` in its `.error.txt` | Paperless has that document already, also in its trash when the text says so. Paperless-ngx 2.x refuses duplicates; 3.x only with `PAPERLESS_CONSUMER_DELETE_DUPLICATES`. **Fix:** Nothing, or restore the document from the trash of Paperless; delete the file from the error folder. |
| A file in the error folder with `Paperless cancelled the task before it finished (REVOKED)` | Paperless stopped the task, such as when it was cancelled or its worker was stopped during it. **Fix:** Move the file back into the inbox to submit it again. |
| A file in the error folder with `Paperless import not finished` in its `.error.txt` | Paperless still lists the task of the file as pending or started a day after the upload, and has no document with its content: its worker may be down, or its queue long. The app doesn't upload the file again, so that Paperless-ngx 3.x can't import it twice. **Fix:** Check that the worker of Paperless runs. If the document appears in Paperless, delete the file; otherwise move it back into the inbox. |
| `Paperless has not finished the import task … a day after the upload` | Paperless doesn't list the task of the file and has no document with its content: its queue lost the task, as after a restart of Redis. The file is uploaded again after 15 minutes. **Fix:** Check that the worker of Paperless runs and that its Redis keeps its data. |
| The status says *Failed* or *Completed with errors* | The run, or single documents, failed. **Fix:** Read the box of errors below the status, then the [log](#read-the-log). |
| The status stays at *Queued* | *Synchronize now* queued a run, and the background jobs of Nextcloud haven't started it: they don't run, or they run with *AJAX* while nobody has Nextcloud open. **Fix:** See [No run starts](#no-run-starts), or run `occ paperless_sync:run` on the server. |
| The status says *Interrupted* | PHP stopped the run before it could say how it ended, such as on a restart of the server or of its container, or at a limit of PHP; its lock is gone and it gave no sign of life for 15 minutes, while an active run writes one every minute. **Fix:** Nothing, if the next run completes: it continues where this one stopped. Otherwise look for a fatal error of PHP in the [log](#read-the-log). |
| `Another synchronization run is already active.` | A run holds the lock, such as a scheduled one or one of `occ`, and only one runs at a time. **Fix:** Wait until the status shows its end, then try again. |
| The next run lies in the past, or the status shows no scheduled run | The background jobs of Nextcloud don't run, or synchronization is off. **Fix:** See [No run starts](#no-run-starts). |
| Other users don't see the archive | It lies in the files of the target user alone. **Fix:** Share its folder; see [Other users don't see the archive](#other-users-dont-see-the-archive). |
| A document in the Paperless trash stays in the archive for several runs | The account doesn't own it, so the Paperless trash doesn't show it to the account. **Fix:** See [Documents of the trash come late](#documents-of-the-trash-come-late). |
| A deleted copy is not in *Deleted files* | It is in the trash bin of the target user, which only that user sees, or the app *Deleted files* is off. **Fix:** See [Deleted copies are not in the trash bin](#deleted-copies-are-not-in-the-trash-bin). |

## Connection

### A Paperless in your network

Nextcloud refuses connections to addresses of the local network, such as `http://paperless:8000` in Docker or `http://192.168.1.20:8000`, to protect against server-side request forgery. Turn on **Paperless runs in my local network** in the section *Connection and ownership* and save: Nextcloud then allows such an address for the requests of Paperless Sync to the saved Paperless URL, and only for them. Don't turn on the system switch `allow_local_remote_servers` for this app, which lifts the protection for every app; if you did, turn the setting on, save, and turn the switch off again with `occ config:system:delete allow_local_remote_servers`. The [guide](guide.md#paperless-in-your-local-network) explains the setting.

### Paperless in Docker

The URL is the address under which the Nextcloud server reaches Paperless, which can differ from the one in your browser. When both run in containers of the same Docker network, it is the name of the service of Paperless and the port inside the container, such as `http://paperless:8000`, with **Paperless runs in my local network** on. `http://localhost:8000` points at the container of Nextcloud itself. Test the address from the Nextcloud server, such as with `docker exec -u www-data nextcloud curl -sS http://paperless:8000/api/` with the name of your container; any answer of Paperless, also `401`, shows that it is reachable.

### A certificate of your own

Nextcloud trusts the certificates of the usual authorities and those imported into it, not the ones of the operating system. For a certificate that you signed yourself, or one of your own authority, import the certificate of the authority, in PEM format, on the Nextcloud server:

```sh
occ security:certificates:import /path/to/your-ca.crt
occ security:certificates
```

The second command lists the imported certificates. Then save the settings again.

### Behind a reverse proxy

Enter the URL as you open Paperless in the browser, with its sub-path if it has one, such as `https://example.com/paperless`, without `/api` at its end: the app adds `/api/documents/` and the other paths of the API to it. A proxy in front of Paperless must:

- pass the requests to `/api/` with their header `Authorization: Token …`, without a login of its own, such as one of single sign-on, which would answer with a web page and the message `Paperless returned invalid JSON.`;
- accept uploads as large as the files of the inbox, such as with `client_max_body_size` in nginx; the free plan of Cloudflare stops at 100 MB;
- leave answers whole, also long ones: a scan reads the documents in pages of 1,000.

## Runs

### No run starts

Every run is a background job of Nextcloud. When the status shows no next run, synchronization is off: turn on **Enable scheduled synchronization** and choose **Test connection and save**. When the next run lies in the past, the background jobs don't run:

1. Open *Administration settings → Basic settings → Background jobs*. It must say **Cron**, and the last job must have run a few minutes ago. *AJAX* runs jobs only while someone has Nextcloud open in a browser, one job at a time.
2. Set up the system cron as [Nextcloud describes it](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html#cron): every five minutes, `php -f /var/www/html/cron.php` as the user of the web server. The image of Nextcloud for Docker runs no cron of its own; give it a second container of the same image with the command `/cron.sh`, or a cron job on the host with `docker exec -u www-data nextcloud php -f /var/www/html/cron.php`.
3. `occ background-job:list --class 'OCA\PaperlessSync\Cron\SyncJob'` shows when Nextcloud last started the job of the app, and `occ background-job:list --class 'OCA\PaperlessSync\Cron\ManualSyncJob'` a run of *Synchronize now* that waits for its start.

Nextcloud starts the job every five minutes at most, and the job starts a run when the interval of the app has passed since the last one; a dry-run doesn't count. A run that is still busy, such as the first synchronization of a large archive, holds back the next one until it ends. `occ paperless_sync:run` starts a run in the shell at once, without the background jobs, and says why it fails.

### Read the log

The log has a warning when an error of a document first occurs or changes, and an error when a run fails as a whole, each with the app `paperless_sync`.

- In the browser: *Administration settings → Logging*, from the app *Log Reader*, whose column *App* names `paperless_sync`.
- On the server: `occ log:tail 50` shows the last entries, and `occ log:tail --follow` the new ones as they come. The column *App* names `paperless_sync`.
- In the file: `grep '"app":"paperless_sync"' data/nextcloud.log`, in the data directory of Nextcloud.

Nextcloud logs warnings and errors by default. To see that a run completed, with its counts, turn the level down to info for a while with `occ log:manage --level info`, and back with `occ log:manage --level warning`. The app keeps the API token of Paperless out of the log.

### Nobody was notified of a failing mirror

The notification of a failing mirror comes after the third run in a row that failed or completed with errors, or once the first of them ended a day ago, and once for the whole row: two failed runs, such as during a restart of Paperless, notify nobody, and neither does a dry-run. It goes to the enabled members of the group `admin`, or of the group that `occ config:app:get paperless_sync notification_group` names; a group that doesn't exist leaves it to the administrators, with a warning in the log. Nextcloud shows notifications only while its app *Notifications* is enabled, and sends them by mail or to the phone only as each user set it up in the personal settings under *Notifications*. The next successful run removes the notification, so a mirror that works again has none. *Administration → Overview* and the [status API](guide.md#being-notified) show the health of the mirror at any time.

### The overview warns about the background jobs of Nextcloud

The check *Paperless Sync background job* warns while scheduled runs are on and Nextcloud runs its background jobs with AJAX or Webcron. Both start a job only while someone uses Nextcloud, so a night without visitors has no runs, and a run may stop halfway when the request ends. Set *Background jobs* to *Cron* in *Administration → Basic settings*, or with `occ background:cron`, and add the cron job of the system that runs `cron.php` every five minutes as the user of the web server, as the [manual of Nextcloud](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html#cron) describes.

### The Activity app shows nothing of Paperless Sync

The entries go to the target user alone, so another account, such as the administrator who started the run, sees none of them. A dry-run and a run that changed nothing add no entry, and neither does a run that stopped before its first change, such as one that can't reach Paperless; the status on the settings page tells about those. Nextcloud keeps the entries only while its app *Activity* is enabled, and from the version of Paperless Sync that brought them on: earlier runs have none. The filter *Paperless Sync* in the navigation of the Activity app shows them alone.

### The link to a new document in Paperless doesn't open

The link of an imported file in the Activity app, the notification of a file sent from Files and *Open in Paperless* in Files start with the saved Paperless URL, which is the address under which the Nextcloud server reaches Paperless. A name or an address that only the server knows, such as `http://paperless:8000` in a Docker network, leads the browser nowhere. Open the document in Paperless by its number instead, such as *P123*, or save a URL that both the server and the browsers reach, such as the public address of Paperless behind its reverse proxy.

### Documents of the trash come late

The Paperless trash shows an account only the documents that it owns and those without an owner. A document of someone else that goes to the trash disappears for the app from both lists, so it counts as missing: its copy moves to the deleted folder only after the configured number of complete scans, below the date of that run instead of the date of the trash. With permanent deletion and *Also delete copies of documents that disappeared without passing the Paperless trash* both on, the copy is even deleted then, although the document can still be restored in Paperless.

**Fix:** Make the account of the app the owner of the documents, with a workflow for new ones and the bulk edit of permissions for the others, as the [guide](guide.md#paperless-connection) shows; both keep the access of the people who own them now. Don't make the account a superuser: it would see the whole trash, but its token controls all of Paperless.

### A change in Paperless doesn't arrive within seconds

Section 6 of the settings, *Changes within seconds*, shows the last call of the webhook and what came of it. Without a last call, Paperless didn't reach the app; the log of Paperless has a line `Failed attempt sending webhook to …` with the reason:

| What you see | Cause and fix |
| --- | --- |
| No last call, and nothing in the log of Paperless | The workflow didn't run: it needs the triggers *Document Added* and *Document Updated*, and its filters must match the document. **Fix:** Check the workflow in Paperless, and that it is enabled. |
| `Connection blocked: … resolves to a non-public address` in the log of Paperless | Paperless refuses webhooks to addresses of its network when `PAPERLESS_WEBHOOKS_ALLOW_INTERNAL_REQUESTS` is `false`. **Fix:** Set it to `true` for a Nextcloud in your network, or use the public address of Nextcloud. |
| `Destination port not permitted` or `Invalid URL scheme` | `PAPERLESS_WEBHOOKS_ALLOWED_PORTS` or `PAPERLESS_WEBHOOKS_ALLOWED_SCHEMES` of Paperless leave out the address of Nextcloud. **Fix:** Add its port or scheme there. |
| `401 Unauthorized` | The secret is missing or wrong, or a proxy in front of Nextcloud drops the header `Authorization`. **Fix:** Enter `Bearer`, a space and the secret as the value of the header `Authorization` under *Webhook headers*, or create a new secret; let the proxy pass the header, as Nextcloud's own clients need it as well. |
| Calls take longer and longer | Calls with a wrong secret made Nextcloud's brute-force protection slow down the address of Paperless. **Fix:** Correct the secret; the first call with the right one ends the delay. |
| `429 Too Many Requests` | More than ten calls with a wrong secret within 30 minutes: Paperless tries every refused call three more times, so three events are enough. Nextcloud now refuses the address before the app sees the call, so the right secret can't end it. **Fix:** Correct the secret, then wait up to 30 minutes, or run `occ security:bruteforce:reset <address>` at once. More than 120 calls a minute also answer 429; the scheduled runs catch up on those. |
| `400 Bad Request` | The call names no document. **Fix:** Turn on *Use parameters for webhook body* and *Send webhook payload as JSON*, and add the parameter `document` with `{{ doc_id }}`, or `{{ doc_url }}` with Paperless-ngx 2.x. If the answer is the HTML page *Access through untrusted domain*, Paperless calls Nextcloud by a host name that isn't in `trusted_domains`, such as the name of its container. **Fix:** Add that host name to `trusted_domains` in `config.php`, or use the address that the browser uses. |
| `404 Not Found`, and the last call says Paperless shows no active document | Paperless called before it had committed a new document, or the document is in its trash. Paperless tries again after a few seconds, which catches a new document; the scheduled runs handle the trash. **Fix:** Nothing to fix, unless the document is active in Paperless and the account of the token can't see it: then give that account the permission to view it. |
| `409 Conflict`, and the last call says nothing changed | Scheduled synchronization or the export is off, or Paperless is not connected. **Fix:** Turn them on in sections 1 and 2 and save. |
| `ReadTimeout` in the log of Paperless, but the copy has changed | Paperless waits five seconds for the answer, and a large document takes longer; the run finished all the same. **Fix:** Nothing to fix, or turn off *Mirror the document during the call*, so that the app answers at once and the background jobs take the document. |
| The last call says *waits for the next background jobs* | Another run held the lock, or *Mirror the document during the call* is off. The next background jobs mirror the document, within five minutes with system cron. |
| The last call says *Paperless shows no active document* | The document is in the trash of Paperless, deleted, or not visible to the account of the app. The scheduled runs handle the trash; for the permissions, see [Documents of the trash come late](#documents-of-the-trash-come-late). |

## Files

### Other users don't see the archive

The archive is in the files of the target user alone; the app gives nobody else access. Share its folder, such as `Documents/Paperless/Archive`, from *Files*, with users or with a group, and preferably read-only, since nothing there goes back to Paperless, and a run may replace an edited copy or write a deleted one anew. Share the inbox folder only with those who may send documents to Paperless: whatever reaches it goes to Paperless under the account of the app.

### Deleted copies are not in the trash bin

A copy that a run deletes, and a file in the way of a copy that moves, go to the trash bin of the target user, *Deleted files* in *Files*, where only the target user sees and restores it: an administrator who started the run doesn't find it in a trash bin of their own. That holds for a scheduled run, a run of *Synchronize now* and one of `occ paperless_sync:run`; in earlier versions, a run of *Synchronize now* ran in the request of the administrator and deleted such files for good. [#119](https://github.com/Dennis-Otto/paperless-sync/issues/119) tracks a run whose process set up the files of another user first, which deletes them for good still: try a change with a dry-run first. Without the app *Deleted files* of Nextcloud, every deletion is for good. A copy whose document gets a new content is no deletion: it stays the same file, with the previous content as a version.

### Send to Paperless is missing from the menu of a file

Files offers *Send to Paperless* to the members of the group in *Group that may send files*, in *4 · Nextcloud inbox*, the administrators by default, while the app has its API token: an empty field or *Disconnect* takes the action away, and so does a group that you aren't a member of. Reload Files after a change. The menu leaves the action out for a file whose name has a marker such as `[P123]`, a copy of a document that Paperless has, for a file that you may not read or download, such as one of a share that allows viewing only, and in the trash bin; a click on a file of the base folder of the app, such as its inbox, answers that the runs look after it: move a file into the inbox to send it from there. After the click, the file goes with the next run of the background jobs of Nextcloud, so it waits while cron doesn't run, as [No run starts](#no-run-starts) describes.

### A file is back after I deleted or renamed it

The archive mirrors Paperless: the next run writes a copy that is missing at its path anew. A renamed or moved copy stays where you put it, as a file of your own that the app no longer updates. To keep a document out of the archive, give it a tag that the settings exclude; to remove it, delete it in Paperless.

## Begin anew

Paperless Sync remembers what it wrote for each target user. Depending on what you want to start anew:

- **Write the archive anew:** turn synchronization off, delete the archive folder in the files of the target user, which moves it to their trash bin, and start a dry-run: it lists an export for every document. Turn synchronization on again, and the runs write the archive anew, within the batch size. The copies of the deleted folder don't come back.
- **Begin a new archive:** enter another target user. The app keeps the records of each user apart, so the new archive starts empty and the old one stays as it is.
- **Remove the connection:** *Disconnect* deletes the token and turns off the schedule and the local-network setting; the other settings and the records of what it wrote stay for a later connection. The [FAQ](faq.md#how-do-i-uninstall-paperless-sync) says what uninstalling leaves behind.

A file of the inbox that Paperless refused waits until it changes: save it anew, rename or replace it to submit it again.
