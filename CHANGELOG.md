# Changelog

All notable changes to this project are documented in this file.

## Unreleased

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
