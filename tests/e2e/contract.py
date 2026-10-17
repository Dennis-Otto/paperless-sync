#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The contract tests against a real Paperless-ngx, which run-contract.sh runs in the
container of the mock, inside the network of Compose, with Python alone:

    python contract.py api URL LINE   seeds Paperless and checks every answer that the app reads
    python contract.py token          the token of the API that api got
    python contract.py rename|trash|restore
                                      changes the invoice in Paperless for the next run of the app
    python contract.py pdf NAME       a PDF for the inbox of the app, on standard output
    python contract.py wait           waits until Paperless has consumed every upload
    python contract.py report MODE    checks the report of a run of the app on standard input
    python contract.py content KEY    compares the file on standard input with the document in Paperless
    python contract.py imported NAME ID
                                      checks that the upload NAME of the app became document ID
    python contract.py status         checks the status of the app on standard input
    python contract.py log            checks the log of Nextcloud on standard input
    python contract.py shapes DIR     compares the shapes of the answers with the fixtures in DIR,
                                      and fails when a field is gone or has another type
    python contract.py fixtures       the recorded answers, as a tar archive on standard output

The answers that api records become the fixtures of the unit tests (tests/fixtures/), so
that those read the shapes of a real Paperless instead of the shapes that the mock assumes.
LINE is 2 for Paperless-ngx 2.x or 3 for 3.x, where the checksums, the tasks of version 10
and the handling of duplicates differ.
"""

import base64
import hashlib
import io
import json
import re
import sys
import tarfile
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path
from urllib.parse import quote

# The version of the API that every request of the app asks for (decision 0012).
API_VERSION = "9"
USERNAME = "contract-admin"
PASSWORD = "contract-only-password"
STATE = Path("/tmp/contract/state.json")
RECORDINGS = Path("/tmp/contract/fixtures")
# Paperless takes a few seconds for each PDF, longer for the first one after its start.
TASK_TIMEOUT = 180
BASE = "Documents/Paperless"
ARCHIVE = f"{BASE}/Archive"
DELETED = f"{ARCHIVE}/_Deleted"
INBOX = f"{BASE}/Inbox"
ERRORS = f"{BASE}/Errors"

# The documents of the scenario: the key, the file, the title after the edit, the
# correspondent, the document type and the created date.
DOCUMENTS = {
    "invoice": ("invoice.pdf", "Monthly invoice", "Example GmbH", "Invoice", "2026-08-26"),
    "statement": ("statement.pdf", "Annual statement", "Example GmbH", "Statement", "2026-08-27"),
    # The tag Inbox of Paperless stays on it, so the app leaves it out.
    "inbox": ("unread.pdf", "Unread letter", "Example Insurance", "Letter", "2026-08-28"),
}
RENAMED = "Monthly invoice corrected"
# A file of no type that Paperless takes: the zero bytes first make it application/octet-stream.
UNSUPPORTED = bytes(16) + b"Synthetic unsupported contract test file.\n"


class ContractError(AssertionError):
    """A difference between what the app reads and what Paperless answers."""


def check(condition, message):
    if not condition:
        raise ContractError(message)


def pdf(text):
    """A one-page PDF with the text, which Paperless reads without OCR."""
    escaped = text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    stream = f"BT /F1 14 Tf 72 720 Td ({escaped}) Tj ET\n".encode("latin-1")
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R"
        b" /Resources << /Font << /F1 5 0 R >> >> >>",
        b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"endstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    document = bytearray(b"%PDF-1.4\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(document))
        document += f"{number} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(document)
    document += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode()
    document += b"".join(f"{offset:010d} 00000 n \n".encode() for offset in offsets)
    document += f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(document)


def content_of(name):
    """The content of a file of the tests by its name: the same bytes in every run."""
    return pdf(f"Synthetic contract test document {name} of Paperless Sync.")


def load_state():
    return json.loads(STATE.read_text(encoding="utf-8"))


def save_state(state):
    STATE.parent.mkdir(parents=True, exist_ok=True)
    STATE.write_text(json.dumps(state, indent=2), encoding="utf-8")


def ended(task):
    """Whether a task has ended with its final result.

    Paperless-ngx 2.x marks a failed task in two steps: its handler of task_failure writes
    FAILURE with the traceback object as the result, "<traceback object at 0x…>", and its
    handler of task_postrun the reason a moment later. A poll between the two reads the
    placeholder, so a failure ends only with its reason. The app reads it too soon as well:
    https://github.com/Dennis-Otto/paperless-sync/issues/161
    """
    status = str(task.get("status", "")).upper()
    if status == "FAILURE":
        return not str(task.get("result") or "").startswith("<traceback object at ")
    return status in ("SUCCESS", "REVOKED")


class Paperless:
    """The REST API of a Paperless-ngx, as the app calls it: with a token and version 9."""

    def __init__(self, url, token=None):
        self.url = url.rstrip("/")
        self.token = token

    def request(self, method, path, body=None, *, version=API_VERSION, expect=200, raw=False, record=None, **form):
        headers = {"Accept": f"application/json; version={version}"}
        if self.token:
            headers["Authorization"] = f"Token {self.token}"
        data = None
        if form:
            boundary = uuid.uuid4().hex
            name, content, mime = form["upload"]
            data = (
                f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="{name}"\r\n'
                f"Content-Type: {mime}\r\n\r\n"
            ).encode() + content + f"\r\n--{boundary}--\r\n".encode()
            headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        elif body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(self.url + path, data=data, method=method, headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                status, received, payload = response.status, response.headers, response.read()
        except urllib.error.HTTPError as error:
            status, received, payload = error.code, error.headers, error.read()
        decoded = payload
        if not raw:
            try:
                decoded = json.loads(payload) if payload else None
            except ValueError:
                decoded = payload.decode("utf-8", "replace")
        if record:
            kept = {key: received[key] for key in ("Content-Type", "X-Version", "X-Api-Version") if received[key]}
            entry = {"request": f"{method} {path}", "status": status, "headers": kept}
            if not raw:
                entry["body"] = decoded
            RECORDINGS.mkdir(parents=True, exist_ok=True)
            (RECORDINGS / f"{record}.json").write_text(
                json.dumps(entry, indent=2, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8"
            )
        if expect is not None and status != expect:
            raise ContractError(f"{method} {path} answered HTTP {status} instead of {expect}: {payload[:500]!r}")
        return status, received, decoded

    def get(self, path, **options):
        return self.request("GET", path, **options)[2]

    def results(self, path, **options):
        """The results of a list, in pages (version 10) or as a plain list (tasks in version 9)."""
        body = self.get(path, **options)
        return body if isinstance(body, list) else body["results"]

    def upload(self, name, content, mime="application/pdf", **options):
        return self.request("POST", "/api/documents/post_document/", upload=(name, content, mime), **options)

    def task(self, task_id, *, version=API_VERSION, record=None):
        """The task of an upload once it has ended, as the app polls it."""
        deadline = time.monotonic() + TASK_TIMEOUT
        while True:
            tasks = self.results(f"/api/tasks/?task_id={task_id}&page_size=10", version=version)
            check(len(tasks) <= 1, f"Paperless lists {len(tasks)} tasks of the ID {task_id}.")
            if tasks and ended(tasks[0]):
                if record:
                    self.request("GET", f"/api/tasks/?task_id={task_id}&page_size=10", version=version, record=record)
                return tasks[0]
            check(time.monotonic() < deadline, f"The task {task_id} didn't end within {TASK_TIMEOUT} s: {tasks!r}")
            time.sleep(1)

    def create(self, kind, name, **fields):
        # Without a match, Paperless doesn't assign them to documents on its own.
        body = {"name": name, "matching_algorithm": 0, **fields}
        return self.request("POST", f"/api/{kind}/", body, expect=201)[2]["id"]


def is_id(value):
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


def is_text(value):
    return isinstance(value, str)


def is_date(value):
    return isinstance(value, str) and re.match(r"^\d{4}-\d{2}-\d{2}", value) is not None


def is_time(value):
    return isinstance(value, str) and re.match(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}", value) is not None


def optional(predicate):
    return lambda value: value is None or predicate(value)


def is_ids(value):
    return isinstance(value, list) and all(is_id(item) for item in value)


def is_count(value):
    return isinstance(value, int) and not isinstance(value, bool) and value >= 0


def is_field_values(value):
    """The custom fields of a document: the ID of each field and its value."""
    return isinstance(value, list) and all(
        isinstance(item, dict) and is_id(item.get("field")) and "value" in item for item in value
    )


# Every field of a document that the app reads (SyncService, PathTemplateService), with
# the shape that it reads it in. A run asks for these alone, in this order
# (PaperlessApiService::DOCUMENT_FIELDS), and the answer has no other.
DOCUMENT_FIELDS = {
    "id": is_id,
    "title": is_text,
    "correspondent": optional(is_id),
    "document_type": optional(is_id),
    "storage_path": optional(is_id),
    "created": is_date,
    "added": is_time,
    "modified": is_time,
    "tags": is_ids,
    "original_file_name": optional(is_text),
    "archived_file_name": optional(is_text),
    "mime_type": is_text,
    "owner": optional(is_id),
    "archive_serial_number": optional(is_count),
    "page_count": optional(is_count),
    "custom_fields": is_field_values,
}
# The query of the documents of a run, as PaperlessApiService::documents() sends it.
FIELDS = ",".join(DOCUMENT_FIELDS)
DOCUMENTS_QUERY = f"ordering=id&fields={FIELDS}&truncate_content=true"


def expect_fields(what, item, fields):
    check(isinstance(item, dict), f"{what} is no object: {item!r}")
    for key, predicate in fields.items():
        check(key in item, f"{what} has no field {key}, which the app reads: {sorted(item)!r}")
        check(predicate(item[key]), f"{what} has {key}={item[key]!r}, which the app can't read.")


def expect_page(what, page, count):
    """A page of a list in the shape the app reads: count, next and results."""
    check(isinstance(page, dict), f"{what} is no page of a list: {page!r}")
    for key in ("count", "next", "previous", "results"):
        check(key in page, f"{what} has no field {key}: {sorted(page)!r}")
    check(page["count"] == count, f"{what} counts {page['count']!r} instead of {count}.")
    check(isinstance(page["results"], list), f"{what} has results={page['results']!r}.")


def digests(data):
    return {"md5": hashlib.md5(data).hexdigest(), "sha256": hashlib.sha256(data).hexdigest()}


def checksum_kind(line):
    """Paperless-ngx 2.x keeps MD5 checksums, 3.x SHA-256 (decision 0009)."""
    return "md5" if line == "2" else "sha256"


def api(url, line):
    """Seeds Paperless as a household does and checks each answer that the app reads."""
    check(line in ("2", "3"), f"LINE is {line!r}, neither 2 nor 3.")
    paperless = Paperless(url)
    _, _, answer = paperless.request("POST", "/api/token/", {"username": USERNAME, "password": PASSWORD})
    check(is_text(answer.get("token")) and answer["token"], f"/api/token/ answered no token: {answer!r}")
    paperless.token = answer["token"]

    # The test of the connection: one document, the version in the header X-Version.
    _, headers, page = paperless.request("GET", "/api/documents/?page=1&page_size=1", record="connection")
    version = headers["X-Version"] or ""
    check(re.match(rf"^{line}\.\d+\.\d+", version), f"Paperless names its version {version!r}, not one of {line}.x.")
    expect_page("The first page of the documents", page, 0)
    # Paperless refuses a version of the API that it doesn't serve with 406, which the app
    # turns into the message about the Paperless it needs.
    status = paperless.request("GET", "/api/documents/?page=1&page_size=1", version="10", expect=None)[0]
    check(status == (406 if line == "2" else 200), f"Version 10 of the API answered HTTP {status} on {version}.")
    status = paperless.request("GET", "/api/documents/?page=1&page_size=1", version="99", expect=None)[0]
    check(status == 406, f"Version 99 of the API answered HTTP {status} instead of 406 on {version}.")

    inbox_tag = paperless.create("tags", "Inbox", is_inbox_tag=True)
    contract_tag = paperless.create("tags", "Contract")
    correspondents = {name: paperless.create("correspondents", name) for name in ("Example GmbH", "Example Insurance")}
    types = {name: paperless.create("document_types", name) for name in ("Invoice", "Statement", "Letter")}
    storage_path = paperless.create("storage_paths", "Contracts", path="{{ correspondent }}/{{ title }}")
    # A select field, whose choices the path template names by their labels.
    _, _, priority = paperless.request(
        "POST",
        "/api/custom_fields/",
        {"name": "Priority", "data_type": "select", "extra_data": {"select_options": [{"label": "High"}, {"label": "Low"}]}},
        expect=201,
    )
    choices = priority["extra_data"]["select_options"]
    check(
        [choice.get("label") for choice in choices] == ["High", "Low"] and all(is_text(choice.get("id")) for choice in choices),
        f"Paperless gave the choices of the select field no IDs: {priority!r}",
    )

    # The uploads, as the app sends a file of its inbox: the file alone, without metadata.
    ids = {}
    for key, (name, *_rest) in DOCUMENTS.items():
        _, _, task_id = paperless.upload(name, content_of(name), record="upload" if key == "invoice" else None)
        check(is_text(task_id) and re.match(r"^[0-9a-f-]{36}$", task_id), f"The upload answered {task_id!r}, no task.")
        task = paperless.task(task_id, record="task-success" if key == "invoice" else None)
        check(task["status"] == "SUCCESS", f"Paperless didn't consume {name}: {task!r}")
        check("New document id" in (task.get("result") or ""), f"The task of {name} names no document: {task!r}")
        ids[key] = int(task["related_document"])
        check(str(ids[key]) in task["result"], f"The task of {name} names another document: {task!r}")
        if line == "3" and key == "invoice":
            v10 = paperless.task(task_id, version="10", record="task-success.v10")
            check(v10["status"] == "success", f"The task of version 10 has the status {v10['status']!r}.")
            check(v10["result_data"] == {"document_id": ids[key]}, f"The task of version 10 has {v10!r}.")
            check(v10["related_document_ids"] == [ids[key]], f"The task of version 10 has {v10!r}.")
    check(len(set(ids.values())) == 3, f"Paperless made the documents {ids!r} of three uploads.")

    # A file that is no PDF, though it says so: the task fails with a reason, which the app
    # writes into the .error.txt next to the file.
    _, _, task_id = paperless.upload("broken.pdf", b"%PDF-1.4 Synthetic broken contract test file.\n")
    task = paperless.task(task_id, record="task-failure")
    check(task["status"] == "FAILURE", f"The broken PDF ended as {task!r}.")
    check("broken.pdf" in (task.get("result") or ""), f"The failed task names no file: {task!r}")
    check(task.get("related_document") is None, f"The failed task names a document: {task!r}")
    if line == "3":
        v10 = paperless.task(task_id, version="10", record="task-failure.v10")
        data = v10.get("result_data") or {}
        check(v10["status"] == "failure", f"The failed task of version 10 has {v10!r}.")
        check("broken.pdf" in (data.get("error_message") or ""), f"The failed task of version 10 has {v10!r}.")

    # The same file again. Paperless-ngx 2.x refuses a duplicate; 3.x consumes it as a
    # document of its own unless PAPERLESS_CONSUMER_DELETE_DUPLICATES is on, as by default.
    _, _, task_id = paperless.upload("copy.pdf", content_of("invoice.pdf"))
    task = paperless.task(task_id, record="task-duplicate")
    if line == "2":
        check(task["status"] == "FAILURE", f"Paperless-ngx 2.x consumed a duplicate: {task!r}")
        check("It is a duplicate of" in (task.get("result") or ""), f"The duplicate says {task!r}.")
        check(task.get("related_document") == str(ids["invoice"]), f"The duplicate names another original: {task!r}")
    else:
        check(task["status"] == "SUCCESS", f"Paperless-ngx 3.x refused a duplicate by default: {task!r}")
        duplicate = int(task["related_document"])
        paperless.request("DELETE", f"/api/documents/{duplicate}/", expect=204)
        paperless.request("POST", "/api/trash/", {"action": "empty", "documents": [duplicate]})
    # A task that Paperless doesn't know, such as one that its results expired from.
    unknown = paperless.results(f"/api/tasks/?task_id={uuid.uuid4()}&page_size=10", record="task-unknown")
    check(unknown == [], f"An unknown task lists {unknown!r}.")

    # A file of a type that Paperless doesn't take: refused at once, with the reason. Paperless
    # tells the type by the content, not by the name or the type of the upload.
    _, _, refused = paperless.upload("notes.bin", UNSUPPORTED, "application/octet-stream", expect=400, record="upload-refused")
    check(
        isinstance(refused, dict) and refused.get("document") == ["File type application/octet-stream not supported"],
        f"The refused upload says {refused!r}, not the reason that the app shows.",
    )

    # The metadata, as the user sets it in Paperless; the invoice and the statement leave
    # the inbox, the letter stays there.
    for key, (_name, title, correspondent, document_type, created) in DOCUMENTS.items():
        tags = [inbox_tag] if key == "inbox" else [contract_tag]
        fields = {
            "title": title,
            "correspondent": correspondents[correspondent],
            "document_type": types[document_type],
            "created": created,
            "tags": tags,
        }
        if key == "statement":
            fields["storage_path"] = storage_path
        if key == "invoice":
            fields["custom_fields"] = [{"field": priority["id"], "value": choices[0]["id"]}]
        _, _, edited = paperless.request("PATCH", f"/api/documents/{ids[key]}/", fields)
        check(edited["tags"] == tags and edited["created"].startswith(created), f"The edit of {key} gave {edited!r}.")

    # The documents, as every run reads them: sorted by ID, with the fields of a run alone.
    documents = paperless.get(f"/api/documents/?{DOCUMENTS_QUERY}&page=1&page_size=1000", record="documents")
    expect_page("The documents", documents, 3)
    check([item["id"] for item in documents["results"]] == sorted(ids.values()), f"The documents are {documents!r}.")
    for item in documents["results"]:
        expect_fields(f"The document {item.get('id')}", item, DOCUMENT_FIELDS)
        # Without the list of fields, each document would bring its text, notes and permissions.
        check(set(item) == set(DOCUMENT_FIELDS), f"Paperless ignored the fields of a run: {sorted(item)!r}")
        check(item["archived_file_name"], f"Paperless kept no archived version of the document {item['id']}.")
        check(item["mime_type"] == "application/pdf", f"The document {item['id']} has {item['mime_type']!r}.")
        check(item["page_count"] == 1, f"The document {item['id']} has page_count={item['page_count']!r}.")
    invoice_fields = next(item for item in documents["results"] if item["id"] == ids["invoice"])["custom_fields"]
    check(invoice_fields == [{"field": priority["id"], "value": choices[0]["id"]}], f"The invoice has {invoice_fields!r}.")
    # The pages of a list that is longer than a page, which the app reads one by one.
    first = paperless.get(f"/api/documents/?{DOCUMENTS_QUERY}&page=1&page_size=2")
    second = paperless.get(f"/api/documents/?{DOCUMENTS_QUERY}&page=2&page_size=2")
    expect_page("The first page of two", first, 3)
    expect_page("The second page of two", second, 3)
    check(len(first["results"]) == 2 and first["next"], f"The first page of two is {first!r}.")
    check(len(second["results"]) == 1 and second["next"] is None, f"The second page of two is {second!r}.")
    check(
        [item["id"] for item in first["results"] + second["results"]] == sorted(ids.values()),
        f"The pages of two are {first!r} and {second!r}.",
    )
    paperless.request("GET", f"/api/documents/?{DOCUMENTS_QUERY}&page=3&page_size=2", expect=404)
    # The newest documents, which the preview of the path template shows.
    newest = paperless.get(f"/api/documents/?page=1&page_size=2&ordering=-added&fields={FIELDS}&truncate_content=true")
    expected = [ids["inbox"], ids["statement"]]
    check([item["id"] for item in newest["results"]] == expected, f"The newest documents are {newest!r}.")

    for kind, names in (
        ("tags", {"Inbox", "Contract"}),
        ("correspondents", set(correspondents)),
        ("document_types", set(types)),
        ("storage_paths", {"Contracts"}),
        ("custom_fields", {"Priority"}),
    ):
        listed = paperless.get(f"/api/{kind}/?page=1&page_size=1000", record=kind)
        expect_page(f"The list {kind}", listed, len(names))
        check({item["name"] for item in listed["results"]} == names, f"The list {kind} is {listed!r}.")
        for item in listed["results"]:
            expect_fields(f"The entry {item.get('id')} of {kind}", item, {"id": is_id, "name": is_text})
            if kind == "tags":
                check(item["is_inbox_tag"] is (item["id"] == inbox_tag), f"The tag {item!r} has is_inbox_tag wrong.")
            if kind == "custom_fields":
                check(item["data_type"] == "select" and item["extra_data"]["select_options"] == choices, f"{item!r}")

    # The owners, by their usernames, for {{ owner_username }} of the path template.
    users = paperless.get("/api/users/?page=1&page_size=1000", record="users")
    check(USERNAME in [user.get("username") for user in users["results"]], f"The users are {users!r}.")
    for user in users["results"]:
        expect_fields(f"The user {user.get('id')}", user, {"id": is_id, "username": is_text})
    # The account and its permissions, which the connection checklist reads.
    settings = paperless.get("/api/ui_settings/", record="ui_settings")
    account = settings.get("user") if isinstance(settings, dict) else None
    expect_fields("The account of /api/ui_settings/", account, {"id": is_id, "is_superuser": lambda value: value is True})
    permissions = settings["permissions"]
    check(isinstance(permissions, list) and all(is_text(item) for item in permissions), f"The permissions are {permissions!r}.")
    # The names of the permissions that the checklist looks for, with those of the tags of the inbox.
    for permission in ("view_document", "add_document", "view_paperlesstask", "view_uisettings", "view_tag", "add_tag"):
        check(permission in permissions, f"The administrator lacks the permission {permission}: {permissions!r}")

    # The checksums of the metadata, which tell a change of the metadata from one of the
    # content without a download, are those of the downloads, MD5 on 2.x, SHA-256 on 3.x.
    kind = checksum_kind(line)
    invoice = ids["invoice"]
    metadata = paperless.get(f"/api/documents/{invoice}/metadata/", record="metadata")
    downloads = {}
    for original in ("true", "false"):
        _, received, data = paperless.request("GET", f"/api/documents/{invoice}/download/?original={original}", raw=True)
        downloads[original] = digests(data)
        check(received["Content-Type"] == "application/pdf", f"The download has the type {received['Content-Type']!r}.")
        if original == "true":
            check(data == content_of("invoice.pdf"), "The original download differs from the uploaded file.")
            # The bytes of the original, for the unit tests to compute its checksums themselves.
            downloads[original]["base64"] = base64.b64encode(data).decode("ascii")
    check(metadata.get("has_archive_version") is True, f"The metadata has no archived version: {metadata!r}")
    for field, original in (("original_checksum", "true"), ("archive_checksum", "false")):
        check(
            metadata.get(field) == downloads[original][kind],
            f"{field} is {metadata.get(field)!r}, not the {kind} {downloads[original][kind]} of the download.",
        )
    RECORDINGS.mkdir(parents=True, exist_ok=True)
    (RECORDINGS / "downloads.json").write_text(json.dumps(downloads, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    # The look-up of a file of the inbox whose upload's answer got lost: by its checksum, the
    # ID alone. A filter that Paperless doesn't know would return every document instead.
    found = []
    for name in ("sha256", "md5"):
        checksum = digests(content_of("invoice.pdf"))[name]
        page = paperless.get(f"/api/documents/?checksum__iexact={checksum}&fields=id&page_size=1", record=f"lookup-{name}")
        found += page["results"]
    check(found == [{"id": invoice}], f"The look-up by checksum found {found!r} instead of the invoice {invoice}.")
    nothing = paperless.get(f"/api/documents/?checksum__iexact={'0' * 64}&fields=id&page_size=1")
    check(nothing["results"] == [], f"The look-up of an unknown checksum found {nothing!r}.")

    # The trash, which the app mirrors: deleted_at, and the way back.
    letter = ids["inbox"]
    paperless.request("DELETE", f"/api/documents/{letter}/", expect=204)
    trash = paperless.get("/api/trash/?page=1&page_size=1000", record="trash")
    expect_page("The trash", trash, 1)
    expect_fields("The document in the trash", trash["results"][0], {**DOCUMENT_FIELDS, "deleted_at": is_time})
    check(paperless.get("/api/documents/?page=1&page_size=1000")["count"] == 2, "The trash still lists as active.")
    paperless.request("POST", "/api/trash/", {"action": "restore", "documents": [letter]})
    restored = paperless.get(f"/api/documents/{letter}/")
    check(restored["deleted_at"] is None, f"The restored document has {restored['deleted_at']!r}.")

    save_state({"url": url, "line": line, "token": paperless.token, "version": version, "ids": ids})
    print(f"Paperless-ngx {version} answers as the app reads it.")


def connect():
    state = load_state()
    return state, Paperless(state["url"], state["token"])


def change(what):
    state, paperless = connect()
    invoice = state["ids"]["invoice"]
    if what == "rename":
        paperless.request("PATCH", f"/api/documents/{invoice}/", {"title": RENAMED})
    elif what == "trash":
        paperless.request("DELETE", f"/api/documents/{invoice}/", expect=204)
    else:
        paperless.request("POST", "/api/trash/", {"action": "restore", "documents": [invoice]})


def wait():
    """Waits until Paperless has ended every task, such as those of the inbox of the app."""
    _, paperless = connect()
    deadline = time.monotonic() + TASK_TIMEOUT
    while True:
        tasks = paperless.results("/api/tasks/")
        running = [task["task_file_name"] for task in tasks if not ended(task)]
        if not running:
            return
        check(time.monotonic() < deadline, f"Paperless didn't end the tasks of {running!r} within {TASK_TIMEOUT} s.")
        time.sleep(1)


def path_of(state, key, title=None):
    _name, first_title, correspondent, document_type, created = DOCUMENTS[key]
    document = state["ids"][key]
    return f"{correspondent}/{document_type}/{created[:4]}/{created} - {title or first_title} [P{document}].pdf"


def report(mode):
    """Checks the report of a run, and prints the paths of its files, for WebDAV."""
    state = load_state()
    payload = json.load(sys.stdin)
    actions = [str(action) for action in payload.get("actions", [])]

    def expect(**counts):
        for key, value in counts.items():
            check(payload.get(key) == value, f"{mode}: the run has {key}={payload.get(key)!r}, not {value}: {payload!r}")

    def action(prefix):
        """The rest of the one line that starts with prefix, up to an arrow."""
        matching = [line for line in actions if line.startswith(prefix)]
        check(len(matching) == 1, f"{mode}: the run lists {len(matching)} actions {prefix}…: {actions!r}")
        return matching[0][len(prefix) :].split(" -> ")[0]

    invoice = state["ids"]["invoice"]
    paths = []
    if mode == "export":
        # The letter in the inbox of Paperless stays out.
        expect(dryRun=False, exported=2, moved=0, errors=0, activeDocuments=3)
        for key in ("invoice", "statement"):
            document = state["ids"][key]
            paths.append(action(f"EXPORT P{document}: "))
            check(paths[-1] == f"{ARCHIVE}/{path_of(state, key)}", f"export: P{document} went to {paths[-1]!r}.")
        check(not any(f"P{state['ids']['inbox']}:" in line for line in actions), f"export: the letter moved: {actions!r}")
    elif mode == "move":
        # A new title alone: the checksum of Paperless says that the content is the same.
        expect(exported=0, moved=1, errors=0)
        paths.append(action(f"MOVE P{invoice}: "))
        check(paths[-1] == f"{ARCHIVE}/{path_of(state, 'invoice', RENAMED)}", f"move: the invoice went to {paths[-1]!r}.")
    elif mode == "trash":
        expect(exported=0, movedToTrash=1, errors=0, trashedDocuments=1)
        paths.append(action(f"TRASH P{invoice}: "))
        suffix = "/" + path_of(state, "invoice", RENAMED)
        check(paths[-1].startswith(f"{DELETED}/") and paths[-1].endswith(suffix), f"trash: the copy went to {paths[-1]!r}.")
    elif mode == "restore":
        # Back from the trash of Paperless, the copy moves back without a download.
        expect(exported=0, moved=1, errors=0, trashedDocuments=0)
        paths.append(action(f"MOVE P{invoice}: "))
        check(paths[-1] == f"{ARCHIVE}/{path_of(state, 'invoice', RENAMED)}", f"restore: the copy is at {paths[-1]!r}.")
    elif mode == "import-submitted":
        # "Tags for every import" names contract, in lower case, which finds the tag Contract,
        # and Nextcloud, which Paperless lacks, so the run creates it.
        expect(importsSubmitted=2, errors=0)
        check("NEW TAG: Nextcloud" in actions, f"import-submitted: the run created no tag Nextcloud: {actions!r}")
        for name in ("receipt.pdf", "broken.pdf"):
            line = f"IMPORT: {INBOX}/{name} (tags: Contract, Nextcloud)"
            check(line in actions, f"import-submitted: the run lists no {line!r}: {actions!r}")
    elif mode == "import-ended":
        # The ID of the new document comes from the task of Paperless, and the broken file
        # moves to the error folder, with the reason of Paperless next to it.
        expect(importsSucceeded=1, importsFailed=1, errors=0)
        document = action(f"IMPORT SUCCESS: {INBOX}/receipt.pdf -> P")
        check(document.isdigit(), f"import-ended: the receipt became {document!r}, no document.")
        destination = action(f"IMPORT ERROR: {INBOX}/broken.pdf -> ")
        check(destination == f"{ERRORS}/broken.pdf", f"import-ended: the broken file went to {destination!r}.")
        paths += [document, destination]
    else:
        raise ContractError(f"No mode {mode!r} of report.")
    for path in paths:
        print(quote(path))


def content(key):
    """The file of a document in Nextcloud has the bytes of its archived version in Paperless."""
    state, paperless = connect()
    received = sys.stdin.buffer.read()
    document = state["ids"][key]
    archived = paperless.request("GET", f"/api/documents/{document}/download/?original=false", raw=True)[2]
    check(received == archived, f"The file of P{document} differs from its archived version in Paperless.")


def imported(name, document):
    """The upload of the inbox of the app became the document, with the bytes of the file."""
    state, paperless = connect()
    item = paperless.get(f"/api/documents/{document}/")
    check(item["original_file_name"] == name, f"P{document} came from {item['original_file_name']!r}, not {name}.")
    original = paperless.request("GET", f"/api/documents/{document}/download/?original=true", raw=True)[2]
    check(original == content_of(name), f"P{document} differs from the file {name} of the inbox.")
    metadata = paperless.get(f"/api/documents/{document}/metadata/")
    kind = checksum_kind(state["line"])
    check(metadata["original_checksum"] == digests(original)[kind], f"P{document} has the checksum {metadata!r}.")
    # The tags of the upload, one form field tags for each, besides the inbox tag of Paperless;
    # the tag that the run created assigns itself to no other document and has no owner.
    tags = {tag["name"]: tag for tag in paperless.results("/api/tags/?page_size=100")}
    check({"Contract", "Nextcloud"} <= set(tags), f"Paperless has the tags {sorted(tags)!r}, without those of the inbox.")
    check(tags["Nextcloud"]["matching_algorithm"] == 0, f"The new tag has a match: {tags['Nextcloud']!r}")
    # Without an owner, as the tags of the consume folder of Paperless, every account sees it.
    check(tags["Nextcloud"]["owner"] is None, f"The new tag has an owner: {tags['Nextcloud']!r}")
    wanted = {tags["Contract"]["id"], tags["Nextcloud"]["id"], tags["Inbox"]["id"]}
    check(set(item["tags"]) == wanted, f"P{document} has the tags {item['tags']!r}, not {sorted(wanted)!r}.")


def status():
    state = load_state()
    payload = json.load(sys.stdin)
    check(payload.get("paperlessVersion") == state["version"], f"The status names {payload.get('paperlessVersion')!r}.")
    check(payload.get("paperlessApiVersion") == API_VERSION, f"The status names {payload.get('paperlessApiVersion')!r}.")
    check(state["token"] not in json.dumps(payload), "The status shows the token of Paperless.")


def log():
    """The log of Nextcloud has no error of the app and never the token."""
    token = load_state()["token"]
    for line in sys.stdin:
        check(token not in line, "The log of Nextcloud shows the token of Paperless.")
        try:
            record = json.loads(line)
        except ValueError:
            continue
        if "paperless_sync" in (str(record.get("app")), str(record.get("message"))):
            check(record.get("level", 0) < 3, f"Nextcloud logged an error of the app: {record!r}")


def shape(value):
    """The keys and types of an answer, without its values: what a fixture promises."""
    if isinstance(value, dict):
        return {key: shape(item) for key, item in value.items()}
    if isinstance(value, list):
        return [shape(value[0])] if value else []
    return "null" if value is None else type(value).__name__


def differences(recorded, fresh, where=""):
    if isinstance(recorded, dict) and isinstance(fresh, dict):
        found = [f"- {where}.{key}" for key in recorded if key not in fresh]
        found += [f"+ {where}.{key}" for key in fresh if key not in recorded]
        for key in recorded.keys() & fresh.keys():
            found += differences(recorded[key], fresh[key], f"{where}.{key}")
        return found
    if isinstance(recorded, list) and isinstance(fresh, list):
        return differences(recorded[0], fresh[0], f"{where}[]") if recorded and fresh else []
    # A field that is null in one answer and set in the other changes no shape.
    if recorded != fresh and "null" not in (recorded, fresh):
        return [f"~ {where}: {recorded} -> {fresh}"]
    return []


def shapes(directory):
    """Prints how the answers of this run differ in their shape from the committed fixtures.

    A field that Paperless added (+) is a note, as the app doesn't read it yet; a field or an
    answer that is gone (-) or has another type (~) fails, because the unit tests read the
    fixtures as answers that Paperless no longer gives.
    """
    committed = {path.name for path in Path(directory).glob("*.json")}
    found = [f"- {name}" for name in sorted(committed - {path.name for path in RECORDINGS.glob("*.json")})]
    for path in sorted(RECORDINGS.glob("*.json")):
        if path.name not in committed:
            found.append(f"+ {path.name}")
            continue
        recorded = json.loads((Path(directory) / path.name).read_text(encoding="utf-8"))
        fresh = json.loads(path.read_text(encoding="utf-8"))
        found += [f"{line[:2]}{path.stem}: {line[2:]}" for line in differences(shape(recorded), shape(fresh))]
    for line in found:
        print(line)
    return 1 if any(line.startswith(("- ", "~ ")) for line in found) else 0


def fixtures():
    """The recorded answers as a tar archive, which run-contract.sh unpacks into tests/fixtures/."""
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w") as archive:
        for path in sorted(RECORDINGS.iterdir()):
            info = tarfile.TarInfo(path.name)
            data = path.read_bytes()
            info.size = len(data)
            info.mode = 0o644
            archive.addfile(info, io.BytesIO(data))
    sys.stdout.buffer.write(buffer.getvalue())


def main(arguments):
    command, *rest = arguments or ["help"]
    if command == "api":
        api(*rest)
    elif command == "token":
        print(load_state()["token"])
    elif command in ("rename", "trash", "restore"):
        change(command)
    elif command == "pdf":
        sys.stdout.buffer.write(content_of(rest[0]))
    elif command == "wait":
        wait()
    elif command == "report":
        report(*rest)
    elif command == "content":
        content(*rest)
    elif command == "imported":
        imported(rest[0], int(rest[1]))
    elif command == "status":
        status()
    elif command == "log":
        log()
    elif command == "shapes":
        return shapes(*rest)
    elif command == "fixtures":
        fixtures()
    else:
        print(__doc__, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except ContractError as error:
        print(f"Contract broken: {error}", file=sys.stderr)
        sys.exit(1)
