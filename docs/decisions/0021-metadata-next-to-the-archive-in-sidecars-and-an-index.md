# The metadata of Paperless goes next to the archive, as a sidecar for each copy and one index

- Status: accepted
- Date: 2026-10-18

## Context

The archive in Nextcloud held the files of the documents alone: their tags, notes, custom fields and archive serial numbers stayed in Paperless, and the path template keeps only a few of them in the names. A mirror of the media folder of Paperless, by rclone or WebDAV, has the same gap. The exporter of Paperless, `document_exporter`, writes the metadata into one `manifest.json` next to the files, but it runs on the server of Paperless, by hand, and its manifest is meant for an import into Paperless again.

## Options

1. One manifest for the whole archive, as the exporter of Paperless writes it. A change of one document rewrites the whole file, and a file with thousands of documents is no file to read for a person.
2. A file next to each copy, such as `Invoice [P123].pdf.json`. It doubles the files in every folder of the archive, which people browse, and moves with every copy.
3. A sidecar for each copy in one hidden folder of the archive, `.paperless/P123.json`, named by the ID of the document, which a copy keeps through every move, and one index `.paperless/index.csv`, with a row for each copy and its path.
4. A column in the table of the state of the exports, for the revision that a sidecar describes, which needs a migration of the database.

## Decision

Option 3, behind the setting *Write metadata next to the archive*, which is off by default. A sidecar holds the names of the correspondent, the document type, the storage path and the tags instead of the IDs of Paperless, which mean nothing without it, the notes with the names of their authors, the custom fields with their names, the file of the copy and its SHA-256, and the address of the document in Paperless; its field `version` grows with every change that a reader must know of. The index is CSV after RFC 4180, in UTF-8 with a byte order mark, so spreadsheets open it as it is, and a value that starts like a formula starts with an apostrophe, after OWASP's advice on CSV injection.

The index is the memory of the sidecars as well, not the database: it keeps the SHA-256 of each sidecar, so a run reads one file and writes a sidecar only when its content changes, also after a change that leaves the document in Paperless as it was, such as a new name of its correspondent. A sidecar goes when its document leaves the state of the exports, which only the rules of [deletion safety](0004-delete-only-what-is-confirmed.md) and an excluded tag allow, and a copy in the deleted folder keeps its sidecar, with its row naming its path and the state of its document. Paperless sends the notes of the documents only while the setting is on, because they may be long and the runs need them for nothing else.

## Consequences

The archive describes itself: a backup of Nextcloud alone keeps the documents with their metadata, a person finds them in a spreadsheet, and a script or another system reads them without Paperless. It needs no migration of the database, and a lost or deleted index costs one run that writes every sidecar again. A sidecar that someone deletes while the index keeps its row comes back only with the next change of its content, or after the index is deleted. The custom fields need the permission to view them, as for a path template that uses them. Turning the setting off leaves the folder as it was, without updates, until someone deletes it.
