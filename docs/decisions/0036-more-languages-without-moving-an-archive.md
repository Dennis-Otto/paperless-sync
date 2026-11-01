# Speak more languages from catalogs that a check holds complete, and let no catalog move an archive

- Status: accepted
- Date: 2026-11-01

## Context

The app spoke English and German. After German, the largest Nextcloud communities are French and Spanish, and their administrators got an English app with English folders. `scripts/check-l10n.mjs` knew only `l10n/de.json`, so a contributed catalog would have gone unchecked.

Three things make another language more than a translation:

- A catalog changes what a new installation proposes, by decision [0017](0017-folder-names-in-the-language-of-nextcloud.md). An installation in use that never stored its names, such as one set up with `occ`, reads them as defaults, so the catalog would rename its folders: a folder of another name is another archive, which the next run fills anew.
- Nextcloud picks the form of a plural in PHP by the rule of the catalog, but in the browser by a table of its own. For French and Spanish, the rule of Nextcloud has three forms, with one for millions in the middle, and the table two, so the browser shows the middle form for every count but one.
- The website and the pictures are maintained by hand and by bots in English and German, and would double their cost with each language.

For the names, the options were a migration for each new language, which each translator or reviewer would have to remember, or runs that store the names that they use, so that a name that once made an archive never changes again.

## Decision

The check takes every `l10n/<language>.json`, with the plural forms of its rule, and `--new` starts a language. Where the rule has a form for millions, the catalog repeats the other form there, as Nextcloud's own catalogs do. The French and Spanish catalogs are complete, and the listing of the App Store comes in each language. The website and the pictures stay in English and German, and the *Help* links of the other languages open the English guide.

A run that writes stores the names that it uses (`ConfigService::keepDefaultNames()`). The migration of this version stores the names of every installation in use, the English names of the earlier versions where the catalogs of this version would otherwise rename them, so that the runs start from what the archive has. A further language needs no migration.

## Consequences

An installation in use on a French or Spanish Nextcloud keeps its English folders and fallbacks; a new one gets French or Spanish names. A name also stays when an administrator changes the default language of Nextcloud after a run, which until now renamed the folders of an installation that had not saved them. A later version can't change a default for an installation that has run.

A new text in the code needs its words in every catalog, or the check fails. That raises the price of a new text, and is the point: a page in one language doesn't show stray English. The French and Spanish texts were drafted with the help of an AI and wait for native speakers to review them; `docs/development.md` shows them how.
