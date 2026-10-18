### Bug fixes

- **One Paperless for the whole run:** settings that you save while a run is busy, such as the connection to another Paperless, apply from the next run. Before, the rest of the run could reach the new Paperless and record its documents as those of the old one, or stop part-way. A run also reads the API token from Nextcloud's credentials store once, instead of twice for every request to Paperless.
