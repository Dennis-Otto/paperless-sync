### Features

- **Changes within seconds:** a workflow of Paperless can call the webhook of the app when it adds or changes a document, and the copy of that document is exported, moved or removed within seconds instead of with the next scheduled run, by the same rules as a run. Section 6 of the settings creates its secret, which only Paperless needs, shows the webhook URL and the last call, and lists the steps in Paperless; calls without the secret are refused and slowed down by Nextcloud.
