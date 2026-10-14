### Bug fixes

- The settings say in German what is wrong with a setting when Nextcloud is set to German: a Paperless URL that is no URL, a number outside its limits, an invalid path template, a target user that Nextcloud doesn't know or a base folder in which the user may not create files, another run that is still active, a run before the setup is complete, and a test of the connection that failed. Before, these messages were English on a German page, also half English in the connection checklist. A message about a setting names it by its label on the page and moves the focus to it.
- The setting *Required consecutive missing scans* is called *Erforderliche aufeinanderfolgende Läufe ohne das Dokument* in German. Before, it was called *Fehlläufe*, failed runs, which it doesn't count.
- The times on the settings page, of the last run and the next one, follow the locale that you chose in the personal settings of Nextcloud, not the language of the browser.
- A copy whose document went to the trash of Paperless moves into the folder of the day of the deletion in the time zone of the target user, or of Nextcloud. Before, a document trashed in Germany shortly after midnight landed in the folder of the day before, the day in UTC.
- The example of the excluded tags is German on a German page.
