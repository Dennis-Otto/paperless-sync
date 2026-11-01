# Roadmap

[← README](https://github.com/Dennis-Otto/paperless-sync) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

What Paperless Sync intends to do in the next twelve months, until October 2027, and what it will not do. It is a direction, not a promise. Ideas are welcome as [feature requests](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=feature_request.yml).

## The next twelve months

The app does what it was built for: a mirror of Paperless-ngx in Nextcloud and an inbox into Paperless. Two larger pieces of work are planned:

- **More languages** ([#316](https://github.com/Dennis-Otto/paperless-sync/issues/316)). French and Spanish after English and German, with the default names of the folders in each, and a check that keeps every language complete, so that a translation by a native speaker is easy to add. The website stays in English and German.
- **A demo to try it.** A Docker setup that starts Nextcloud, Paperless-ngx and the app with sample documents, on ports of your own machine only, so that you can look at the app without touching your own Nextcloud or Paperless.

Beyond these, the year is about keeping it working and safe:

- **Every new major version of Nextcloud.** The upstream bot raises `max-version` in `appinfo/info.xml` once the end-to-end tests pass against the new version, and the app follows the changes of Nextcloud's API.
- **Changes of the Paperless-ngx API.** A change that breaks the synchronization is fixed first, with a test that keeps it fixed.
- **Fixes and security.** Reported bugs and vulnerabilities come before anything new; [SECURITY.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/SECURITY.md) has the times.
- **Current dependencies and tooling,** through the update bots and the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature requests with the most reactions are considered next, in that order: anything that breaks for users first, then what makes the synchronization safer, then the rest.

## Done

The larger pieces of work of the recent releases, and those that `main` holds for the next one; the [changelog](changelog.md) has every change.

- **More ways into Paperless:** [*Send to Paperless*](guide.md#send-from-files) in the menu of Files and [rules of Flow](guide.md#send-by-a-rule-of-flow).
- **Changes within seconds:** the [webhook](guide.md#changes-within-seconds) of a workflow of Paperless instead of the next interval.
- **An archive that describes itself:** [tags of Paperless as tags of Nextcloud](guide.md#tags-in-nextcloud) and [metadata next to the archive](guide.md#metadata-next-to-the-archive).
- **Monitoring and scripts:** [notifications, setup checks and a status API](guide.md#being-notified), for Uptime Kuma or Home Assistant too, and [`occ` commands](guide.md#command-line).
- **A first run that goes well:** [*Getting started*](guide.md#getting-started-in-the-settings) in the settings and [the account of the app](guide.md#the-account-of-the-app) in one step.
- **For the people who use and run it:** [a page for those who use the archive](use.md), [a note in Files](use.md#what-edits-renames-and-deletions-do) that says what a folder of the app does, and [settings delegated to a group](guide.md#delegating-the-settings).
- **Each user their own documents** ([#84](https://github.com/Dennis-Otto/paperless-sync/issues/84)): with [per-user ownership](guide.md#per-user-ownership), each user of Nextcloud gets the documents that their user of Paperless owns, through the one connection of the administrator ([decision 0025](decisions/0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md)), and, if the administrator chooses it, [a copy of those that Paperless shares with them](guide.md#documents-that-paperless-shares) ([decision 0033](decisions/0033-each-user-also-gets-a-copy-of-what-paperless-shares-with-them.md)).

## Not planned

- **Two-way synchronization.** Paperless remains the source of truth; changes of the mirrored files in Nextcloud are not sent back to Paperless.
- **Access through WebDAV or with the passwords of users.** The app works through Nextcloud's file API alone.
- **A Paperless or a token of each user.** One administrator connects one Paperless with one token; per-user ownership gives each user the documents that they own there ([decision 0025](decisions/0025-each-user-gets-the-documents-they-own-through-one-account-of-the-app.md)).
- **Search in Nextcloud.** That is [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search), a separate app with its own releases.
- **Deletion without the guard.** Permanent deletion stays off by default and waits for the configured number of complete runs.
