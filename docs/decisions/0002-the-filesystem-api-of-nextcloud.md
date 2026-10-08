# Write the archive through the filesystem API of Nextcloud

- Status: accepted
- Date: 2026-10-08

## Context

The documents of Paperless-ngx should appear in Nextcloud as files, in folders that their metadata names, and files dropped into a Nextcloud inbox should reach Paperless.

## Options

1. A client outside Nextcloud that writes over WebDAV, with the password or an app password of a user.
2. A Nextcloud app that writes through the filesystem API of the server, in the folders of one target user.

## Decision

Option 2. The app runs as a background job of Nextcloud and writes through its internal filesystem API into the folders of the configured target user, who owns the archive; other users see it through shares. The token of Paperless stays in the credentials manager of Nextcloud.

## Consequences

No password of a Nextcloud user is stored anywhere, and the files are real files of Nextcloud with versions, shares and search. The app depends on the public API of the Nextcloud versions it supports, which the end-to-end tests check from `min-version` to `max-version`.
