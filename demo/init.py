# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The one-shot containers of the demo, with the Python of their image alone.

python init.py secrets makes the password of the accounts and the key of Paperless on the
first start, into the volume that the other containers read and into demo/.env for people.
python init.py run then does what an administrator does, through the same APIs: it fills
Paperless with the synthetic household archive of tests/e2e/showcase.py, creates the
account of the app, connects the app with the user demo as the owner of the archive,
starts a dry run and then the first run, and says where everything is.

Each step can run again, after a failure and after another docker compose up.
"""

from __future__ import annotations

import base64
import json
import os
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

ENV_FILE = Path("/demo/.env")
SHARED = Path("/secrets")
# Set up once: another docker compose up says so and ends.
READY = SHARED / "ready"
# The first run has begun, so its documents may be in the archive already: a dry run after
# this no longer lists all of them.
FIRST_RUN = SHARED / "first-run-started"
PASSWORD = "DEMO_PASSWORD"
SECRET_KEY = "PAPERLESS_SECRET_KEY"
ENV_HEADER = "# The passwords of the demo, made on its first start. Git ignores this file; never commit them."

ADMIN = "admin"
OWNER = "demo"
ACCOUNT = "nextcloud-sync"
ARCHIVE_PATH = "/Documents/Paperless/Archive"
SETTINGS_URL = "/apps/paperless_sync/settings"
RUN_URL = "/apps/paperless_sync/sync/run"
STATUS_URL = "/apps/paperless_sync/sync/status"
UPLOAD_URL = "/api/documents/post_document/"
TITLES_URL = "/api/documents/?fields=id,title&truncate_content=true"

START_TIMEOUT = 900
TASK_TIMEOUT = 300
RUN_TIMEOUT = 300

# Requests in the network of the demo never go through a proxy of the machine, which Docker
# can give containers in their environment.
OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}))


class DemoError(Exception):
    """A step that failed, with what to do about it."""


def say(message: str = "") -> None:
    print(message, flush=True)


def read_env(path: Path) -> dict[str, str]:
    """The settings of an .env file; comments and other lines don't count."""
    if not path.exists():
        return {}
    pairs = (line.split("=", 1) for line in path.read_text(encoding="utf-8").splitlines() if "=" in line)
    return {name.strip(): value.strip() for name, value in pairs if not name.lstrip().startswith("#")}


def write_for_the_owner_of_the_folder(path: Path, text: str) -> None:
    """Writes a file that only the person who owns the folder reads.

    The container runs as root, and Docker Compose reads .env on the machine as that person.
    Docker Desktop keeps its own owners and modes of a folder of the machine, and may refuse.
    """
    path.write_text(text, encoding="utf-8", newline="\n")
    folder = path.parent.stat()
    try:
        # Python on Windows, where the tests of this file run as well, has no chown.
        if hasattr(os, "chown"):
            os.chown(path, folder.st_uid, folder.st_gid)
        path.chmod(0o600)
    except OSError as error:
        say(f"{path.name} keeps its owner and mode: {error}")


def make_secrets(env_file: Path = ENV_FILE, shared: Path = SHARED) -> dict[str, str]:
    """The secrets of the demo, the same on every start.

    The volume holds what Nextcloud and Paperless were set up with, so it wins over .env,
    which can be deleted or edited. What else .env holds, such as a port, stays.
    """
    kept = read_env(env_file)
    lines = env_file.read_text(encoding="utf-8").splitlines() if env_file.exists() else [ENV_HEADER]
    values = {}
    for name in (PASSWORD, SECRET_KEY):
        stored = shared / name
        if stored.exists():
            value = stored.read_text(encoding="utf-8").strip()
        else:
            value = kept.get(name) or secrets.token_hex(16)
        stored.write_text(value, encoding="utf-8")
        stored.chmod(0o600)
        values[name] = value
        line = f"{name}={value}"
        if name in kept:
            lines = [line if existing.split("=", 1)[0].strip() == name else existing for existing in lines]
        else:
            lines.append(line)
    write_for_the_owner_of_the_folder(env_file, "\n".join(lines) + "\n")
    return values


