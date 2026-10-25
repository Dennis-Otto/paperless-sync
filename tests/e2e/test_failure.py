"""The tests of tests/e2e/failure.py, which names the first error of each run of the
end-to-end tests that failed, with samples of the logs of each kind of failure: a check in
the browser, an assertion of assert_json.py, a message of run.sh, the time limit of
run-all.sh and a broken contract.

scripts/check-project.sh runs them with the Python of the machine alone:
python3 tests/e2e/test_failure.py. They need no pytest; pytest finds them all the same.
"""

from __future__ import annotations

import contextlib
import io
import re
import subprocess
import sys
import tempfile
import traceback
from collections.abc import Callable
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import failure  # noqa: E402

SCRIPT = Path(failure.__file__)

SETUP = """The tests run against Nextcloud 33.0.4 on SQLite with local storage.
The containers run after 41 s.
Nextcloud and the app are set up after 95 s.
"""

# The canary of #226, as browser.mjs and run.sh tell it now: files.mjs fails in the browser.
BROWSER = (
    SETUP
    + """Send to Paperless passes after 181 s.
Send to Paperless in Flow passes after 185 s.
axe-core, status of a failed run, light theme: 23 rule(s) passed, 0 violated, 0 of them serious or critical
Settings: an error appears next to the buttons, in view, and moves the focus to the setting that it names, or takes it.
Settings: a failed connection and an ended session say what to do.
The check in the browser files.mjs fails: locator.waitFor: Timeout 60000ms exceeded.
Kept for the artifact of the job: files-1-1.png, files-1.trace.zip.
file:///tmp/browser/files.mjs:49
locator.waitFor: Timeout 60000ms exceeded.
Call log:
  - waiting for locator('[data-cy-files-list-row-name="receipt.pdf"]') to be visible
The check in the browser flow.mjs fails: Saving the rule keeps its three tags: []
The checks in the browser fail: files.mjs flow.mjs.
The end-to-end tests fail with exit code 1.
NAME                 IMAGE     COMMAND
nextcloud-1  | AH00558: apache2: Could not reliably determine the server's fully qualified domain name
"""
)

ASSERTION = (
    SETUP
    + """Traceback (most recent call last):
  File "/tmp/assert_json.py", line 734, in <module>
    raise AssertionError(f"{mode}: expected {key}={value!r}, got {actual!r}")
AssertionError: dry-run: expected exported=2, got 1
The end-to-end tests fail with exit code 1.
mock-1  | Traceback (most recent call last):
mock-1  | ValueError: from the logs of the containers, which the cleanup prints
"""
)

MESSAGE = (
    SETUP
    + """The scenarios pass after 300 s.
Synchronize now queues the run.
The webhook answered HTTP 500 instead of 200: {"status":"error"}

The end-to-end tests fail with exit code 1.
"""
)


def run(*arguments: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, "-B", str(SCRIPT), *arguments], capture_output=True, text=True, check=False
    )


def quietly(function: Callable[[], str]) -> tuple[str, str]:
    """What the function returns and what it prints."""
    printed = io.StringIO()
    with contextlib.redirect_stdout(printed):
        result = function()
    return result, printed.getvalue()


def test_a_check_in_the_browser_is_the_first_error_with_its_script() -> None:
    found = failure.describe("Nextcloud 36.0.0 dev", BROWSER)
    assert found == failure.Failure(
        "Nextcloud 36.0.0 dev",
        "locator.waitFor: Timeout 60000ms exceeded.",
        "files.mjs",
        "Send to Paperless in Flow passes after 185 s.",
        ("flow.mjs",),
    ), found
    assert found.markdown() == (
        "- **Nextcloud 36.0.0 dev**: `locator.waitFor: Timeout 60000ms exceeded.` in `tests/e2e/files.mjs`."
        " The last step that passed: Send to Paperless in Flow passes after 185 s. Failed as well: `flow.mjs`."
    ), found.markdown()
    assert found.text() == (
        "locator.waitFor: Timeout 60000ms exceeded. In tests/e2e/files.mjs."
        " The last step that passed: Send to Paperless in Flow passes after 185 s. Failed as well: flow.mjs."
    ), found.text()


