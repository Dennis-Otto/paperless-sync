#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Checks what upgrade.sh records before and after the upgrade of an installation in use.

It reads a JSON object from standard input, in one of these modes:

- release: the state of the release after its runs (upgrade_state.php): whether it has what
  the upgrade must keep, the copies of two owners, an import whose task is pending, one that
  waits to be tried again and a file to send.
- state [CHANGES]: {"before": …, "after": …}, the state before the upgrade and right after
  it: every row of every table of the app, every value of its configuration and its
  credentials, every executed migration, and every file below the base folder of each user
  with its ID, unchanged. The upgrade may add columns, tables and values; only
  installed_version and types, which Nextcloud writes with every update of an app, may
  change, and CHANGES, which a migration of the commit changes on purpose, each as
  TABLE.COLUMN or appconfig.KEY, such as "paperless_sync_import.status appconfig.base_path".
- settings: {"before": …, "after": …}, the stored settings as the settings page shows them,
  and the state of the webhook: every key unchanged, new keys allowed.
- mock: {"before": …, "after": …}, what the Paperless mock counted: no download, no upload and
  no request with a wrong token in between.
- retry: {"before": …, "after": …}, the states before the upgrade and after the run that
  followed it: each import that waited to be tried again still waits, until the same time.
- migrations VERSIONS: the state right after the upgrade, whose executed migrations must be
  exactly VERSIONS, those of the files of lib/Migration/ in the package, such as
  000100Date20260826000000.
"""

import json
import sys

mode = sys.argv[1]
# The value that a mode compares with, such as the migrations of the package.
argument = sys.argv[2] if len(sys.argv) > 2 else ""
payload = json.load(sys.stdin)

# The values that Nextcloud writes into the configuration of an app with each update of it.
UPDATED_BY_NEXTCLOUD = {"installed_version", "types"}
# The tokens of the mock, which the state must never hold in plain text.
TOKENS = ("e2e-only-token", "e2e-superuser-only-token")

problems = []


def compare_rows(table, before, after, changes=frozenset()):
    """Every row of the table from before is there after, with the values of its columns."""
    rows = {str(row["id"]): row for row in after}
    for row in before:
        kept = rows.get(str(row["id"]))
        if kept is None:
            problems.append(f"The upgrade removed the row {row['id']} of {table}: {row!r}")
            continue
        for column, value in row.items():
            if column not in kept:
                problems.append(f"The upgrade removed the column {column} of {table}.")
            elif kept[column] != value and f"{table}.{column}" not in changes:
                problems.append(f"The upgrade changed {column} of the row {row['id']} of {table} from {value!r} to {kept[column]!r}.")


def compare_values(name, before, after, ignored=frozenset()):
    for key, value in before.items():
        if key in ignored:
            continue
        if key not in after:
            problems.append(f"The upgrade removed {key} from {name}, which was {value!r}.")
        elif after[key] != value:
            problems.append(f"The upgrade changed {key} of {name} from {value!r} to {after[key]!r}.")


def retries(state):
    """The imports that wait to be tried again, by their user and path."""
    return {
        (row["owner_uid"], row["path"]): {key: row[key] for key in ("status", "etag", "attempts", "retry_at")}
        for row in state["tables"].get("paperless_sync_import", [])
        if row["status"] == "retry"
    }


if mode == "release":
    tables = payload["tables"]
    exports = [row for row in tables.get("paperless_sync_export", []) if row["path"]]
    owners = {row["owner_uid"] for row in exports}
    if len(owners) < 2:
        problems.append(f"The release exported the documents of {sorted(owners)!r}, not of two owners: {exports!r}")
    imports = tables.get("paperless_sync_import", [])
    if not [row for row in imports if row["status"] == "pending" and row["task_id"]]:
        problems.append(f"The release has no import whose task is pending: {imports!r}")
    if not [row for row in imports if row["status"] == "retry" and int(row["retry_at"]) > 0]:
        problems.append(f"The release has no import that waits to be tried again: {imports!r}")
    if not tables.get("paperless_sync_send"):
        problems.append("The release has no file to send to Paperless.")
    copies = [path for files in payload["files"].values() for path in files if path.endswith("].pdf")]
    if len(copies) < 2:
        problems.append(f"The release has fewer than two copies of documents in the files of the users: {payload['files']!r}")
elif mode == "state":
    before, after = payload["before"], payload["after"]
    changes = frozenset(argument.split())
    serialized = json.dumps(payload)
    if any(token in serialized for token in TOKENS):
        problems.append("The configuration or the tables of the app hold a token of Paperless in plain text.")
    for table, rows in before["tables"].items():
        if table not in after["tables"]:
            problems.append(f"The upgrade dropped the table {table}.")
        else:
            compare_rows(table, rows, after["tables"][table], changes)
    changed_values = {change.removeprefix("appconfig.") for change in changes if change.startswith("appconfig.")}
    compare_values("the configuration of the app", before["appconfig"], after["appconfig"], UPDATED_BY_NEXTCLOUD | changed_values)
    compare_values("the credentials of the app", before["credentials"], after["credentials"])
    for version in sorted(set(before["migrations"]) - set(after["migrations"])):
        problems.append(f"The upgrade forgot that the migration {version} ran.")
    for user, files in before["files"].items():
        compare_values(f"the files of {user} (path: file ID)", files, after["files"].get(user, {}))
elif mode == "settings":
    before, after = payload["before"], payload["after"]
    for name in before:
        compare_values(f"the {name} of the settings page", before[name], after.get(name, {}))
    added = sorted(set(after["config"]) - set(before["config"]))
    if added:
        print(f"The upgrade adds the settings {', '.join(added)}, with their defaults.")
elif mode == "mock":
    before, after = payload["before"], payload["after"]
    for key, name in (
        ("downloads", "downloads of documents"),
        ("uploads", "uploads"),
        ("unavailableUploads", "uploads that Paperless could not take"),
        ("invalidAuth", "requests with a wrong token"),
    ):
        if after[key] != before[key]:
            problems.append(f"After the upgrade, the run made {name}: {before[key]!r} before, {after[key]!r} after.")
elif mode == "retry":
    before, after = retries(payload["before"]), retries(payload["after"])
    if not before:
        problems.append("No import waited to be tried again before the upgrade, so the check proves nothing.")
    for key, row in before.items():
        if after.get(key) != row:
            problems.append(f"The import of {key[1]} of {key[0]} waited to be tried again as {row!r}, and after the upgrade and a run as {after.get(key)!r}.")
elif mode == "migrations":
    expected = set(argument.split())
    executed = set(payload["migrations"])
    if not expected:
        problems.append("The package has no migrations, so the check proves nothing.")
    for version in sorted(expected - executed):
        problems.append(f"Nextcloud didn't execute the migration {version} of the package.")
    for version in sorted(executed - expected):
        problems.append(f"Nextcloud executed the migration {version}, which the package doesn't have.")
else:
    raise SystemExit(f"Unknown mode {mode}")

if problems:
    print(f"The upgrade check {mode} failed:", file=sys.stderr)
    for problem in problems:
        print(f"  {problem}", file=sys.stderr)
    sys.exit(1)
