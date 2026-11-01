### Features

- **French and Spanish:** the settings, their messages, the connection checklist, the report of a run, the notes in Files, the Activity stream and the notifications now speak French and Spanish to accounts set to these languages, and the listing in the App Store has a summary and a description in each. A new installation on a French or Spanish Nextcloud proposes folders in its language, such as `Archives` and `Boîte de réception`, or `Archivo` and `Bandeja de entrada`. An installation in use keeps the folders that it has. The texts were drafted with the help of an AI: native speakers are welcome to correct them, and [Translate Paperless Sync](https://dennis-otto.github.io/paperless-sync/development.html#translate-paperless-sync) shows how, and how to add a language.

### Bug fixes

- **A folder name no longer changes after the first run:** an installation that was set up with `occ` and never saved its names got new folders, and a new archive, when an administrator changed the default language of Nextcloud. A run now stores the names that it uses.
