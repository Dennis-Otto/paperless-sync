### Performance

- Every run asks Paperless only for the fields of the documents that it needs, without the OCR text, the notes and the permissions of each document. Before, every run, every 5 minutes by default, received the whole text of the whole archive: about 4 MB for 1,000 documents with 4 KB of text each. The live path preview of the settings asks for the same fields, so it shows the paths that a run computes.
- A run asks Paperless for the checksum of a changed document once for each revision. Before, documents that waited for a later run because the batch was full asked again in every run: after a change of 50 titles with a batch of 5, 275 requests, each of which makes Paperless read the files of the document, instead of 50.
- A run over an archive without changes no longer writes to the database: it reads the state of all exported documents at once and writes only what changes. Before, a run with 10,000 unchanged documents ran 30,000 statements, every 5 minutes.
- The settings and the status of the app are lazy app configuration: Nextcloud loads them when the app needs them, not with every request of every user. The update converts the stored values.
