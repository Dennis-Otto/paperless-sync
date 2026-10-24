"""The tests of .github/checks.project.toml, the explanations of the checks of this app
alone, which the PR coach shows when one of them fails, and the commands of their autofix,
which the fix bot runs.

scripts/check-project.sh runs them with the Python of the machine alone, 3.11 or newer for
tomllib: python3 tests/coach/test_checks_project.py. They need no pytest, since the job
that runs them has none; pytest finds them all the same. They read the catalog as the
coach reads it, with .github/blueprint/coach.py.
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
import tempfile
import tomllib
import traceback
from collections.abc import Callable, Iterator
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".github"))

from blueprint.coach import (  # noqa: E402 - after the folder of blueprint.py
    Catalog,
    Entry,
    check_steps,
    missing,
    required_checks,
    undeclared,
)

PROJECT_CATALOG = ROOT / ".github" / "checks.project.toml"
# The workflows of this app alone, whose jobs explain their failures themselves; the
# blueprint's workflows do so in the blueprint.
PROJECT_WORKFLOWS = (
    ROOT / ".github" / "workflows" / "e2e.yml",
    ROOT / ".github" / "workflows" / "visual.yml",
)
# The step of scripts/check.sh that runs scripts/check-project.sh.
PROJECT_STEP = "Checks of this project"
# The files of the repository that the checks build and Git doesn't track.
BUILT = ("build/",)


def project_entries() -> Iterator[tuple[str, Entry]]:
    """Each explanation of .github/checks.project.toml, as the coach reads it."""
    data = tomllib.loads(PROJECT_CATALOG.read_text(encoding="utf-8"))
    source = PROJECT_CATALOG.relative_to(ROOT).as_posix()
    for kind in ("check", "step"):
        for key, table in data.get(kind, {}).items():
            yield kind, Entry.parse(kind, key, table, source)


def project_checks() -> list[str]:
    """The checks of scripts/check-project.sh, by the names that it prints."""
    text = (ROOT / "scripts" / "check-project.sh").read_text(encoding="utf-8")
    return re.findall(r"""^printf '\\n== %s\\n' "([^"]+)"$""", text, re.M)


def jobs(workflow: Path) -> dict[str, str]:
    """The jobs of a workflow, each by its id with its text: those of jobs: are indented
    by two spaces, and everything of a job by more."""
    text = workflow.read_text(encoding="utf-8")
    body = text[text.index("\njobs:\n") :]
    parts = re.split(r"^  ([A-Za-z0-9_-]+):\n", body, flags=re.M)
    return dict(zip(parts[1::2], parts[2::2], strict=True))


def name_of(job: str) -> str:
    """The name of a job, with the expressions of its matrix."""
    name = re.search(r"^    name: (.+)$", job, re.M)
    assert name, "a job without a name shows its id, which no explanation names"
    return name.group(1).strip()


def names_of(job: str) -> list[str]:
    """The names that GitHub shows for the checks of a job: its name, once for each
    value of the matrix that the name uses."""
    names = [name_of(job)]
    for key in re.findall(r"\$\{\{ matrix\.(\w+) \}\}", names[0]):
        values = re.search(rf"^        {key}: \[(.+)\]$", job, re.M)
        assert values, f"the matrix of the job lacks {key}"
        expression = f"${{{{ matrix.{key} }}}}"
        names = [
            each.replace(expression, value.strip())
            for each in names
            for value in values.group(1).split(",")
        ]
    return names


def heading_anchors(markdown: Path) -> set[str]:
    """The anchors that GitHub and MkDocs give the headings of a Markdown file."""
    anchors = set()
    fence = False
    for line in markdown.read_text(encoding="utf-8").splitlines():
        if line.startswith("```"):
            fence = not fence
        heading = re.match(r"#{1,6} (.+)", line)
        if heading and not fence:
            words = re.sub(r"[^\w\- ]", "", heading.group(1).strip().lower())
            anchors.add(words.replace(" ", "-"))
    return anchors


def named_paths(entry: Entry) -> set[str]:
    """The files and folders of the repository that an explanation names: in code in its
    texts, and in its commands and its autofix."""
    texts = " ".join([entry.problem, entry.why, entry.fix])
    tokens = [
        token
        for token in re.findall(r"`([^`\s]+)`", texts)
        if "/" in token or re.search(r"\.\w+$", token)
    ]
    for command in (*entry.commands, *entry.autofix):
        words = command.split("#", 1)[0].split()
        tokens += [word for word in words if "/" in word and "=" not in word]
    return {token.rstrip("/") for token in tokens if not token.startswith(BUILT)}


# ---------------------------------------------------------------- the tests


def test_every_check_and_step_is_explained_with_its_autofix() -> None:
    catalog = Catalog.load(ROOT)
    gaps = missing(catalog, required_checks(ROOT), check_steps(ROOT))
    assert not gaps, f"without an explanation: {gaps}"
    silent = undeclared(catalog)
    assert not silent, f"without an autofix: {silent}"


