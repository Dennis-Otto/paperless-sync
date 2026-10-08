# Delete only what Paperless has confirmed gone, and only what fits into a run

- Status: accepted
- Date: 2026-10-08

## Context

A sync that mirrors deletions can destroy an archive when the source answers wrongly once: a failing request, an empty page, a wrong token.

## Options

1. Mirror every deletion at once.
2. Move copies to a deleted folder first, delete for good only when allowed and confirmed by several complete scans, and limit every run to a batch of changes.

## Decision

Option 2. A document in the trash of Paperless moves its copy to the `_Gelöscht` folder, or keeps it in place, as configured. A document that is gone without the trash waits for the configured number of complete scans. Permanent deletion is off by default. Only a copy that is still there is moved or deleted, and every move and deletion counts against the batch size; an error concerns only its own document.

## Consequences

A wrong answer of Paperless can't empty the archive in one run, and a deleted document can be restored from the deleted folder. Mirroring a large deletion takes several runs.
