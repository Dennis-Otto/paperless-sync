"""The tests of .github/mkdocs_project.py, the hook that writes the versions of
appinfo/info.xml on the website.

scripts/check-project.sh runs them with the Python of the machine alone:
python3 tests/website/test_mkdocs_project.py. They need neither MkDocs nor pytest,
since the job that runs them has neither; pytest finds them all the same. The docs
job builds the website with the hook as well.
"""

from __future__ import annotations

import importlib.util
import re
import sys
import tempfile
import traceback
import types
from collections.abc import Callable, Iterator
from contextlib import contextmanager
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from xml.etree import ElementTree

ROOT = Path(__file__).resolve().parents[2]
EN_DASH = "\N{EN DASH}"


def load_hook() -> Any:
    """The hook, with a stand-in for the error of MkDocs when MkDocs is missing."""
    if importlib.util.find_spec("mkdocs") is None:
        package = types.ModuleType("mkdocs")
        exceptions = types.ModuleType("mkdocs.exceptions")

        class PluginError(Exception):
            """The error with which a hook fails the build."""

        exceptions.PluginError = PluginError  # type: ignore[attr-defined]
        package.exceptions = exceptions  # type: ignore[attr-defined]
        sys.modules["mkdocs"] = package
        sys.modules["mkdocs.exceptions"] = exceptions
    spec = importlib.util.spec_from_file_location(
        "mkdocs_project", ROOT / ".github" / "mkdocs_project.py"
    )
    assert spec is not None
    assert spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


hook = load_hook()
PluginError: type[Exception] = sys.modules["mkdocs.exceptions"].PluginError


@contextmanager
def fails(message: str) -> Iterator[None]:
    """Expect the hook to fail the build with a message that contains message."""
    try:
        yield
    except PluginError as error:
        failure = str(error)
    else:
        raise AssertionError(f"The hook didn't fail with {message!r}.")
    assert message in failure, f"{message!r} is not in {failure!r}"


def dependency(**versions: str) -> ElementTree.Element:
    """A dependency of appinfo/info.xml, such as <nextcloud min-version="33"/>."""
    attributes = {key.replace("_", "-"): value for key, value in versions.items()}
    return ElementTree.Element("nextcloud", attributes)


def page(source: str = "index.md") -> SimpleNamespace:
    """A page of MkDocs, as far as the hook reads it."""
    return SimpleNamespace(file=SimpleNamespace(src_uri=source))


def build(info: str) -> None:
    """Run the hook at the start of a build whose appinfo/info.xml is info."""
    with tempfile.TemporaryDirectory() as folder:
        (Path(folder) / "appinfo").mkdir()
        (Path(folder) / "appinfo" / "info.xml").write_text(info, encoding="utf-8")
        hook.on_config(SimpleNamespace(config_file_path=f"{folder}/mkdocs.yml"))


def known(**values: str) -> None:
    """Let the hook know these versions, as on_config would."""
    hook.VALUES.clear()
    hook.VALUES.update(values)


def test_a_range_has_an_en_dash() -> None:
    versions = dependency(min_version="33", max_version="35")
    assert hook.versions(versions, "Nextcloud") == f"33{EN_DASH}35"


def test_the_same_lowest_and_highest_is_one_version() -> None:
    versions = dependency(min_version="34", max_version="34")
    assert hook.versions(versions, "Nextcloud") == "34"


def test_no_highest_is_the_lowest_with_a_plus() -> None:
    assert hook.versions(dependency(min_version="8.2"), "PHP") == "8.2+"


def test_a_missing_dependency_fails() -> None:
    with fails("names no min-version of PHP"):
        hook.versions(None, "PHP")


def test_an_empty_lowest_fails() -> None:
    with fails("names no min-version of Nextcloud"):
        hook.versions(dependency(min_version="", max_version="35"), "Nextcloud")


def test_the_build_reads_the_versions_anew() -> None:
    known(old="1")
    build(
        "<info><dependencies><php min-version='8.3' max-version='8.4'/>"
        "<nextcloud min-version='34'/></dependencies></info>"
    )
    values = dict(hook.VALUES)
    assert values == {"nextcloud": "34+", "php": f"8.3{EN_DASH}8.4"}


