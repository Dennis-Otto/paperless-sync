# Security design

[← README](../README.md) · [Architecture](architecture.md) · [Roadmap](roadmap.md) · [Releases](releases.md)

What Paperless Sync protects, what it trusts and which risks remain. [SECURITY.md](../SECURITY.md) says how to report a vulnerability and how to verify a release, and argues why the repository and its releases are safe.

## What you can expect

- Only administrators of Nextcloud can see and change the settings, start a run and read its status. Every request passes Nextcloud's login and its CSRF check.
- The Paperless API token is stored in Nextcloud's credentials manager and never leaves the server: no response, page or message of the app contains it.
- Synchronization stays off until an administrator has saved a configuration that works with Paperless and Nextcloud. A dry run changes nothing.
- The app writes only below the configured base folder of the target user, through Nextcloud's file API. Every path is built from cleaned metadata and can't leave that folder.
- Nothing is deleted for good unless an administrator turns permanent deletion on; even then, only after the document was missing from Paperless and its trash for the configured number of complete runs.
- Requests to Paperless go through Nextcloud's HTTP client, which checks TLS certificates.

## What is protected

| Asset | Where it lives | Protection |
| --- | --- | --- |
| The Paperless API token | Nextcloud's credentials manager | Stored only there; the settings carry only whether a token is configured; saving without a new token keeps the stored one |
| Documents and their metadata | Paperless, and their copies in the folders of the target user | Read only with the token of a dedicated Paperless account; written only through Nextcloud's file API |
| The files of the target user | Nextcloud | Only below the base folder; files are replaced or moved only as the conflict policy allows; deletion only as configured |
| The configuration | Nextcloud's app configuration | Changed only by administrators; every value checked before it is saved |

## Trust boundaries

1. **Browser → app.** Requests pass Nextcloud's login, its CSRF check and the check of the administrator. The app checks every setting against what it may be: URLs with `http` or `https` and without credentials, a query or a fragment; numbers within their ranges; policies from a fixed list; folder names and paths without `.`, `..` or empty parts; a path template with known variables and the marker `{{ id }}`.
2. **App → Paperless.** Every answer is untrusted input. JSON must have the expected shape, document IDs must be numbers, and every part of a path is cleaned of control characters, separators and names that Windows reserves. A download goes to a temporary file first and follows at most three redirects, only to `http` or `https`.
3. **App → files of Nextcloud.** Only below the base folder of the target user, with the permissions of that user.
4. **Inbox folder → Paperless.** Every file that someone puts into the inbox folder is uploaded to Paperless as a new document, when the inbox import is on.

## Threats and countermeasures

| Threat | Countermeasure | Evidence |
| --- | --- | --- |
| Another site uses the session of an administrator | Nextcloud checks the CSRF token of every request; no route of the app opts out of it or of the check of the administrator | the controllers in `lib/Controller/`, which carry no `NoCSRFRequired`, `NoAdminRequired` or `PublicPage` attribute |
| The API token reaches the browser or a log | The token stays in the credentials manager; the settings and the status carry only whether one is configured | `tests/Unit/Service/ConfigServiceTest.php`, `tests/Unit/Settings/AdminSettingsTest.php` |
| Metadata of Paperless or a setting leads a path out of the base folder | The path template accepts only known variables; every part is cleaned and `.`, `..` and empty parts are rejected | `testRejectsTraversalAndUnknownVariables`, `testCleansComponents` and `testRelativePathMustNotContainEmptyComponents` in `tests/Unit/Service/PathTemplateServiceTest.php` |
| A malformed or hostile answer of Paperless | JSON is checked for its shape and types; a document without a numeric ID fails the run instead of writing a file | `testInvalidMetadataIsRejected` in `tests/Unit/Service/PaperlessApiServiceTest.php`, `testDocumentWithoutAValidIdFailsTheRun` in `tests/Unit/Service/SyncServiceTest.php` |
| Files are deleted by mistake | Permanent deletion is off by default; a missing document waits for the configured number of complete runs; a dry run never counts | `testMissingDocumentWaitsForTheConfiguredRuns` and `testDryRunDoesNotCountMissingRuns` in `tests/Unit/Service/SyncServiceTest.php` |
| Text from Paperless runs as script on the settings page | The template escapes every value, and the script of the page writes text only as text | `templates/settings.php`, `js/settings.js` |
| Two runs at once break the state | A lock lets only one run at a time, and the background job never runs in parallel | `testRunWhileAnotherRunIsActiveIsAConflict`, `testCronChecksEveryFiveMinutesAndNeverRunsTwice` |
| A slow or huge Paperless blocks Nextcloud | Timeouts for every request and the batch size for the changes of one run | `testBatchSizeLimitsTheChangesOfOneRun` in `tests/Unit/Service/SyncServiceTest.php` |

The Docker end-to-end tests run the app in real Nextcloud containers of every supported version against a mock of the Paperless API ([tests/e2e/README.md](../tests/e2e/README.md)).

## Residual risks

- The permissions of the Paperless account decide what the app can read and do. Use a dedicated account with only the permissions of the features you use, as the [README](../README.md#paperless-connection) describes.
- An `http` URL is allowed, for a Paperless in a trusted local network; then the token and the documents travel unencrypted. Use `https` whenever the connection leaves such a network.
- Everyone who can write to the inbox folder can add documents to Paperless while the inbox import is on. Share the inbox folder only with people who may do that.
- The mirrored files follow Nextcloud's sharing: whoever the target user shares the archive with sees the documents.
- Paperless remains the source of truth. With permanent deletion on, a document deleted in Paperless is deleted in Nextcloud too, after the grace runs. Keep independent backups.
