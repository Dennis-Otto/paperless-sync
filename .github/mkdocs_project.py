"""The hook of the website of Paperless Sync, beside the hooks of the blueprint.

mkdocs.yml names this file under hooks. A page that writes {{ app.nextcloud }} or
{{ app.php }} shows the versions of Nextcloud and PHP that appinfo/info.xml asks
for, such as 33 to 35 and 8.2 or later, so that the home page follows a change of
the app, such as the pull request of the upstream bot that raises max-version,
without an edit of its own. A placeholder that this hook doesn't know fails the
build.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any
from xml.etree import ElementTree

from mkdocs.exceptions import PluginError

# Any placeholder of this hook, known or not.
PLACEHOLDER = re.compile(r"\{\{ app\.[^}]*\}\}")
# The values of the placeholders, read at the start of the last build.
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
    VALUES["{{ app.nextcloud }}"] = versions(
        dependencies.find("nextcloud"), "Nextcloud"
    )
    VALUES["{{ app.php }}"] = versions(dependencies.find("php"), "PHP")


def on_page_markdown(markdown: str, *, page: Any, **_: Any) -> str:
    """Fill in the placeholders of a page."""
    for placeholder, value in VALUES.items():
        markdown = markdown.replace(placeholder, value)
    unknown = PLACEHOLDER.search(markdown)
    if unknown:
        source = page.file.src_uri
        raise PluginError(f"{source} has the unknown placeholder {unknown[0]}.")
    return markdown