def test_a_check_that_fails_on_several_pages_is_named_once() -> None:
    found = failure.describe(
        "Nextcloud 35",
        "The check in the browser accessibility.mjs fails: 2 serious or critical violation(s) in getting started\n"
        "The check in the browser accessibility.mjs fails: 1 serious or critical violation(s) in Flow\n"
        "The check in the browser keyboard.mjs fails: 1 check(s) by keyboard alone fail\n"
        "The check in the browser keyboard.mjs fails: 1 check(s) by keyboard alone fail\n",
    )
    assert found.script == "accessibility.mjs" and found.others == ("keyboard.mjs",), found
    assert found.error == "2 serious or critical violation(s) in getting started", found


def test_an_assertion_is_the_first_error_and_the_logs_of_the_containers_are_not() -> None:
    found = failure.describe("Nextcloud 33 on PostgreSQL", ASSERTION)
    assert found.error == "AssertionError: dry-run: expected exported=2, got 1", found
    assert found.script == "" and found.passed == "Nextcloud and the app are set up after 95 s.", found
    assert found.markdown() == (
        "- **Nextcloud 33 on PostgreSQL**: `AssertionError: dry-run: expected exported=2, got 1`."
        " The last step that passed: Nextcloud and the app are set up after 95 s."
    ), found.markdown()
    assert found.text() == (
        "AssertionError: dry-run: expected exported=2, got 1. The last step that passed: Nextcloud and the app are set up after 95 s."
    ), found.text()


def test_an_exception_of_occ_is_named_with_its_file_and_line() -> None:
    # The installation on MariaDB with S3 of the first run of #274, whose object store failed.
    # occ pads the lines of its box with spaces, which a file here may not end its lines with.
    pad = " " * 46
    log = "\n".join([
        "The containers run after 83 s.",
        "",
        "In ObjectStoreStorage.php line 575:",
        pad,
        "  Error while writing stream to object store  ",
        pad,
        "",
        "In WrappedHttpHandler.php line 204:",
        pad,
        '  Error executing "PutObject" on "http://s3:8333/nextcloud/urn%3Aoid%3A8"; AW  ',
        "  S HTTP error:  ",
        "",
        "maintenance:install [--database DATABASE] [--database-name DATABASE-NAME] [--data-dir DATA-DIR] [--] <command>",
        "",
        "The end-to-end tests fail with exit code 1.",
    ])
    found = failure.describe("Nextcloud 35 on MariaDB with S3", log)
    assert found.error == "Error while writing stream to object store (ObjectStoreStorage.php line 575)", found
    assert found.passed == "The containers run after 83 s.", found
    bare = failure.describe("Nextcloud 35", "In Manager.php line 12:\n")
    assert bare.error == "an exception without a message (Manager.php line 12)", bare


def test_a_message_of_run_sh_is_the_last_line_before_its_cleanup() -> None:
    found = failure.describe("Nextcloud 34", MESSAGE)
    assert found.error == 'The webhook answered HTTP 500 instead of 200: {"status":"error"}', found
    assert found.script == "", found
    assert found.passed == "The scenarios pass after 300 s.", found


def test_an_error_before_the_last_step_that_passed_was_not_the_failure() -> None:
    log = SETUP.replace(
        "The containers run after 41 s.\n",
        "The containers run after 41 s.\nAssertionError: a retry that run.sh tolerates\n",
    )
    found = failure.describe("Nextcloud 33", log + "occ paperless_sync:run ended with exit code 2: {}\n")
    assert found.error == "occ paperless_sync:run ended with exit code 2: {}", found