class Server:
    """Requests to a server of the demo, with the answer as JSON where it is JSON."""

    def __init__(self, url: str, headers: dict[str, str]) -> None:
        self.url = url
        self.headers = headers

    def request(
        self, method: str, path: str, *, body: bytes | None = None, content_type: str = ""
    ) -> tuple[int, object]:
        headers = self.headers | ({"Content-Type": content_type} if content_type else {})
        request = urllib.request.Request(self.url + path, data=body, method=method, headers=headers)
        try:
            with OPENER.open(request, timeout=120) as response:
                status, payload = response.status, response.read()
        except urllib.error.HTTPError as error:
            status, payload = error.code, error.read()
        try:
            return status, json.loads(payload) if payload else None
        except ValueError:
            return status, payload.decode("utf-8", "replace")

    def json(self, method: str, path: str, value: object = None, expect: tuple[int, ...] = (200,)) -> object:
        body = None if value is None else json.dumps(value).encode()
        status, answer = self.request(method, path, body=body, content_type="application/json")
        return checked(method, path, status, answer, expect)

    def form(self, method: str, path: str, fields: dict[str, str], expect: tuple[int, ...] = (200,)) -> object:
        body = urllib.parse.urlencode(fields).encode()
        status, answer = self.request(method, path, body=body, content_type="application/x-www-form-urlencoded")
        return checked(method, path, status, answer, expect)

    def wait(self, name: str, path: str) -> None:
        """Waits until the server answers the path, and says how long that took."""
        started = time.monotonic()
        while True:
            try:
                status, _ = self.request("GET", path)
                if status == 200:
                    say(f"{name} is up after {round(time.monotonic() - started)} s.")
                    return
            except OSError:
                pass
            if time.monotonic() - started > START_TIMEOUT:
                raise DemoError(
                    f"{name} didn't start within {START_TIMEOUT} s. "
                    "The logs tell why: docker compose -f demo/compose.yaml logs"
                )
            time.sleep(2)


def checked(method: str, path: str, status: int, answer: object, expect: tuple[int, ...]) -> object:
    if status not in expect:
        raise DemoError(f"{method} {path} answered HTTP {status}: {str(answer)[:500]}")
    return answer


def results(server: Server, path: str) -> list[dict]:
    """Every entry of a list of Paperless; the demo has fewer than one page holds."""
    separator = "&" if "?" in path else "?"
    return server.json("GET", f"{path}{separator}page_size=1000")["results"]


def multipart(fields: list[tuple[str, str]], name: str, content: bytes) -> tuple[bytes, str]:
    """A form with the fields and a PDF, as the upload of Paperless reads it."""
    boundary = uuid.uuid4().hex
    parts = [
        f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        for key, value in fields
    ]
    parts.append(
        f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="{name}"\r\n'
        "Content-Type: application/pdf\r\n\r\n".encode()
        + content
        + f"\r\n--{boundary}--\r\n".encode()
    )
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


def ids_by_showcase_id(paperless: Server, kind: str, entries: list[dict], **fields: object) -> dict[int, int]:
    """Creates what Paperless doesn't have yet, and maps the IDs of showcase.py to its own."""
    existing = {item["name"]: item["id"] for item in results(paperless, f"/api/{kind}/")}
    ids = {}
    for entry in entries:
        if entry["name"] not in existing:
            # Without a match, Paperless doesn't assign them to other documents on its own. Without
            # an owner, the account of the app sees them, as those of a household usually are.
            body = {"name": entry["name"], "matching_algorithm": 0, "owner": None, **fields}
            existing[entry["name"]] = paperless.json("POST", f"/api/{kind}/", body, expect=(201,))["id"]
        ids[entry["id"]] = existing[entry["name"]]
    return ids


