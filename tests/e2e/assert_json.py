#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys
from urllib.parse import quote

mode = sys.argv[1]
# A value that the mode compares with, such as the time of an earlier run.
argument = sys.argv[2] if len(sys.argv) > 2 else ""
payload = json.load(sys.stdin)
serialized = json.dumps(payload, sort_keys=True)

if any(token in serialized for token in ("e2e-only-token", "e2e-view-only-token", "e2e-no-ui-settings-token")):
    raise AssertionError("A response exposed a synthetic Paperless token")


def expect(key, value):
    actual = payload.get(key)
    if actual != value:
        raise AssertionError(f"{mode}: expected {key}={value!r}, got {actual!r}; payload={payload!r}")


# Every line of the connection checklist with the export and the inbox on, in its order.
CHECKLIST = ["paperless", "documents", "trash", "correspondents", "document_types", "storage_paths", "tags", "upload", "folder"]


def checks():
    """The lines of the connection checklist of the answer, by their ID, in their order."""
    lines = payload.get("checks")
    if not isinstance(lines, list):
        raise AssertionError(f"{mode}: the answer has no checklist: {payload!r}")
    return {line["id"]: line for line in lines}


def expect_line(line_id, status, message=None, hint=None):
    line = checks().get(line_id)
    if line is None or line["status"] != status:
        raise AssertionError(f"{mode}: expected the line {line_id} {status}, got {line!r}")
    if message is not None and line["message"] != message:
        raise AssertionError(f"{mode}: the line {line_id} says {line['message']!r} instead of {message!r}")
    if hint is not None and line["hint"] != hint:
        raise AssertionError(f"{mode}: the line {line_id} advises {line['hint']!r} instead of {hint!r}")


# What the checklist says of the account of the view-only token of the mock.
def expect_view_only_checklist():
    if list(checks()) != CHECKLIST:
        raise AssertionError(f"{mode}: the checklist has the lines {list(checks())!r}")
    expect_line("paperless", "passed", "Paperless-ngx 3.3.0 answers at this URL.")
    expect_line("documents", "passed", "Visible to the account: 1")
    expect_line(
        "tags",
        "failed",
        "The account may not view them (HTTP 403).",
        "In Paperless, under Users & Groups, give the account the permission View for Tags.",
    )
    expect_line(
        "upload",
        "failed",
        "The account may neither add documents nor view the tasks that import them.",
        "In Paperless, under Users & Groups, give the account the permissions Add for Documents and View for Paperless Tasks.",
    )
    # It sees the document of the account of the app, which the trash of Paperless leaves out.
    expect_line(
        "trash",
        "warning",
        "Visible to the account: 0",
        "Paperless leaves the documents of other owners out of the trash that it shows the account, and the account sees 1 of them. "
        "When they go to the trash, their copies count as missing and move to the deleted folder only after the required missing scans. "
        "Make the account of the app their owner.",
    )
    for line_id in ("correspondents", "document_types", "storage_paths", "folder"):
        expect_line(line_id, "passed")


def expect_action(action):
    if action not in payload.get("actions", []):
        raise AssertionError(f"{mode}: the run lists no action {action!r}: {payload!r}")


ARCHIVE = "Documents/Paperless/Archive/Example GmbH/Invoice/2026"


if mode == "config":
    expect("paperlessUrl", "http://paperless-mock:8080")
    expect("targetUser", "sync-target")
    expect("tokenConfigured", True)
    expect("permanentDelete", True)
    expect("paperlessLocalNetwork", True)
    # A save with a token entered tests the connection first, with every line of the checklist.
    expect("connectionTested", True)
    if list(checks()) != CHECKLIST:
        raise AssertionError(f"{mode}: the checklist has the lines {list(checks())!r}")
    for line_id in CHECKLIST:
        expect_line(line_id, "passed")
    expect_line("folder", "passed", "sync-target may create files in Documents/Paperless.")
