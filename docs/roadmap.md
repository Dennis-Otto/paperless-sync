# Roadmap

[← README](../README.md) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

What Paperless Sync intends to do in the next twelve months, until October 2027, and what it will not do. It is a direction, not a promise. Ideas are welcome as [feature requests](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=feature_request.yml).

## The next twelve months

The app does what it was built for: a mirror of Paperless-ngx in Nextcloud and an inbox into Paperless. No larger feature is planned at the moment; the year is about keeping it working and safe:

- **Every new major version of Nextcloud.** The upstream bot raises `max-version` in `appinfo/info.xml` once the end-to-end tests pass against the new version, and the app follows the changes of Nextcloud's API.
- **Changes of the Paperless-ngx API.** A change that breaks the synchronization is fixed first, with a test that keeps it fixed.
- **Fixes and security.** Reported bugs and vulnerabilities come before anything new; [SECURITY.md](../SECURITY.md) has the times.
- **Current dependencies and tooling,** through the update bots and the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature requests with the most reactions are considered next, in that order: anything that breaks for users first, then what makes the synchronization safer, then the rest.

## Not planned

- **Two-way synchronization.** Paperless remains the source of truth; changes of the mirrored files in Nextcloud are not sent back to Paperless.
- **Access through WebDAV or with the passwords of users.** The app works through Nextcloud's file API alone.
- **Search in Nextcloud.** That is [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search), a separate app with its own releases.
- **Deletion without the guard.** Permanent deletion stays off by default and waits for the configured number of complete runs.
