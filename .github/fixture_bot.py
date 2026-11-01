# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The fixture bot of .github/workflows/paperless-fixtures.yml: new answers of Paperless
reach the unit tests in the pull request that brings them.

When the app passes against a real Paperless-ngx in a pull request, and the fixtures in
tests/fixtures/ differ from its answers, the job of the contract tests keeps them as an
artifact fixes-paperless-ngx-<line> (tests/e2e/run-contract.sh). After the end-to-end tests,
this script of main takes these artifacts as data that the pull request made:

    python3 .github/fixture_bot.py plan --sha SHA --folder FOLDER --output FILE
    python3 .github/fixture_bot.py commit --sha SHA --branch NAME --app SLUG --url URL --folder FOLDER

plan removes everything from the folder but the fixtures that the artifacts may hold, and
says what the bot does: action=commit for a pull request of this repository, action=suggest
for one from a fork, which no bot may push to, and no action for a pull request that moved
on or that has the label no-bot-fixes. It fails when the bot's own commit just wrote a
fixture that differs again, as two recordings of one image must be the same bytes. commit
commits the fixtures as whoever GH_TOKEN names, through GitHub's API, which signs the commit;
its push starts the checks again.

It reaches GitHub with the GitHub CLI alone, as GH_TOKEN and GH_REPO say.
tests/coach/test_fixture_bot.py tests it.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import subprocess
import sys
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

# The fixtures that the bot may write, one folder for each line of Paperless.
FIXTURE = re.compile(r"tests/fixtures/paperless-ngx-[23]/[A-Za-z0-9][A-Za-z0-9._-]*\.json")
VERSION = re.compile(r"\d+\.\d+\.\d+[0-9A-Za-z.+-]*")
HEADLINE = "test(fixtures): record the answers of Paperless-ngx"
# The label of a pull request whose author wants no commits of a bot, as for the fix bot.
OPT_OUT = "no-bot-fixes"
# What one commit of the bot holds at most, as for the fix bot.
MOST_FILES = 100
MOST_BYTES = 1_000_000
COMMIT = """
mutation($input: CreateCommitOnBranchInput!) {
  createCommitOnBranch(input: $input) { commit { oid } }
}
"""

# The GitHub CLI, with its arguments and what it reads from standard input.
Gh = Callable[[Sequence[str], str], str]


@dataclass(frozen=True)
class Plan:
    """What the bot does with the fixtures of a commit, and what it says. It stops with
    an error when its own commit just wrote a fixture that differs again."""

    action: str
    number: int | None
    text: str
    loop: bool = False


def gh(arguments: Sequence[str], text: str = "") -> str:
    """Runs the GitHub CLI and gives its output; it fails with what the CLI says."""
    done = subprocess.run(
        ["gh", *arguments],
        input=text,
        capture_output=True,
        encoding="utf-8",
        check=False,
    )
    if done.returncode:
        raise RuntimeError(f"gh {arguments[0]} failed: {done.stderr.strip()}")
    return done.stdout


def api(run: Gh, path: str) -> Any:
    """An answer of GitHub's API."""
    return json.loads(run(["api", path], ""))


def is_fixture(relative: str, path: Path) -> bool:
    """Whether a file of an artifact is what its job keeps: JSON at the path of a fixture,
    of no unusual size. The artifact comes from a pull request."""
    if not FIXTURE.fullmatch(relative) or path.is_symlink():
        return False
    data = path.read_bytes()
    if len(data) > MOST_BYTES:
        return False
    try:
        json.loads(data.decode("utf-8"))
    except ValueError:
        return False
    return True


def load(folder: Path) -> dict[str, bytes]:
    """The fixtures of the artifacts in folder, one subfolder for each, by their paths.
    Whatever else an artifact holds leaves the folder, so that no later step reads it."""
    files: dict[str, bytes] = {}
    for base in sorted(folder.glob("*/files")):
        for path in sorted(base.rglob("*")):
            if path.is_dir() and not path.is_symlink():
                continue
            relative = path.relative_to(base).as_posix()
            if is_fixture(relative, path) and len(files) < MOST_FILES:
                files[relative] = path.read_bytes()
            else:
                path.unlink()
    return files


def versions(folder: Path) -> list[str]:
    """The versions of Paperless that the artifacts in folder name."""
    found = set()
    for path in folder.glob("*/version.txt"):
        version = path.read_text(encoding="utf-8", errors="replace").strip()
        if VERSION.fullmatch(version):
            found.add(version)
    return sorted(found)


def headline(names: Sequence[str]) -> str:
    """The first line of the bot's commit, with the versions of Paperless that it recorded."""
    return f"{HEADLINE} {' and '.join(names)}" if names else HEADLINE


def pull_of(run: Gh, sha: str) -> dict[str, Any] | None:
    """The open pull request whose head is sha, of this repository or from a fork."""
    fields = "number,headRefOid,isCrossRepository,labels"
    pulls = json.loads(run(["pr", "list", "--state", "open", "--limit", "500", "--json", fields], ""))
    return next((pull for pull in pulls if pull["headRefOid"] == sha), None)


def repository() -> str:
    return os.environ["GH_REPO"]