def test_a_run_that_a_signal_stopped_says_so() -> None:
    found = failure.describe("Nextcloud 35", SETUP + "Send to Paperless passes after 181 s.\n"
                             "The end-to-end tests fail with exit code 143.\n")
    assert found.error == "Stopped by a signal, such as the time limit of run-all.sh (exit code 143).", found
    assert found.passed == "Send to Paperless passes after 181 s.", found


def test_a_broken_contract_and_the_colors_of_a_log() -> None:
    found = failure.describe(
        "Paperless-ngx 3.x",
        "\x1b[32mThe setup of the account in Paperless passes after 12 s.\x1b[0m\n"
        "\x1b[31mContract broken: GET /api/tags/ answered HTTP 500 instead of 200: b''\x1b[0m\n"
        "The contract tests fail with exit code 1.\n",
    )
    assert found.error == "Contract broken: GET /api/tags/ answered HTTP 500 instead of 200: b''", found
    assert found.passed == "The setup of the account in Paperless passes after 12 s.", found


def test_a_message_of_run_contract_sh_is_not_hidden_by_the_logs_of_the_containers() -> None:
    found = failure.describe(
        "Paperless-ngx 2.x",
        "The setup of the account in Paperless passes after 12 s.\n"
        "occ paperless_sync:run ended with exit code 1: {}\n"
        "The contract tests fail with exit code 1.\n"
        "NAME                 IMAGE     STATUS\n"
        'nextcloud-1  | 172.18.0.1 - - [25/Oct/2026:01:02:03 +0000] "GET /status.php HTTP/1.1" 200\n',
    )
    assert found.error == "occ paperless_sync:run ended with exit code 1: {}", found


def test_both_scripts_print_the_line_where_the_report_stops() -> None:
    for name in ("run.sh", "run-contract.sh"):
        lines = (SCRIPT.parent / name).read_text(encoding="utf-8").splitlines()
        printed = [
            line.strip().removeprefix('echo "').removesuffix('" >&2').replace("${status}", "1")
            for line in lines
            if "fail with exit code ${status}." in line
        ]
        assert any(failure.STOPPED.match(line) for line in printed), (name, printed)


def test_a_long_error_is_cut_and_a_backtick_cannot_end_its_code() -> None:
    found = failure.describe("Nextcloud 33", f"Error: `{'x' * 400}`\n")
    assert len(found.error) == failure.LONGEST and found.error.endswith("…"), found.error
    assert found.markdown().startswith("- **Nextcloud 33**: `Error: 'xxx"), found.markdown()
    assert found.markdown().count("`") == 2, found.markdown()


def test_a_log_without_an_error_says_so() -> None:
    found = failure.describe("Nextcloud 33", SETUP)
    assert found == failure.Failure("Nextcloud 33", passed="Nextcloud and the app are set up after 95 s."), found
    empty = failure.describe("Nextcloud 33", "The containers run after 41 s.\n\n")
    assert empty.error == "" and empty.passed == "The containers run after 41 s.", empty
    assert "names no error" in empty.markdown() and "names no error" in empty.text(), empty
    assert failure.Failure("x", "Error: y").text() == "Error: y.", failure.Failure("x", "Error: y").text()


def test_the_folder_of_the_artifacts_has_a_failure_for_each_run_with_a_log() -> None:
    with tempfile.TemporaryDirectory() as name:
        folder = Path(name)
        for run_, title in (("35", "Nextcloud 35 (35.0.1): failed"), ("33-pgsql", None), ("34", "")):
            (folder / run_).mkdir()
            (folder / run_ / "run.log").write_text(MESSAGE, encoding="utf-8")
            if title is not None:
                (folder / run_ / "title.txt").write_text(f"{title}\n", encoding="utf-8")
        (folder / "browser").mkdir()
        (folder / "notes.txt").write_text("no run", encoding="utf-8")
        titles = [found.title for found in failure.failures(folder)]
        assert titles == ["33-pgsql", "34", "Nextcloud 35 (35.0.1): failed"], titles


