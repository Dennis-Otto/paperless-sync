### Features

- **Paperless Sync speaks Traditional Chinese:** on a Nextcloud set to 正體中文（臺灣） (`zh_TW`), the settings, their messages, the connection checklist, the notes in Files, *Send to Paperless*, the Activity stream and the notifications are in Traditional Chinese, in the words that Nextcloud and Paperless-ngx use in Taiwan, such as 檔案, 活動 and 關聯方. A new installation on such a Nextcloud proposes Traditional Chinese folders, such as `文件/Paperless`, `封存` and `收件匣`; an installation in use keeps the folders that it has. The App Store shows the summary and the description in Traditional Chinese. The texts were drafted with the help of an AI and checked against the English ones; native speakers are welcome to improve them. Hong Kong (`zh_HK`) stays English for now.

### Bug fixes

- An installation in use on a Chinese Nextcloud that updates straight from a version before 1.4.0 keeps its English folders. Before, the update gave it the Chinese names of the new catalog, so that the next run would have started the archive again in other folders.