def own_commit_wrote(run: Gh, sha: str) -> list[str]:
    """The files of sha when it is a commit of a bot with the headline of this one."""
    commit = api(run, f"repos/{repository()}/commits/{sha}")
    message = (commit.get("commit") or {}).get("message") or ""
    if (commit.get("author") or {}).get("type") != "Bot" or not message.startswith(HEADLINE):
        return []
    return [item["filename"] for item in commit.get("files") or []]


def plan(run: Gh, sha: str, files: dict[str, bytes]) -> Plan:
    """Decides what the bot does with the fixtures of a commit."""
    if not files:
        return Plan("", None, "The fixtures are the answers of Paperless; the bot has nothing to do.\n")
    pull = pull_of(run, sha)
    if pull is None:
        return Plan("", None, f"{sha[:7]} is no longer the head of an open pull request; its newest run decides.\n")
    number = int(pull["number"])
    if OPT_OUT in {label["name"] for label in pull["labels"]}:
        return Plan("", number, f"#{number} has the label {OPT_OUT}: the bot leaves it alone.\n")
    if pull["isCrossRepository"]:
        return Plan("suggest", number, f"#{number} comes from a fork, so the bot suggests the fixtures.\n")
    again = sorted(set(files) & set(own_commit_wrote(run, sha)))
    if again:
        return Plan(
            "",
            number,
            f"The commit {sha[:7]} of the bot wrote {', '.join(again)}, and the contract tests of that commit "
            "recorded other bytes for them, so something differs from run to run: compare the fixtures with the "
            "artifact of the run, add it to stable() in tests/e2e/contract.py, and record them anew.\n",
            loop=True,
        )
    return Plan("commit", number, f"#{number} comes from a branch of this repository, so the bot commits the fixtures.\n")


def blob_sha(data: bytes) -> str:
    """The id of a file in git."""
    return hashlib.sha1(b"blob %d\0" % len(data) + data, usedforsecurity=False).hexdigest()


def changed(run: Gh, sha: str, files: dict[str, bytes]) -> dict[str, bytes]:
    """The fixtures that the commit sha has and that differ; like the fix bot, this one
    changes files and adds none."""
    tree = api(run, f"repos/{repository()}/git/trees/{sha}?recursive=1")
    if tree.get("truncated"):
        raise RuntimeError(f"The files of {sha[:7]} are too many for one answer.")
    blobs = {item["path"]: item["sha"] for item in tree["tree"] if item["type"] == "blob"}
    return {path: data for path, data in files.items() if path in blobs and blobs[path] != blob_sha(data)}


def head_of(run: Gh, branch: str) -> str:
    """The commit of a branch, and nothing for one that is gone."""
    try:
        return str(api(run, f"repos/{repository()}/git/ref/heads/{branch}")["object"]["sha"])
    except RuntimeError:
        return ""


def commit(run: Gh, sha: str, branch: str, app: str, url: str, folder: Path) -> str:
    """Commits the fixtures in folder onto branch while it is still at sha, and says what
    it did."""
    files = changed(run, sha, load(folder))
    if not files:
        return "Every fixture is in the branch already.\n"
    user = api(run, f"users/{app}[bot]")
    email = f"{user['id']}+{app}[bot]@users.noreply.github.com"
    change = {
        "branch": {"repositoryNameWithOwner": repository(), "branchName": branch},
        "expectedHeadOid": sha,
        "message": {
            "headline": headline(versions(folder)),
            "body": f"Made by the contract tests in {url}\n\nSigned-off-by: {app}[bot] <{email}>",
        },
        "fileChanges": {
            "additions": [{"path": path, "contents": base64.b64encode(data).decode()} for path, data in sorted(files.items())]
        },
    }
    body = json.dumps({"query": COMMIT, "variables": {"input": change}})
    try:
        answer = json.loads(run(["api", "graphql", "--input", "-"], body))
    except RuntimeError:
        # A branch that moved on has checks of its own, which record again.
        if head_of(run, branch) != sha:
            return f"{branch} moved on from {sha[:7]}; its contract tests record again.\n"
        raise
    made = answer["data"]["createCommitOnBranch"]["commit"]["oid"]
    listing = "".join(f"- `{path}`\n" for path in sorted(files))
    return f"The bot committed {made[:7]} onto `{branch}`:\n\n{listing}"


def main(arguments: Sequence[str] | None = None, run: Gh = gh) -> int:
    parser = argparse.ArgumentParser(description=(__doc__ or "").splitlines()[0])
    actions = parser.add_subparsers(dest="action", required=True)
    planning = actions.add_parser("plan")
    planning.add_argument("--output", type=Path, required=True)
    committing = actions.add_parser("commit")
    for option in ("--branch", "--app", "--url"):
        committing.add_argument(option, required=True)
    for action in (planning, committing):
        action.add_argument("--sha", required=True)
        action.add_argument("--folder", type=Path, required=True)
    given = parser.parse_args(arguments)
    if given.action == "commit":
        sys.stdout.write(commit(run, given.sha, given.branch, given.app, given.url, given.folder))
        return 0
    decided = plan(run, given.sha, load(given.folder))
    with given.output.open("a", encoding="utf-8") as file:
        file.write(f"action={decided.action}\npull={decided.number or ''}\n")
    sys.stdout.write(decided.text)
    if decided.loop:
        print(f"::error::{decided.text.strip()}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