def seed(paperless: Server, showcase) -> None:
    """The household archive in Paperless, as if someone had scanned it."""
    documents, _ = showcase.documents("showcase")
    say(f"Filling Paperless-ngx with {len(documents)} synthetic documents.")
    correspondents = ids_by_showcase_id(paperless, "correspondents", showcase.CORRESPONDENTS)
    types = ids_by_showcase_id(paperless, "document_types", showcase.DOCUMENT_TYPES)
    # Paperless gives every new document each inbox tag that exists, so that one comes last, and
    # the documents get their tags once they are all there.
    plain_tags = [tag for tag in showcase.TAGS if not tag["is_inbox_tag"]]
    inbox_tags = [tag for tag in showcase.TAGS if tag["is_inbox_tag"]]
    tags = ids_by_showcase_id(paperless, "tags", plain_tags, is_inbox_tag=False)
    known = {document["title"] for document in results(paperless, TITLES_URL)}
    tasks = []
    for document in documents:
        if document["title"] in known:
            continue
        fields = [("title", document["title"]), ("created", document["created"])]
        if document["correspondent"] is not None:
            fields.append(("correspondent", str(correspondents[document["correspondent"]])))
        if document["document_type"] is not None:
            fields.append(("document_type", str(types[document["document_type"]])))
        body, content_type = multipart(fields, document["original_file_name"], showcase.content(document["id"]))
        status, answer = paperless.request("POST", UPLOAD_URL, body=body, content_type=content_type)
        tasks.append(checked("POST", UPLOAD_URL, status, answer, (200,)))
    wait_for_tasks(paperless, tasks)
    tags |= ids_by_showcase_id(paperless, "tags", inbox_tags, is_inbox_tag=True)
    by_title = {document["title"]: document["id"] for document in results(paperless, TITLES_URL)}
    for document in documents:
        if document["tags"]:
            own_tags = [tags[tag] for tag in document["tags"]]
            paperless.json("PATCH", f"/api/documents/{by_title[document['title']]}/", {"tags": own_tags})
    say(f"Paperless-ngx has read the {len(documents)} documents.")


def wait_for_tasks(paperless: Server, tasks: list[str]) -> None:
    deadline = time.monotonic() + TASK_TIMEOUT
    for task in tasks:
        while True:
            found = paperless.json("GET", f"/api/tasks/?task_id={task}")
            found = found if isinstance(found, list) else found["results"]
            if found and found[0]["status"] in ("SUCCESS", "FAILURE", "REVOKED"):
                if found[0]["status"] != "SUCCESS":
                    raise DemoError(f"Paperless-ngx didn't read a document: {found[0]}")
                break
            if time.monotonic() > deadline:
                raise DemoError(f"Paperless-ngx didn't read the documents within {TASK_TIMEOUT} s: {found}")
            time.sleep(1)


def settings(port: str, *, enabled: bool) -> dict[str, str]:
    """The settings of the page, as an administrator enters them; the rest keep their defaults."""
    values = {
        "paperless_url": f"http://localhost:{port}",
        "paperless_local_network": "1",
        "target_user": OWNER,
        "enabled": "1" if enabled else "0",
    }
    return {f"settings[{name}]": value for name, value in values.items()}


def connect(nextcloud: Server, paperless: Server, token: str, password: str, port: str) -> None:
    """The user of the archive and the account of the app, with the settings of the app."""
    say(f"Creating the user {OWNER} in Nextcloud.")
    user = {"userid": OWNER, "password": password}
    answer = nextcloud.form("POST", "/ocs/v2.php/cloud/users?format=json", user, expect=(200, 400))
    # 102: the user exists, from an earlier try.
    if answer["ocs"]["meta"]["statuscode"] not in (200, 102):
        raise DemoError(f"Nextcloud didn't create the user {OWNER}: {answer['ocs']['meta']}")
    say(f"Creating the account {ACCOUNT} of the app in Paperless-ngx and connecting the app.")
    account_setup = settings(port, enabled=False) | {"superuserToken": token, "username": ACCOUNT, "update": "true"}
    nextcloud.form("POST", f"{SETTINGS_URL}/account", account_setup)
    # The account owns the documents, as the connection checklist asks for: their trash then
    # reaches the archive at once.
    account = next(user for user in results(paperless, "/api/users/") if user["username"] == ACCOUNT)
    for document in results(paperless, TITLES_URL):
        paperless.json("PATCH", f"/api/documents/{document['id']}/", {"owner": account["id"]})


