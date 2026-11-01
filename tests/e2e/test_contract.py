# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The tests of the recording of tests/e2e/contract.py: two runs of one image of Paperless
give the same bytes, so that a change of a fixture is a change of Paperless, and the fixture
bot of .github/workflows/paperless-fixtures.yml commits nothing without one.

scripts/check-project.sh runs them with the Python of the machine alone:
python3 tests/e2e/test_contract.py. They need no pytest; pytest finds them all the same.
"""

from __future__ import annotations

import contextlib
import io
import json
import re
import sys
import tarfile
import tempfile
import traceback
from collections.abc import Callable
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import contract  # noqa: E402 - after the folder of the script

FIXTURES = Path(__file__).resolve().parents[1] / "fixtures"
ORIGINAL = {"base64": "L3RtcC9hYmMvMjAyNi0wNS0wNA==", "md5": "1" * 32, "sha256": "2" * 64}
DOCUMENT = "2026-08-26"


def run(
    *,
    day: str = "2026-05-04",
    task: str = "9d3260c0-9b01-4f3b-adf7-1d5fad416984",
    unknown: str = "5593bc05-0f51-4b9b-821a-fcbf8d94ca98",
    archive: tuple[str, str] = ("b65407c547a5039de3afe23ffac27e5e", "c6c775caa72e4048d79448e54c6786433e596c0deefd0b01fe186dfab1c20b60"),
    choice: str = "3CcVIMWfzc4zZ9jG",
    moment: str = "07:16:47",
    temporary: str = "ocrmypdf.io.ho_i5o3g",
    size: int = 52808,
    seconds: float = 0.308826,
    permissions: tuple[str, ...] = ("view_document", "add_tag", "add_document"),
    tasks: tuple[int, int] = (1, 2),
) -> dict[str, dict]:
    """The recordings of a run of a fresh Paperless, with what differs from run to run."""
    time = f"{day}T{moment}.254708Z"
    return {
        "custom_fields.json": {
            "body": {"results": [{"data_type": "select", "extra_data": {"select_options": [{"id": choice, "label": "High"}]}}]},
        },
        "document.json": {
            "body": {
                "added": time,
                "archived_file_name": f"{DOCUMENT} Example GmbH Monthly invoice.pdf",
                "created": DOCUMENT,
                "custom_fields": [{"field": 1, "value": choice}],
                "id": 1,
                "modified": time,
                "owner": 3,
                "tags": [2],
                "title": "Monthly invoice",
            },
            "headers": {"X-Version": "3.3.1"},
            "request": "GET /api/documents/1/",
            "status": 200,
        },
        "document-shared.json": {
            "body": {
                "added": time,
                "archived_file_name": f"{day} shared.pdf",
                "created": day,
                "permissions": {"view": {"users": [3, 4]}},
                "deleted_at": None,
                "is_shared_by_requester": False,
            },
        },
        "downloads.json": {"false": {"md5": archive[0], "sha256": archive[1]}, "true": ORIGINAL},
        "metadata.json": {
            "body": {
                "archive_checksum": archive[1],
                "archive_metadata": [
                    {"key": "CreateDate", "value": f"{day}T{moment}Z"},
                    {"key": "MetadataDate", "value": f"{day}T{moment}.529895+00:00"},
                    {"key": "DocumentID", "value": f"uuid:{task[:8]}-0de0-11fd-0000-189a561f7c09"},
                ],
                "archive_size": size,
                "original_checksum": ORIGINAL["sha256"],
                "original_size": 1021,
            },
        },
        "task-failure.json": {
            "body": [
                {
                    "date_done": time,
                    "id": tasks[1],
                    "result": f"broken.pdf: RuntimeError: /tmp/{temporary}/origin: seek before beginning of buffer",
                    "task_file_name": "broken.pdf",
                }
            ],
        },
        "task-success.json": {
            "body": [{"date_done": time, "id": tasks[0], "related_document": 1, "task_id": task}],
            "request": f"GET /api/tasks/?task_id={task}&page_size=10",
        },
        "task-success.v10.json": {
            "body": {
                "results": [
                    {"duration_seconds": seconds, "id": tasks[0], "task_id": task, "wait_time_seconds": seconds / 2},
                ],
            },
        },
        "task-unknown.json": {"body": [], "request": f"GET /api/tasks/?task_id={unknown}&page_size=10"},
        "ui_settings.json": {"body": {"permissions": list(permissions), "user": {"id": 3}}},
        "upload.json": {"body": task, "status": 200},
        "users.json": {"body": {"results": [{"date_joined": time, "inherited_permissions": list(permissions), "id": 3}]}},
    }


def texts(recordings: dict[str, dict]) -> dict[str, str]:
    return {name: contract.fixture_text(entry) for name, entry in contract.stable(recordings).items()}


def test_two_runs_of_one_image_give_the_same_bytes() -> None:
    other = run(
        day="2026-05-05",
        task="b54880cb-0d88-40f9-8b38-f68f2725608b",
        unknown="dd5bdc83-7bc2-48d8-a109-4b60f4865e75",
        archive=("8df10b7d41cd096f248ea5f29ea86132", "a84af8b18f355a9d8195e7ed8aad62c237f336876c75781a41617387dd311f1a"),
        choice="9H0q2nDhdIRdUGPY",
        moment="23:59:58",
        temporary="ocrmypdf.io.p_h0nspo",
        size=52806,
        seconds=2.010451,
        permissions=("add_document", "view_document", "add_tag"),
        # A task of Paperless itself between the two of the contract tests.
        tasks=(5, 7),
    )
    first, second = texts(run()), texts(other)
    for name, text in first.items():
        assert text == second[name], f"{name} differs in two runs:\n{text}\n{second[name]}"


def test_a_run_over_midnight_gives_the_same_bytes() -> None:
    recordings = run()
    recordings["document.json"]["body"]["added"] = "2026-05-03T23:59:59.000001Z"
    recordings["document.json"]["body"]["created"] = "2026-05-03"
    expected = run()
    expected["document.json"]["body"]["created"] = "2026-05-04"
    assert texts(recordings) == texts(expected)


def test_a_change_of_paperless_stays_in_the_recording() -> None:
    recordings = run()
    recordings["document.json"]["body"]["new_field"] = "a value"
    recordings["document.json"]["headers"]["X-Version"] = "3.3.2"
    recordings["metadata.json"]["body"]["original_size"] = 1022
    recordings["task-failure.json"]["body"][0]["result"] = "broken.pdf: Another reason"
    fresh, same = contract.stable(recordings), contract.stable(run())
    assert fresh["document.json"]["body"]["new_field"] == "a value"
    assert fresh["document.json"]["headers"]["X-Version"] == "3.3.2"
    assert fresh["metadata.json"]["body"]["original_size"] == 1022
    assert fresh["task-failure.json"]["body"][0]["result"] == "broken.pdf: Another reason"
    assert fresh["document.json"] != same["document.json"]


def test_answers_that_share_a_value_still_share_it() -> None:
    stable = contract.stable(run())
    downloads = stable["downloads.json"]["false"]
    assert stable["metadata.json"]["body"]["archive_checksum"] == downloads["sha256"]
    assert stable["metadata.json"]["body"]["archive_checksum"] != ORIGINAL["sha256"]
    assert len(downloads["md5"]) == 32 and len(downloads["sha256"]) == 64
    task = stable["upload.json"]["body"]
    assert re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}", task)
    assert stable["task-success.json"]["body"][0]["task_id"] == task
    assert stable["task-success.v10.json"]["body"]["results"][0]["task_id"] == task
    assert f"task_id={task}&" in stable["task-success.json"]["request"]
    assert stable["task-unknown.json"]["request"] != stable["task-success.json"]["request"]
    choice = stable["custom_fields.json"]["body"]["results"][0]["extra_data"]["select_options"][0]["id"]
    assert stable["document.json"]["body"]["custom_fields"][0]["value"] == choice
    assert len(choice) == 16
    assert stable["task-success.v10.json"]["body"]["results"][0]["id"] == stable["task-success.json"]["body"][0]["id"]
    assert stable["task-success.json"]["body"][0]["id"] < stable["task-failure.json"]["body"][0]["id"]


def test_what_the_app_reads_stays_as_paperless_sent_it() -> None:
    stable = contract.stable(run())
    document = stable["document.json"]["body"]
    assert (document["id"], document["owner"], document["tags"]) == (1, 3, [2])
    assert document["created"] == DOCUMENT
    assert document["archived_file_name"] == f"{DOCUMENT} Example GmbH Monthly invoice.pdf"
    assert stable["downloads.json"]["true"] == ORIGINAL
    assert stable["metadata.json"]["body"]["original_checksum"] == ORIGINAL["sha256"]
    # The document without a date of its own, which Paperless dates by the day of the run.
    shared = stable["document-shared.json"]["body"]
    assert shared["created"] == contract.RECORDED_DAY
    assert shared["archived_file_name"] == f"{contract.RECORDED_DAY} shared.pdf"
    assert shared["permissions"] == {"view": {"users": [3, 4]}}
    assert shared["deleted_at"] is None and shared["is_shared_by_requester"] is False


def test_times_keep_their_form_and_the_types_stay() -> None:
    recordings = run()
    stable = contract.stable(recordings)
    values = {item["key"]: item["value"] for item in stable["metadata.json"]["body"]["archive_metadata"]}
    assert values["CreateDate"] == f"{contract.RECORDED_TIME}Z"
    assert values["MetadataDate"] == f"{contract.RECORDED_TIME}.000000+00:00"
    assert stable["task-success.v10.json"]["body"]["results"][0]["duration_seconds"] == 1.0
    assert isinstance(stable["task-success.v10.json"]["body"]["results"][0]["duration_seconds"], float)
    assert isinstance(stable["metadata.json"]["body"]["archive_size"], int)
    assert "/tmp/contract/origin" in stable["task-failure.json"]["body"][0]["result"]
    # What contract.py shapes compares with the fixtures: the recording changes none of it.
    for name, entry in recordings.items():
        assert contract.differences(contract.shape(entry), contract.shape(stable[name])) == [], name


def test_the_order_of_a_set_of_paperless_is_sorted() -> None:
    stable = contract.stable(run())
    assert stable["ui_settings.json"]["body"]["permissions"] == ["add_document", "add_tag", "view_document"]
    assert stable["users.json"]["body"]["results"][0]["inherited_permissions"] == ["add_document", "add_tag", "view_document"]


def test_the_fixtures_are_what_a_recording_writes() -> None:
    folders = sorted(FIXTURES.glob("paperless-ngx-*"))
    assert len(folders) == 2
    for folder in folders:
        recordings = {path.name: json.loads(path.read_text(encoding="utf-8")) for path in folder.glob("*.json")}
        for name, text in texts(recordings).items():
            on_disk = (folder / name).read_text(encoding="utf-8")
            assert text == on_disk, f"{folder.name}/{name} differs from a recording; CONTRACT_RECORD=1 writes it."


def test_the_archive_holds_the_stable_recordings() -> None:
    recorded = contract.RECORDINGS
    standard = sys.stdout
    with tempfile.TemporaryDirectory() as temporary:
        contract.RECORDINGS = Path(temporary)
        for name, entry in run().items():
            (Path(temporary) / name).write_text(json.dumps(entry), encoding="utf-8")
        sys.stdout = io.TextIOWrapper(io.BytesIO())
        try:
            contract.fixtures()
            sys.stdout.flush()
            data = sys.stdout.buffer.getvalue()
        finally:
            sys.stdout = standard
            contract.RECORDINGS = recorded
    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
        members = archive.getmembers()
        assert [member.name for member in members] == sorted(run())
        assert all(member.mode == 0o644 and member.mtime == 0 for member in members)
        extracted = archive.extractfile("task-failure.json")
        assert extracted is not None
        assert extracted.read().decode("utf-8") == texts(run())["task-failure.json"]


def test_the_version_is_the_one_that_paperless_named() -> None:
    state = contract.STATE
    with tempfile.TemporaryDirectory() as temporary:
        contract.STATE = Path(temporary) / "state.json"
        contract.STATE.write_text(json.dumps({"version": "3.3.1"}), encoding="utf-8")
        out = io.StringIO()
        try:
            with contextlib.redirect_stdout(out):
                assert contract.main(["version"]) == 0
        finally:
            contract.STATE = state
    assert out.getvalue() == "3.3.1\n"


def main() -> int:
    """Run every test of this file, as pytest would, and count the failures."""
    tests: list[tuple[str, Callable[[], None]]] = [
        (name, test) for name, test in globals().items() if re.match(r"test_\w+$", name) and callable(test)
    ]
    failures = 0
    for name, test in tests:
        try:
            test()
        except AssertionError as error:
            failures += 1
            frame = traceback.extract_tb(error.__traceback__)[-1]
            print(f"FAIL {name}, line {frame.lineno}: {frame.line} {error}")
    print(f"{len(tests) - failures} of {len(tests)} tests of the recording of tests/e2e/contract.py pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