def test_the_report_outside_github_actions_prints_the_failures_alone() -> None:
    with tempfile.TemporaryDirectory() as name:
        folder = Path(name)
        (folder / "coming").mkdir()
        (folder / "coming" / "run.log").write_text(BROWSER, encoding="utf-8")
        markdown, printed = quietly(lambda: failure.report(folder, environment={}))
        assert markdown.startswith("- **coming**: `locator.waitFor"), markdown
        assert printed == f"{markdown}\n", printed
        assert sorted(path.name for path in folder.rglob("*")) == ["coming", "run.log"]


def test_the_report_in_github_actions_writes_the_summary_the_annotations_and_the_output() -> None:
    with tempfile.TemporaryDirectory() as name:
        folder = Path(name) / "artifacts"
        (folder / "coming").mkdir(parents=True)
        (folder / "coming" / "run.log").write_text(BROWSER, encoding="utf-8")
        (folder / "coming" / "title.txt").write_text("Nextcloud 36.0.0 dev (latest-master.tar.bz2, 2026-10-18)", encoding="utf-8")
        summary, output = Path(name) / "summary.md", Path(name) / "output.txt"
        output.write_text("version=Nextcloud 36\n", encoding="utf-8")
        environment = {"GITHUB_ACTIONS": "true", "GITHUB_STEP_SUMMARY": str(summary), "GITHUB_OUTPUT": str(output)}
        markdown, printed = quietly(lambda: failure.report(folder, "first-error-3", environment))
        annotation = printed.splitlines()[-1]
        assert annotation == (
            "::error title=First error%3A Nextcloud 36.0.0 dev (latest-master.tar.bz2%2C 2026-10-18)::"
            "locator.waitFor: Timeout 60000ms exceeded. In tests/e2e/files.mjs."
            " The last step that passed: Send to Paperless in Flow passes after 185 s. Failed as well: flow.mjs."
        ), annotation
        assert summary.read_text(encoding="utf-8") == f"### The first error of each run that failed\n\n{markdown}\n"
        written = output.read_text(encoding="utf-8")
        match = re.fullmatch(r"version=Nextcloud 36\nfirst-error-3<<(FIRST_ERROR_[0-9a-f]{32})\n(.*)\n\1\n", written, re.S)
        assert match and match.group(2) == markdown, written
        # Without a file for the summary or the output, it writes the annotations alone.
        _, printed = quietly(lambda: failure.report(folder, environment={"GITHUB_ACTIONS": "true"}))
        assert printed.splitlines()[-1].startswith("::error title="), printed
    assert failure.escape("50%\r\nnext") == "50%25%0D%0Anext"


def test_the_report_without_a_failed_run_writes_no_output() -> None:
    with tempfile.TemporaryDirectory() as name:
        output = Path(name) / "output.txt"
        environment = {"GITHUB_ACTIONS": "true", "GITHUB_OUTPUT": str(output)}
        for folder in (Path(name), Path(name) / "missing"):
            markdown, printed = quietly(lambda folder=folder: failure.report(folder, environment=environment))
            assert markdown == "" and printed == f"No run in {folder} left the log of a failure.\n", printed
        assert not output.exists()


def test_the_command_ends_with_0_and_names_its_usage_otherwise() -> None:
    with tempfile.TemporaryDirectory() as name:
        result = run(name)
        assert result.returncode == 0 and "left the log of a failure" in result.stdout, result
    result = run()
    assert result.returncode == 2 and "python3 tests/e2e/failure.py FOLDER [OUTPUT]" in result.stderr, result
    with contextlib.redirect_stderr(io.StringIO()) as usage:
        assert failure.main(["a", "b", "c"]) == 2
    assert "FOLDER [OUTPUT]" in usage.getvalue(), usage.getvalue()
    with tempfile.TemporaryDirectory() as name:
        code, printed = quietly(lambda: str(failure.main([name, "first-error-2"])))
        assert code == "0" and "left the log of a failure" in printed, printed


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
    print(f"{len(tests) - failures} of {len(tests)} tests of tests/e2e/failure.py pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