elif mode == "checklist-view-only":
    # Test connection lists every check of the settings in the form, without saving them.
    if set(payload) != {"checks"}:
        raise AssertionError(f"{mode}: the test answers with more than its checklist: {payload!r}")
    expect_view_only_checklist()
elif mode == "checklist-no-ui-settings":
    # Every list passes, but without View for UI Settings, Paperless names neither the
    # permissions nor the ID of the account.
    if set(payload) != {"checks"} or list(checks()) != CHECKLIST:
        raise AssertionError(f"{mode}: the test answers with another checklist: {payload!r}")
    for line_id in ("paperless", "documents", "correspondents", "document_types", "storage_paths", "tags", "folder"):
        expect_line(line_id, "passed")
    expect_line(
        "trash",
        "warning",
        hint="Paperless shows the account in its trash only the documents that it owns and those without an owner. "
        "Trashed documents of other owners count as missing: they move to the deleted folder only after the required missing scans. "
        "Make the account of the app the owner of the documents to mirror, and give it the permission View for UI Settings, so that this checklist can check it.",
    )
    expect_line(
        "upload",
        "warning",
        "Paperless did not say whether the account may add documents and view the tasks that import them.",
        "In Paperless, under Users & Groups, give the account the permission View for UI Settings, so that this checklist can read its permissions. "
        "The inbox needs Add for Documents and View for Paperless Tasks.",
    )
elif mode == "saved-with-failures":
    # A permission that one module needs is saved with the settings, and the checklist says what to do.
    expect("tokenConfigured", True)
    expect("connectionTested", True)
    expect_view_only_checklist()
elif mode == "local-network-refused":
    expect(
        "message",
        "Nextcloud blocks connections to local addresses such as paperless-mock. "
        'If Paperless runs in your network, turn on "Paperless runs in my local network" '
        "and save the settings.",
    )
    expect("setting", "paperless_local_network")
    if list(checks()) != ["paperless", "folder"]:
        raise AssertionError(f"{mode}: a refused address ends the checks of Paperless: {payload!r}")
    expect_line("paperless", "failed")
    if checks()["paperless"]["setting"] != "paperless_local_network":
        raise AssertionError(f"{mode}: the line of Paperless names no setting: {payload!r}")
elif mode == "dry-run":
    expect("dryRun", True)
    expect("exported", 1)
    expect("errors", 0)
    if not any(str(action).startswith("EXPORT P123:") for action in payload.get("actions", [])):
        raise AssertionError(f"Dry-run export action missing: {payload!r}")
elif mode == "preview":
    expect("connected", True)
    expect(
        "template",
        "{{ owner_username }}/{{ created_month_name }}/{{ asn }} {{ title }} {{ custom_fields.Invoice number }} ({{ page_count }} pages) [P{{ id }}]",
    )
    expect(
        "paths",
        [
            {
                "id": 123,
                "title": "Monthly invoice",
                "path": "e2e-owner/August/42 Monthly invoice INV-2026-08 (2 pages) [P123].pdf",
                "skipped": False,
            }
        ],
    )
elif mode == "dry-run-unsaved":
    expect("dryRun", True)
    expect("unsavedSettings", True)
    expect("importsSubmitted", 0)
    expect("errors", 0)
    expect_action("EXPORT P123: Documents/Paperless/Archive/2026/Monthly invoice [P123].pdf")
elif mode == "dry-run-other-user":
    expect(
        "message",
        "A dry-run of unsaved settings uses the saved connection to Paperless and the saved target user. "
        "Save the settings to try another one.",
    )
    expect("setting", "target_user")
elif mode == "export":
    expect("dryRun", False)
    expect("exported", 1)
    expect("errors", 0)
    if not any(str(action).startswith("EXPORT P123:") for action in payload.get("actions", [])):
        raise AssertionError(f"The run lists no export: {payload!r}")
