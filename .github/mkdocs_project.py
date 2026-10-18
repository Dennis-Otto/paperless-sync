"""The hook of the website of Paperless Sync, beside the hooks of the blueprint.

mkdocs.yml names this file under hooks. A page shows a version that appinfo/info.xml
asks for in a field, such as <span data-app="php">8.2+</span>, named nextcloud or php.
The hook writes the versions of info.xml into every field, so that the website follows
a change of the app, such as the pull request of the upstream bot that raises
max-version, without an edit of its own. It fills the fields in the HTML of a page, so
that it reaches the fields that a page takes from the README by a snippet as well, such
as those of the requirements in the guide; a field shown as code is text there and
stays as it is. The text in the Markdown is what github.com shows, and it may lag
behind the website until the next edit of the page; scripts/check-project.sh makes
sure that it never claims more than info.xml. A field that this hook doesn't know or
can't read fails the build.

The hook also shows an alert of GitHub as an admonition of Material: a quote that
begins with [!NOTE], [!TIP], [!IMPORTANT], [!WARNING] or [!CAUTION], such as the
warning about edits of the archive that the guide takes from the README. Without it,
the website would show the marker as text in a plain quote. The admonition of a German
page, such as guide.de.md, has a German title, such as Warnung. An alert with a quote
inside fails the build, since the end of the inner quote would end the admonition.

And it leads the path of a picture of HTML, such as a <picture> of a screenshot, to its
file, on the German pages in de/ and in the snippets of the README alike.
tests/website/test_mkdocs_project.py tests the hook.
"""

from __future__ import annotations

import posixpath
import re
from pathlib import Path
from typing import Any
from xml.etree import ElementTree

from mkdocs.exceptions import PluginError

# A field of this hook: its name and the text that the hook replaces.
FIELD = re.compile(r'<span data-app="([^"]*)">[^<]*</span>')
# An element of the HTML with a data-app attribute, which must be a field. In code,
# the HTML has &lt; for <, so that a field shown as code is no element.
ELEMENT = re.compile(r"<[a-zA-Z][^<>]*\sdata-app=")
# An alert of GitHub, as Markdown writes it in HTML: a quote whose first paragraph
# begins with the kind of the alert, such as [!WARNING], then the rest of the quote.
ALERT = re.compile(
    r"<blockquote>\s*<p>\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*\n?(.*?)</blockquote>",
    re.DOTALL | re.IGNORECASE,
)
# The admonition of Material for each kind of alert, the one whose color comes
# closest to that of GitHub.
ADMONITIONS = {
    "NOTE": "note",
    "TIP": "tip",
    "IMPORTANT": "info",
    "WARNING": "warning",
    "CAUTION": "danger",
}
# The title of each kind of alert on a German page, whose source ends with .de.md, such
# as guide.de.md; any other page, also an English one that the German website shows for
# want of a German version, has the kind itself as its title, such as Warning.
GERMAN_TITLES = {
    "NOTE": "Hinweis",
    "TIP": "Tipp",
    "IMPORTANT": "Wichtig",
    "WARNING": "Warnung",
    "CAUTION": "Vorsicht",
}
# The path of a picture in HTML, a single one without a scheme, a query or a size: the
# attribute and the path.
PICTURE = re.compile(r'\b(src|srcset)="([^":?#\s,/][^":?#\s,]*)"')
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


def on_page_content(html: str, *, page: Any, config: Any = None, **_: Any) -> str:
    """Write the versions into the fields of a page, those of its snippets included,
    lead its pictures to their files, and show its alerts of GitHub as admonitions."""
    source = page.file.src_uri
    if config is not None:
        html = pictures(html, page, Path(config.docs_dir))

    def fill(field: re.Match[str]) -> str:
        name = field[1]
        if name not in VALUES:
            raise PluginError(f'{source} has the unknown field data-app="{name}".')
        return f'<span data-app="{name}">{VALUES[name]}</span>'

    html, filled = FIELD.subn(fill, html)
    if len(ELEMENT.findall(html)) != filled:
        raise PluginError(
            f'{source} has a field that isn\'t <span data-app="name">text</span>.'
        )
    return ALERT.sub(lambda alert: admonition(alert, source), html)


def pictures(html: str, page: Any, docs: Path) -> str:
    """Lead the path of each picture of HTML, such as a <picture> of a screenshot, to its
    file from the address of the page.

    MkDocs fixes the path of a picture of Markdown, but leaves that of HTML as it is,
    a path from the source of the page. That breaks on the German website, whose pages
    are in de/ but show the pictures of docs/images, and for a snippet of the README,
    which writes its paths from the root of the repository, such as
    docs/images/report-light.png. A path that leads to no file of docs/ from the source,
    such as one that MkDocs already fixed or one of another server, stays as it is.
    """
    folder = posixpath.dirname(page.file.src_uri)
    here = posixpath.dirname(page.url) or "."

    def lead(attribute: re.Match[str]) -> str:
        path = attribute[2]
        target = posixpath.normpath(posixpath.join(folder, path))
        if path.startswith("docs/") and not (docs / target).is_file():
            target = path.removeprefix("docs/")
        if target.startswith("../") or not (docs / target).is_file():
            return attribute[0]
        return f'{attribute[1]}="{posixpath.relpath(target, here)}"'

    return PICTURE.sub(lead, html)


def admonition(alert: re.Match[str], source: str) -> str:
    """The admonition of Material for an alert of GitHub, titled with its kind in the
    language of the page."""
    kind = alert[1].upper()
    title = GERMAN_TITLES[kind] if source.endswith(".de.md") else kind.title()
    body = alert[2].strip()
    if "<blockquote" in body:
        raise PluginError(f"{source} has a quote inside the alert [!{kind}].")
    # The marker alone in its paragraph, before a blank line of the quote, leaves the
    # end of that paragraph; otherwise, the text of the alert goes on in it.
    body = body.removeprefix("</p>").lstrip() if body.startswith("</p>") else f"<p>{body}"
    return (
        f'<div class="admonition {ADMONITIONS[kind]}">\n'
        f'<p class="admonition-title">{title}</p>\n'
        f"{body}\n</div>"
    )
