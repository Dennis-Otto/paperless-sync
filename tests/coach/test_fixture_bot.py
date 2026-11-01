# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The tests of .github/fixture_bot.py, which commits the fixtures that the contract tests
kept onto a pull request, with a GitHub CLI that answers from a table.

scripts/check-project.sh runs them with the Python of the machine alone:
python3 tests/coach/test_fixture_bot.py. They need no pytest; pytest finds them all the same.
"""

from __future__ import annotations

import base64
import contextlib
import hashlib
import io
import json
import os
import re
import sys
import tempfile
import traceback
from collections.abc import Callable, Iterator, Sequence
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".github"))
os.environ["GH_REPO"] = "owner/name"

import fixture_bot  # noqa: E402 - after the folder of the bot

SHA = "a" * 40
FIXTURE = "tests/fixtures/paperless-ngx-3/documents.json"
OTHER = "tests/fixtures/paperless-ngx-2/ui_settings.json"


class Cli:
    """A GitHub CLI whose answers are a table from the first arguments to their output; an
    exception in it is what the command raises."""

    def __init__(self, answers: dict[str, object]) -> None:
        self.answers = answers
        self.calls: list[tuple[list[str], str]] = []

    def __call__(self, arguments: Sequence[str], text: str) -> str:
        self.calls.append((list(arguments), text))
        key = " ".join(arguments[:2]) if arguments[0] == "pr" else arguments[1]
        answer = self.answers[key]
        if isinstance(answer, Exception):
            raise answer
        return json.dumps(answer)


def pull(number: int = 7, *, fork: bool = False, labels: Sequence[str] = (), sha: str = SHA) -> dict[str, object]:
    return {
        "number": number,
        "headRefOid": sha,
        "isCrossRepository": fork,
        "labels": [{"name": label} for label in labels],
    }


@contextlib.contextmanager
def artifacts(files: dict[str, bytes], version: str = "3.3.2") -> Iterator[Path]:
    """A folder as the workflow downloads the artifacts into it."""
    with tempfile.TemporaryDirectory() as temporary:
        folder = Path(temporary)
        artifact = folder / "fixes-paperless-ngx-3"
        for relative, data in files.items():
            path = artifact / "files" / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        artifact.mkdir(exist_ok=True)
        (artifact / "version.txt").write_text(f"{version}\n", encoding="utf-8")
        yield folder


def blob(data: bytes) -> str:
    return hashlib.sha1(b"blob %d\0" % len(data) + data, usedforsecurity=False).hexdigest()


def test_the_bot_takes_only_fixtures_from_an_artifact() -> None:
    too_large = b'"' + b"x" * fixture_bot.MOST_BYTES + b'"'
    files = {
        FIXTURE: b"{}\n",
        OTHER: b"[]\n",
        "tests/fixtures/paperless-ngx-3/task-failure.v10.json": b"{}\n",
        "tests/fixtures/paperless-ngx-3/broken.json": b"{",
        "tests/fixtures/paperless-ngx-3/latin.json": b'"\xff"',
        "tests/fixtures/paperless-ngx-3/large.json": too_large,
        "tests/fixtures/paperless-ngx-4/documents.json": b"{}\n",
        "tests/fixtures/README.md": b"text\n",
        ".github/workflows/ci.yml": b"{}\n",
        "lib/Service/PaperlessApiService.php": b"{}\n",
    }
    with artifacts(files) as folder:
        loaded = fixture_bot.load(folder)
        assert sorted(loaded) == sorted([FIXTURE, OTHER, "tests/fixtures/paperless-ngx-3/task-failure.v10.json"])
        left = [path.relative_to(folder).as_posix() for path in folder.rglob("*") if path.is_file()]
        # Nothing else stays for a later step, such as the suggestions of blueprint.py.
        assert sorted(left) == sorted(
            [f"fixes-paperless-ngx-3/files/{path}" for path in loaded] + ["fixes-paperless-ngx-3/version.txt"]
        )


def test_the_bot_takes_a_limited_number_of_fixtures() -> None:
    files = {f"tests/fixtures/paperless-ngx-3/f{index:03}.json": b"{}\n" for index in range(fixture_bot.MOST_FILES + 2)}
    with artifacts(files) as folder:
        assert len(fixture_bot.load(folder)) == fixture_bot.MOST_FILES


def test_the_bot_leaves_a_link_alone() -> None:
    with artifacts({FIXTURE: b"{}\n"}) as folder:
        base = folder / "fixes-paperless-ngx-3" / "files"
        link = base / "tests/fixtures/paperless-ngx-3/link.json"
        try:
            link.symlink_to(base / FIXTURE)
        except OSError:
            # Windows without the right to make links.
            return
        assert sorted(fixture_bot.load(folder)) == [FIXTURE]
        assert not link.is_symlink()


def test_the_headline_names_the_versions_of_paperless() -> None:
    assert fixture_bot.headline(["3.3.2"]) == "test(fixtures): record the answers of Paperless-ngx 3.3.2"
    assert fixture_bot.headline(["2.20.16", "3.3.2"]) == "test(fixtures): record the answers of Paperless-ngx 2.20.16 and 3.3.2"
    assert fixture_bot.headline([]) == "test(fixtures): record the answers of Paperless-ngx"


def test_a_version_that_is_none_stays_out_of_the_commit() -> None:
    with artifacts({}, version="3.3.2\n\nSigned-off-by: someone") as folder:
        assert fixture_bot.versions(folder) == []
    with artifacts({}, version="3.3.2-rc.1") as folder:
        assert fixture_bot.versions(folder) == ["3.3.2-rc.1"]


def test_no_fixtures_ask_nothing_of_github() -> None:
    cli = Cli({})
    decided = fixture_bot.plan(cli, SHA, {})
    assert (decided.action, decided.number, decided.loop) == ("", None, False)
    assert cli.calls == []


def test_a_pull_request_that_moved_on_decides_by_its_newest_run() -> None:
    cli = Cli({"pr list": [pull(sha="b" * 40)]})
    decided = fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"})
    assert (decided.action, decided.number) == ("", None)
    assert "no longer the head" in decided.text


def test_the_label_leaves_a_pull_request_alone() -> None:
    cli = Cli({"pr list": [pull(labels=["no-bot-fixes"])]})
    decided = fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"})
    assert (decided.action, decided.number) == ("", 7)


def test_a_fork_gets_suggestions() -> None:
    cli = Cli({"pr list": [pull(fork=True)]})
    assert fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"}).action == "suggest"


def test_a_branch_of_this_repository_gets_a_commit() -> None:
    person = {"author": {"type": "User"}, "commit": {"message": "chore(deps): update Paperless-ngx"}, "files": []}
    cli = Cli({"pr list": [pull(labels=["dependencies"])], f"repos/owner/name/commits/{SHA}": person})
    decided = fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"})
    assert (decided.action, decided.number, decided.loop) == ("commit", 7, False)


def test_the_commit_of_a_person_with_that_headline_is_no_loop() -> None:
    person = {
        "author": {"type": "User"},
        "commit": {"message": f"{fixture_bot.HEADLINE} 3.3.2"},
        "files": [{"filename": FIXTURE}],
    }
    cli = Cli({"pr list": [pull()], f"repos/owner/name/commits/{SHA}": person})
    assert fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"}).action == "commit"


def test_another_line_after_the_commit_of_the_bot_is_no_loop() -> None:
    bot = {
        "author": {"type": "Bot"},
        "commit": {"message": f"{fixture_bot.HEADLINE} 2.20.16"},
        "files": [{"filename": OTHER}],
    }
    cli = Cli({"pr list": [pull()], f"repos/owner/name/commits/{SHA}": bot})
    assert fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"}).action == "commit"


def test_a_fixture_that_differs_again_after_the_commit_of_the_bot_stops_it() -> None:
    bot = {
        "author": {"type": "Bot"},
        "commit": {"message": f"{fixture_bot.HEADLINE} 3.3.2\n\nMade by the contract tests"},
        "files": [{"filename": FIXTURE}],
    }
    cli = Cli({"pr list": [pull()], f"repos/owner/name/commits/{SHA}": bot})
    decided = fixture_bot.plan(cli, SHA, {FIXTURE: b"{}\n"})
    assert (decided.action, decided.number, decided.loop) == ("", 7, True)
    assert FIXTURE in decided.text and "stable()" in decided.text


def run_main(arguments: list[str], cli: Cli) -> tuple[int, str, str]:
    out, error = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(error):
        code = fixture_bot.main(arguments, cli)
    return code, out.getvalue(), error.getvalue()


def test_plan_writes_its_outputs() -> None:
    cli = Cli({"pr list": [pull(fork=True)]})
    with artifacts({FIXTURE: b"{}\n"}) as folder:
        output = folder / "output"
        code, said, _ = run_main(["plan", "--sha", SHA, "--folder", str(folder), "--output", str(output)], cli)
        assert code == 0
        assert "fork" in said
        assert output.read_text(encoding="utf-8") == "action=suggest\npull=7\n"


def test_plan_without_a_pull_request_writes_empty_outputs() -> None:
    with artifacts({}) as folder:
        output = folder / "output"
        code, _, _ = run_main(["plan", "--sha", SHA, "--folder", str(folder), "--output", str(output)], Cli({}))
        assert code == 0
        assert output.read_text(encoding="utf-8") == "action=\npull=\n"


def test_plan_fails_with_an_annotation_in_a_loop() -> None:
    bot = {"author": {"type": "Bot"}, "commit": {"message": fixture_bot.HEADLINE}, "files": [{"filename": FIXTURE}]}
    cli = Cli({"pr list": [pull()], f"repos/owner/name/commits/{SHA}": bot})
    with artifacts({FIXTURE: b"{}\n"}) as folder:
        output = folder / "output"
        code, said, error = run_main(["plan", "--sha", SHA, "--folder", str(folder), "--output", str(output)], cli)
        assert code == 1
        assert "other bytes" in said
        assert error.startswith("::error::")
        assert output.read_text(encoding="utf-8") == "action=\npull=7\n"


def commit_answers(files: dict[str, bytes], **more: object) -> dict[str, object]:
    tree = [{"path": path, "sha": blob(data), "type": "blob"} for path, data in files.items()]
    return {
        f"repos/owner/name/git/trees/{SHA}?recursive=1": {"tree": [*tree, {"path": "tests", "sha": "c", "type": "tree"}]},
        "users/release-app[bot]": {"id": 42},
        "graphql": {"data": {"createCommitOnBranch": {"commit": {"oid": "d" * 40}}}},
        **more,
    }


COMMIT_ARGUMENTS = ["--sha", SHA, "--branch", "renovate/paperless", "--app", "release-app", "--url", "https://run"]


def test_the_commit_holds_the_changed_fixtures_alone_signed_off() -> None:
    new = b'{"new": true}\n'
    same = b"[]\n"
    cli = Cli(commit_answers({FIXTURE: b"{}\n", OTHER: same}))
    missing = "tests/fixtures/paperless-ngx-3/owned-documents.json"
    with artifacts({FIXTURE: new, OTHER: same, missing: b"{}\n"}) as folder:
        code, said, _ = run_main(["commit", *COMMIT_ARGUMENTS, "--folder", str(folder)], cli)
    assert code == 0
    assert "committed ddddddd" in said and FIXTURE in said and OTHER not in said
    arguments, text = cli.calls[-1]
    assert arguments == ["api", "graphql", "--input", "-"]
    change = json.loads(text)["variables"]["input"]
    assert change["expectedHeadOid"] == SHA
    assert change["branch"] == {"repositoryNameWithOwner": "owner/name", "branchName": "renovate/paperless"}
    assert change["message"]["headline"] == "test(fixtures): record the answers of Paperless-ngx 3.3.2"
    assert "https://run" in change["message"]["body"]
    assert change["message"]["body"].endswith(
        "Signed-off-by: release-app[bot] <42+release-app[bot]@users.noreply.github.com>"
    )
    # The bot changes fixtures and adds none, as the fix bot does.
    assert change["fileChanges"]["additions"] == [{"path": FIXTURE, "contents": base64.b64encode(new).decode()}]


def test_fixtures_that_the_branch_has_already_need_no_commit() -> None:
    data = b"{}\n"
    cli = Cli(commit_answers({FIXTURE: data}))
    with artifacts({FIXTURE: data}) as folder:
        _, said, _ = run_main(["commit", *COMMIT_ARGUMENTS, "--folder", str(folder)], cli)
    assert "already" in said
    assert all(call[0][1] != "graphql" for call in cli.calls)


def test_too_many_files_for_one_answer_fail_the_commit() -> None:
    answers = {f"repos/owner/name/git/trees/{SHA}?recursive=1": {"truncated": True, "tree": []}}
    with artifacts({FIXTURE: b"{}\n"}) as folder:
        try:
            run_main(["commit", *COMMIT_ARGUMENTS, "--folder", str(folder)], Cli(answers))
        except RuntimeError as error:
            assert "too many" in str(error)
        else:
            raise AssertionError("The commit went on without the files of the branch.")


def test_a_branch_that_moved_on_keeps_its_commit() -> None:
    answers = commit_answers(
        {FIXTURE: b"{}\n"},
        graphql=RuntimeError("gh api failed: expected head"),
        **{"repos/owner/name/git/ref/heads/renovate/paperless": {"object": {"sha": "e" * 40}}},
    )
    with artifacts({FIXTURE: b"[]\n"}) as folder:
        _, said, _ = run_main(["commit", *COMMIT_ARGUMENTS, "--folder", str(folder)], Cli(answers))
    assert "moved on" in said


def test_a_branch_that_is_gone_keeps_its_commit() -> None:
    answers = commit_answers(
        {FIXTURE: b"{}\n"},
        graphql=RuntimeError("gh api failed: no branch"),
        **{"repos/owner/name/git/ref/heads/renovate/paperless": RuntimeError("gh api failed: 404")},
    )
    with artifacts({FIXTURE: b"[]\n"}) as folder:
        _, said, _ = run_main(["commit", *COMMIT_ARGUMENTS, "--folder", str(folder)], Cli(answers))
    assert "moved on" in said


def test_any_other_failure_of_the_commit_fails_the_bot() -> None:
    answers = commit_answers(
        {FIXTURE: b"{}\n"},
        graphql=RuntimeError("gh api failed: forbidden"),
        **{"repos/owner/name/git/ref/heads/renovate/paperless": {"object": {"sha": SHA}}},
    )
    with artifacts({FIXTURE: b"[]\n"}) as folder:
        try:
            run_main(["commit", *COMMIT_ARGUMENTS, "--folder", str(folder)], Cli(answers))
        except RuntimeError as error:
            assert "forbidden" in str(error)
        else:
            raise AssertionError("The bot took a failed commit for a branch that moved on.")


def test_the_workflows_agree_on_the_run_and_its_artifacts() -> None:
    """A new name on one side would end the bot without a failure on either."""
    workflows = ROOT / ".github" / "workflows"
    end_to_end = (workflows / "e2e.yml").read_text(encoding="utf-8")
    bot = (workflows / "paperless-fixtures.yml").read_text(encoding="utf-8")
    name = re.search(r"^name: (.+)$", end_to_end, re.M)
    assert name is not None
    assert f"workflows: [{name[1]}]" in bot
    assert "name: fixes-paperless-ngx-${{ matrix.line }}" in end_to_end
    assert "path: ${{ runner.temp }}/fixes-paperless-ngx-${{ matrix.line }}/" in end_to_end
    assert '"^fixes-paperless-ngx-[23]$"' in bot
    # What run-contract.sh keeps is what load() reads.
    script = (ROOT / "tests" / "e2e" / "run-contract.sh").read_text(encoding="utf-8")
    assert '"${CONTRACT_FIXES}/files/${FIXTURES}/${name}"' in script
    assert 'FIXTURES="tests/fixtures/paperless-ngx-${LINE}"' in script
    assert fixture_bot.FIXTURE.fullmatch("tests/fixtures/paperless-ngx-2/ui_settings.json")
    assert fixture_bot.FIXTURE.fullmatch("tests/fixtures/paperless-ngx-3/task-failure.v10.json")
    assert '"${CONTRACT_FIXES}/version.txt"' in script


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
    print(f"{len(tests) - failures} of {len(tests)} tests of .github/fixture_bot.py pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