elif mode.startswith("exit-"):
    # occ paperless_sync:run exits with 1 when the run had errors of single documents, else with 0.
    code = int(mode.removeprefix("exit-"))
    if (code == 1) != (payload.get("errors", 0) > 0) or payload.get("dryRun") is not False:
        raise AssertionError(f"occ paperless_sync:run exited with {code} after this report: {payload!r}")
elif mode == "queued":
    # "Synchronize now" answers before the run starts, with the status that the page follows.
    expect("queued", True)
    if payload.get("status", {}).get("state") != "queued":
        raise AssertionError(f"The answer of a queued run brings no queued status: {payload!r}")
elif mode == "status-export":
    # The run that the background job made, as the status of the settings page shows it.
    expect("state", "completed")
    report = payload.get("report", {})
    if report.get("dryRun") is not False or report.get("exported") != 1:
        raise AssertionError(f"The status keeps no report of the export of the background job: {payload!r}")
    if not any(str(action).startswith("EXPORT P123:") for action in report.get("actions", [])):
        raise AssertionError(f"The status lists no export: {payload!r}")
elif mode == "export-stopped":
    expect("exported", 0)
    expect("errors", 1)
    actions = [str(action) for action in payload.get("actions", [])]
    if not any(action.startswith("ERROR: P123: Not enough free space in Nextcloud") for action in actions):
        raise AssertionError(f"The full quota is not reported as the error of the document: {payload!r}")
    if not any(action.startswith("EXPORT STOPPED:") for action in actions):
        raise AssertionError(f"The full quota does not stop the exports: {payload!r}")
elif mode == "move":
    expect("moved", 1)
    expect("exported", 0)
    expect("errors", 0)
elif mode == "excluded":
    expect("removedExcluded", 1)
    expect("errors", 0)
elif mode == "trash":
    expect("movedToTrash", 1)
    expect("errors", 0)
elif mode == "scan-incomplete":
    expect("permanentlyDeleted", 0)
    expect("movedToTrash", 0)
    expect("errors", 0)
    actions = [str(action) for action in payload.get("actions", [])]
    if not any(action.startswith("SCAN INCOMPLETE:") for action in actions):
        raise AssertionError(f"The incomplete scan is not reported: {payload!r}")
    if any(action.startswith(("WAIT ", "MISSING ", "DELETE ")) for action in actions):
        raise AssertionError(f"An incomplete scan counted a document as missing: {payload!r}")
elif mode == "missing-wait":
    expect("permanentlyDeleted", 0)
    if not any(str(action).startswith("WAIT P123:") for action in payload.get("actions", [])):
        raise AssertionError(f"Missing-document grace action absent: {payload!r}")
elif mode == "deletion-off":
    expect("dryRun", True)
    expect("permanentlyDeleted", 0)
    expect("movedToTrash", 0)
    expect("errors", 0)
    if any(str(action).startswith(("DELETE ", "MISSING ")) for action in payload.get("actions", [])):
        raise AssertionError(f"With permanent deletion off, a document purged from the trash lost its copy: {payload!r}")
elif mode == "metadata-run":
    # The run that turns the metadata next to the archive on changes no copy.
    expect("unchanged", 1)
    expect("exported", 0)
    expect("errors", 0)
elif mode == "sidecar":
    # The sidecar of P123 holds what Paperless knows of the document, in names instead of IDs.
    expect("version", 1)
    expect("id", 123)
    expect("title", "Monthly invoice corrected")
    expect("correspondent", "Example GmbH")
    expect("document_type", "Invoice")
    expect("created", "2026-08-26")
    expect("archive_serial_number", 42)
    expect("notes", [{"note": "Paid on 1 September.", "created": "2026-09-01T09:00:00+02:00", "user": "e2e-owner"}])
    expect("custom_fields", [{"name": "Invoice number", "type": "string", "value": "INV-2026-08"}])
    expect("url", "http://paperless-mock:8080/documents/123/details")
    file = payload.get("file") or {}
    if file.get("version") != "archive" or len(file.get("sha256") or "") != 64:
        raise AssertionError(f"sidecar: the copy is the archive version with its SHA-256: {payload!r}")
