#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Names the first error of each run of the end-to-end tests that failed.

    python3 tests/e2e/failure.py FOLDER [OUTPUT]

FOLDER is that of E2E_ARTIFACTS, which keeps a folder for each run that failed: run.sh puts
the evidence of its failure there, and run-all.sh and canary.sh its log, run.log, and its
title, title.txt; the job of the contract tests writes both for its line of Paperless-ngx.
For each such run, this prints a line of Markdown with its title, its first error, the check
in the browser that failed, if one did, and the last step that passed. In GitHub Actions, it also writes the lines into the
summary of the job and as an annotation each, and into the output OUTPUT of the step, by
default first-error, which the job "issue about …" of .github/workflows/e2e.yml puts into
its issue and into each further comment.

The first error of a log is, in this order:

1. the first line of a check in the browser that failed, "The check in the browser
   files.mjs fails: …", which browser.mjs prints; the checks that fail after it are named
   as well;
2. after the last step that passed, the first error of Python or Node.js, such as
   "AssertionError: …" of assert_json.py, or "Contract broken: …" of contract.py;
3. when a signal stopped the run, such as the time limit of run-all.sh, that;
4. otherwise the last line before "The end-to-end tests fail with exit code …", which run.sh
   prints as its cleanup starts, mostly a message of run.sh itself, such as "The webhook
   answered HTTP 500 instead of 200: …".

With a folder, it always ends with 0: it explains a failure and never adds one.
"""

from __future__ import annotations

import os
import re
import secrets
import sys
from dataclasses import dataclass
from pathlib import Path

# What run.sh and run-contract.sh print as their cleanup starts after a failure; what
# follows, such as the logs of the containers, isn't the output of the tests.
STOPPED = re.compile(r"^The (?:end-to-end|contract) tests fail with exit code (\d+)\.$")
BROWSER = re.compile(r"^The check in the browser (\S+) fails: (.+)$")
ERROR = re.compile(r"^(?:Contract broken: .+|(?:[A-Za-z_][\w.]*\.)?[A-Za-z_]*(?:Error|Exception): .+)$")
# The steps that run.sh names as it passes them.
PASSED = re.compile(
    r"^(?:The containers run after \d+ s\.|Nextcloud and the app are set up after \d+ s\.|.+ pass(?:es)? after \d+ s\.)$"
)
COLORS = re.compile(r"\x1b\[[0-9;]*m")
# An error is cut to this length; assert_json.py adds the whole answer to some.
LONGEST = 300


@dataclass(frozen=True)
class Failure:
    """The failure of one run, as its log tells it: its first error, the check in the browser
    that failed, if one did, the last step that passed, and the other checks in the browser
    that failed after it."""

    title: str
    error: str = ""
    script: str = ""
    passed: str = ""
    others: tuple[str, ...] = ()

    def text(self) -> str:
        """The failure in plain text, for an annotation."""
        if not self.error:
            return "The log of the run names no error; its group in the log of the job shows what failed."
        where = f" In tests/e2e/{self.script}." if self.script else ""
        others = f" Failed as well: {', '.join(self.others)}." if self.others else ""
        return f"{sentence(self.error)}{where}{self.after()}{others}"

    def markdown(self) -> str:
        """The failure as an item of a list in Markdown, for the summary and the issue."""
        if not self.error:
            return f"- **{self.title}**: the log of the run names no error; its group in the log of the job shows what failed."
        where = f" in {code('tests/e2e/' + self.script)}" if self.script else ""
        others = f" Failed as well: {', '.join(code(other) for other in self.others)}." if self.others else ""
        return f"- **{self.title}**: {code(self.error)}{where}.{self.after()}{others}"

    def after(self) -> str:
        return f" The last step that passed: {self.passed}" if self.passed else ""


def sentence(text: str) -> str:
    return text if text.endswith((".", "!", "?", "…")) else f"{text}."


def code(text: str) -> str:
    """Text as code in Markdown, in which a backtick of its own can't end the code."""
    return f"`{text.replace('`', chr(39))}`"


