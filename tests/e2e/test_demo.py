# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The tests of the demo in demo/ that need no container: its passwords, what demo/compose.yaml
promises, and that a run or a smoke test with an empty archive fails.

scripts/check-project.sh runs them with the Python of the machine alone:
python3 tests/e2e/test_demo.py. They need no pytest; pytest finds them all the same.
tests/e2e/demo.sh starts the demo itself, in the job demo of the end-to-end tests.
"""

from __future__ import annotations

import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import traceback
from collections.abc import Callable
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
sys.path[:0] = [str(HERE), str(ROOT / "demo")]

import init  # noqa: E402
import showcase  # noqa: E402

COMPOSE = (ROOT / "demo" / "compose.yaml").read_text(encoding="utf-8")
SMOKE = (HERE / "demo.sh").read_text(encoding="utf-8")


def services() -> dict[str, str]:
    """The text of each service of demo/compose.yaml, by its name."""
    part = COMPOSE.split("\nservices:\n", 1)[1].split("\nvolumes:\n", 1)[0]
    return dict(re.findall(r"^  (\w+):\n((?:(?:    .*|  #.*)?\n)+)", part + "\n", re.MULTILINE))


def in_a_folder(test: Callable[[Path, Path], None]) -> None:
    with tempfile.TemporaryDirectory() as name:
        folder = Path(name)
        (folder / "shared").mkdir()
        test(folder / ".env", folder / "shared")


def test_a_start_makes_the_secrets_once_and_keeps_them() -> None:
    def test(env_file: Path, shared: Path) -> None:
        first = init.make_secrets(env_file, shared)
        assert set(first) == {init.PASSWORD, init.SECRET_KEY}, first
        assert re.fullmatch(r"[0-9a-f]{32}", first[init.PASSWORD]), "128 random bits, which any shell can quote"
        assert first[init.PASSWORD] != first[init.SECRET_KEY]
        assert init.read_env(env_file) == first
        assert (shared / init.PASSWORD).read_text(encoding="utf-8") == first[init.PASSWORD]
        assert init.make_secrets(env_file, shared) == first

    in_a_folder(test)


def test_the_volume_wins_over_a_changed_or_missing_env_file() -> None:
    def test(env_file: Path, shared: Path) -> None:
        first = init.make_secrets(env_file, shared)
        env_file.unlink()
        assert init.make_secrets(env_file, shared) == first, "Nextcloud and Paperless were set up with these"
        assert init.read_env(env_file) == first
        env_file.write_text(f"{init.PASSWORD}=edited\n", encoding="utf-8")
        assert init.make_secrets(env_file, shared) == first
        assert init.read_env(env_file) == first

    in_a_folder(test)


def test_the_env_file_of_earlier_volumes_comes_back() -> None:
    def test(env_file: Path, shared: Path) -> None:
        first = init.make_secrets(env_file, shared)
        for stored in shared.iterdir():
            stored.unlink()
        assert init.make_secrets(env_file, shared) == first, "after docker compose down --volumes"

    in_a_folder(test)


def test_other_lines_of_the_env_file_stay() -> None:
    def test(env_file: Path, shared: Path) -> None:
        env_file.write_text("# My ports\nDEMO_NEXTCLOUD_PORT=9000\n", encoding="utf-8")
        values = init.make_secrets(env_file, shared)
        lines = env_file.read_text(encoding="utf-8").splitlines()
        assert lines[:2] == ["# My ports", "DEMO_NEXTCLOUD_PORT=9000"], lines
        assert init.read_env(env_file) == {"DEMO_NEXTCLOUD_PORT": "9000", **values}

    in_a_folder(test)


def test_only_the_owner_reads_the_env_file_where_the_system_has_modes() -> None:
    def test(env_file: Path, shared: Path) -> None:
        init.make_secrets(env_file, shared)
        if os.name == "posix":
            assert stat.S_IMODE(env_file.stat().st_mode) == 0o600
            assert stat.S_IMODE((shared / init.PASSWORD).stat().st_mode) == 0o600

    in_a_folder(test)


class Nextcloud:
    """The answers of the app to the first run, without a server."""

    def __init__(self, dry_run: int, state: str, exported: int) -> None:
        self.dry_run = dry_run
        self.status = {"state": state, "summary": {"exported": exported}, "error": "Paperless is gone"}
        self.posted: list[tuple[str, dict[str, str]]] = []

    def form(self, method: str, path: str, fields: dict[str, str], expect: tuple[int, ...] = (200,)) -> object:
        assert method == "POST", method
        self.posted.append((path, fields))
        return {"exported": self.dry_run}

    def json(self, method: str, path: str, value: object = None, expect: tuple[int, ...] = (200,)) -> object:
        assert (method, path) == ("GET", "/apps/paperless_sync/sync/status")
        return self.status


def first_run_error(nextcloud: Nextcloud, *, again: bool = False) -> str:
    silent, init.say = init.say, lambda message="": None
    with tempfile.TemporaryDirectory() as name:
        started = Path(name) / "first-run-started"
        if again:
            started.touch()
        try:
            init.first_run(nextcloud, "8581", 14, started)
        except init.DemoError as error:
            return str(error)
        finally:
            init.say = silent
        assert started.exists(), "the next start must know that the archive may have documents"
    return ""


def test_the_first_run_comes_after_a_dry_run_and_fills_the_archive() -> None:
    nextcloud = Nextcloud(14, "completed", 14)
    assert first_run_error(nextcloud) == ""
    paths = [path for path, _ in nextcloud.posted]
    assert paths == [init.RUN_URL, init.SETTINGS_URL, init.RUN_URL], paths
    assert nextcloud.posted[0][1] == {"dryRun": "true"}
    assert nextcloud.posted[1][1]["settings[enabled]"] == "1"
    assert nextcloud.posted[1][1]["settings[target_user]"] == "demo"
    assert nextcloud.posted[2][1] == {"dryRun": "false"}


def test_an_archive_that_stays_empty_fails_the_demo() -> None:
    assert "lists 0 documents instead of 14" in first_run_error(Nextcloud(0, "completed", 0))
    assert "completed with 0 of the 14" in first_run_error(Nextcloud(14, "completed", 0))
    failed = first_run_error(Nextcloud(14, "failed", 0))
    assert "failed with 0 of the 14" in failed and "Paperless is gone" in failed, failed


def test_a_start_after_a_complete_first_run_ends_with_the_settings_on() -> None:
    nextcloud = Nextcloud(0, "completed", 14)
    assert first_run_error(nextcloud, again=True) == ""
    assert [path for path, _ in nextcloud.posted] == [init.RUN_URL, init.SETTINGS_URL], nextcloud.posted
    assert nextcloud.posted[1][1]["settings[enabled]"] == "1"


def test_a_start_after_an_interrupted_first_run_exports_the_rest() -> None:
    nextcloud = Nextcloud(5, "completed", 5)
    assert first_run_error(nextcloud, again=True) == ""
    assert [path for path, _ in nextcloud.posted] == [init.RUN_URL, init.SETTINGS_URL, init.RUN_URL]
    assert "completed with 2 of the 5" in first_run_error(Nextcloud(5, "completed", 2), again=True)


def test_a_start_waits_for_the_first_run_that_is_still_going() -> None:
    nextcloud = Nextcloud(0, "running", 0)
    answers = iter([{"state": "running"}, {"state": "completed"}])
    nextcloud.json = lambda method, path: next(answers)
    sleep, init.time.sleep = init.time.sleep, lambda seconds: None
    try:
        assert first_run_error(nextcloud, again=True) == ""
    finally:
        init.time.sleep = sleep
    assert next(answers, None) is None, "the dry run only comes after the run has ended"


def test_a_failure_says_how_to_start_over() -> None:
    lines: list[str] = []
    say, init.say = init.say, lines.append
    run, init.run = init.run, lambda: (_ for _ in ()).throw(init.DemoError("It broke."))
    try:
        assert init.main(["run"]) == 1
    finally:
        init.say, init.run = say, run
    assert "It broke." in lines[0] and "down --volumes" in lines[1], lines


def test_a_dry_run_without_the_documents_keeps_the_marker_away() -> None:
    with tempfile.TemporaryDirectory() as name:
        started = Path(name) / "first-run-started"
        say, init.say = init.say, lambda message="": None
        try:
            init.first_run(Nextcloud(0, "completed", 0), "8581", 14, started)
        except init.DemoError:
            pass
        finally:
            init.say = say
        assert not started.exists(), "the first run hasn't begun, so the next start checks the dry run again"


def test_the_smoke_test_fails_on_an_empty_archive() -> None:
    """demo.sh with a docker whose every command passes and whose archive has no file."""
    # ignore_cleanup_errors: Git Bash on Windows lets go of the folder a moment after it ends.
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as name:
        docker = Path(name) / "docker"
        docker.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8", newline="\n")
        docker.chmod(0o755)
        env = {key: value for key, value in os.environ.items() if key != "CI"}
        env.update(DOCKER_BIN=docker.as_posix(), E2E_PROJECT_NAME="paperless_sync_demo_unit")
        # shutil.which: on Windows, a plain bash would start that of WSL before the one on the PATH.
        done = subprocess.run(
            [shutil.which("bash") or "bash", "tests/e2e/demo.sh"],
            capture_output=True,
            encoding="utf-8",
            env=env,
            cwd=ROOT,
            check=False,
        )
    assert done.returncode == 1, done.stdout + done.stderr
    assert "The archive of the demo has 0 files instead of 14." in done.stderr, done.stderr
    assert "The demo tests fail with exit code 1." in done.stderr, done.stderr


def test_the_smoke_test_expects_what_the_showcase_seeds() -> None:
    documents, trash = showcase.documents("showcase")
    assert not trash
    assert f"PAPERLESS_DOCUMENTS={len(documents)}\n" in SMOKE
    assert f"ARCHIVE_FILES={len(showcase.ARCHIVE)}\n" in SMOKE
    inbox = {tag["id"] for tag in showcase.TAGS if tag["is_inbox_tag"]}
    waiting = [document for document in documents if inbox & set(document["tags"])]
    assert len(documents) - len(waiting) == len(showcase.ARCHIVE), waiting


def test_every_image_is_pinned_by_its_digest() -> None:
    images = re.findall(r"^\s*image:\s*(\S+)", COMPOSE, re.MULTILINE)
    assert len(images) >= 7, images
    unpinned = [image for image in images if not re.search(r":[^:@]+@sha256:[0-9a-f]{64}$", image)]
    assert not unpinned, f"Renovate keeps an image current by its tag and digest: {unpinned}"
    assert len({image for image in images if image.startswith("nextcloud:")}) == 1, "one Nextcloud in every service"


def test_every_service_has_its_image_in_its_own_lines() -> None:
    # Renovate reads image: of a service; one that comes through a merge key stays behind.
    for name, text in services().items():
        assert re.search(r"^    image: ", text, re.MULTILINE), f"the service {name} has no image of its own"


def test_every_port_stays_on_this_machine() -> None:
    for name, text in services().items():
        if name != "paperless":
            assert "ports:" not in text, f"the service {name} publishes a port of its own"
    published = re.findall(r"^\s+ports:\n((?:\s+- .*\n)+)", COMPOSE, re.MULTILINE)
    assert len(published) == 1, published
    lines = published[0].splitlines()
    assert len(lines) == 2, "Nextcloud and Paperless"
    for line in lines:
        assert line.strip().startswith("- 127.0.0.1:"), line


def test_no_password_is_written_in_the_compose_file() -> None:
    for line in COMPOSE.splitlines():
        match = re.match(r"\s*(\w*(?:PASSWORD|SECRET|TOKEN|KEY)\w*):\s*(.*)$", line)
        if match:
            assert match[1].endswith("_FILE") and match[2].startswith("/secrets/"), line
    secrets = {"DEMO_PASSWORD", "PAPERLESS_SECRET_KEY"}
    assert {init.PASSWORD, init.SECRET_KEY} == secrets
    assert set(re.findall(r"/secrets/(\w+)", COMPOSE)) == secrets


def test_the_demo_runs_a_nextcloud_that_the_app_supports() -> None:
    info = (ROOT / "appinfo" / "info.xml").read_text(encoding="utf-8")
    versions = re.search(r'<nextcloud min-version="(\d+)" max-version="(\d+)"', info)
    assert versions, "appinfo/info.xml has no versions of Nextcloud"
    major = re.search(r"image: nextcloud:(\d+)\.", COMPOSE)
    assert major, "demo/compose.yaml has no image of Nextcloud by its version"
    assert int(versions[1]) <= int(major[1]) <= int(versions[2]), (
        f"Nextcloud {major[1]} in demo/compose.yaml, while appinfo/info.xml supports {versions[1]} to {versions[2]}"
    )


def test_git_keeps_the_password_out_and_the_hook_executable() -> None:
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    ignored = subprocess.run(["git", "check-ignore", "--quiet", "demo/.env"], cwd=ROOT, env=env, check=False)
    assert ignored.returncode == 0, "Git must ignore demo/.env"
    mode = subprocess.run(
        ["git", "ls-files", "--stage", "demo/nextcloud-hook.sh"],
        cwd=ROOT,
        env=env,
        capture_output=True,
        encoding="utf-8",
        check=True,
    ).stdout
    assert mode.startswith("100755 "), f"Nextcloud skips a hook without the executable flag: {mode!r}"


def test_the_app_package_leaves_the_demo_out() -> None:
    ignored = (ROOT / ".nextcloudignore").read_text(encoding="utf-8").splitlines()
    assert "/demo" in ignored, ".nextcloudignore must list /demo, or it ends up in the package of the App Store"


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
    print(f"{len(tests) - failures} of {len(tests)} tests of the demo pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