def test_the_step_of_this_project_explains_each_of_its_checks() -> None:
    step = Catalog.load(ROOT).step(PROJECT_STEP)
    assert step is not None
    assert step.problem.startswith("A check of this app alone"), (
        "the explanation of the blueprint, not that of this project"
    )
    checks = project_checks()
    assert len(checks) >= 10, checks
    unexplained = [name for name in checks if f"*{name}*" not in step.problem]
    assert not unexplained, f"scripts/check-project.sh runs {unexplained} unexplained"


def test_every_job_of_this_project_that_runs_its_code_explains_its_failure() -> None:
    catalog = Catalog.load(ROOT)
    own = {entry.key for kind, entry in project_entries() if kind == "check"}
    explained = 0
    for workflow in PROJECT_WORKFLOWS:
        for job_id, job in jobs(workflow).items():
            # A job that checks nothing out runs no code of the repository, such as the
            # one that writes the issue of the canary, and can't run blueprint.py.
            if "actions/checkout@" not in job:
                continue
            step = re.search(
                r"- name: Explain the failure\n\s+if: failure\(\)\n\s+env:\n"
                r"\s+CHECK: (.+)\n\s+run: \|\n(?:\s+.*\n)*?"
                r'\s+python3 "\$script" coach explain "\$CHECK"\n',
                job,
            )
            assert step, f"the job {job_id} of {workflow.name} doesn't explain a failure"
            assert step.group(1).strip() == name_of(job), (
                f"the job {job_id} explains another check than its own"
            )
            for name in names_of(job):
                entry = catalog.check(name)
                assert entry is not None, f"the check {name} has no explanation"
                assert entry.key in own, f"the blueprint explains {name} by {entry.key}"
                explained += 1
    assert explained >= 7, explained


def test_the_files_and_headings_that_the_explanations_name_exist() -> None:
    for kind, entry in project_entries():
        gone = sorted(path for path in named_paths(entry) if not (ROOT / path).exists())
        assert not gone, f"the {kind} {entry.key} names {gone}, which don't exist"
        assert entry.docs, f"the {kind} {entry.key} has no docs"
        if entry.docs.startswith("https://"):
            continue
        page, _, anchor = entry.docs.partition("#")
        assert (ROOT / page).is_file(), f"the {kind} {entry.key} links to {page}"
        if anchor:
            assert anchor in heading_anchors(ROOT / page), (
                f"the {kind} {entry.key} links to {entry.docs}, a heading that is gone"
            )


def test_the_autofix_of_this_project_fixes_what_it_promises() -> None:
    bash, node = shutil.which("bash"), shutil.which("node")
    if bash is None or node is None:
        print("     (skipped: the commands need bash and Node.js, which the CI has)")
        return
    step = Catalog.load(ROOT).step(PROJECT_STEP)
    assert step is not None and step.autofix
    with tempfile.TemporaryDirectory() as folder:
        copy = Path(folder)
        for name in ("img", "docs/images", "js", "templates", "lib", "l10n"):
            shutil.copytree(ROOT / name, copy / name)
        (copy / "scripts").mkdir()
        shutil.copy2(ROOT / "scripts" / "check-l10n.mjs", copy / "scripts")
        # What a pull request may bring: an icon of the website that differs from that
        # of the app, a text that no code translates, and a copy for the browser with
        # other words.
        (copy / "docs/images/app.svg").write_text("<svg/>\n", encoding="utf-8")
        catalog_path = copy / "l10n" / "de.json"
        catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
        catalog["translations"]["A text that no code translates"] = "Ein Text"
        catalog_path.write_text(
            json.dumps(catalog, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8"
        )
        browser = copy / "l10n" / "de.js"
        words = browser.read_text(encoding="utf-8")
        assert '"Files": "Dateien"' in words
        browser.write_text(
            words.replace('"Files": "Dateien"', '"Files": "Ordner"'), encoding="utf-8"
        )
        for command in step.autofix:
            subprocess.run(
                [bash, "-c", command], cwd=copy, capture_output=True, check=False
            )
        assert (copy / "docs/images/app.svg").read_bytes() == (
            ROOT / "img/app.svg"
        ).read_bytes()
        for name in ("de.json", "de.js"):
            assert (copy / "l10n" / name).read_bytes() == (
                ROOT / "l10n" / name
            ).read_bytes(), f"l10n/{name} differs after the autofix"
        check = subprocess.run(
            [node, "scripts/check-l10n.mjs"], cwd=copy, capture_output=True, check=False
        )
        assert check.returncode == 0, check.stdout + check.stderr


def main() -> int:
    """Run every test of this file, as pytest would, and count the failures."""
    tests: list[tuple[str, Callable[[], None]]] = [
        (name, test)
        for name, test in globals().items()
        if re.match(r"test_\w+$", name) and callable(test)
    ]
    failures = 0
    for name, test in tests:
        try:
            test()
        except AssertionError as error:
            failures += 1
            frame = traceback.extract_tb(error.__traceback__)[-1]
            print(f"FAIL {name}, line {frame.lineno}: {frame.line} {error}")
        else:
            print(f"ok   {name}")
    print(f"{len(tests) - failures} of {len(tests)} tests of the explanations pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