def shorten(line: str) -> str:
    line = line.strip()
    return line if len(line) <= LONGEST else f"{line[: LONGEST - 1]}…"


def first_error(lines: list[str], passed: int, exit_code: str) -> tuple[str, str, int]:
    """The first error of the lines of a log before the cleanup of run.sh, by the rules of
    the docstring, with the check in the browser that failed and the index of its line."""
    for index, line in enumerate(lines):
        if match := BROWSER.match(line):
            return match.group(2), match.group(1), index
    after = range(passed + 1, len(lines))
    for index in after:
        if ERROR.match(lines[index]):
            return lines[index], "", index
    if exit_code == "143":
        return "Stopped by a signal, such as the time limit of run-all.sh (exit code 143).", "", len(lines)
    rest = [index for index in after if lines[index].strip()]
    return (lines[rest[-1]], "", rest[-1]) if rest else ("", "", len(lines))


def describe(title: str, log: str) -> Failure:
    """The failure of a run from its log."""
    lines = [COLORS.sub("", line).rstrip() for line in log.splitlines()]
    exit_code = ""
    for index, line in enumerate(lines):
        if match := STOPPED.match(line):
            lines, exit_code = lines[:index], match.group(1)
            break
    steps = [index for index, line in enumerate(lines) if PASSED.match(line)]
    error, script, found = first_error(lines, steps[-1] if steps else -1, exit_code)
    passed = [index for index in steps if index < found]
    others = [match.group(1) for line in lines[found + 1 :] if (match := BROWSER.match(line))]
    return Failure(
        title,
        shorten(error) if error else "",
        script,
        lines[passed[-1]].strip() if passed else "",
        tuple(dict.fromkeys(other for other in others if other != script)),
    )


def failures(folder: Path) -> list[Failure]:
    """The failure of each run in the folder of E2E_ARTIFACTS that left its log there."""
    found = []
    for run in sorted(path for path in folder.glob("*") if (path / "run.log").is_file()):
        title_file = run / "title.txt"
        title = title_file.read_text(encoding="utf-8").strip() if title_file.is_file() else ""
        log = (run / "run.log").read_text(encoding="utf-8", errors="replace")
        found.append(describe(title or run.name, log))
    return found


def escape(text: str, property_: bool = False) -> str:
    """Text for a command of the workflow, such as ::error, whose own characters it escapes."""
    text = text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
    return text.replace(":", "%3A").replace(",", "%2C") if property_ else text


def report(folder: Path, output: str = "first-error", environment: dict[str, str] | None = None) -> str:
    """Prints the failures, and in GitHub Actions writes them into its summary, annotations and output."""
    environment = os.environ if environment is None else environment
    found = failures(folder) if folder.is_dir() else []
    if not found:
        print(f"No run in {folder} left the log of a failure.")
        return ""
    markdown = "\n".join(failure.markdown() for failure in found)
    print(markdown)
    if environment.get("GITHUB_ACTIONS") != "true":
        return markdown
    for failure in found:
        print(f"::error title={escape(f'First error: {failure.title}', property_=True)}::{escape(failure.text())}")
    if summary := environment.get("GITHUB_STEP_SUMMARY"):
        with open(summary, "a", encoding="utf-8") as file:
            file.write(f"### The first error of each run that failed\n\n{markdown}\n")
    if outputs := environment.get("GITHUB_OUTPUT"):
        delimiter = f"FIRST_ERROR_{secrets.token_hex(16)}"
        with open(outputs, "a", encoding="utf-8") as file:
            file.write(f"{output}<<{delimiter}\n{markdown}\n{delimiter}\n")
    return markdown


def main(arguments: list[str]) -> int:
    if not 1 <= len(arguments) <= 2:
        print(__doc__, file=sys.stderr)
        return 2
    report(Path(arguments[0]), *arguments[1:])
    return 0


if __name__ == "__main__":  # pragma: no cover - test_failure.py runs it as a command
    sys.exit(main(sys.argv[1:]))
