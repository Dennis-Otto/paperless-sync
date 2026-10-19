### Bug fixes

- **The real reason of a failed import on Paperless-ngx 2.x:** Paperless-ngx 2.x marks a failed import first without its reason, as `<traceback object at 0x…>`, and writes the reason a moment later. A run that checked the import in that moment moved the file to the error folder with the placeholder in its `.error.txt`, and a file sent from Files reported it in its notification. The app now waits for the reason, so the `.error.txt` and the notification say why Paperless didn't import the file.