elif mode == "deleted":
    expect("permanentlyDeleted", 1)
    expect("errors", 0)
elif mode == "import-submitted":
    expect("importsSubmitted", int(argument or 1))
    expect("errors", 0)
elif mode == "import-success":
    expect("importsSucceeded", 1)
    expect("errors", 0)
    # The ID of the document that Paperless created, from the result of its task.
    expect_action("IMPORT SUCCESS: Documents/Paperless/Inbox/Insurance/2026/policy.pdf -> P501")
elif mode == "inbox-tags-checklist":
    # Test connection checks the permissions of the tags of the inbox: View and Add for Tags.
    expect_line("inbox_tags", "passed", "The account may view and create the tags that the inbox gives its files.")
elif mode == "inbox-tags-settings":
    if [payload.get("inboxFolderTags"), payload.get("inboxTags"), payload.get("inboxCreateTags")] != [True, "Scanned", True]:
        raise AssertionError(f"inbox-tags-settings: the tags of the inbox were not saved: {payload!r}")
elif mode == "import-tags":
    # The tag for every import and the folder 2026 are new, Insurance finds insurance.
    for action in (
        "NEW TAG: Scanned",
        "NEW TAG: 2026",
        "IMPORT: Documents/Paperless/Inbox/Insurance/2026/policy.pdf (tags: Scanned, insurance, 2026)",
    ):
        expect_action(action)
elif mode == "mock-tags":
    created = [(tag.get("name"), tag.get("matching_algorithm")) for tag in payload.get("createdTags", [])]
    # Without a match, Paperless assigns the new tags to no other document on its own.
    if created != [("Scanned", 0), ("2026", 0)]:
        raise AssertionError(f"mock-tags: unexpected new tags: {created!r}")
    policy = [upload for upload in payload.get("uploads", []) if upload.get("filename") == "policy.pdf"]
    if len(policy) != 1 or policy[0].get("tags") != [100, 11, 101]:
        raise AssertionError(f"mock-tags: the upload of policy.pdf has other tags: {policy!r}")
elif mode == "import-failed":
    expect("importsFailed", int(argument or 1))
    expect("errors", 0)
elif mode == "paperless":
    # The Paperless that the last run reached, and the version of its API that the app uses.
    expect("paperlessVersion", argument)
    expect("paperlessApiVersion", "9")
elif mode == "import-rejected":
    expect("importsFailed", 1)
    expect("importsSubmitted", 0)
    expect("errors", 0)
    if not any(
        str(action).startswith("IMPORT REJECTED: Documents/Paperless/Inbox/notes.bin:")
        and "File type application/octet-stream not supported" in str(action)
        for action in payload.get("actions", [])
    ):
        raise AssertionError(f"The run names no refusal with the reason of Paperless: {payload!r}")
elif mode == "import-waiting":
    expect("importsFailed", 0)
    expect("importsSubmitted", 0)
    expect("skipped", 1)
    expect("errors", 0)
elif mode == "status-errors":
    # The run that the full quota stopped: its error stays in the status, for the settings page.
    expect("state", "completed-with-errors")
    report = payload.get("report", {})
    messages = [str(message) for message in report.get("errorMessages", [])]
    if not any(message.startswith("P123: Not enough free space in Nextcloud") for message in messages):
        raise AssertionError(f"The status keeps no error of the document: {payload!r}")
    if not any(str(action).startswith("EXPORT STOPPED:") for action in report.get("actions", [])):
        raise AssertionError(f"The status keeps no report of the run: {payload!r}")
    if payload.get("lastEnded", 0) < payload.get("lastStarted", 0) or payload.get("lastSuccess", 0) < 1:
        raise AssertionError(f"The times of the runs are wrong: {payload!r}")
elif mode == "enabled":
    expect("enabled", True)
elif mode == "disabled":
    expect("enabled", False)
