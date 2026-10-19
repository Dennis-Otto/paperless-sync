# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Fixed

- **Screen readers name the custom field button as it reads:** the button *custom_fields.…* under the path template now announces its visible text, with *Insert a custom field, then type its name* as its tooltip and description, so voice control finds it by what it shows.

## [1.1.0](https://github.com/Dennis-Otto/paperless-sync/compare/v1.0.0...v1.1.0) (2026-10-19)


### Features

* give every Nextcloud user the Paperless documents they own ([#230](https://github.com/Dennis-Otto/paperless-sync/issues/230)) ([db6809f](https://github.com/Dennis-Otto/paperless-sync/commit/db6809ff88fae558160ca8942e3c9b288038a356))


### Bug fixes

* a 2.x task that just failed reports its real error, not the traceback placeholder ([#227](https://github.com/Dennis-Otto/paperless-sync/issues/227)) ([4df72ac](https://github.com/Dennis-Otto/paperless-sync/commit/4df72acda8a23c0513d76077fa3116115ffb000d))

## [1.0.0](https://github.com/Dennis-Otto/paperless-sync/compare/v0.4.0...v1.0.0) (2026-10-18)

### Features

- **Send to Paperless and Open in Paperless in Files:** the menu of a file or a folder in Files, and of a selection, has *Send to Paperless*: the file goes to Paperless with the tags of the inbox, those of its subfolders below a sent folder included, and stays where it is, and a notification tells you when Paperless has it, with a link to the new document, or why Paperless didn't import it. A file whose content Paperless has already isn't uploaded again, so Paperless-ngx 3.x makes no duplicate. The uploads use the API token of the app, so only the members of *Group that may send files* in *4 · Nextcloud inbox* get the action: the administrators, until you choose another group or none; the connection checklist then tests that the account of Paperless may add documents, as a warning while the inbox is off, so a mirror whose token may not upload keeps a clean checklist (empty the group to turn the action off). Files of a share whose owner allows viewing only can't be sent, because a copy in Paperless would be a download. *Open in Paperless* opens the document of every copy of the archive, by the marker such as `[P123]` in its name, for everyone who sees the file.
- **Send to Paperless in Flow:** administrators can make rules in *Administration → Flow* that send files to Paperless on their own, such as every PDF that a member of a group uploads, or every file of a folder that has the tag *To Paperless*: the file goes to Paperless with the tags for every import of the inbox and the tags of the rule, which the form of the rule suggests from Paperless, and stays where it is. The uploads use the API token of the app, so only administrators can make such rules; the files of the folder of the app, which a run writes, and copies of the archive stay out. A rule may send many files, so the owner of a file hears only of an import that Paperless refused or that failed.
- **Metadata next to the archive:** with *Write metadata next to the archive* in *3 · Structured archive*, every run writes what Paperless knows of each document into the hidden folder `.paperless` of the archive: `P123.json` with the title, the correspondent, the document type, the tags, the notes, the custom fields, the dates and the address in Paperless, and `index.csv` with a row for every document and the path of its copy, which LibreOffice Calc opens with a double-click and Excel with *Data* → *From Text/CSV*. So the archive describes itself also without Paperless, such as in a backup of Nextcloud or on the way to another system. A sidecar is written only when its content changes, at most as many in a run as its batch size allows; its row follows its copy into the deleted folder, and the sidecar goes with the copy under the rules of deletion safety. Everyone who can open the archive folder can read the notes and custom fields there, so check its shares first. It is off until you turn it on.
- **Tags in Nextcloud:** with *Show Paperless tags as Nextcloud tags* in *3 · Structured archive*, each copy carries the tags of its document as collaborative tags of Nextcloud, so that Files filters and searches the archive by them. They follow every change in Paperless, also of a document whose copy stays as it is, every user sees them, and only administrators can assign them; a tag that someone made in Nextcloud stays as it is, and the report names a tag of Paperless whose name such a tag has already.
- **Only mirror documents with these tags:** a new field in *3 · Structured archive* turns the archive into a mirror of a part of Paperless, such as only the documents tagged `Nextcloud`. A document needs at least one of the tags, whatever the case of its name; an excluded tag and the inbox of Paperless still leave a document out. A document that loses its last tag to mirror loses its copy in the next run, within the batch size, as one with an excluded tag does, and the preview under the path template and a dry-run show which documents a new list leaves out before you save it. Every tag to mirror must be a tag of Paperless: a typo or a tag renamed in Paperless stops the export before a file changes, and says which name is missing, instead of removing the copies of the documents that should stay. Empty, as it is by default, the archive mirrors every document as before.

### Changed

- **The first stable version, 1.0.0.** The app now does what it was built for: it mirrors Paperless-ngx into Nextcloud Files as an archive by correspondent, type and year that keeps the shares, comments and versions of its files and moves a copy into the deleted folder only when Paperless has confirmed its document gone, sends the files of an inbox folder, of the menu of Files and of Flow rules to Paperless, follows the changes of Paperless within seconds through its workflow webhook, and tells how each run went in the settings, in the Activity stream, in notifications, in the setup checks of Nextcloud, with `occ` and through its status API, documented for administrators from the installation to the troubleshooting. From now on, a release that asks more of your server, such as a newer Nextcloud, PHP or Paperless-ngx, or that changes the path template variables, the occ commands of the app or its status API in a way that breaks your archive, a script or a monitor, is a new major version, so that an update within 1.x never needs more than the update itself.

### Bug fixes

- **One Paperless for the whole run:** settings that you save while a run is busy, such as the connection to another Paperless, apply from the next run. Before, the rest of the run could reach the new Paperless and record its documents as those of the old one, or stop part-way. A run also reads the API token from Nextcloud's credentials store once, instead of twice for every request to Paperless.

## [0.4.0](https://github.com/Dennis-Otto/paperless-sync/compare/v0.3.0...v0.4.0) (2026-10-17)

### Features

- Files of the inbox can bring their tags to Paperless. With *Subfolders become tags*, a file in `Inbox/Taxes/2026` gets the tags *Taxes* and *2026*, as the consume folder of Paperless does with `PAPERLESS_CONSUMER_SUBDIRS_AS_TAGS`, and *Tags for every import* go with every file. A name finds the tag of Paperless whatever its case. A name that Paperless shows the account of the app no tag of is named once in the report of the run and in the Nextcloud log, and the file goes without it; with *Create missing tags*, the run creates the tag instead, without a match, so that Paperless assigns it to no other document, and without an owner, so that every account that may view tags sees it. The report names the tags of each file, a dry-run shows the tags that a run would create, and **Test connection** checks the permissions **View** and **Add** for Tags that the tags need. All of it is off until you turn it on.
- **Synchronize now** queues the run as a background job of Nextcloud and answers at once, so a reverse proxy no longer cuts off a long run and shows its own error page. The status says *Queued* until the background jobs start the run, within five minutes with the system cron or at once with `occ background-job:worker --once 'OCA\PaperlessSync\Cron\ManualSyncJob'`, and the page follows it and then says how it ended. Like a scheduled run, it keeps the previous content of an updated copy as a version. While a dry-run or another run is active, it waits for the next background jobs.
- **Command line:** `occ paperless_sync:run` runs a synchronization or, with `--dry-run`, lists its changes, in the shell and as long as it takes; `--batch-size=N` or `--unlimited` changes the batch size for this run, such as for the first import of a large archive. `occ paperless_sync:status` shows the status of the last run, and `occ paperless_sync:forget <ID>…` or `--all` makes the next runs download documents again and write each in place of its copy. Every command prints text or, with `--output=json`, JSON, and tells by its exit code how it ended: 0 completed, 1 with errors of single documents, 2 failed, 3 another run is active.
- A run that ended without saying how, because PHP stopped it, such as on a restart of the server, shows as *Interrupted* instead of *Running* forever, once its lock is gone and it gave no sign of life for 15 minutes. A run that takes longer than Nextcloud keeps its lock, an hour by default, writes a sign of life every minute, so no scheduled run or *Synchronize now* starts beside it.
- The settings offer the users of Nextcloud for the target user, and the tags of Paperless for the excluded tags and the tags for every import, so that you pick them instead of typing them. A user ID that Nextcloud doesn't know is marked as soon as you leave the field, a disabled account says *disabled*, and a tag that Paperless doesn't have is marked *not in Paperless*, so a typo shows before a run instead of as documents that are exported anyway or imports without their tag. A tag that Paperless doesn't have yet can still be typed, ended with Enter or a comma. The tags come from Paperless with the saved URL and token, which stays on the server.
- The report of a run reads in your language instead of a block of English codes: every change has a colored badge of its kind, such as *Export*, *Trash* or *Refused*, with its document, its file and what happens to it; the errors come first, then the notices, such as an incomplete scan of Paperless; a filter above the list shows the changes of one kind. Only the counts of changes that happened show, and a sentence such as *11 unchanged, 1 skipped* the rest; a dry-run says *Dry-run — nothing was changed* in its heading. When you are the target user, a click on the path of a file opens its folder in Files, and **Copy report** copies the report, with its lines in English, for a bug report.
- **Changes within seconds:** a workflow of Paperless can call the webhook of the app when it adds or changes a document, and the copy of that document is exported, moved or removed within seconds instead of with the next scheduled run, by the same rules as a run. Section 6 of the settings creates its secret, which only Paperless needs, shows the webhook URL and the last call, and lists the steps in Paperless; calls without the secret are refused and slowed down by Nextcloud.

## [0.3.0](https://github.com/Dennis-Otto/paperless-sync/compare/v0.2.0...v0.3.0) (2026-10-17)

### Features

- While you type a path template, the settings show where the five newest documents of Paperless would go, a moment after you stop and before you save, with the fallback names and the switches of the page and a note for a document that a run would skip. Buttons under the field put a variable in at the cursor, the field shows the template in a font for code, and an invalid template says why while you type.
- **Run dry-run** tries the changes of the form before you save them, and its report says *Dry-run of unsaved settings*; nothing is saved. It keeps the saved connection to Paperless and the saved target user, which only a save tests and confirms with your password, and asks you to save first when they changed.
- New path template variables, with the names that Paperless-ngx uses for its own file names: `{{ asn }}`, `{{ page_count }}`, `{{ owner_username }}`, `{{ tag_list }}`, `{{ created_day }}`, `{{ created_month_name }}`, in the language of Nextcloud, `{{ created_year_short }}`, `{{ added_month }}`, `{{ added_day }}`, and `{{ custom_fields.<name> }}` for the value of a custom field, with the label of the choice of a select field. The app reads the users and the custom fields of Paperless only for a template that uses them, and a custom field that Paperless doesn't have stops the run before a file moves.
- **Test connection** in the settings checks every Paperless permission that the enabled modules need, without saving, and lists the result below the connection settings: whether Paperless answers at the URL, in which version, and takes the token, how many documents the account sees, whether it may view the trash, the correspondents, document types, storage paths and tags for the export, whether it owns the documents that it sees, which the trash of Paperless shows it only then, whether it may add documents and view the tasks that import them for the inbox, and whether the target user may create files in the base folder. Every check that fails says what to grant or fix, such as the permission **View** for Tags under *Users & Groups* in Paperless, a certificate that Nextcloud doesn't trust, or a URL that answers with the login page of a proxy. Saving runs the same checks: it refuses settings with which no run can work, and saves a permission that only one module lacks with the checklist, so that you can grant it afterwards. Before, saving tested only the list of documents and said *Connection successful*, so a token without the permission for the tags passed, and every scheduled run failed afterwards with `HTTP 403`.
- The checklist and a dry-run explain that Paperless lists in its trash only the documents that the account of the app owns and those without an owner: a dry-run says `TRASH EMPTY` when documents go missing while the trash lists none, because such documents of other owners reach the deleted folder only after the missing scans. The README lists the exact permissions that the account needs, including **View** for Paperless Tasks for the inbox, without which an uploaded file stayed in the inbox for good, and **View** for UI Settings for the checklist.
- The **Activity** stream of the target user shows what Paperless Sync did, also in a scheduled run: one entry for each run that changed something, such as *Paperless Sync added or updated 6 documents and moved 1 document to _Gelöscht: a.pdf, b.pdf, c.pdf, d.pdf, e.pdf and 2 more*, with links to the first five files, and one entry for each file of the inbox that Paperless imported, with a link to its new document in Paperless, failed to import, with the reason and a link to the file in the error folder, or refused. The filter **Paperless Sync** of the Activity app shows them alone, and the activity settings can send them by mail or as a notification. Before, a run by hand left no entry, and a scheduled run left only entries of a *remote account* that didn't name Paperless. A dry-run and a run without changes add no entry.
- A new installation proposes the names of its folders and the fallback names for missing metadata in the default language of Nextcloud: `Documents/Paperless`, `Archive`, `Inbox`, `Failed imports`, `_Deleted`, `_No correspondent`, `_No document type`, `_No date` and `Untitled` in English and in every language without a translation yet, and the German names as before on a German Nextcloud, also one set to a regional variant such as `de_DE`. Before, every Nextcloud got the German names, such as `Dokumente/Paperless/Archiv`, in the files of its users. An installation that an earlier version set up keeps the German names with the update, also those that it never saved, so no folder moves; the README lists both sets under *Folder names*.
- **Notifications of a failing mirror:** when three runs in a row fail or complete with errors, or the first of them ended a day ago, every administrator gets one notification with the error and a link to the settings, and the next successful run removes it. The group `admin` gets it unless `occ config:app:set paperless_sync notification_group` names another. The target user gets a notification for each file of the inbox that Paperless could not import or refused. A dry-run notifies nobody.
- **Setup checks:** *Administration → Overview* and `occ setupchecks` show whether the mirror works, with an error when the last run failed and a warning when it completed with errors or no run succeeded for three intervals, and warn when scheduled runs are on but Nextcloud's background jobs don't run with cron. The settings page shows the same warnings on top.
- **Status API for monitors:** administrators, and monitors such as Uptime Kuma or Home Assistant with an app password, read the health of the mirror, the last run and its error at `/ocs/v2.php/apps/paperless_sync/api/v1/status`.

### Bug fixes

- The settings say in German what is wrong with a setting when Nextcloud is set to German: a Paperless URL that is no URL, a number outside its limits, an invalid path template, a target user that Nextcloud doesn't know or a base folder in which the user may not create files, another run that is still active, a run before the setup is complete, and a test of the connection that failed. Before, these messages were English on a German page, also half English in the connection checklist. A message about a setting names it by its label on the page and moves the focus to it.
- The setting *Required consecutive missing scans* is called *Erforderliche aufeinanderfolgende Läufe ohne das Dokument* in German. Before, it was called *Fehlläufe*, failed runs, which it doesn't count.
- The times on the settings page, of the last run and the next one, follow the locale that you chose in the personal settings of Nextcloud, not the language of the browser.
- A copy whose document went to the trash of Paperless moves into the folder of the day of the deletion in the time zone of the target user, or of Nextcloud. Before, a document trashed in Germany shortly after midnight landed in the folder of the day before, the day in UTC.
- The example of the excluded tags is German on a German page.

### Performance

- Every run asks Paperless only for the fields of the documents that it needs, without the OCR text, the notes and the permissions of each document. Before, every run, every 5 minutes by default, received the whole text of the whole archive: about 4 MB for 1,000 documents with 4 KB of text each. The live path preview of the settings asks for the same fields, so it shows the paths that a run computes.
- A run asks Paperless for the checksum of a changed document once for each revision. Before, documents that waited for a later run because the batch was full asked again in every run: after a change of 50 titles with a batch of 5, 275 requests, each of which makes Paperless read the files of the document, instead of 50.
- A run over an archive without changes no longer writes to the database: it reads the state of all exported documents at once and writes only what changes. Before, a run with 10,000 unchanged documents ran 30,000 statements, every 5 minutes.
- The settings and the status of the app are lazy app configuration: Nextcloud loads them when the app needs them, not with every request of every user. The update converts the stored values.

## [0.2.0](https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.10...v0.2.0) (2026-10-14)

### Security

- A run that can't reach Paperless, because Paperless is down or its name or certificate fails, no longer writes the Paperless API token into the Nextcloud log. Before, the log received the token in plain text with the trace of the error, with every failed run by hand or by the schedule, and so did everyone who reads the log or receives a copy of it. **Every earlier version is affected: create a new API token in Paperless, enter it in the settings, and delete the old Nextcloud logs and their copies, such as in backups or log collectors.**
- The API token goes only to the Paperless URL that it was saved with, so another URL needs the token again. Before, saving without a token sent the stored token to the new URL to test the connection, so whoever could save the settings, with the session of an administrator for example, could receive it at a server of their own. The settings page asks for the token as soon as the URL changes. A URL changed past the settings page, with `occ` or Nextcloud's provisioning API, doesn't get the token either: synchronization pauses until the token is entered again. The update keeps the stored token for the URL that is configured.
- Saving the settings and disconnecting Paperless ask for the password of the administrator, as Nextcloud does for its own sensitive settings, unless it was confirmed in the last 30 minutes.
- A Paperless at an address of your network, such as `http://paperless:8000` in Docker or `http://192.168.1.20:8000`, works with the new setting **Paperless runs in my local network**, which allows such an address only for the requests of Paperless Sync to the saved Paperless URL. Before, saving failed with `Host "paperless" violates local access rules`, and the only way out was the switch `allow_local_remote_servers`, which turns off Nextcloud's protection against server-side request forgery for every app and every user. Saving and a run by hand now say what to do instead, also for a name that only the hosts file of the server knows, and the settings page moves the focus to the setting. While the setting is off, a download that Paperless redirects to an address of your network is refused, also when Nextcloud's `dns_pinning` is turned off. **If you turned that switch on for this app, turn the setting on and save, then turn the switch off again: `occ config:system:delete allow_local_remote_servers`.**

### Features

- The settings page shows how the last run ended at every visit, also a scheduled run that nobody watched: its state, in red when it failed or had errors, when it finished, the last successful run, the next scheduled run, the errors of the run in a box of their own, and its report. While a run is active, the page follows it until it ends.
- The Nextcloud log has a warning, with the ID and the path of the document, when an error of a document first occurs or changes. An error that comes back in every run is logged once.

### Bug fixes

- On Paperless-ngx 3.x, the `.error.txt` of a failed import of the inbox has the reason of Paperless again, such as a file type that it doesn't take, and the ID of the Paperless task. Before, it said only *Paperless import failed*: Paperless-ngx 3.x answers in version 10 of its API by default, whose shape the app didn't read.
- A file of the inbox whose task Paperless cancelled, or has not finished a day after the upload, no longer waits in the inbox for good. A cancelled task, `REVOKED` in the task list of Paperless, moves the file to the error folder with that reason. A day after the upload, a file whose content Paperless has, found by its checksum, counts as imported. A task that Paperless still lists as pending or started moves the file to the error folder, with a note that the document may still appear, because Paperless-ngx 3.x would import a second upload as a duplicate. A task that Paperless no longer lists, as after a restart of its queue, sends the file up again after 15 minutes, with an error in the report and a warning in the Nextcloud log.
- Every request asks for version 9 of the Paperless API, so that a new default of Paperless can't change the answers that the app reads. The settings page names the Paperless that the last run reached, such as *The last run reached Paperless-ngx 3.3.0 through version 9 of its API.*, and the report names the document that a successful import created, such as `IMPORT SUCCESS: Dokumente/Paperless/Eingang/police.pdf -> P456`. **Paperless Sync now needs Paperless-ngx 2.16 or newer, the first version with version 9 of the API; with an older Paperless, saving and every run say so.**
- A new content of a document, such as a new archive version after Paperless processed it again, updates its file in Nextcloud in place, so the file keeps its shares and public links, comments, tags and favorites, and in a scheduled run its previous content becomes a version of the file. Before, the app deleted the file and wrote a new one with a new file ID: shares and public links of the file broke, its comments, tags and favorites were lost, the old copy filled the trash bin of the target user, and the desktop and mobile clients downloaded it as a new file. A document whose content and path change moves first and is updated at its new path.
- A document that Paperless restores from its trash, or lists again after it was missing, keeps the copy that the trash behavior *Keep archive file in place* left in place, without a download. Before, the run downloaded it and wrote it again.
- With the conflict policy *Skip and report conflict*, the app updates its own copies: a document whose content changed while its path stayed the same, or which came back after it was missing, is no longer reported as a conflict in every run while its copy stays outdated. A file that someone else put at the path, and a copy that someone changed in Nextcloud, also one whose document gets a new path, are still left alone and reported as a conflict.
- You can pause scheduled synchronization while Paperless is down, its token revoked or the target user disabled: uncheck **Enable scheduled synchronization** and save. Saving tests the connection only when the schedule is on or a new token is entered, and says so when it didn't. Before, every save had to pass the test, so the only ways to stop a schedule that failed every few minutes were **Disconnect**, which deleted every setting, and `occ`.
- **Disconnect** deletes the API token and turns off scheduled synchronization and **Paperless runs in my local network**, and keeps the other settings, also your deletion policy, as its question says. Before, it deleted every setting while the page went on showing the old values.
- The settings page always shows what Nextcloud stored, also after a save or a disconnect, such as the URL without a slash at its end, and says when the form has changes that aren't saved, also changes that you make while a save is in progress. **Run dry-run** and **Synchronize now** use the saved settings, so **Synchronize now** waits until you save, and the browser asks before you leave the page with unsaved changes. Before, a run could apply settings that the page didn't show.
- Results and errors appear next to the buttons, which stay at the bottom of the window, and an error takes the focus. Before, they appeared below the long form, out of view. The page no longer shows the HTML of a proxy, such as its *504 Gateway Time-out* during a long run: while the status shows the run, it says that the run continues and follows it there. A lost connection and an expired session are explained in your language, a run with errors says how many, and a failed refresh of the status no longer replaces the result of the run.
- The save button says **Save** when saving doesn't test the connection, with the schedule off and no new token, and **Test connection and save** when it does. An error that names a setting, such as **Enable scheduled synchronization** after a failed test, scrolls that setting into view, where the buttons at the bottom of the window no longer hide it. The hint to turn off the schedule is in German for German administrators. When neither a run nor the status answers, the status no longer shows the run as running for good, and the message asks you to refresh it.
- The questions before a run and before **Disconnect**, and the other messages of the settings page itself, are in German for German administrators.
- A dry-run no longer pushes back the next scheduled run: the interval counts from the last real run, also one started with **Synchronize now**. Before, a dry-run at 10:00 with an interval of an hour moved the next run to 11:00. Only a dry-run that is still running holds the next run back, until it ends.
- The interval is at least 5 minutes, as a hint below it explains: Nextcloud starts background jobs every 5 minutes at most. Before, the settings accepted 1 to 4 minutes, which acted as 5. An interval below 5 minutes that you saved before acts as 5 minutes, as it did, and the settings show it as such.
- **Disconnect** sits at the right end of the bar of buttons, away from **Synchronize now**, and every checkbox sits next to the first line of its label. Before, the styles of Nextcloud put **Disconnect** right after **Synchronize now** and pushed every checkbox below its label. The titles of the sections no longer wrap next to their description, and each section links to its part of the guide.
- The settings of deletion say in plain words what they do: turn on permanent deletion only after a few runs with deletion off looked right, and *Also delete copies of documents that disappeared without passing the Paperless trash*, which replaces *Also allow deletion when the app never observed the Paperless trash state*.
- On Paperless-ngx 2.x, a change of the title, tags, correspondent or type of a document moves its file to the new path, as on Paperless-ngx 3, instead of downloading the document again and replacing the file. Before, the app only recognized the SHA-256 checksums of Paperless-ngx 3, so on 2.x every such change gave the file a new ID in Nextcloud, which lost its shares, comments and favorites, left the old copy in the trash bin and made the desktop and mobile clients download it again. The app now keeps the SHA-256 and the MD5 of every download. For a file that an earlier version exported, the first such change reads the file in Nextcloud once to add its MD5, instead of downloading the document again, also when its move waits for a later run because the batch is full or the quota is reached.
- A large document no longer ends the run with a fatal error of PHP's `memory_limit`. Before, the app read every download into memory to compute its checksum, so a document larger than the free memory stopped the run before it saved its state, the status stayed *running*, and every following run died on the same document. Downloads now go from a temporary file to Nextcloud in chunks.
- The report of a run with more than 100 lines lists its errors, deletions, removals of excluded copies and moves to the deleted folder first and says how many lines it leaves out. Before, it dropped every line after the hundredth without a word, so that a dry-run with many files in the inbox could hide the deletions that the run would make.
- A run that fails no longer counts as the last completed run; the status shows its end as *Last finished*.
- A run no longer counts a document as missing when Paperless answered a page of its list incompletely, such as an empty page from a proxy, or when documents were added or deleted while the run read the pages. The report says `SCAN INCOMPLETE`, the Nextcloud log has a warning, and the next complete scan carries on. Before, a document that still existed in Paperless could move to the deleted folder, or be deleted when direct deletion is on.
- A document that Paperless lists again starts its count of missing scans anew, also when its export fails or waits for the next run, so that only scans in a row count.
- Every export that a run tries counts against the batch size, also one that fails, and a document whose export failed waits behind the others. Before, a run that couldn't write, against a full quota or a read-only storage for example, downloaded every pending document again in every run and failed on each one. Failed exports don't take the places of moves of trashed or missing documents, and a move or deletion that fails takes its place in the batch too.
- Exports respect the quota of the target user. Nextcloud applies no quota to the temporary file of a write, so before, an export could fill the quota beyond its limit. When the quota is full, or the archive is read-only or its storage unavailable, a run stops exporting and moving at the first failure and reports `EXPORT STOPPED`; the other documents follow in the next run. A file name that Nextcloud refuses concerns only its document.
- A run exports the most recently added documents first, also a newly scanned letter with an old date, so that it doesn't wait behind a backlog such as the first export of a large archive.
- A run fails, before it changes anything in the archive, when Paperless answers its list of tags, correspondents, document types or storage paths incompletely. Before, a document with an excluded tag could be exported when the tag was missing from a short page.
- The report keeps the lines `SCAN INCOMPLETE` and `EXPORT STOPPED` also after a hundred other lines.
- With the conflict policy *Skip and report conflict*, a file in the way is reported before the document is downloaded, and a dry-run reports the conflict too. Before, the run downloaded the document in vain.
- A `$` in a title or a name stays in the path of the file: "Refund $100 from Shop" no longer becomes "Refund 0 from Shop", and "Tarif $0 Basis" no longer takes in a piece of the template. A title that contains a variable, such as `{{ correspondent }}`, stays as it is too.
- Documents with a long title or name in Cyrillic, Chinese, emoji or another script of several bytes per character reach Nextcloud again. Names are shortened to the bytes that Nextcloud accepts, with room for the temporary name of a write. Before, such a document failed in every run.
- A long title, correspondent or original file name no longer cuts the marker `[P123]` and the extension off a file name. Before, two such documents could get the same path, and with *Replace synchronized file* the second one replaced the file of the first. Now only the free text is shortened, the longest first, and a template that leaves out the marker or the extension gets them added at the end of the file name.
- The path template accepts variables without spaces, such as `{{id}}` for `{{ id }}`, and the message about an unknown variable names it as `{{ owner }}`.
- Control characters beyond ASCII and invalid bytes become `_` in names too, and names of Windows devices such as `COM0`, `LPT¹` or `CONIN$` get a leading `_` as well.
- The next run moves the files whose names these fixes change once to their corrected path. Two documents that shared one file are downloaded again instead, so that each gets its own content back.

## [0.1.10](https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.9...v0.1.10) (2026-10-08)

### Bug fixes

- Files of the Nextcloud inbox reach Paperless also when Nextcloud can't tell their size beforehand, as for files on object storage such as S3. Before, the upload had no `Content-Length`, Paperless refused it with `No file was submitted.`, and the app uploaded every file of the inbox again in every run.
- A file that Paperless refuses, such as one of an unsupported type, an empty one or one too large for a proxy in front of Paperless, stays in the inbox and is skipped until it changes. The report of the run and the Nextcloud log name the reason of Paperless once. Before, the app uploaded the file again in every run.
- When Paperless can't be reached or fails with a server error, a run uploads no further files of the inbox, and the file is tried again after 15 minutes, then after twice as long each time, at most once a day. Before, every run uploaded every file of the inbox again.

## [0.1.9](https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.8...v0.1.9) (2026-10-08)

### Bug fixes

- The report of a run by hand lists every file that the run wrote, moved or deleted and every import, as the report of a dry-run does. Before, it counted the changes but said "No file changes were required." below the counts.
- The status in the settings names the state of the last run in the language of the user, such as "Completed" or "Abgeschlossen". Before, it showed an internal name such as `completed` or `never-run`, also in German.

## [0.1.8](https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.7...v0.1.8) (2026-10-08)

### Bug fixes

- The list of file changes in the report of a run can be scrolled with the keyboard and shows the focus, so that the long report of a large archive is readable without a mouse; screen readers name it "File changes of the run".

## [0.1.7](https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.6...v0.1.7) (2026-10-07)

### Bug fixes

- The copy of a document that disappeared from Paperless now moves to the deleted folder after the confirmation runs also when a run reaches its batch size first: it waits for the next run. Before, it stayed where it was for good.
- *Moved to trash* and *Deleted* in the summary of a run count only copies that were really moved or deleted, no longer documents whose copy was already gone.
- A copy of a missing document that cannot be moved or deleted is reported as an error of that document, and the run carries on with the others. Before, the whole run failed.
- A document in the Paperless trash whose copy is already gone no longer counts as *Moved to trash* in every run, and the path recorded for its copy no longer grows by one more deleted folder and date in each run.
- A document in the Paperless trash that was never exported no longer causes an error in every run.
- With the trash behavior *Keep archive file in place*, a dry run no longer reports and counts a move to the trash that the real run doesn't make.

## 0.1.6 - 2026-10-07

### Fixed

- Saving, resetting, running and refreshing the administration settings no longer fails with empty request URLs when Nextcloud renders the settings page before it has loaded the app (the same cause as [paperless-unified-search#25](https://github.com/Dennis-Otto/paperless-unified-search/issues/25)). The app now declares its routes as attributes on the controllers, which Nextcloud 29 and later register for every enabled app.

<!-- Release notes generated using configuration in .github/release.yml at f579d34122588298decbbd1ad82ca996e0b00a26 -->

### What's Changed
### Fixes
* fix: register the routes as attributes by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/34
### Other changes
* ci: look after issues with the issue assistant by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/33


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.5...v0.1.6

## 0.1.5 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at 0215bf94461794ed1f345e856d14bb1946cbd5cf -->

### What's Changed
### Dependencies
* chore(deps): Bump anchore/sbom-action from 0.24.2 to 0.24.3 in the actions-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/30
* chore(deps): Bump nextcloud from 33.0.9-apache to 33.0.9-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/31


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.4...v0.1.5

## 0.1.4 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at 603966a71d3d95695c17c4b8e589444bab7f9fd8 -->

### What's Changed
### Dependencies
* chore(deps-dev): Bump vimeo/psalm from 6.18.1 to 6.19.1 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/28


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.3...v0.1.4

## 0.1.3 - 2026-09-28

<!-- Release notes generated using configuration in .github/release.yml at 2f7471bc9b6964221eefe90c1b076351081b9b6b -->

### What's Changed
### Dependencies
* chore(deps-dev): Bump vimeo/psalm from 6.17.2 to 6.18.1 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/23
* chore(deps): Bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/24
* chore(deps): Bump nextcloud from 33.0.9-apache to 33.0.9-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/26


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.2...v0.1.3

## 0.1.2 - 2026-09-21

<!-- Release notes generated using configuration in .github/release.yml at eb19bcabfbee4d333095f5cee134a20c2d3ee84c -->

### What's Changed
### Dependencies
* chore(deps-dev): Bump vimeo/psalm from 6.17.0 to 6.17.2 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/20
* chore(deps): Bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/21
### Other changes
* ci: pin Dependabot auto-merge to the inspected commit by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/19


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.1...v0.1.2

## 0.1.1 - 2026-09-19

- Publish checked, signed Nextcloud maintenance releases automatically after merged Dependabot updates.
- Generate categorized release notes, with an optional maintainer introduction for manual releases.
- Require successful PR and main checks on the exact release commit, without cancelling other CI runs.
- Resume interrupted GitHub and App Store publication without duplicate versions or replacing public assets.

- Publish the configured app version unchanged for the first GitHub release.
- Move release commits before reproducible packaging while keeping commit and tag publication atomic.
- Pin checkout, PHP setup, and Gitleaks actions to immutable Node 24-compatible revisions.
- Verify DCO sign-offs, translations, dependencies, app metadata, and production-package boundaries automatically.
- Validate unsigned and signed release archives and exclude development-only files from packages.
- Add reproducible Docker end-to-end coverage for Nextcloud 33 and 34 with a deterministic Paperless API mock.
- Exercise dry-run, export, metadata moves, exclusions, trash, guarded deletion, inbox success and failure, pruning, permissions, and background-job registration.
- Expand contribution, security, testing, release, and manual acceptance documentation.
- Protect `main` behind required CI, E2E, secret-scan, linear-history, and pull-request rules, including release version commits.
- Add weekly grouped Dependabot updates with protected automatic squash merges for patch and minor changes while keeping major updates subject to maintainer approval.
- Add CodeQL analysis and continuous SPDX SBOM generation.
- Publish detached signatures, SBOMs, and public Sigstore provenance with releases.
- Document project governance, conduct, and support.

<!-- Release notes generated using configuration in .github/release.yml at 5ce005ddfe9a014e551056f4192fc986de9862ca -->

### What's Changed
### Dependencies
* chore(deps): Bump nextcloud from 33.0.8-apache to 33.0.8-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/12
* chore(deps-dev): Bump the composer-routine group with 2 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/14
* chore(deps): Bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/15
* chore(deps): Bump nextcloud from 33.0.8-apache to 33.0.9-apache in /tests/e2e in the containers-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/16
* chore(deps): Bump python from 3.14-alpine to 3.14-alpine in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-sync/pull/13
### Other changes
* ci: automate protected dependency releases and recover publication by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-sync/pull/17


**Full Changelog**: https://github.com/Dennis-Otto/paperless-sync/compare/v0.1.0...v0.1.1

## 0.1.0 - 2026-08-26

- Add configurable one-way Paperless archive synchronization using Nextcloud's native filesystem API.
- Add optional Nextcloud inbox import with Paperless task tracking and error handling.
- Add metadata-aware moves, configurable path templates, Paperless trash mirroring, optional permanent deletion, and empty-folder cleanup.
- Add dry-run support, synchronization status, safe defaults, persistent database state, and native background jobs.
- Add automated testing, secret scanning, semantic versioning, signed packaging, and App Store release workflows.
