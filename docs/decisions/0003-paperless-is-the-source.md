# Paperless is the source of the archive

- Status: accepted
- Date: 2026-10-08

## Context

The same document lives in Paperless and as a copy in Nextcloud. When both may change it, a sync has to merge changes and decide conflicts.

## Options

1. A two-way sync of the archive.
2. Paperless is the source: the archive in Nextcloud mirrors it, and new documents reach Paperless only through the inbox folder.

## Decision

Option 2. Titles, metadata and folders follow Paperless; a run renames and moves the copies to match. Files in the inbox are imported into Paperless and leave the inbox once Paperless has taken them.

## Consequences

A change of a copy in Nextcloud doesn't flow back to Paperless; the conflict policy decides what happens to a file that is in the way of a copy. Editing a document means editing it in Paperless.
