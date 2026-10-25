"""The tests of tests/e2e/assert_upgrade.py, which checks what the upgrade from the last
release keeps (upgrade.sh), with samples of its states: a check of an upgrade that loses
data must fail, so that the pass of the upgrade test means something.

scripts/check-project.sh runs them with the Python of the machine alone:
python3 tests/e2e/test_assert_upgrade.py. They need no pytest; pytest finds them all the same.
"""

from __future__ import annotations

import copy
import json
import re
import subprocess
import sys
import traceback
from collections.abc import Callable
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent / "assert_upgrade.py"

# A state as upgrade_state.php reads it from a release in use.
STATE = {
    "tables": {
        "paperless_sync_export": [
            {"id": 1, "owner_uid": "e2e-mapped", "document_id": 123, "path": "Documents/Paperless/Archive/a [P123].pdf", "state": "active"},
            {"id": 2, "owner_uid": "e2e-other", "document_id": 124, "path": "Documents/Paperless/Archive/b [P124].pdf", "state": "active"},
        ],
        "paperless_sync_import": [
            {"id": 1, "owner_uid": "sync-target", "path": "Documents/Paperless/Inbox/letter.pdf", "etag": "a", "task_id": "task-1", "status": "pending", "attempts": 0, "retry_at": 0},
            {"id": 2, "owner_uid": "sync-target", "path": "Documents/Paperless/Inbox/later.pdf", "etag": "b", "task_id": "", "status": "retry", "attempts": 1, "retry_at": 1792901313},
        ],
        "paperless_sync_send": [{"id": 1, "user_id": "e2e-admin", "file_id": 42, "status": "queued"}],
    },
    "appconfig": {"base_path": "Documents/Paperless", "error_folder": "Failed imports", "installed_version": "1.2.0", "types": ""},
    "credentials": {"/paperless_sync.api-token": "encrypted-1", "/paperless_sync.webhook-secret": "encrypted-2"},
    "migrations": ["000100Date20260826000000", "000110Date20261008000000"],
    "files": {
        "e2e-mapped": {"Documents/Paperless/Archive": 10, "Documents/Paperless/Archive/a [P123].pdf": 11},
        "e2e-other": {"Documents/Paperless/Archive": 20, "Documents/Paperless/Archive/b [P124].pdf": 21},
    },
}
SETTINGS = {"config": {"basePath": "Documents/Paperless", "ownership": "per-user", "tokenConfigured": True}, "webhook": {"configured": True}}
MOCK = {"downloads": 2, "uploads": [{"filename": "letter.pdf"}], "unavailableUploads": ["later.pdf"], "invalidAuth": 0}


def run(mode: str, payload: object, *arguments: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, "-B", str(SCRIPT), mode, *arguments],
        input=json.dumps(payload),
        capture_output=True,
        text=True,
        check=False,
    )


def passes(mode: str, payload: object, *arguments: str) -> None:
    result = run(mode, payload, *arguments)
    assert result.returncode == 0, result.stderr


def fails(mode: str, payload: object, message: str, *arguments: str) -> None:
    result = run(mode, payload, *arguments)
    assert result.returncode == 1, f"{mode} passed: {result.stdout}"
    assert message in result.stderr, result.stderr


def upgraded() -> dict:
    """The state after an upgrade that kept everything, with a new column, table, value and migration."""
    after = copy.deepcopy(STATE)
    for row in after["tables"]["paperless_sync_export"]:
        row["remote_checksum"] = None
    after["tables"]["paperless_sync_new"] = []
    after["appconfig"]["installed_version"] = "1.2.1"
    after["appconfig"]["new_setting"] = "default"
    after["migrations"].append("000160Date20261025000000")
    return after


def test_release_has_what_the_upgrade_must_keep() -> None:
    passes("release", STATE)


def test_release_without_a_retry_proves_nothing() -> None:
    state = copy.deepcopy(STATE)
    state["tables"]["paperless_sync_import"].pop()
    fails("release", state, "no import that waits to be tried again")


def test_release_without_a_pending_import_or_a_send_proves_nothing() -> None:
    state = copy.deepcopy(STATE)
    state["tables"]["paperless_sync_import"].pop(0)
    state["tables"]["paperless_sync_send"] = []
    fails("release", state, "no import whose task is pending")
    fails("release", state, "no file to send")


def test_release_with_the_copies_of_one_owner_proves_nothing() -> None:
    state = copy.deepcopy(STATE)
    state["tables"]["paperless_sync_export"].pop()
    del state["files"]["e2e-other"]
    fails("release", state, "not of two owners")
    fails("release", state, "fewer than two copies")


def test_an_upgrade_may_add_columns_tables_values_and_migrations() -> None:
    passes("state", {"before": STATE, "after": upgraded()})


def test_an_upgrade_must_keep_every_row_and_column() -> None:
    after = upgraded()
    after["tables"]["paperless_sync_import"].pop()
    fails("state", {"before": STATE, "after": after}, "removed the row 2 of paperless_sync_import")
    after = upgraded()
    del after["tables"]["paperless_sync_send"][0]["status"]
    fails("state", {"before": STATE, "after": after}, "removed the column status of paperless_sync_send")
    after = upgraded()
    after["tables"]["paperless_sync_export"][0]["state"] = "missing"
    fails("state", {"before": STATE, "after": after}, "changed state of the row 1 of paperless_sync_export")
    after = upgraded()
    del after["tables"]["paperless_sync_send"]
    fails("state", {"before": STATE, "after": after}, "dropped the table paperless_sync_send")


