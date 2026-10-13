"""The hook of the website of Paperless Sync, beside the hooks of the blueprint.

mkdocs.yml names this file under hooks. A page shows a version that appinfo/info.xml
asks for in a field, such as <span data-app="php">8.2+</span>, named nextcloud or php.
The hook writes the versions of info.xml into every field, so that the website follows
a change of the app, such as the pull request of the upstream bot that raises
max-version, without an edit of its own. The text in the Markdown is what github.com
shows, and it may lag behind the website until the next edit of the page. A field that
this hook doesn't know or can't read fails the build.
tests/website/test_mkdocs_project.py tests the hook.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any
from xml.etree import ElementTree

from mkdocs.exceptions import PluginError

# A field of this hook: its name and the text that the hook replaces.
FIELD = re.compile(r'<span data-app="([^"]*)">[^<]*</span>')
# The versions for the fields, by name, read at the start of the last build.
VALUES: dict[str, str] = {}


def versions(dependency: ElementTree.Element | None, name: str) -> str:
    """The versions of a dependency in appinfo/info.xml: a range with an en dash, a
    single version, or the lowest one with a plus when there is no highest."""
    if dependency is None or not dependency.get("min-version"):
        raise PluginError(f"appinfo/info.xml names no min-version of {name}.")
    lowest = dependency.get("min-version", "")
    highest = dependency.get("max-version", "")
    if not highest:
        return f"{lowest}+"
    if highest == lowest:
        return lowest
    return f"{lowest}\N{EN DASH}{highest}"


def on_config(config: Any) -> None:
    """Read the versions from appinfo/info.xml anew at the start of every build."""
    info = Path(config.config_file_path).parent / "appinfo" / "info.xml"
    dependencies = ElementTree.parse(info).getroot().find("dependencies")
    if dependencies is None:
        raise PluginError("appinfo/info.xml has no dependencies.")
    VALUES.clear()
    VALUES["nextcloud"] = versions(dependencies.find("nextcloud"), "Nextcloud")
    VALUES["php"] = versions(dependencies.find("php"), "PHP")


def on_page_markdown(markdown: str, *, page: Any, **_: Any) -> str:
    """Write the versions into the fields of a page."""
    source = page.file.src_uri

    def fill(field: re.Match[str]) -> str:
        name = field[1]
        if name not in VALUES:
            raise PluginError(f'{source} has the unknown field data-app="{name}".')
        return f'<span data-app="{name}">{VALUES[name]}</span>'

    markdown, filled = FIELD.subn(fill, markdown)
    if markdown.count("data-app") != filled:
        raise PluginError(
            f'{source} has a field that isn\'t <span data-app="name">text</span>.'
        )
    return markdown
