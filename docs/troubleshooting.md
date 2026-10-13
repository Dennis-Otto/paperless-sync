# Troubleshooting

[← README](https://github.com/Dennis-Otto/paperless-sync) · [Guide](guide.md) · [FAQ](faq.md)

When something doesn't work, Paperless Sync says why, and this page leads from what you see to its cause and its fix. The [FAQ](faq.md) answers the questions about what the app does, such as where deleted files go. Where to look first:

- **Saving the settings fails:** the message next to the buttons, in the bar at the bottom of *Administration settings → Paperless Sync*, names the cause.
- **A run fails or ends with errors:** the status at the top of the settings shows the state of the last run, also of a scheduled one, with a box of its errors, and the [Nextcloud log](#read-the-log) has an entry of the app `paperless_sync` for each of them.
- **No run starts at all:** the [background jobs](#no-run-starts) of Nextcloud don't run.
- **A file is missing or in an unexpected place:** the report of the last run, or of a dry-run, has a line for every file that the run writes, moves or deletes.

## The way of a request to Paperless

Saving the settings with the schedule on, or with a new token, tests the connection with one request for the first document, and every run starts with the same request. Each step on its way can fail with an error of its own:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-a-request-from-nextcloud-to-paperless-and-the-error-of-each.dark.svg">
  <img src="images/diagrams/the-way-of-a-request-from-nextcloud-to-paperless-and-the-error-of-each.svg" alt="The way of a request from Nextcloud to Paperless, and the error of each step: A request of Paperless Sync passes six steps before Paperless answers with the documents. An address of the local network needs the setting Paperless runs in my local network, or Nextcloud blocks it. The name must resolve and Paperless must answer at its port, or the message has cURL error 6 or 7. A certificate of HTTPS must be one that Nextcloud trusts, or the message has cURL error 60. The URL must lead to the API of Paperless, or the answer is 404 Not Found, or a page of a login that is no JSON. The token must be valid, or the answer is 401 Unauthorized. Finally, in a run, the account must see every list that the run reads, or the answer is 403 Forbidden.">
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
| `401 Unauthorized` | The token is wrong, was deleted in Paperless, or its account is inactive. **Fix:** Create a token in Paperless and enter it again. |
| `403 Forbidden` for `/api/tags/`, `/api/correspondents/`, `/api/document_types/` or `/api/storage_paths/` | The account may see the documents, but not that list, which every run reads. Saving tests only the documents, so it succeeds. **Fix:** Give the account the permission to view that list in Paperless. |
| `Nextcloud user … does not exist.` | The target user is no Nextcloud user. **Fix:** Enter the account name, as *Accounts* in the administration lists it, not the display name. |
| `The configured Nextcloud base folder is not a writable folder.` | The base folder is a file, or a share that the target user may only read. **Fix:** Choose a folder that the target user owns. |
| `Enter the API token again to connect to another Paperless URL.` | The URL changed, and the token goes only to the URL that it was saved with. **Fix:** Enter the token with the new URL. |
| `Paperless Sync is not completely configured.` | No URL, token or target user, or the URL changed past the settings page, with `occ` for example, and waits for its token. **Fix:** Enter the missing value, or the token again, and save. |
| `Nextcloud file conflict at …` | Conflicts are skipped, and a file of someone else is in the way of the document, or someone edited its copy in Nextcloud. **Fix:** Move or delete that file, or choose **Replace synchronized file**; see the [conflict policy](guide.md#conflict-policy). |
| `EXPORT STOPPED` and `Not enough free space in Nextcloud` | The quota of the target user is full, or the archive is read-only or its storage unavailable. **Fix:** Raise the quota or free space; the next run carries on. |
| `SCAN INCOMPLETE` | A page of Paperless came back short, or documents were added or deleted while the run read them. The run counted no document as missing. **Fix:** Nothing, if the next run is complete. If it stays, look for a proxy that cuts long answers, or permissions that change. |
| `Paperless answered the upload with HTTP 403` | The account may not add documents, which the inbox needs. The upload waits and tries again later. **Fix:** Give the account the permission `documents.add_document` in Paperless. |
| `IMPORT REJECTED` with `HTTP 413` | A proxy in front of Paperless refuses large files, such as the 100 MB of the free plan of Cloudflare. **Fix:** Raise the limit of the proxy, then save the file anew to submit it again. |
| `IMPORT REJECTED` with another reason | Paperless refused the file, such as one of a type that it doesn't take. **Fix:** Convert the file, or remove it from the inbox. |
| The status says *Failed* or *Completed with errors* | The run, or single documents, failed. **Fix:** Read the box of errors below the status, then the [log](#read-the-log). |
| The next run lies in the past, or the status shows no scheduled run | The background jobs of Nextcloud don't run, or synchronization is off. **Fix:** See [No run starts](#no-run-starts). |
| Other users don't see the archive | It lies in the files of the target user alone. **Fix:** Share its folder; see [Other users don't see the archive](#other-users-dont-see-the-archive). |
| A document in the Paperless trash stays in the archive for several runs | The account doesn't own it, so the Paperless trash doesn't show it to the account. **Fix:** See [Documents of the trash come late](#documents-of-the-trash-come-late). |
| A deleted copy is not in *Deleted files* | A run started with *Synchronize now* deleted it. **Fix:** See [Deleted copies are not in the trash bin](#deleted-copies-are-not-in-the-trash-bin). |

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
3. `occ background-job:list --class 'OCA\PaperlessSync\Cron\SyncJob'` shows when Nextcloud last started the job of the app.

Nextcloud starts the job every five minutes at most, and the job starts a run when the interval of the app has passed since the last one; a dry-run doesn't count. A run that is still busy, such as the first synchronization of a large archive, holds back the next one until it ends.

### Read the log

The log has a warning when an error of a document first occurs or changes, and an error when a run fails as a whole, each with the app `paperless_sync`.

- In the browser: *Administration settings → Logging*, from the app *Log Reader*, whose column *App* names `paperless_sync`.
- On the server: `occ log:tail 50` shows the last entries, and `occ log:tail --follow` the new ones as they come. The column *App* names `paperless_sync`.
- In the file: `grep '"app":"paperless_sync"' data/nextcloud.log`, in the data directory of Nextcloud.

Nextcloud logs warnings and errors by default. To see that a run completed, with its counts, turn the level down to info for a while with `occ log:manage --level info`, and back with `occ log:manage --level warning`. The app keeps the API token of Paperless out of the log.

### Documents of the trash come late

The Paperless trash shows an account only the documents that it owns and those without an owner. A document of someone else that goes to the trash disappears for the app from both lists, so it counts as missing: its copy moves to the deleted folder only after the configured number of complete scans, below the date of that run instead of the date of the trash. With permanent deletion and *Also delete copies of documents that disappeared without passing the Paperless trash* both on, the copy is even deleted then, although the document can still be restored in Paperless.

**Fix:** Make the account of the app the owner of the documents, with a workflow for new ones and the bulk edit of permissions for the others, as the [guide](guide.md#paperless-connection) shows; both keep the access of the people who own them now. Don't make the account a superuser: it would see the whole trash, but its token controls all of Paperless.

## Files

### Other users don't see the archive

The archive is in the files of the target user alone; the app gives nobody else access. Share its folder, such as `Dokumente/Paperless/Archiv`, from *Files*, with users or with a group, and preferably read-only, since nothing there goes back to Paperless, and a run may replace an edited copy or write a deleted one anew. Share the inbox folder only with those who may send documents to Paperless: whatever reaches it goes to Paperless under the account of the app.

### Deleted copies are not in the trash bin

A copy that a scheduled run deletes, and a file in the way of a copy that moves, go to the trash bin of the target user, *Deleted files* in *Files*, where only the target user sees and restores it. A run that an administrator starts with *Synchronize now* deletes such files for good instead, which [#119](https://github.com/Dennis-Otto/paperless-sync/issues/119) tracks: until it is fixed, leave runs that may delete to the schedule, and try a change with a dry-run first. Without the app *Deleted files* of Nextcloud, every deletion is for good. A copy whose document gets a new content is no deletion: it stays the same file, with the previous content as a version.

### A file is back after I deleted or renamed it

The archive mirrors Paperless: the next run writes a copy that is missing at its path anew. A renamed or moved copy stays where you put it, as a file of your own that the app no longer updates. To keep a document out of the archive, give it a tag that the settings exclude; to remove it, delete it in Paperless.

## Begin anew

Paperless Sync remembers what it wrote for each target user. Depending on what you want to start anew:

- **Write the archive anew:** turn synchronization off, delete the archive folder in the files of the target user, which moves it to their trash bin, and start a dry-run: it lists an export for every document. Turn synchronization on again, and the runs write the archive anew, within the batch size. The copies of the deleted folder don't come back.
- **Begin a new archive:** enter another target user. The app keeps the records of each user apart, so the new archive starts empty and the old one stays as it is.
- **Remove the connection:** *Disconnect* deletes the token and turns off the schedule and the local-network setting; the other settings and the records of what it wrote stay for a later connection. The [FAQ](faq.md#how-do-i-uninstall-paperless-sync) says what uninstalling leaves behind.

A file of the inbox that Paperless refused waits until it changes: save it anew, rename or replace it to submit it again.
