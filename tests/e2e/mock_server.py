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
    "add_tag",
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
# The tags of the household of the tests; insurance is in lower case, as the folders of the
# inbox may name it in another case. No document has Private or Draft, which the settings page
# offers in its tag pickers, and it leaves out "Tax, 2026", a name with a comma, which a list of
# names can't hold.
TAGS = [
    {"id": 9, "name": "Inbox", "is_inbox_tag": True},
    {"id": 10, "name": "Private", "is_inbox_tag": False},
    {"id": 11, "name": "insurance"},
    {"id": 12, "name": "Draft", "is_inbox_tag": False},
    {"id": 13, "name": "Tax, 2026", "is_inbox_tag": False},
]
# A form field tags of an upload, as Guzzle writes it: its headers, an empty line and the ID.
TAG_FIELD = re.compile(rb'name="tags"\r\n(?:[^\r\n]+\r\n)*\r\n(\d+)\r\n')
# The content of P123 after /control/content/revised, as after a new scan in Paperless.
REVISED_CONTENT = b"%PDF-1.4\nSynthetic Paperless archive document P123, revised.\n%%EOF\n"
LOCK = threading.Lock()
STATE = {}
SCENARIOS = {
    "active", "renamed", "tagged", "excluded", "trash", "incomplete", "restored", "conflict", "vanished", "empty", "archive", "names",
    "per-user",
}
# The versions of Paperless-ngx that the mock plays, with the versions of the API that each serves
# and its default (src/paperless/settings.py, REST_FRAMEWORK): 2.15 is the last before version 9,
# and 3.0 made 10 the default.
PAPERLESS = {
    "2.15.3": {"allowed": range(1, 9), "default": 8},
    "2.20.15": {"allowed": range(1, 10), "default": 9},
    "3.3.0": {"allowed": (9, 10), "default": 10},
}
# The ends of an import task that /control/tasks/... sets, as Paperless-ngx 3.x keeps them in
# PaperlessTask: its status and its result_data (src/documents/signals/handlers.py).
TASK_ENDS = {"pending", "started", "success", "failure", "duplicate", "revoked"}


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
                # The ordering and the fields that each scan of the documents asked for.
                "documentScans": [],
                # The ID and the fields of every document that a run read alone, as the webhook asks.
                "documentReads": [],
                # The IDs of documents that the scan leaves out though Paperless has them, as pages
                # that shift while a run reads them can (/control/left-out/<ids>), and the ID of
                # every read of one of them alone.
                "leftOut": [],
                "leftOutReads": [],
                "invalidAuth": 0,
                "uploads": [],
                "refusedUploads": [],
                # The tags that the app created, with the fields that it sent.
                "createdTags": [],
                "tasks": {},
                "paperless": "3.3.0",
                # The API version that each request asked for, "none" without one.
                "apiVersions": {},
                # The document and its new owner of every change of an owner, as per-user ownership makes it.
                "ownerChanges": [],
            }
        )


def task_end(task, end, number):
    """Sets the status and result_data of a task as Paperless-ngx 3.x stores them."""
    task["status"] = "failure" if end == "duplicate" else end
    task["result_data"] = {
        "success": {"document_id": 500 + number},
        "failure": {
            "error_type": "ConsumerError",
            "error_message": f"{task['filename']}: Synthetic unsupported mime type",
            "traceback": "Traceback (most recent call last): synthetic",
        },
        "duplicate": {"duplicate_of": 123, "duplicate_in_trash": False},
    }.get(end)


def task_v9_paperless_2(task_id, task):
    """A task as Paperless-ngx 2.20 lists it (TasksViewSerializer): a Celery state and a text."""
    data = task["result_data"] or {}
    result, related = None, None
    if "document_id" in data:
        result, related = f"Success. New document id {data['document_id']} created", str(data["document_id"])
    elif "duplicate_of" in data:
        result = f"Not consuming {task['filename']}: It is a duplicate of Monthly invoice (#{data['duplicate_of']})."
        related = str(data["duplicate_of"])
    elif "error_message" in data:
        result = data["error_message"]
    return {
        "id": task["id"],
        "task_id": task_id,
        "task_name": "consume_file",
        "task_file_name": task["filename"],
        "date_created": "2026-10-13T10:00:00+02:00",
        "date_done": None if task["status"] in ("pending", "started") else "2026-10-13T10:00:30+02:00",
        "type": "auto_task",
        "status": task["status"].upper(),
        "result": result,
        "acknowledged": False,
        "related_document": related,
        "owner": 3,
    }


