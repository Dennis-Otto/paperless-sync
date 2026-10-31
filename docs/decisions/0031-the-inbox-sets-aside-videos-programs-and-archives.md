# The inbox sets aside videos, programs and archives by their extensions, and leaves every other file to Paperless

- Status: accepted
- Date: 2026-10-31

## Context

On 2026-10-03, a clean-up of a computer put a folder of downloads and a folder of videos into the inbox of the maintainer, and the next runs of the app uploaded 132 files to Paperless. The inbox sent every file that it found: Paperless refuses a type that it doesn't take, but only after the upload, which for a video can be gigabytes, once for each file.

The maintainer decided that a wrong type of file is what stops an upload, videos, programs and archives, and that the number of files decides nothing: a large batch of scans goes through without a question.

## Options

1. A limit on the number of new files, with a question to the administrator above it. It would stop a clean-up, and a batch of scans just the same, which is the daily use of the inbox.
2. A list of the types that Paperless takes, and every other file set aside. Paperless judges a file by its content, its parsers differ with its version and its settings, such as Tika for office documents, and it reads any text as a document. A list in the app would go out of date, and its mistake would hold back a real document without an upload, such as a scan with an odd name or none.
3. A list of the types that no parser of Paperless makes a document of, by the extensions of the names, and every other file goes to Paperless as before. Its mistake is an upload that Paperless refuses, as today, which the report names as *Refused*.
4. The same list by the MIME type that Nextcloud detects. Nextcloud takes it from the extension as well, so it adds nothing but the list of `listFiles` to change, and it reports an unknown extension as `application/octet-stream`.
5. Each such file moves to a folder of its own, or to the error folder with a `.error.txt`. A move is a change to files that the user may want where they are, with its conflicts, and a `.error.txt` each for hundreds of files that nothing failed for.

## Decision

Options 3 and, in place of 5, a file that stays where it is.

`InboxFileTypes` has the extensions of videos, programs and archives, in the names of the kinds that the report shows. `ImportPlanner` plans a file of such a type as `SetAside`: it stays in the inbox, without a state and without a place in the batch, in a run and in a dry-run alike. A file of such a type with an upload that Paperless already has keeps it: the task still ends, and an imported file stays as it is, so an update changes nothing for the files in the inbox. A state that only waited for another upload, of a file that Paperless refused or that waits for its next attempt, is forgotten.

Each report names every such file with a line of the lowest priority, which any other line replaces when the report is full, so that a folder of videos hides no change, and with one notice, which stays past the limit of the lines, with the count and the first five names. The status keeps that report, so the settings page and `occ paperless_sync:status` show it. Such a file is no failure, so it reaches neither the errors, the activity stream nor the notifications, which every run would repeat.

## Consequences

An administrator sees at once why a video did not arrive, and stops seeing it by moving or deleting the file; the next report leaves it out by itself, as it comes from the inbox of that run, without a state to clean up. A document inside an archive doesn't reach Paperless until someone unpacks it into the inbox, which the guide for troubleshooting says.

A type outside the list still goes up once, such as an audio file or a picture of a design tool, and Paperless refuses it as before; a kind or an extension to add is one line in `InboxFileTypes`. The inbox alone follows this rule: *Send to Paperless* in Files and the operation of Flow send what someone chose or a rule of an administrator names, and Paperless refuses what it can't use.