elif mode == "job":
    # The status after the background job: a complete run, not a dry-run, that exported
    # the document, after the last run of an hour ago.
    expect("state", "completed")
    expect("error", "")
    summary = payload.get("summary", {})
    if summary.get("dryRun") is not False or summary.get("exported") != 1 or summary.get("errors") != 0:
        raise AssertionError(f"The background job ran no complete export: {payload!r}")
    if not payload.get("lastCompleted", 0) > int(argument):
        raise AssertionError(f"The background job didn't complete a run after {argument}: {payload!r}")
elif mode == "restored":
    expect("moved", 1)
    expect("exported", 0)
    expect("movedToTrash", 0)
    expect("errors", 0)
    expect_action(f"MOVE P123: {ARCHIVE}/2026-08-26 - Monthly invoice corrected [P123].pdf")
elif mode == "mock-no-download":
    # A run that moved a copy back, or skipped a conflict, downloaded no document.
    expect("downloads", int(argument))
    expect("invalidAuth", 0)
elif mode == "conflict-skip":
    expect("conflictMode", "skip")
elif mode == "conflict-replace":
    expect("conflictMode", "replace")
elif mode == "conflict":
    expect("exported", 0)
    expect("unchanged", 1)
    expect("errors", 1)
    expect_action(f"ERROR: P124: Nextcloud file conflict at {ARCHIVE}/2026-08-26 - Annual statement [P124].pdf.")
elif mode == "conflict-replaced":
    expect("exported", 1)
    expect("unchanged", 1)
    expect("errors", 0)
    expect_action(f"EXPORT P124: {ARCHIVE}/2026-08-26 - Annual statement [P124].pdf")
elif mode == "vanished-wait":
    expect("movedToTrash", 0)
    expect("permanentlyDeleted", 0)
    expect("unchanged", 1)
    expect("errors", 0)
    expect_action("WAIT P123: missing run 1/2")
elif mode == "vanished":
    # After the grace runs, the copy of a document that vanished without the trash moves
    # to the deleted folder; it is never deleted, as direct deletion is not allowed.
    expect("movedToTrash", 1)
    expect("permanentlyDeleted", 0)
    expect("errors", 0)
    if not any(
        str(action).startswith("MISSING P123: Documents/Paperless/Archive/_Deleted/")
        for action in payload.get("actions", [])
    ):
        raise AssertionError(f"The vanished document didn't move to the deleted folder: {payload!r}")
elif mode == "vanished-kept":
    expect("movedToTrash", 0)
    expect("permanentlyDeleted", 0)
    expect("unchanged", 1)
    expect("errors", 0)
elif mode == "status":
    expect("state", "completed")
    expect("error", "")
    expect("paperlessVersion", "3.3.0")
    expect("paperlessApiVersion", "9")
    # The schedule is off in these tests.
    expect("nextRun", None)
    if not isinstance(payload.get("summary"), dict):
        raise AssertionError(f"Status summary is missing: {payload!r}")
    report = payload.get("report", {})
    if report.get("errorMessages") != [] or report.get("dryRun") is not False:
        raise AssertionError(f"The status keeps no report of the last run: {payload!r}")
    if payload.get("lastSuccess") != payload.get("lastEnded"):
        raise AssertionError(f"The last run, without errors, is not the last successful one: {payload!r}")
elif mode == "mock-after-move":
    expect("downloads", 1)
    if payload.get("metadataRequests", 0) < 1:
        raise AssertionError(f"Metadata-only move did not request a checksum: {payload!r}")
    expect("invalidAuth", 0)
    # Every run asked for the documents by their IDs, the live path preview for the newest ones,
    # and both for the fields that the app reads, without the text of the documents.
    scans = payload.get("documentScans", [])
    if sum(scan.get("ordering") == "id" for scan in scans) < 4:
        raise AssertionError(f"Expected a scan of the documents by their IDs in every run: {scans!r}")
    for scan in scans:
        fields = scan.get("fields", "").split(",")
        if (
            scan.get("ordering") not in ("id", "-added")
            or "title" not in fields
            or "content" in fields
            or "notes" in fields
        ):
            raise AssertionError(f"A scan asked for more of the documents than the app reads: {scan!r}")
