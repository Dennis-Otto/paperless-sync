# A dry-run takes the plan of a run

- Status: accepted
- Date: 2026-10-18

## Context

An administrator reads a dry-run before turning on the schedule, and before a new path template or deletion policy reaches the archive ([0014](0014-try-unsaved-settings-with-the-saved-connection.md)). It is only worth that trust when it shows what a run with the same settings does.

The export and the inbox were two long loops of `SyncService` that decided what to do with each document and file, counted the batch, made the changes and saved the states in one place, with about 30 forks between a dry-run and a run (`if ($report->dryRun)`) in the two loops and their helpers ([#190](https://github.com/Dennis-Otto/paperless-sync/issues/190)). Each fix had to keep both ways in step: the count of the batch, the restore of a copy that the keep policy left in place, and the order of the questions for checksums all touched both. A fork forgotten in one of them would make a dry-run promise a change that a run doesn't make, or the other way round, and no test compared the two.

## Options

1. Keep the loops, and add tests that compare a dry-run with a run. The tests would find a difference, but every new kind of change would still need its forks in the right places.
2. Plan everything first, then apply the whole plan. A dry-run would be the plan itself, but the plan can't know which change fails: a run whose export fails leaves its place in the batch to the next document, and a full quota stops the other writes, which a plan made up front would ignore. And a plan of every document would ask Paperless for the checksums of documents that wait for a later run.
3. Planners that decide what happens to each document and file, one action at a time, and executors that apply each action, or in a dry-run only report it. The planners read Paperless, the archive and the states and change nothing; the executors are the one place where the two kinds of run differ. A batch that both see counts the places of the changes.

## Decision

Option 3. `ExportPlanner` and `ImportPlanner` plan the export and the inbox as generators of actions (`lib/Model/Plan/`), such as an export, a move to the deleted folder, a deletion, an upload or a document that waits; a planner reaches the files of Nextcloud only through `TargetFiles`, which lists, finds and reads them but can't write. `ExportExecutor` and `ImportExecutor` apply the actions, and `SyncBatch` holds the places of the changes, with the rules that a run had before: a failed export takes the place of an attempt but no change, a removal still has a place after the writes stopped.

The plan is lazy: a planner plans a document once the executor has applied the actions before it, so it sees the batch as it stands, in a dry-run as in a run. When the batch is full, a document with a new path waits without a question to Paperless; one that keeps its path is still asked once for the checksum of its revision, which its state keeps for a later run.

## Consequences

A dry-run and a run share every decision, and a test runs each kind of change as both and compares their reports. The planners have tests of their own, as tables of the actions that each situation leads to, without a change to check, and the executors are tested with the doubles of the storage and the state.

A new kind of change, such as a run of one profile, is a new action and its handling in the executor; a planner never asks which kind of run it plans. What stays outside are the parts of a run that are no changes of a document or file: the folders that a run prepares, the tags that a run creates in Paperless, which the executor of the inbox asks `InboxTags` for, and the notice that only a dry-run shows when the trash of Paperless lists nothing. The metadata next to the archive ([0021](0021-metadata-next-to-the-archive-in-sidecars-and-an-index.md)) is written by the executor of the export, after the state of each copy that a run exported, moved or kept at its path; a dry-run writes none.

One difference to the code before: a second failure of the whole archive within the export, after the writes already stopped, is now logged as the error of its document, as in the trash and with the missing documents, instead of a second notice that the export stopped.
