# Name new folders in the default language of Nextcloud, and keep the names of every installation in use

- Status: accepted
- Date: 2026-10-14

## Context

Every installation got German folders in the files of its target user, whatever the language of its Nextcloud: `Dokumente/Paperless`, `Archiv`, `Eingang`, `Fehler` and `_Gelöscht`, and the German fallback names `_Ohne Korrespondent`, `_Ohne Dokumenttyp`, `_Ohne Datum` and `Ohne Titel` in the paths of the documents. Administrators of English, French or Spanish instances found them in the files of their users, and renaming them after the first run moves the whole archive. Most users of Paperless-ngx don't read German.

The names of an installation in use must not change, though: a folder of another name is another archive, which the next run would fill anew. The settings page stores every setting with the first save, but an installation set up with `occ`, or one that saved before a setting existed, uses the default of a setting that it never stored.

## Options

1. English names for everyone. Simple, but a German instance, the users of the app so far, would get English folders.
2. The names in the language of the administrator who opens the settings page, through Nextcloud's translations. Two administrators would see different names, and a scheduled run, which has no reader, the English ones.
3. The names in the default language of Nextcloud, `default_language`, through the catalog of the app: its generic language, such as German for `de_DE`, when the app has no catalog for the regional one, and English when it has neither. An installation in use keeps the German names of the earlier versions, which a step of the update stores for each name that it never saved.

For the step of the update, a repair step that Nextcloud runs after the migrations of every update, as Nextcloud recommends for changes of data, or a migration, which runs once for each installation.

## Decision

Option 3, with a migration. The names are English texts in the code that the catalog translates, so a German Nextcloud keeps today's names, and a language follows with its catalog. The language is that of the instance, so that the page, every administrator and every run agree on the names before the first save.

The migration keeps the German names for an installation with a target user, or with copies or imports in the tables of the runs, and leaves a new installation alone. A repair step would run again with every later update, and would then give the German names to an installation that started with English ones but never saved them, such as one set up with `occ`.

## Consequences

An English instance starts with `Documents/Paperless/Archive`, and the README lists the names in both languages. A German instance without a default language gets the English names too, until an administrator sets `default_language` or types the names before the first save; the README says so. A new text of a name needs its German words in `l10n/de.json`, which `scripts/check-l10n.mjs` enforces. A change of the default language, or a catalog for another language, renames no folder of an installation that saved its settings, or that an earlier version set up: their names are stored. Only a new installation set up with `occ` alone, without the names, follows the default language until it stores them.