def wait_for_run(nextcloud: Server) -> dict:
    """The status of the run that is queued or running, once it has ended."""
    deadline = time.monotonic() + RUN_TIMEOUT
    while True:
        status = nextcloud.json("GET", STATUS_URL)
        if status["state"] not in ("queued", "running"):
            return status
        if time.monotonic() > deadline:
            raise DemoError(
                f"The first run didn't end within {RUN_TIMEOUT} s. "
                "The log of the worker tells why: docker compose -f demo/compose.yaml logs worker"
            )
        time.sleep(1)


def first_run(nextcloud: Server, port: str, expected: int, started: Path = FIRST_RUN) -> None:
    """The dry run, the schedule, the first run and what it wrote.

    A start that ended during the first run left part of the archive or all of it, so the next
    start exports only what the dry run still lists.
    """
    again = started.exists()
    if again:
        say("An earlier start began the first run; this one finishes it.")
        wait_for_run(nextcloud)
    report = nextcloud.form("POST", RUN_URL, {"dryRun": "true"})
    remaining = report["exported"]
    say(f"The dry run changes nothing and lists {remaining} documents to export.")
    if not again and remaining != expected:
        raise DemoError(f"The dry run lists {remaining} documents instead of {expected}.")
    nextcloud.form("POST", SETTINGS_URL, settings(port, enabled=True))
    started.touch()
    if remaining:
        say("Synchronization is on. The first run exports them.")
        nextcloud.form("POST", RUN_URL, {"dryRun": "false"}, expect=(202,))
        status = wait_for_run(nextcloud)
        exported = status["summary"]["exported"]
        if status["state"] != "completed" or exported != remaining:
            raise DemoError(
                f"The first run ended as {status['state']} with {exported} of the {remaining} documents: "
                f"{status['error']}"
            )
    say(f"The archive has {expected} documents.")


def banner(password: str, nextcloud_port: str, paperless_port: str) -> None:
    say(f"""
The demo of Paperless Sync is ready.

  The archive   http://localhost:{nextcloud_port}/apps/files/files?dir={ARCHIVE_PATH}
                user {OWNER}, password {password}
  The settings  http://localhost:{nextcloud_port}/settings/admin/paperless_sync
                user {ADMIN}, the same password
  Paperless     http://localhost:{paperless_port}
                user {ADMIN}, the same password

The password stays in demo/.env. The demo runs in Docker until you stop it:

  docker compose -f demo/compose.yaml down            stop it and keep its data
  docker compose -f demo/compose.yaml down --volumes  remove its data as well
""")


def run() -> None:
    # tests/e2e/showcase.py, which only this container mounts.
    import showcase

    password = (SHARED / PASSWORD).read_text(encoding="utf-8").strip()
    nextcloud_port = os.environ["DEMO_NEXTCLOUD_PORT"]
    paperless_port = os.environ["DEMO_PAPERLESS_PORT"]
    credentials = base64.b64encode(f"{ADMIN}:{password}".encode()).decode()
    nextcloud = Server(
        "http://localhost",
        {"Accept": "application/json", "Authorization": f"Basic {credentials}", "OCS-APIRequest": "true"},
    )
    paperless = Server(f"http://localhost:{paperless_port}", {"Accept": "application/json; version=9"})
    say("Paperless-ngx and Nextcloud set themselves up, which takes a few minutes on the first start.")
    paperless.wait("Paperless-ngx", "/api/schema/")
    nextcloud.wait("Nextcloud", "/status.php")
    if READY.exists():
        say(f"The demo has been set up already. Its password is in demo/.env: http://localhost:{nextcloud_port}")
        return
    token = paperless.form("POST", "/api/token/", {"username": ADMIN, "password": password})["token"]
    paperless.headers |= {"Authorization": f"Token {token}"}
    seed(paperless, showcase)
    connect(nextcloud, paperless, token, password, paperless_port)
    first_run(nextcloud, paperless_port, len(showcase.ARCHIVE))
    READY.touch()
    banner(password, nextcloud_port, paperless_port)


def main(arguments: list[str]) -> int:
    try:
        if arguments == ["secrets"]:
            make_secrets()
        elif arguments == ["run"]:
            run()
        else:
            say("Usage: python init.py secrets|run")
            return 2
    except DemoError as error:
        say(f"The demo didn't start: {error}")
        say("Another docker compose up tries again; docker compose -f demo/compose.yaml down --volumes starts over.")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
