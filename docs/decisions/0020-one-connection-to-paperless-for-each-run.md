# One connection to Paperless for each run

- Status: accepted
- Date: 2026-10-18

## Context

The client of Paperless read the settings and the API token again for every request: each of its methods asked `ConfigService` for the URL, the local network and the token ([#183](https://github.com/Dennis-Otto/paperless-sync/issues/183)). Each read of the token is a query of Nextcloud's credentials store and a decryption, and reading the settings read the token as well, so every download, checksum, upload and page of a list decrypted it twice: fourteen times for an empty Paperless, thousands of times for the first export of a large archive.

A run also kept its own copy of the settings, from its start, while the client took whatever was stored at the moment of each request. When an administrator saved another URL and token while a run was busy, the rest of the run reached the new Paperless and recorded its IDs as those of the old one, which the state of the exports and the deletion guards ([0004](0004-delete-only-what-is-confirmed.md)) take as the truth. Or the cache of the app configuration of a long-running background job still held the old URL, the stored token no longer matched it, and the run stopped part-way.

And a client that can only reach the one stored connection is the main obstacle in the code to a run for another connection, such as one Paperless for each user.

## Options

1. Keep the client as it was, and cache the token in `ConfigService` for the length of a request. Fewer decryptions, but a long run would still mix two Paperless, and the client would still know the settings.
2. A client for each connection: a value `PaperlessConnection` with the URL, the token and the local network, and a factory that makes a client for it. A run reads its settings and its connection together, once, at its start, and passes its client to the inbox and the export.
3. Let the run pass the URL and the token to every method of the client. As consistent as option 2, but every call and every test double carries the token, and each new method is one more parameter that must be marked `#[\SensitiveParameter]`.

## Decision

Option 2. `ConfigService::snapshot()` reads the settings with one decryption of the token, and the token joins the connection only for the URL that it was saved with, as before. `PaperlessClientFactory::connect()` makes a `PaperlessApiService` for that connection alone, which no longer depends on `ConfigService`; `SyncService::run()`, the run of one document and the preview of the path template take one client each, and the settings page takes one for the connection checklist and one for the tags. The API version stays a constant of the client ([0012](0012-ask-for-version-9-of-the-paperless-api.md)), not a part of the connection: one app speaks one version.

The token is a private property of the connection: Nextcloud's log writes the public properties of every object in the trace of an error, and `var_dump()` and `print_r()` show it as `[redacted]`.

## Consequences

A run decrypts the token once. Settings that an administrator saves during a run reach the next run, never the rest of this one, and a token never goes to a URL that it wasn't saved with, also not for a moment. A test proves both against the real client: a save after the first request of a run leaves every later request at the first Paperless, with its token.

A run for another connection, such as for another user of Nextcloud with a Paperless of their own, now needs only a connection of its own; the state, the lock and the status still belong to the one global configuration. A dry-run of unsaved settings takes the saved connection, as [0014](0014-try-unsaved-settings-with-the-saved-connection.md) decided, now through `ConfigService::connection()`.