def task_v9_paperless_3(task_id, task):
    """A task as Paperless-ngx 3.x lists it in version 9 (TaskSerializerV9), derived from result_data."""
    data = task["result_data"] or {}
    result = None
    if "document_id" in data:
        result = f"Success. New document id {data['document_id']} created"
    elif "reason" in data:
        result = data["reason"]
    elif "duplicate_of" in data:
        result = f"Not consuming: It is a duplicate of document #{data['duplicate_of']}"
    elif "error_message" in data:
        result = data["error_message"]
    related = data.get("document_id") or data.get("duplicate_of")
    return {
        "id": task["id"],
        "task_id": task_id,
        "task_name": "consume_file",
        "task_file_name": task["filename"],
        "type": "manual_task",
        "status": task["status"].upper(),
        "date_created": "2026-10-13T10:00:00+02:00",
        "date_done": None if task["status"] in ("pending", "started") else "2026-10-13T10:00:30+02:00",
        "result": result,
        "acknowledged": False,
        "related_document": related,
        "duplicate_documents": (
            [{"id": data["duplicate_of"], "title": "Monthly invoice", "deleted_at": None}] if "duplicate_of" in data else []
        ),
        "owner": 3,
    }


def task_v10(task_id, task):
    """A task as Paperless-ngx 3.x lists it in version 10 (TaskSerializerV10)."""
    data = task["result_data"]
    related = [] if not data else [data[key] for key in ("document_id", "duplicate_of") if key in data][:1]
    return {
        "id": task["id"],
        "task_id": task_id,
        "task_type": "consume_file",
        "task_type_display": "Consume File",
        "trigger_source": "api_upload",
        "trigger_source_display": "API Upload",
        "status": task["status"],
        "status_display": task["status"].capitalize(),
        "date_created": "2026-10-13T10:00:00+02:00",
        "date_started": None if task["status"] == "pending" else "2026-10-13T10:00:01+02:00",
        "date_done": None if task["status"] in ("pending", "started") else "2026-10-13T10:00:30+02:00",
        "duration_seconds": None,
        "wait_time_seconds": None,
        "input_data": {"filename": task["filename"]},
        "result_data": data,
        "related_document_ids": related,
        "acknowledged": False,
        "owner": 3,
    }


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
        # Paperless-ngx sends the text of a document and its notes unless the request names the fields it needs.
        "content": "Synthetic OCR text of the monthly invoice. " * 100,
        # A note, which the metadata next to the archive keeps with the name of its author.
        "notes": [
            {
                "id": 1,
                "note": "Paid on 1 September.",
                "created": "2026-09-01T09:00:00+02:00",
                "user": {"id": 3, "username": "e2e-owner"},
            }
        ],
        # The fields of the variables of Paperless's own file names, which the default path
        # template doesn't use.
        "owner": 3,
        "archive_serial_number": 42,
        "page_count": 2,
        "custom_fields": [{"field": 1, "value": "INV-2026-08"}],
    }


# The users and the custom fields of Paperless, for {{ owner_username }} and
# {{ custom_fields.<name> }}.
USERS = [{"id": 3, "username": "e2e-owner"}, {"id": 4, "username": "E2E-Other"}]
CUSTOM_FIELDS = [{"id": 1, "name": "Invoice number", "data_type": "string", "extra_data": {}}]


def original_content(document_id):
    """The content of a document as the mock first serves it."""
    return f"%PDF-1.4\nSynthetic Paperless archive document P{document_id}.\n%%EOF\n".encode("utf-8")


def document_content(document_id):
    """The content of a document, that of P123 the revised one after /control/content/revised."""
    with LOCK:
        revised = STATE["content"] == "revised"
    return REVISED_CONTENT if document_id == 123 and revised else original_content(document_id)


def paperless_checksum(data):
    """The checksum of a document as the Paperless-ngx of the test reports it, MD5 or SHA-256."""
    with LOCK:
        algorithm = STATE["checksum"]
    return hashlib.new(algorithm, data).hexdigest()


def restored():
    # Paperless writes the time of the restore into the document, so the app compares
    # its checksum before it moves the copy back.
    return document("Monthly invoice corrected", "2026-08-28T09:00:00+02:00")


