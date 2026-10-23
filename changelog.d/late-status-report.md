### Bug fixes

- **The report of a dry-run stays in view:** an answer of the status that the settings page asked for before a dry-run, such as the one after *Save*, could arrive after the report of the dry-run and show the report from before it again, such as *Dry-run, finished* in place of *Dry-run of unsaved settings, finished*. The page now shows only a status that is newer than what it shows, and keeps following a run that is queued or active.
