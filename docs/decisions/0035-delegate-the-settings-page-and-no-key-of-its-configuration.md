# Delegate the settings page, and no key of its configuration

- Status: accepted
- Date: 2026-11-01

## Context

Nextcloud's *Administration privileges* offers a page for delegation to a group when its settings class implements `IDelegatedSettings` and its routes carry `#[AuthorizedAdminSetting]`. In households and small offices, the person who runs Paperless is often no administrator of Nextcloud, and Paperless Sync wasn't offered there.

`IDelegatedSettings` asks two more things of the app, and the page itself asks a third:

- `getAuthorizedAppConfig()` names the keys of the app configuration that the group may write. Nextcloud's `AppConfigController` of the provisioning API then writes them for a member, as they are: the checks of `ConfigService`, such as paths without `..` and numbers within their ranges, don't run.
- `getName()` names the setting within its section, or nothing.
- The pickers of the target user and of the groups asked the provisioning API, which answers only for administrators. For a member, it answers an empty list of users, 403 for the groups and, worst, 404 for a user who exists, which the page shows as *Nextcloud has no user with the ID …*.

## Options

1. **List the keys of the app.** What most apps with a page of plain settings do, as their page writes through that API. This page doesn't, so the keys would add nothing but a way past the checks.
2. **Delegate the routes and name no key.** The page keeps its one way of saving, through `SettingsController`.
3. **Leave the pickers to administrators,** and let a member type every ID. It keeps the provisioning API, and gives a member a form whose fields say that existing users don't exist, unless the page knows who reads it and hides what it can't answer.
4. **Delegate the pages *Accounts* or *Sharing* of Nextcloud as well,** which would open its API for a member, and much else with it.
5. **Routes of the app for the pickers,** behind the same attribute as the page, which answer what a picker shows and no more.

## Decision

Options 2 and 5. `AdminSettings` implements `IDelegatedSettings`, with no name and `getAuthorizedAppConfig()` empty. Every route of the page carries `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, and `DirectoryController` answers the pickers for administrators and members alike, with the account name, the display name and whether a user is enabled, without an e-mail address. The status API, whose documentation names an app password of an administrator for monitors, stays with the administrators.

## Consequences

A member gets a page that works like that of an administrator, by one way of saving, in which every value passes the same checks; a test finds every route of the page by its attribute, so that a new route that forgets the delegation fails. A member is an administrator of this app, with its reach into the files of every user and the network of the server, which the page, the guide and the security design say.

Members see the account names and display names of every user and group, which a picker of the target user can't do without. They don't get the notification of a failing mirror by themselves: Nextcloud has no public API that names the delegated groups, and the setting `notification_group` remains for it.
