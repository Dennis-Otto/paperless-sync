# The settings create the account of the app in Paperless with a token of a superuser that they use once

- Status: accepted
- Date: 2026-10-25

## Context

The app reads Paperless with the token of an account of its own, which is no superuser and has only the permissions of the modules that are on: a superuser sees the trash of every owner, but whoever obtains its token controls all of Paperless ([the security design](../security.md#residual-risks)). Setting up that account was the largest step of the setup, and it happened in Paperless: create a user, tick up to ten boxes of permissions in the rows of *Users & Groups*, sign in as that user and create its token under *My Profile*. The connection checklist ([decision 0011](0011-check-every-permission-before-a-run.md)) said afterwards which box was missing, so a first setup went back and forth between both apps (#251).

The API of Paperless does all of this in two requests: `POST /api/users/` creates a user with its permissions by their codenames, and `POST /api/token/` gives the token of a user for its username and password. Both need the token of an account that may manage users, in practice a superuser.

## Options

1. Keep the manual setup and improve its documentation. The steps stay, and so do their mistakes.
2. Ask for the token of a superuser and keep using it. One field less, but the app would hold the key to all of Paperless for good, which the design rules out.
3. Ask for the token of a superuser once, create the account of the app with it, store the token of that account, and forget the token of the superuser.
4. Ask for the password of a superuser instead of a token. A password signs in to everything that the account reaches, also the web interface, and may be shared with other services.

## Decision

Option 3, opt in, beside the manual setup, which stays for administrators who won't give the token of a superuser to Nextcloud, with commands for `curl` in the documentation that the contract tests run against a real Paperless-ngx.

- One table, `AccountPermissions`, says which permissions the modules of the settings need; the connection checklist checks the same codenames, and the README shows the table.
- `AccountSetup` creates the user, `nextcloud-sync` unless the administrator names another, with exactly these permissions, as no superuser and no staff, with a random password of 64 letters and digits that nobody learns, and gets its token. The settings are then saved with that token, as with a typed one, and the checklist runs with it.
- A user that exists already changes only when the administrator agrees: the page asks, and an update gives it exactly these permissions, no staff, and a new random password, because Paperless gives a token only for a password; its token stays the one that it had. A superuser is never taken over, not even with an update.
- The token of the superuser goes only to the Paperless URL of the form, for these requests. It is never stored, logged or answered: it is a `#[\SensitiveParameter]` wherever it passes, which a unit test checks for every parameter named after a token or a password, and the end-to-end tests look for it in every answer, the configuration of the app and the log. The setup asks for the password of the administrator first, as a save does.
- Settings that can't be saved, and a base folder in which the target user can't create files, are refused before a request to Paperless, so that Paperless gets no account for a setup that fails.
- `occ paperless_sync:create-account` does the same with the saved settings for scripted setups, with the token from a file or from standard input, never from an argument, which the list of processes and the history of the shell would show.

## Consequences

A first setup needs one token from Paperless and no visit to *Users & Groups*; the checklist that follows only names what no permission of an account can give, the access to the documents of other owners. The token of a superuser crosses Nextcloud once, in a request that the administrator confirmed with their password: whoever controls the server at that moment could catch it, as they could catch any token that the settings take. An update replaces the password of an existing user, so a person who signed in with it can't any more; the page says so before. When Paperless renames a permission, the setup and the checklist change in one place, and the contract tests against Paperless-ngx 2.x and 3.x find it.
