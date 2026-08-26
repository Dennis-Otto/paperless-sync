#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys

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
elif mode == "mock-final":
    expect("downloads", 2)
    expect("invalidAuth", 0)
    uploads = payload.get("uploads", [])
    if [item.get("filename") for item in uploads] != ["policy.pdf", "broken.pdf"]:
        raise AssertionError(f"Unexpected upload filenames: {uploads!r}")
    if not all(item.get("containsFixture") is True for item in uploads):
        raise AssertionError(f"Uploaded fixtures were not transferred intact: {uploads!r}")
else:
    raise AssertionError(f"Unknown assertion mode: {mode}")
