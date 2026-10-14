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

if "e2e-only-token" in serialized:
    raise AssertionError("A response exposed the synthetic Paperless token")


def expect(key, value):
    actual = payload.get(key)
    if actual != value:
        raise AssertionError(f"{mode}: expected {key}={value!r}, got {actual!r}; payload={payload!r}")


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
    # A save with a token entered tests the connection first.
    expect("connectionTested", True)
elif mode == "local-network-refused":
    expect(
        "message",
        "Nextcloud blocks connections to local addresses such as paperless-mock. "
        'If Paperless runs in your network, turn on "Paperless runs in my local network" '
        "and save the settings.",
    )
    expect("setting", "paperless_local_network")
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
    expect_action("IMPORT SUCCESS: Documents/Paperless/Inbox/Insurance/policy.pdf -> P501")
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
else:
    raise AssertionError(f"Unknown assertion mode: {mode}")
