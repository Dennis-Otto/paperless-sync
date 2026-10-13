# Reach a Paperless in the local network per request, never for the whole server

- Status: accepted
- Date: 2026-10-13

## Context

Most self-hosted Paperless instances run next to Nextcloud, at an address of the local network: `http://paperless:8000` in Docker, `http://paperless.lan` or `http://192.168.1.20:8000`. Nextcloud's HTTP client refuses such addresses by default, against server-side request forgery, so saving the settings failed with Nextcloud's own text `Host "paperless" violates local access rules`. The only way out that people found was the system switch `allow_local_remote_servers`, which no document of the app mentioned. It turns the protection off for the whole server: for every app and for every request that a user can start, such as link previews, calendar subscriptions, federation and external storage. The end-to-end tests set the switch too, so they could not notice. The other Paperless app for Nextcloud has the same complaint open (nextcloud/integration_paperless#60).

Nextcloud's HTTP client accepts the option `nextcloud.allow_local_address` for a single request, honored by the check of the host, of every redirect and of the DNS pinning.

## Options

1. Document `allow_local_remote_servers`. Nothing to build, but every installation in a local network loses Nextcloud's protection for everything, for one app.
2. Always allow local addresses for the requests of the app. Works without a setting, but the protection is gone for the requests of the app for everyone who doesn't need it, also for a Paperless on the internet.
3. A setting of the app, *Paperless runs in my local network*, off by default, that allows local addresses for the requests of the app to the saved Paperless URL only, with the per-request option.

## Decision

Option 3. Every request of the app to Paperless goes through one method, `PaperlessApiService::send()`, which adds the option exactly when the setting is on. With the setting off, the download, whose own `allow_redirects` replaces the check of every redirect by Nextcloud's HTTP client, checks every redirect itself with Nextcloud's `IRemoteHostValidator`, so that it doesn't depend on `dns_pinning`. The test of the connection on save uses the value of the form, so that it works before it is saved. Only a clear yes turns the setting on, and saving needs the password of an administrator. When Nextcloud refuses an address, or finds no DNS record for a name that only the hosts file of the server may know, the app says what to do, translated, and the settings page moves the focus to the setting.

## Consequences

A Paperless in the local network works without weakening the server, and the end-to-end tests prove it, since they run without `allow_local_remote_servers` and check the refusal first. With the setting on, an administrator can point the app at any address of the network of the server, and Paperless can redirect the requests of the app there; the security design names this residual risk. The setting belongs to the URL that an administrator saves: a future mode in which users enter their own Paperless URLs must never apply it to those. [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) has the same need and follows in its own change.
