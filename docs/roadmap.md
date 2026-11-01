# Roadmap

[← README](https://github.com/Dennis-Otto/paperless-sync) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

What Paperless Sync intends to do in the next twelve months, until October 2027, and what it will not do. It is a direction, not a promise. Ideas are welcome as [feature requests](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=feature_request.yml).

## The next twelve months

The app does what it was built for: a mirror of Paperless-ngx in Nextcloud and an inbox into Paperless. No larger feature is planned at the moment; the year is about keeping it working and safe:

- **Every new major version of Nextcloud.** The upstream bot raises `max-version` in `appinfo/info.xml` once the end-to-end tests pass against the new version, and the app follows the changes of Nextcloud's API.
- **Changes of the Paperless-ngx API.** A change that breaks the synchronization is fixed first, with a test that keeps it fixed.
- **Fixes and security.** Reported bugs and vulnerabilities come before anything new; [SECURITY.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/SECURITY.md) has the times.
- **Current dependencies and tooling,** through the update bots and the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature requests with the most reactions are considered next, in that order: anything that breaks for users first, then what makes the synchronization safer, then the rest.

## Done

- **Each user their own documents** ([#84](https://github.com/Dennis-Otto/paperless-sync/issues/84)): with [per-user ownership](guide.md#per-user-ownership), each user of Nextcloud gets the documents that their user of Paperless owns, through the one connection of the administrator ([decision 0025](decisions/0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md)), and, if the administrator chooses it, [a copy of those that Paperless shares with them](guide.md#documents-that-paperless-shares) ([decision 0033](decisions/0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md)).

## Not planned

- **Two-way synchronization.** Paperless remains the source of truth; changes of the mirrored files in Nextcloud are not sent back to Paperless.
- **Access through WebDAV or with the passwords of users.** The app works through Nextcloud's file API alone.
- **A Paperless or a token of each user.** One administrator connects one Paperless with one token; per-user ownership gives each user the documents that they own there ([decision 0025](decisions/0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md)).
- **Search in Nextcloud.** That is [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search), a separate app with its own releases.
- **Deletion without the guard.** Permanent deletion stays off by default and waits for the configured number of complete runs.