def statement():
    # A second document, for the conflicts and the document that vanishes.
    letter = document("Annual statement")
    letter["id"] = 124
    return letter


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
    if scenario in ("renamed", "tagged"):
        # A new content is a new revision of the document. Tagged is the same revision with the
        # tags insurance and "Tax, 2026", which leave its copy as it is.
        modified = "2026-08-26T12:00:00+02:00" if STATE["content"] == "revised" else "2026-08-26T11:00:00+02:00"
        return [document("Monthly invoice corrected", modified, [11, 13] if scenario == "tagged" else None)], []
    if scenario == "excluded":
        return [document("Monthly invoice corrected", "2026-08-26T11:00:00+02:00", [9])], []
    if scenario == "trash":
        trashed = document("Monthly invoice corrected", "2026-08-26T11:00:00+02:00")
        trashed["deleted_at"] = "2026-08-27T08:00:00+02:00"
        return [], [trashed]
    if scenario == "restored":
        return [restored()], []
    if scenario == "conflict":
        # A second document, at whose path in Nextcloud a file of the user waits.
        return [restored(), statement()], []
    if scenario == "vanished":
        # The first document is gone from Paperless without passing through its trash.
        return [statement()], []
    if scenario in ("empty", "incomplete"):
        return [], []
    if scenario == "archive":
        # A larger archive, whose dry-run lists more changes than the report shows at once.
        return [archived_letter(number) for number in range(1, 41)], []
    if scenario == "names":
        return [named_document(document_id) for document_id in NAMED_DOCUMENTS], []
    if scenario == "per-user":
        # Per-user ownership: P123 of e2e-owner, P124 of E2E-Other, whose user of Nextcloud is
        # e2e-other, whatever the case, and the documents that the inbox of e2e-owner sent. As in
        # Paperless, the account of the app owns the document of an upload until the app gives it
        # its owner, so the runs must hold it until then.
        other = statement()
        other["owner"] = 4
        with LOCK:
            owners = {
                task["result_data"]["document_id"]: OWNER_ID
                for task in STATE["tasks"].values()
                if task["status"] == "success" and "document_id" in (task["result_data"] or {})
            }
            owners.update({change["document"]: change["owner"] for change in STATE["ownerChanges"]})
        sent = []
        for document_id, owner in owners.items():
            letter = document(f"Sent letter {document_id}")
            letter["id"] = document_id
            letter["owner"] = owner
            sent.append(letter)
        return [document(), other, *sent], []
    raise ValueError(f"Unknown scenario: {scenario}")


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.api_headers = {}
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
        if token is None or not self._versioned():
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
        elif parsed.path == "/api/documents/" and ("owner__id" in query or "owner__isnull" in query):
            # The count of the documents of a user, or of those without an owner, for the
            # connection checklist of per-user ownership.
            owner = int(query["owner__id"][0]) if "owner__id" in query else None
            self._page([item for item in documents if item.get("owner") == owner])
        elif parsed.path == "/api/documents/" and "checksum__iexact" in query:
            # The lookup of a document by the checksum of its original, MD5 up to Paperless-ngx 2.x
            # and SHA-256 since 3.0, as before an upload from Files.
            checksum = query["checksum__iexact"][0].lower()
            self._page([
                {"id": item["id"]}
                for item in documents
                if checksum in (hashlib.md5(document_content(item["id"])).hexdigest(), hashlib.sha256(document_content(item["id"])).hexdigest())
            ])
        elif parsed.path == "/api/documents/":
            # Paperless-ngx sends only the fields that a request names, and the scan of a run names them.
            if "ordering" in query:
                with LOCK:
                    STATE["documentScans"].append(
                        {"ordering": query["ordering"][0], "fields": query.get("fields", [""])[0]}
                    )
            if "fields" in query:
                fields = query["fields"][0].split(",")
                documents = [{key: value for key, value in item.items() if key in fields} for item in documents]
            # A scan that pages shifted: one document deleted and another one added keep the count,
            # so the scan looks complete without a document that /api/documents/<id>/ still serves.
            with LOCK:
                left_out = set(STATE["leftOut"])
            documents = [item for item in documents if item.get("id") not in left_out]
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
            with LOCK:
                created = [{"id": tag["id"], "name": tag["name"]} for tag in STATE["createdTags"]]
            self._page(showcase.TAGS if self._showcase() else [*TAGS, *created])
        elif parsed.path == "/api/users/":
            self._page(USERS)
        elif parsed.path == "/api/custom_fields/":
            self._page(CUSTOM_FIELDS)
        elif parsed.path == "/api/tasks/":
            task_id = query.get("task_id", [""])[0]
            with LOCK:
                task = STATE["tasks"].get(task_id)
                paperless = STATE["paperless"]
            if self.api_version >= 10:
                self._page([] if task is None else [task_v10(task_id, task)])
            else:
                # Version 9 lists the tasks without pages.
                render = task_v9_paperless_2 if paperless.startswith("2.") else task_v9_paperless_3
                self._json(200, [] if task is None else [render(task_id, task)])
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
            # The documents with long or unusual names carry the first content of P123.
            data = original_content(123)
            if parsed.path.endswith("/download/"):
                self._bytes(200, data, "application/pdf")
            else:
                checksum = paperless_checksum(data)
                self._json(200, {"original_checksum": checksum, "archive_checksum": checksum})
        elif match := re.fullmatch(r"/api/documents/(123|124|5\d\d)/(download|metadata)/", parsed.path):
            # P123, P124, and the documents of the uploads, which the scenario per-user lists.
            data = document_content(int(match.group(1)))
            if match.group(2) == "download":
                with LOCK:
                    STATE["downloads"] += 1
                self._bytes(200, data, "application/pdf")
            else:
                checksum = paperless_checksum(data)
                with LOCK:
                    STATE["metadataRequests"] += 1
                self._json(200, {"original_checksum": checksum, "archive_checksum": checksum})
        elif match := re.fullmatch(r"/api/documents/(\d+)/", parsed.path):
            # One document, as the run of one document reads it for the webhook of a workflow;
            # Paperless answers 404 for a document in its trash or deleted.
            document_id = int(match.group(1))
            fields = query.get("fields", [""])[0]
            with LOCK:
                STATE["documentReads"].append({"id": document_id, "fields": fields})
                if document_id in STATE["leftOut"]:
                    STATE["leftOutReads"].append(document_id)
            document = next((item for item in documents if item.get("id") == document_id), None)
            if document is None:
                self._json(404, {"detail": "No Document matches the given query."})
            else:
                if fields:
                    document = {key: value for key, value in document.items() if key in fields.split(",")}
                self._json(200, document)
        else:
            self._json(404, {"detail": "Not found"})

    def do_POST(self):
        self.api_headers = {}
        parsed = urlparse(self.path)
        if parsed.path.startswith("/control/scenario/"):
            scenario = parsed.path.rsplit("/", 1)[-1]
            if scenario not in SCENARIOS | showcase.SCENARIOS:
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
        if match := re.fullmatch(r"/control/left-out/(none|\d+(?:,\d+)*)", parsed.path):
            # The documents that the scan leaves out: /control/left-out/123,124, or none.
            left_out = [] if match.group(1) == "none" else [int(item) for item in match.group(1).split(",")]
            with LOCK:
                STATE["leftOut"] = left_out
            self._json(200, {"leftOut": left_out})
            return
        if parsed.path.startswith("/control/paperless/"):
            version = parsed.path.rsplit("/", 1)[-1]
            if version not in PAPERLESS:
                self._json(400, {"detail": "Unknown Paperless version"})
                return
            with LOCK:
                STATE["paperless"] = version
            self._json(200, {"paperless": version})
            return
        if parsed.path.startswith("/control/tasks/"):
            # The end of every task: /control/tasks/<end>.
            end = parsed.path.rsplit("/", 1)[-1]
            if end not in TASK_ENDS:
                self._json(400, {"detail": "Unknown task end"})
                return
            with LOCK:
                for task in STATE["tasks"].values():
                    task_end(task, end, task["id"])
            self._json(200, {"end": end})
            return
        if match := re.fullmatch(r"/control/task-of/([^/]+)/([a-z]+)", parsed.path):
            # The end of the task of the last upload of a file: /control/task-of/<name>/<end>.
            filename, end = match.groups()
            with LOCK:
                task_ids = [upload["taskId"] for upload in STATE["uploads"] if upload["filename"] == filename]
                if end in TASK_ENDS and task_ids:
                    task = STATE["tasks"][task_ids[-1]]
                    task_end(task, end, task["id"])
            if end not in TASK_ENDS or not task_ids:
                self._json(400, {"detail": "Unknown task end or file"})
                return
            self._json(200, {"end": end})
            return
        if parsed.path == "/control/reset":
            reset_state()
            self._json(200, {"status": "reset"})
            return
        if parsed.path == "/api/tags/":
            self._create_tag()
            return
        if parsed.path != "/api/documents/post_document/":
            self._json(404, {"detail": "Not found"})
            return
        token = self._authorized()
        if token is None or not self._versioned():
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
            number = len(STATE["uploads"]) + 1
            task_id = f"task-{number}"
            STATE["uploads"].append(
                {
                    "taskId": task_id,
                    "filename": filename,
                    "containsFixture": b"Synthetic inbox PDF" in body,
                    # The IDs of the tags of the new document: one form field tags for each.
                    "tags": [int(tag) for tag in TAG_FIELD.findall(body)],
                }
            )
            STATE["tasks"][task_id] = {"id": number, "filename": filename, "status": "pending", "result_data": None}
        self._json(200, task_id)

    def do_PATCH(self):
        """Changes the owner of a document, as per-user ownership does after an upload; nothing else."""
        self.api_headers = {}
        match = re.fullmatch(r"/api/documents/(\d+)/", urlparse(self.path).path)
        token = self._authorized()
        if token is None or not self._versioned():
            return
        if match is None:
            self._json(404, {"detail": "Not found"})
            return
        if "change_document" not in ACCOUNTS[token]["permissions"]:
            self._json(403, FORBIDDEN)
            return
        length = int(self.headers.get("Content-Length", "0"))
        try:
            fields = json.loads(self.rfile.read(length) or b"null")
        except ValueError:
            fields = None
        if not isinstance(fields, dict) or set(fields) != {"owner"} or not isinstance(fields["owner"], int):
            self._json(400, {"detail": "Only the owner may change."})
            return
        document_id = int(match.group(1))
        with LOCK:
            STATE["ownerChanges"].append({"document": document_id, "owner": fields["owner"]})
        self._json(200, {"id": document_id, "owner": fields["owner"]})

    def _create_tag(self):
        """Creates a tag as Paperless-ngx does: with Add for Tags, a name, and an ID of its own."""
        token = self._authorized()
        if token is None or not self._versioned():
            return
        if "add_tag" not in ACCOUNTS[token]["permissions"]:
            self._json(403, FORBIDDEN)
            return
        length = int(self.headers.get("Content-Length", "0"))
        try:
            fields = json.loads(self.rfile.read(length) or b"null")
        except ValueError:
            fields = None
        name = fields.get("name") if isinstance(fields, dict) else None
        if not isinstance(name, str) or not name.strip():
            self._json(400, {"name": ["This field is required."]})
            return
        with LOCK:
            existing = [tag["name"].lower() for tag in [*TAGS, *STATE["createdTags"]]]
            if name.lower() in existing:
                self._json(400, {"name": ["Tag with this name already exists."]})
                return
            tag = {"id": 100 + len(STATE["createdTags"]), "name": name, "matching_algorithm": fields.get("matching_algorithm")}
            STATE["createdTags"].append(tag)
        self._json(201, tag)

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
        return token

    def _versioned(self):
        """
        Serves the version of the API that the header Accept asks for, or the default of the
        Paperless that the mock plays, as Django REST framework's AcceptHeaderVersioning does, and
        refuses one that it doesn't serve with 406. An answer to a request with a token names the
        newest version and the version of Paperless in X-Api-Version and X-Version.
        """
        match = re.search(r"version=([^;,\s]+)", self.headers.get("Accept", ""))
        with LOCK:
            paperless = STATE["paperless"]
            versions = STATE["apiVersions"]
            requested = match.group(1) if match else "none"
            versions[requested] = versions.get(requested, 0) + 1
        allowed = PAPERLESS[paperless]["allowed"]
        if match is None:
            self.api_version = PAPERLESS[paperless]["default"]
        elif match.group(1).isdigit() and int(match.group(1)) in allowed:
            self.api_version = int(match.group(1))
        else:
            self._json(406, {"detail": 'Invalid version in "Accept" header.'})
            return False
        self.api_headers = {"X-Api-Version": str(max(allowed)), "X-Version": paperless}
        return True

    def _page(self, results, count=None):
        self._json(200, {"count": len(results) if count is None else count, "next": None, "previous": None, "results": results})

    def _json(self, status, payload):
        self._bytes(status, json.dumps(payload).encode("utf-8"), "application/json")

    def _bytes(self, status, body, content_type):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        for name, value in getattr(self, "api_headers", {}).items():
            self.send_header(name, value)
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, message, *args):
        print(f"paperless-mock: {message % args}", flush=True)


if __name__ == "__main__":
    reset_state()
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