def test_a_build_without_dependencies_fails() -> None:
    with fails("appinfo/info.xml has no dependencies."):
        build("<info><name>Paperless Sync</name></info>")


def test_a_build_without_php_fails() -> None:
    with fails("names no min-version of PHP"):
        build("<info><dependencies><nextcloud min-version='33'/></dependencies></info>")


def test_the_build_reads_the_info_of_this_app() -> None:
    hook.on_config(SimpleNamespace(config_file_path=str(ROOT / "mkdocs.yml")))
    assert set(hook.VALUES) == {"nextcloud", "php"}


def test_a_page_gets_the_versions_in_its_fields() -> None:
    known(nextcloud=f"33{EN_DASH}36", php="8.2+")
    html = (
        f'<li>Nextcloud <span data-app="nextcloud">33{EN_DASH}35</span></li>\n'
        '<li>PHP <span data-app="php"></span></li>\n'
    )
    assert hook.on_page_content(html, page=page()) == (
        f'<li>Nextcloud <span data-app="nextcloud">33{EN_DASH}36</span></li>\n'
        '<li>PHP <span data-app="php">8.2+</span></li>\n'
    )


def test_a_page_without_fields_stays_as_it_is() -> None:
    known(nextcloud="33+", php="8.2+")
    html = "<p>A name ends in [P{{ id }}], such as <code>{{ app.php }}</code>.</p>\n"
    assert hook.on_page_content(html, page=page()) == html


def test_a_field_shown_as_code_stays_as_it_is() -> None:
    known(nextcloud="33+", php="8.3+")
    html = (
        "<p>The fields, such as <code>&lt;span "
        'data-app="php"&gt;8.2+&lt;/span&gt;</code>, of a page.</p>\n'
    )
    assert hook.on_page_content(html, page=page("development.md")) == html


def test_an_unknown_field_fails() -> None:
    known(nextcloud="33+", php="8.2+")
    with fails('guide.md has the unknown field data-app="mysql".'):
        hook.on_page_content('<span data-app="mysql">8</span>', page=page("guide.md"))


def test_a_field_that_the_hook_cant_read_fails() -> None:
    known(nextcloud="33+", php="8.2+")
    for html in (
        "<span data-app='php'>8.2+</span>",
        '<span class="version" data-app="php">8.2+</span>',
        '<span data-app="php"><b>8.2+</b></span>',
        '<span data-app="php">8.2+</span> and <abbr data-app="php">PHP</abbr>',
    ):
        with fails("index.md has a field that isn't"):
            hook.on_page_content(html, page=page())


def test_the_home_page_has_both_fields() -> None:
    known(nextcloud="NEXTCLOUD", php="PHP")
    markdown = (ROOT / "docs" / "index.md").read_text(encoding="utf-8")
    filled = hook.on_page_content(markdown, page=page())
    assert '<li>Nextcloud <span data-app="nextcloud">NEXTCLOUD</span></li>' in filled
    assert '<li>PHP <span data-app="php">PHP</span></li>' in filled


def test_the_requirements_of_the_readme_get_both_fields() -> None:
    # The guide takes the requirements from the README by a snippet, which MkDocs
    # writes into its HTML before the hook fills the fields.
    guide = (ROOT / "docs" / "guide.md").read_text(encoding="utf-8")
    assert '--8<-- "README.md:requirements"' in guide
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    start = readme.index("<!-- --8<-- [start:requirements] -->")
    end = readme.index("<!-- --8<-- [end:requirements] -->")
    known(nextcloud="NEXTCLOUD", php="PHP")
    filled = hook.on_page_content(readme[start:end], page=page("guide.md"))
    assert '**Nextcloud <span data-app="nextcloud">NEXTCLOUD</span>**' in filled
    assert '**PHP <span data-app="php">PHP</span>**' in filled


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
    print(f"{len(tests) - failures} of {len(tests)} tests of the hook pass.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