elif mode == "names":
    # Every name within the bytes of Nextcloud, with the marker and the extension whole;
    # the paths go to standard output, for run.sh to read through WebDAV.
    folder = "Example GmbH/Invoice/2026/2026-08-26 - "
    expected = {
        201: folder + "Refund $100 from Shop $1 ${1}0 {{ correspondent }} [P201].pdf",
        202: folder + "株" * 64 + " [P202].pdf",
        203: folder + "😀" * 48 + " [P203].pdf",
        204: "Ж" * 100 + "/Invoice/2026/2026-08-26 - Monthly invoice [P204].pdf",
    }
    expect("exported", 4)
    expect("errors", 0)
    for document_id, relative in expected.items():
        path = f"Documents/Paperless/Archive/{relative}"
        if f"EXPORT P{document_id}: {path}" not in payload.get("actions", []):
            raise AssertionError(f"names: no export of P{document_id} to {path}: {payload!r}")
        *folders, name = relative.split("/")
        if len(name.encode()) > 217 or any(len(part.encode()) > 200 for part in folders):
            raise AssertionError(f"names: {relative} has a name longer than Nextcloud allows")
        print(quote(path))
elif mode == "mock-final":
    # The first export, the revised content and the original one again, the export that the full
    # quota stopped after its download, and the export after it.
    expect("downloads", 5)
    expect("invalidAuth", 0)
    uploads = payload.get("uploads", [])
    if sorted(item.get("filename") for item in uploads) != ["broken.pdf", "cancelled.pdf", "copy.pdf", "policy.pdf"]:
        raise AssertionError(f"Unexpected upload filenames: {uploads!r}")
    if not all(item.get("containsFixture") is True for item in uploads):
        raise AssertionError(f"Uploaded fixtures were not transferred intact: {uploads!r}")
    expect("refusedUploads", ["notes.bin"])
    # Every request of the app asks for version 9 of the API of Paperless.
    if set(payload.get("apiVersions", {})) != {"9"}:
        raise AssertionError(f"Requests asked for other API versions of Paperless than 9: {payload.get('apiVersions')!r}")
elif mode == "activity-none":
    entries = payload["ocs"]["data"]
    if entries:
        raise AssertionError(f"activity-none: the stream has entries: {entries!r}")
elif mode == "activity-export":
    # The run by hand of an administrator: one entry for the target user, which names the
    # file that the run wrote, with its link, and belongs to the archive folder.
    entries = payload["ocs"]["data"]
    if len(entries) != 1:
        raise AssertionError(f"activity-export: expected one entry, got {entries!r}")
    entry = entries[0]
    name = "2026-08-26 - Monthly invoice [P123].pdf"
    if entry.get("app") != "paperless_sync" or entry.get("type") != "paperless_sync":
        raise AssertionError(f"activity-export: the entry is not one of the app: {entry!r}")
    if entry.get("subject") != f"Paperless Sync added or updated 1 document: {name}":
        raise AssertionError(f"activity-export: unexpected subject: {entry!r}")
    template, objects = entry["subject_rich"]
    if template != "Paperless Sync added or updated 1 document: {file1}":
        raise AssertionError(f"activity-export: unexpected rich subject: {entry!r}")
    file = objects.get("file1", {})
    if file.get("type") != "file" or file.get("path") != f"{ARCHIVE}/{name}" or not file.get("link", "").endswith(f"/f/{file.get('id')}"):
        raise AssertionError(f"activity-export: the file has no link: {entry!r}")
    if entry.get("user") != "e2e-admin":
        raise AssertionError(f"activity-export: the run by hand names no author: {entry!r}")
    if entry.get("object_type") != "files" or entry.get("object_name") != "Documents/Paperless/Archive":
        raise AssertionError(f"activity-export: the entry does not belong to the archive folder: {entry!r}")
    if not entry.get("icon", "").endswith("/img/app.svg"):
        raise AssertionError(f"activity-export: the entry has no icon of the app: {entry!r}")
