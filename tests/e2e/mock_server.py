#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import hashlib
import json
import re
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

TOKEN = "e2e-only-token"
DOCUMENT_CONTENT = b"%PDF-1.4\nSynthetic Paperless archive document P123.\n%%EOF\n"
LOCK = threading.Lock()
STATE = {}


def reset_state():
    with LOCK:
        STATE.clear()
        STATE.update(
            {
                "scenario": "active",
                "downloads": 0,
                "metadataRequests": 0,
                "invalidAuth": 0,
                "uploads": [],
                "tasks": {},
            }
        )


def document(title="Monthly invoice", modified="2026-08-26T10:00:00+02:00", tags=None):
    return {
        "id": 123,
        "title": title,
        "correspondent": 4,
        "document_type": 7,
        "storage_path": None,
        "tags": [] if tags is None else tags,
        "created": "2026-08-26",
        "added": "2026-08-26T10:00:00+02:00",
        "modified": modified,
        "original_file_name": "scan.pdf",
        "archived_file_name": "archive.pdf",
        "mime_type": "application/pdf",
    }


def scenario_documents():
    scenario = STATE["scenario"]
    if scenario == "active":
        return [document()], []
    if scenario == "renamed":
        return [document("Monthly invoice corrected", "2026-08-26T11:00:00+02:00")], []
    if scenario == "excluded":
        return [document("Monthly invoice corrected", "2026-08-26T11:00:00+02:00", [9])], []
    if scenario == "trash":
        trashed = document("Monthly invoice corrected", "2026-08-26T11:00:00+02:00")
        trashed["deleted_at"] = "2026-08-27T08:00:00+02:00"
        return [], [trashed]
    if scenario == "empty":
        return [], []
    raise ValueError(f"Unknown scenario: {scenario}")


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/health":
            self._json(200, {"status": "ok"})
            return
        if parsed.path == "/control/state":
            with LOCK:
                self._json(200, STATE.copy())
            return
        if not parsed.path.startswith("/api/"):
            self._json(404, {"detail": "Not found"})
            return
        if not self._authorized():
            return

        documents, trash = scenario_documents()
        if parsed.path == "/api/documents/":
            self._page(documents)
        elif parsed.path == "/api/trash/":
            self._page(trash)
        elif parsed.path == "/api/correspondents/":
            self._page([{"id": 4, "name": "Example GmbH"}])
        elif parsed.path == "/api/document_types/":
            self._page([{"id": 7, "name": "Invoice"}])
        elif parsed.path == "/api/storage_paths/":
            self._page([])
        elif parsed.path == "/api/tags/":
            self._page([{"id": 9, "name": "Inbox", "is_inbox_tag": True}])
        elif parsed.path == "/api/tasks/":
            task_id = parse_qs(parsed.query).get("task_id", [""])[0]
            with LOCK:
                task = STATE["tasks"].get(task_id)
            self._page([] if task is None else [{"task_id": task_id, **task}])
        elif parsed.path == "/api/documents/123/download/":
            with LOCK:
                STATE["downloads"] += 1
            self._bytes(200, DOCUMENT_CONTENT, "application/pdf")
        elif parsed.path == "/api/documents/123/metadata/":
            checksum = hashlib.sha256(DOCUMENT_CONTENT).hexdigest()
            with LOCK:
                STATE["metadataRequests"] += 1
            self._json(200, {"original_checksum": checksum, "archive_checksum": checksum})
        else:
            self._json(404, {"detail": "Not found"})

    def do_POST(self):
        parsed = urlparse(self.path)
        if parsed.path.startswith("/control/scenario/"):
            scenario = parsed.path.rsplit("/", 1)[-1]
            if scenario not in {"active", "renamed", "excluded", "trash", "empty"}:
                self._json(400, {"detail": "Unknown scenario"})
                return
            with LOCK:
                STATE["scenario"] = scenario
            self._json(200, {"scenario": scenario})
            return
        if parsed.path.startswith("/control/tasks/"):
            status = parsed.path.rsplit("/", 1)[-1].upper()
            if status not in {"PENDING", "SUCCESS", "FAILURE"}:
                self._json(400, {"detail": "Unknown task status"})
                return
            with LOCK:
                for task in STATE["tasks"].values():
                    task["status"] = status
                    task["message"] = "Synthetic unsupported mime type" if status == "FAILURE" else ""
            self._json(200, {"status": status})
            return
        if parsed.path == "/control/reset":
            reset_state()
            self._json(200, {"status": "reset"})
            return
        if parsed.path != "/api/documents/post_document/":
            self._json(404, {"detail": "Not found"})
            return
        if not self._authorized():
            return

        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        match = re.search(br'filename="([^"]+)"', body)
        filename = match.group(1).decode("utf-8", "replace") if match else "unknown"
        with LOCK:
            task_id = f"task-{len(STATE['uploads']) + 1}"
            STATE["uploads"].append(
                {
                    "taskId": task_id,
                    "filename": filename,
                    "containsFixture": b"Synthetic inbox PDF" in body,
                }
            )
            STATE["tasks"][task_id] = {"status": "PENDING", "message": ""}
        self._json(200, task_id)

    def _authorized(self):
        valid = (
            self.headers.get("Authorization") == f"Token {TOKEN}"
            and self.headers.get("User-Agent", "").startswith("Nextcloud-Paperless-Sync/")
        )
        if not valid:
            with LOCK:
                STATE["invalidAuth"] += 1
            self._json(401, {"detail": "Invalid synthetic credentials"})
        return valid

    def _page(self, results):
        self._json(200, {"count": len(results), "next": None, "previous": None, "results": results})

    def _json(self, status, payload):
        self._bytes(status, json.dumps(payload).encode("utf-8"), "application/json")

    def _bytes(self, status, body, content_type):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, message, *args):
        print(f"paperless-mock: {message % args}", flush=True)


if __name__ == "__main__":
    reset_state()
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
