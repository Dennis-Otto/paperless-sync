#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import hashlib
import json
import re
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

import showcase

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
                "refusedUploads": [],
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


def archived_letter(number):
    letter = document(f"Archived letter {number}")
    letter["id"] = 1000 + number
    return letter


# Titles and a correspondent that a path template must take as they are: "$1" and
# "{{ … }}" stay text, and the names in Chinese, emoji and Cyrillic are longer than the
# 250 bytes that Nextcloud accepts.
NAMED_DOCUMENTS = {
    201: ("Refund $100 from Shop $1 ${1}0 {{ correspondent }}", 4),
    202: ("株" * 120, 4),
    203: ("😀" * 120, 4),
    204: ("Monthly invoice", 5),
}
LONG_CORRESPONDENT = {"id": 5, "name": "Ж" * 120}


def named_document(document_id):
    title, correspondent = NAMED_DOCUMENTS[document_id]
    named = document(title)
    named["id"] = document_id
    named["correspondent"] = correspondent
    return named


def scenario_documents():
    scenario = STATE["scenario"]
    if scenario in showcase.SCENARIOS:
        return showcase.documents(scenario)
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
    if scenario in ("empty", "incomplete"):
        return [], []
    if scenario == "archive":
        # A larger archive, whose dry-run lists more changes than the report shows at once.
        return [archived_letter(number) for number in range(1, 41)], []
    if scenario == "names":
        return [named_document(document_id) for document_id in NAMED_DOCUMENTS], []
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
            # An incomplete answer, as of a proxy or a permission glitch: an empty page whose count
            # still lists the document.
            self._page(documents, 1 if STATE["scenario"] == "incomplete" else None)
        elif parsed.path == "/api/trash/":
            self._page(trash)
        elif parsed.path == "/api/correspondents/":
            correspondents = [{"id": 4, "name": "Example GmbH"}]
            if self._scenario() == "names":
                correspondents.append(LONG_CORRESPONDENT)
            self._page(showcase.CORRESPONDENTS if self._showcase() else correspondents)
        elif parsed.path == "/api/document_types/":
            self._page(showcase.DOCUMENT_TYPES if self._showcase() else [{"id": 7, "name": "Invoice"}])
        elif parsed.path == "/api/storage_paths/":
            self._page([])
        elif parsed.path == "/api/tags/":
            self._page(showcase.TAGS if self._showcase() else [{"id": 9, "name": "Inbox", "is_inbox_tag": True}])
        elif parsed.path == "/api/tasks/":
            task_id = parse_qs(parsed.query).get("task_id", [""])[0]
            with LOCK:
                task = STATE["tasks"].get(task_id)
            self._page([] if task is None else [{"task_id": task_id, **task}])
        elif self._showcase() and re.fullmatch(r"/api/documents/\d+/(download|metadata)/", parsed.path):
            document_id = int(parsed.path.split("/")[3])
            data = showcase.content(document_id)
            if data is None:
                self._json(404, {"detail": "Not found"})
            elif parsed.path.endswith("/download/"):
                self._bytes(200, data, "application/pdf")
            else:
                checksum = showcase.checksum(document_id)
                self._json(200, {"original_checksum": checksum, "archive_checksum": checksum})
        elif self._scenario() == "names" and re.fullmatch(r"/api/documents/20[1-4]/(download|metadata)/", parsed.path):
            if parsed.path.endswith("/download/"):
                self._bytes(200, DOCUMENT_CONTENT, "application/pdf")
            else:
                checksum = hashlib.sha256(DOCUMENT_CONTENT).hexdigest()
                self._json(200, {"original_checksum": checksum, "archive_checksum": checksum})
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
            if scenario not in {"active", "renamed", "excluded", "trash", "incomplete", "empty", "archive", "names"} | showcase.SCENARIOS:
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

        # Like Django under Paperless, read a body only as long as its Content-Length: a
        # chunked body without one arrives as no file at all.
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        match = re.search(br'filename="([^"]+)"', body)
        filename = match.group(1).decode("utf-8", "replace") if match else "unknown"
        refusal = None
        if length == 0:
            refusal = "No file was submitted."
        elif b"Synthetic unsupported file" in body:
            refusal = "File type application/octet-stream not supported"
        if refusal is not None:
            with LOCK:
                STATE["refusedUploads"].append(filename)
            self._json(400, {"document": [refusal]})
            return
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

    def _showcase(self):
        return self._scenario() in showcase.SCENARIOS

    def _scenario(self):
        with LOCK:
            return STATE["scenario"]

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

    def _page(self, results, count=None):
        self._json(200, {"count": len(results) if count is None else count, "next": None, "previous": None, "results": results})

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
