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

# The token of the account of the app, as the README sets it up: no superuser, with every
# permission that the export and the inbox need, and the owner of every document.
TOKEN = "e2e-only-token"
# The token of an account that may view the documents, correspondents, document types and
# storage paths, but neither the tags nor add documents and view their tasks: the
# connection checklist names those permissions, and notes that it sees documents of
# another owner, which the trash of Paperless leaves out.
VIEW_ONLY_TOKEN = "e2e-view-only-token"
# The token of an account with every permission of a run but View for UI Settings, so
# that Paperless doesn't tell the checklist its permissions and its ID.
NO_UI_SETTINGS_TOKEN = "e2e-no-ui-settings-token"
FORBIDDEN = {"detail": "You do not have permission to perform this action."}
# The ID of the account of the app, which owns every document of the mock.
OWNER_ID = 1
RUN_PERMISSIONS = [
    "view_document",
    "add_document",
    "change_document",
    "delete_document",
    "view_correspondent",
    "view_documenttype",
    "view_storagepath",
    "view_tag",
    "view_paperlesstask",
]


def account(user_id, username, permissions):
    """What /api/ui_settings/ says of an account that is no superuser, as Paperless-ngx does."""
    return {
        "user": {"id": user_id, "username": username, "is_staff": False, "is_superuser": False, "groups": []},
        "settings": {},
        "permissions": permissions,
    }


ACCOUNTS = {
    TOKEN: account(OWNER_ID, "nextcloud", [*RUN_PERMISSIONS, "view_uisettings"]),
    VIEW_ONLY_TOKEN: account(
        2,
        "nextcloud-view-only",
        ["view_document", "view_correspondent", "view_documenttype", "view_storagepath", "view_uisettings"],
    ),
    NO_UI_SETTINGS_TOKEN: account(3, "nextcloud-no-ui-settings", RUN_PERMISSIONS),
}
# The permission that Paperless-ngx asks of an account for each endpoint that the app reads.
VIEW_PERMISSIONS = {
    "/api/documents/": "view_document",
    "/api/trash/": "view_document",
    "/api/correspondents/": "view_correspondent",
    "/api/document_types/": "view_documenttype",
    "/api/storage_paths/": "view_storagepath",
    "/api/tags/": "view_tag",
    "/api/tasks/": "view_paperlesstask",
    "/api/ui_settings/": "view_uisettings",
}
DOCUMENT_CONTENT = b"%PDF-1.4\nSynthetic Paperless archive document P123.\n%%EOF\n"
# The content of P123 after /control/content/revised, as after a new scan in Paperless.
REVISED_CONTENT = b"%PDF-1.4\nSynthetic Paperless archive document P123, revised.\n%%EOF\n"
LOCK = threading.Lock()
STATE = {}


def reset_state():
    with LOCK:
        STATE.clear()
        STATE.update(
            {
                "scenario": "active",
                "content": "original",
                # Paperless-ngx reports MD5 checksums up to 2.x and SHA-256 since 3.0.
                "checksum": "sha256",
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


def document_content():
    """The content of P123, the revised one after /control/content/revised."""
    with LOCK:
        revised = STATE["content"] == "revised"
    return REVISED_CONTENT if revised else DOCUMENT_CONTENT


def paperless_checksum(data):
    """The checksum of a document as the Paperless-ngx of the test reports it, MD5 or SHA-256."""
    with LOCK:
        algorithm = STATE["checksum"]
    return hashlib.new(algorithm, data).hexdigest()


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
        # A new content is a new revision of the document.
        modified = "2026-08-26T12:00:00+02:00" if STATE["content"] == "revised" else "2026-08-26T11:00:00+02:00"
        return [document("Monthly invoice corrected", modified)], []
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
        token = self._authorized()
        if token is None:
            return

        documents, trash = scenario_documents()
        query = parse_qs(parsed.query)
        permission = VIEW_PERMISSIONS.get(parsed.path)
        if permission is not None and permission not in ACCOUNTS[token]["permissions"]:
            self._json(403, FORBIDDEN)
        elif parsed.path == "/api/ui_settings/":
            self._json(200, ACCOUNTS[token])
        elif parsed.path == "/api/documents/" and "owner__id__none" in query:
            # The filter of the connection checklist: the documents with an owner other than the
            # account. The account of the app owns every document of the mock.
            others = query["owner__id__none"][0] != str(OWNER_ID) and query.get("owner__isnull") == ["0"]
            self._page(documents if others else [])
        elif parsed.path == "/api/documents/":
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
            task_id = query.get("task_id", [""])[0]
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
                checksum = paperless_checksum(DOCUMENT_CONTENT)
                self._json(200, {"original_checksum": checksum, "archive_checksum": checksum})
        elif parsed.path == "/api/documents/123/download/":
            with LOCK:
                STATE["downloads"] += 1
            self._bytes(200, document_content(), "application/pdf")
        elif parsed.path == "/api/documents/123/metadata/":
            checksum = paperless_checksum(document_content())
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
        if parsed.path.startswith("/control/content/"):
            content = parsed.path.rsplit("/", 1)[-1]
            if content not in {"original", "revised"}:
                self._json(400, {"detail": "Unknown content"})
                return
            with LOCK:
                STATE["content"] = content
            self._json(200, {"content": content})
            return
        if parsed.path.startswith("/control/checksum/"):
            algorithm = parsed.path.rsplit("/", 1)[-1]
            if algorithm not in {"md5", "sha256"}:
                self._json(400, {"detail": "Unknown checksum"})
                return
            with LOCK:
                STATE["checksum"] = algorithm
            self._json(200, {"checksum": algorithm})
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
        token = self._authorized()
        if token is None:
            return
        if "add_document" not in ACCOUNTS[token]["permissions"]:
            self._json(403, FORBIDDEN)
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
        """The token of a request with a valid token and the User-Agent of the app, or None."""
        authorization = self.headers.get("Authorization", "")
        token = authorization.removeprefix("Token ")
        valid = (
            authorization.startswith("Token ")
            and token in ACCOUNTS
            and self.headers.get("User-Agent", "").startswith("Nextcloud-Paperless-Sync/")
        )
        if not valid:
            with LOCK:
                STATE["invalidAuth"] += 1
            self._json(401, {"detail": "Invalid synthetic credentials"})
            return None
        # Paperless-ngx names its version in every answer to an authenticated request: 2.x with
        # the MD5 checksums of the test, 3.x with SHA-256.
        with LOCK:
            self.paperless_version = "2.20.15" if STATE["checksum"] == "md5" else "3.3.0"
        return token

    def _page(self, results, count=None):
        self._json(200, {"count": len(results) if count is None else count, "next": None, "previous": None, "results": results})

    def _json(self, status, payload):
        self._bytes(status, json.dumps(payload).encode("utf-8"), "application/json")

    def _bytes(self, status, body, content_type):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        version = getattr(self, "paperless_version", None)
        if version is not None:
            self.send_header("X-Version", version)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, message, *args):
        print(f"paperless-mock: {message % args}", flush=True)


if __name__ == "__main__":
    reset_state()
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
