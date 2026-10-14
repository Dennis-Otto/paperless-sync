# Try unsaved settings with the saved connection, and keep the paths the same in every run

- Status: accepted
- Date: 2026-10-14

## Context

The path template is the setting that is easiest to get wrong, and before, a mistake showed only after a save and a dry-run: the dry-run used the saved settings, not the form. People who know the storage paths of Paperless-ngx expect its variables too, such as the archive serial number, the owner, the tags, the name of the month and custom fields ([#147](https://github.com/Dennis-Otto/paperless-sync/issues/147)).

A preview and a dry-run of the form must not undo what a save protects. Saving tests the connection, asks for the password of the administrator, sends the stored token only to the URL that it was saved with ([0008](0008-local-network-per-request.md) and the token protection in the security design), and decides whose files the app writes. A preview that the page asks for while the administrator types, and a dry-run, ask for no password.

Some of the new variables depend on more than the document: the name of a month on a language, the owner on the users of Paperless, a custom field on its definition. A path that changes between two runs moves the file, so each of them must come out the same in every run.

## Options

1. Previews and dry-runs of the whole form, with its URL, token, local network and target user, behind the password like a save. Complete, but the password would interrupt the typing, and a dry-run that reaches a new address without a save would be a way around the test of the save.
2. Previews and dry-runs of the form with the saved connection and target user: the page sends the form, and the server refuses a dry-run whose URL, local network or target user differ from the saved ones, with the setting to save first. The preview takes only the settings that decide a path or whether a run skips a document.
3. No dry-run of the form, only the preview of the template.

## Decision

Option 2. `POST /settings/preview` renders the five newest documents of Paperless with the template, the fallback names and the switches of the page, through the same `PathTemplateService::render()` and the same rules of skipping as a run, and saves nothing. `POST /sync/run` takes the settings of the page only with `dryRun`; `ConfigService::validateForDryRun()` checks them as a save does and refuses a change of the connection or the target user with `UnsavedSettingException`, which names the setting. The report of such a dry-run says *Dry-run of unsaved settings*, also in the status that the page shows at the next visit.

The new variables stay plain `{{ name }}` placeholders, without the logic of Jinja, so a value can never run code or add a folder. `{{ created_month_name }}` takes the language that Nextcloud forces or has as its default, never the one of the administrator who starts a run, so that a run by hand and a scheduled run write the same paths. The users and the custom fields are read only for a template that uses them, so that a token without the permission to view them keeps working with every other template; when Paperless refuses them, or lacks a custom field of the template, the run fails before it changes a file, because the paths of every document would change otherwise, as with an incomplete list of correspondents ([0004](0004-delete-only-what-is-confirmed.md)).

## Consequences

An administrator sees a mistake in the template within a second of typing it, and can try a new template, other folders or another deletion policy with a dry-run before saving them. A new Paperless URL, token, local network setting or target user still needs a save first. A change of the default language of Nextcloud moves the files of a template with `{{ created_month_name }}`, as a change of the template does; the guide says so. The names in that language need PHP's `intl` extension, which Nextcloud only recommends: without it they are English, so installing or removing `intl` moves those files too. A custom field that is renamed in Paperless stops the runs until the template names it again, which the error of the run explains.