elif mode == "activity-job":
    entry = payload["ocs"]["data"][0]
    if entry.get("user") != "" or not str(entry.get("subject", "")).startswith("Paperless Sync added or updated 1 document: "):
        raise AssertionError(f"activity-job: the scheduled run has no entry of its own: {entry!r}")
elif mode == "activity-imports":
    entries = {str(entry.get("subject")): entry for entry in payload["ocs"]["data"]}
    imported = entries.get("policy.pdf was imported into Paperless as P501")
    if imported is None:
        raise AssertionError(f"activity-imports: no entry of the import: {list(entries)!r}")
    document = imported["subject_rich"][1].get("document", {})
    if document.get("link") != "http://paperless-mock:8080/documents/501/details":
        raise AssertionError(f"activity-imports: the new document has no link to Paperless: {imported!r}")
    failed = entries.get("Paperless could not import broken.pdf")
    if failed is None or failed.get("message") != "broken.pdf: Synthetic unsupported mime type":
        raise AssertionError(f"activity-imports: no entry of the failed import with its reason: {list(entries)!r}")
    cancelled = entries.get("Paperless could not import cancelled.pdf")
    if cancelled is None or not str(cancelled.get("message")).startswith("Paperless cancelled the task before it finished (REVOKED)"):
        raise AssertionError(f"activity-imports: no entry of the revoked import with its reason: {list(entries)!r}")
    duplicate = entries.get("Paperless could not import copy.pdf")
    if duplicate is None or "It is a duplicate of" not in str(duplicate.get("message")):
        raise AssertionError(f"activity-imports: no entry of the duplicate with its reason: {list(entries)!r}")
    if failed["subject_rich"][1]["file"].get("path") != "Documents/Paperless/Errors/broken.pdf":
        raise AssertionError(f"activity-imports: the failed import does not link the file in the error folder: {failed!r}")
    refused = entries.get("Paperless refused notes.bin; it stays in the inbox until it changes")
    if refused is None or "File type application/octet-stream not supported" not in str(refused.get("message")):
        raise AssertionError(f"activity-imports: no entry of the refusal with its reason: {list(entries)!r}")
    submitted = [subject for subject in entries if subject == "Paperless Sync sent 1 file to Paperless"]
    if not submitted:
        raise AssertionError(f"activity-imports: no entry of the uploads: {list(entries)!r}")
elif mode == "notifications-imports":
    # The target user has a notification for every import that failed or was refused, with the
    # reason of Paperless, and one that leads to the file in the error folder.
    entries = {str(entry.get("subject")): entry for entry in payload["ocs"]["data"] if entry.get("app") == "paperless_sync"}
    failed = entries.get("Paperless could not import broken.pdf")
    if failed is None or failed.get("message") != "broken.pdf: Synthetic unsupported mime type":
        raise AssertionError(f"notifications-imports: no notification of the failed import with its reason: {list(entries)!r}")
    if failed.get("object_type") != "import" or not str(failed.get("link", "")).endswith(f"/f/{failed.get('object_id')}"):
        raise AssertionError(f"notifications-imports: the failed import does not lead to its file: {failed!r}")
    if failed["subjectRichParameters"]["file"].get("path") != "Documents/Paperless/Errors/broken.pdf":
        raise AssertionError(f"notifications-imports: the failed import does not name the file in the error folder: {failed!r}")
    refused = entries.get("Paperless refused notes.bin; it stays in the inbox until it changes")
    if refused is None or "File type application/octet-stream not supported" not in str(refused.get("message")):
        raise AssertionError(f"notifications-imports: no notification of the refusal with its reason: {list(entries)!r}")
    if any(subject.endswith("was imported into Paperless") or " as P" in subject for subject in entries):
        raise AssertionError(f"notifications-imports: a successful import notified the user: {list(entries)!r}")
