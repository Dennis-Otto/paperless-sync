# Translate a message where its reader is known, and check the German catalog against the code

- Status: accepted
- Date: 2026-10-14

## Context

Paperless Sync speaks English and German. The settings page and the connection checklist were German for an administrator who reads German, but about 30 messages that come from the services were English on the same page: an invalid setting, a target user that Nextcloud doesn't know, another run that is still active. Some of them were half German and half English, such as a line of the checklist with the English reason of a failed folder test, and their English named settings by words that the page doesn't use, such as *Batch size* for *Maximum changes per module and run* ([#157](https://github.com/Dennis-Otto/paperless-sync/issues/157)). The check of the translations only compared `l10n/de.js` with `l10n/de.json`, so it passed with every gap, and one text was even in the catalog twice. The folder of the day in `_Gelöscht` was the day in UTC, so a document trashed in Germany shortly after midnight landed in the folder of the day before.

## Options

1. Give every service the translations of Nextcloud, `IL10N`, and translate a message where it comes from. The services also run in the background job, where no administrator reads them, and their messages go to the log, which stays English for whoever reads it.
2. Translate where the reader is known: a service throws or returns the English text with its parameters, an `InvalidSettingException` or a `ConnectionCheck`, and the controller of the request translates it with the language of the administrator; the log keeps the English. A script reads every text that the code translates, also those that it translates later, and compares them with the catalog.
3. Send English keys to the browser and translate them there. The page would need every message of the server in `l10n/de.js`, and a message with parameters would need both sides to agree on them.

## Decision

Option 2. `InvalidSettingException` carries the English text, the values of its placeholders and the setting that it names, and `translated()` gives it in the language of the administrator; the settings and the run controllers answer with it, and with the setting, which the page moves the focus to. A message names a setting by its label on the page, in quotes.

`scripts/check-l10n.mjs` reads the first argument of every call that translates, `translate()` and `translatePlural()` in `js/`, `->t()` and `->n()` in `templates/` and `lib/`, and the texts that `lib/` translates later, those of a `ConnectionCheck` and of an `InvalidSettingException`, also through a class constant. It fails on a text that the catalog lacks, on a German text with other placeholders, on a text that no code translates, on a text twice and on an `l10n/de.js` that differs; `--fix` repairs the last three. So a text that is translated later is a string or a class constant at the call, never a variable.

The folder of the day in `_Gelöscht` is the day of the deletion in the time zone of the target user, whose files they are, from the personal settings of Nextcloud, or else in its default time zone.

## Consequences

A message on a German page is German, and the CI fails on a new text without its German words, with the file and the line, before a release ships it. Services stay free of the language of a reader, and their messages in the log stay English. The errors of a run in its status and report are English, as in the log, because a run, also a scheduled one, has no reader when it writes them; since [#162](https://github.com/Dennis-Otto/paperless-sync/issues/162), each comes with its record, the English text with its values and its setting, which the settings page translates when it reads the status, and a text of Paperless or of Nextcloud stays as it came. The day comes from the personal settings of the target user, never from the browser offset of whoever starts a run, which Nextcloud's `IDateTimeZone::getTimeZone()` falls back to for a user without a time zone of their own. A change of the time zone of the target user moves no copy: only a new deletion takes the new day.