def test_an_upgrade_must_keep_the_configuration_credentials_and_migrations() -> None:
    after = upgraded()
    after["appconfig"]["error_folder"] = "Fehlgeschlagene Importe"
    fails("state", {"before": STATE, "after": after}, "changed error_folder of the configuration")
    after = upgraded()
    del after["credentials"]["/paperless_sync.api-token"]
    fails("state", {"before": STATE, "after": after}, "removed /paperless_sync.api-token from the credentials")
    after = upgraded()
    after["migrations"].remove("000110Date20261008000000")
    fails("state", {"before": STATE, "after": after}, "forgot that the migration 000110Date20261008000000 ran")


def test_a_migration_may_change_what_it_names_on_purpose() -> None:
    after = upgraded()
    after["tables"]["paperless_sync_export"][0]["state"] = "missing"
    after["appconfig"]["error_folder"] = "Fehlgeschlagene Importe"
    passes("state", {"before": STATE, "after": after}, "paperless_sync_export.state appconfig.error_folder")
    fails("state", {"before": STATE, "after": after}, "changed error_folder of the configuration", "paperless_sync_export.state")
    after["tables"]["paperless_sync_import"][0]["status"] = "done"
    fails("state", {"before": STATE, "after": after}, "changed status of the row 1 of paperless_sync_import", "paperless_sync_export.state appconfig.error_folder")


def test_an_upgrade_must_keep_every_file_with_its_id() -> None:
    after = upgraded()
    after["files"]["e2e-mapped"]["Documents/Paperless/Archive/a [P123].pdf"] = 99
    fails("state", {"before": STATE, "after": after}, "changed Documents/Paperless/Archive/a [P123].pdf of the files of e2e-mapped")
    after = upgraded()
    del after["files"]["e2e-other"]
    fails("state", {"before": STATE, "after": after}, "removed Documents/Paperless/Archive from the files of e2e-other")


def test_the_state_never_holds_a_token_in_plain_text() -> None:
    after = upgraded()
    after["appconfig"]["token"] = "e2e-only-token"
    fails("state", {"before": STATE, "after": after}, "a token of Paperless in plain text")


def test_the_settings_keep_every_key_and_may_add_one() -> None:
    after = copy.deepcopy(SETTINGS)
    after["config"]["newSetting"] = False
    result = run("settings", {"before": SETTINGS, "after": after})
    assert result.returncode == 0, result.stderr
    assert "adds the settings newSetting" in result.stdout, result.stdout
    after["config"]["basePath"] = "Dokumente/Paperless"
    fails("settings", {"before": SETTINGS, "after": after}, "changed basePath of the config of the settings page")
    after = copy.deepcopy(SETTINGS)
    after["webhook"]["configured"] = False
    fails("settings", {"before": SETTINGS, "after": after}, "changed configured of the webhook of the settings page")


def test_the_run_after_the_upgrade_downloads_and_uploads_nothing() -> None:
    passes("mock", {"before": MOCK, "after": copy.deepcopy(MOCK)})
    for key, message in (
        ("downloads", "downloads of documents"),
        ("uploads", "made uploads"),
        ("unavailableUploads", "uploads that Paperless could not take"),
        ("invalidAuth", "requests with a wrong token"),
    ):
        after = copy.deepcopy(MOCK)
        after[key] = 3 if isinstance(after[key], int) else [*after[key], {"filename": "again.pdf"}]
        fails("mock", {"before": MOCK, "after": after}, message)


def test_the_retry_still_waits_after_the_run() -> None:
    after = copy.deepcopy(STATE)
    after["tables"]["paperless_sync_import"][0]["status"] = "done"
    passes("retry", {"before": STATE, "after": after})
    after["tables"]["paperless_sync_import"][1]["retry_at"] = 0
    fails("retry", {"before": STATE, "after": after}, "later.pdf of sync-target waited to be tried again")
    after["tables"]["paperless_sync_import"].pop()
    fails("retry", {"before": STATE, "after": after}, "None")
    before = copy.deepcopy(STATE)
    before["tables"]["paperless_sync_import"].pop()
    fails("retry", {"before": before, "after": before}, "proves nothing")


def test_every_migration_of_the_package_ran() -> None:
    passes("migrations", STATE, "000100Date20260826000000 000110Date20261008000000 ")
    fails("migrations", STATE, "didn't execute the migration 000120Date20261014000000", "000100Date20260826000000 000110Date20261008000000 000120Date20261014000000")
    fails("migrations", STATE, "executed the migration 000110Date20261008000000, which the package doesn't have", "000100Date20260826000000")
    fails("migrations", STATE, "proves nothing", "")


def test_an_unknown_mode_fails() -> None:
    result = run("unknown", {})
    assert result.returncode != 0 and "Unknown mode unknown" in result.stderr, result.stderr


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
    print(f"{len(tests) - failures} of {len(tests)} tests of tests/e2e/assert_upgrade.py pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