elif mode == "notifications-failing":
    entries = [entry for entry in payload["ocs"]["data"] if entry.get("app") == "paperless_sync" and entry.get("object_type") == "synchronization"]
    if len(entries) != 1:
        raise AssertionError(f"notifications-failing: expected one notification of the failing mirror, got {entries!r}")
    entry = entries[0]
    if not str(entry.get("subject")).startswith("Paperless Sync failed ") or not str(entry.get("subject")).endswith(" times in a row"):
        raise AssertionError(f"notifications-failing: unexpected subject: {entry!r}")
    if not entry.get("message") or len(entry["message"]) > 200:
        raise AssertionError(f"notifications-failing: the notification has no error of at most 200 characters: {entry!r}")
    if "/settings/admin/paperless_sync" not in str(entry.get("link")):
        raise AssertionError(f"notifications-failing: the notification does not lead to the settings: {entry!r}")
elif mode == "notifications-recovered":
    entries = [entry for entry in payload["ocs"]["data"] if entry.get("app") == "paperless_sync" and entry.get("object_type") == "synchronization"]
    if entries:
        raise AssertionError(f"notifications-recovered: a successful run left the notification of the failing mirror: {entries!r}")
elif mode == "status-api-failing":
    data = payload["ocs"]["data"]
    if data.get("healthy") is not False or data.get("health") != "failed" or data.get("failedRuns", 0) < 3 or not data.get("error"):
        raise AssertionError(f"status-api-failing: the status API does not report the failing mirror: {data!r}")
elif mode == "status-api-recovered":
    data = payload["ocs"]["data"]
    # ok, or off while the scheduled runs of the tests are off.
    if data.get("healthy") is not True or data.get("health") not in ("ok", "off") or data.get("failedRuns") != 0 or data.get("error") != "":
        raise AssertionError(f"status-api-recovered: the status API does not report the recovered mirror: {data!r}")
elif mode == "webhook-secret":
    # The answer to the creation of the secret of the webhook, the one time that it shows it.
    secret = payload.get("secret")
    if not isinstance(secret, str) or len(secret) != 48 or not secret.isalnum():
        raise AssertionError(f"webhook-secret: no secret of 48 letters and digits: {payload!r}")
    expect("configured", True)
    if not str(payload.get("url", "")).endswith("/apps/paperless_sync/api/v1/webhook"):
        raise AssertionError(f"webhook-secret: the address of the webhook is wrong: {payload!r}")
elif mode == "webhook-result":
    # The answer of the webhook to a call for P123, or another document, with its result.
    if payload.get("result") != argument:
        raise AssertionError(f"webhook-result: expected the result {argument!r}: {payload!r}")
    expect("document", 999 if argument == "missing" else 123)
elif mode == "webhook-state":
    # The webhook as the settings page shows it after the calls: the last call, and never the secret.
    if argument in serialized or "secret" in payload:
        raise AssertionError("webhook-state: the settings page received the secret of the webhook again")
    expect("configured", True)
    last_call = payload.get("lastCall") or {}
    if last_call.get("document") != 123 or last_call.get("result") != "moved":
        raise AssertionError(f"webhook-state: the last call is not the move of P123: {payload!r}")
elif mode == "mock-after-webhook":
    # Every call read its document alone, with the fields of the scan of a run.
    reads = payload.get("documentReads", [])
    if sorted({read.get("id") for read in reads}) != [123, 999]:
        raise AssertionError(f"mock-after-webhook: the runs of one document read other documents: {reads!r}")
    for read in reads:
        fields = read.get("fields", "").split(",")
        if "title" not in fields or "content" in fields:
            raise AssertionError(f"mock-after-webhook: a read asked for other fields than a run reads: {read!r}")
elif mode == "recovered":
    if payload.get("errors") != 0 or payload.get("dryRun") is not False:
        raise AssertionError(f"recovered: the run after the failures did not succeed: {payload!r}")
else:
    raise AssertionError(f"Unknown assertion mode: {mode}")
