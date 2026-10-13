#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys
from urllib.parse import quote

mode = sys.argv[1]
payload = json.load(sys.stdin)
serialized = json.dumps(payload, sort_keys=True)

if "e2e-only-token" in serialized:
    raise AssertionError("A response exposed the synthetic Paperless token")


def expect(key, value):
    actual = payload.get(key)
    if actual != value:
        raise AssertionError(f"{mode}: expected {key}={value!r}, got {actual!r}; payload={payload!r}")


if mode == "config":
    expect("paperlessUrl", "http://paperless-mock:8080")
    expect("targetUser", "sync-target")
    expect("tokenConfigured", True)
    expect("permanentDelete", True)
elif mode == "dry-run":
    expect("dryRun", True)
    expect("exported", 1)
    expect("errors", 0)
    if not any(str(action).startswith("EXPORT P123:") for action in payload.get("actions", [])):
        raise AssertionError(f"Dry-run export action missing: {payload!r}")
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
    expect("importsSubmitted", 1)
    expect("errors", 0)
elif mode == "import-success":
    expect("importsSucceeded", 1)
    expect("errors", 0)
elif mode == "import-failed":
    expect("importsFailed", 1)
    expect("errors", 0)
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
elif mode == "status":
    expect("state", "completed")
    expect("error", "")
    if not isinstance(payload.get("summary"), dict):
        raise AssertionError(f"Status summary is missing: {payload!r}")
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
    # The first export, the one that the full quota stopped after its download, and the export after it.
    expect("downloads", 3)
    expect("invalidAuth", 0)
    uploads = payload.get("uploads", [])
    if [item.get("filename") for item in uploads] != ["policy.pdf", "broken.pdf"]:
        raise AssertionError(f"Unexpected upload filenames: {uploads!r}")
    if not all(item.get("containsFixture") is True for item in uploads):
        raise AssertionError(f"Uploaded fixtures were not transferred intact: {uploads!r}")
    expect("refusedUploads", ["notes.bin"])
else:
    raise AssertionError(f"Unknown assertion mode: {mode}")
