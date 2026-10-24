#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys

APP_ID = "paperless_sync"
# The token of the account of the app, the token of the superuser that the setup of the
# account takes once, and the start of the tokens of the accounts that it creates.
TOKENS = ("e2e-only-token", "e2e-superuser-only-token", "e2e-token-of-")

for raw_line in sys.stdin:
    if any(token in raw_line for token in TOKENS):
        raise AssertionError("Nextcloud log exposed a synthetic Paperless token")
    line = raw_line.strip()
    if not line:
        continue
    try:
        record = json.loads(line)
    except json.JSONDecodeError:
        continue

    app = str(record.get("app", ""))
    message = str(record.get("message", ""))
    level = record.get("level", 0)
    if APP_ID in (app, message) and isinstance(level, int) and level >= 3:
        raise AssertionError(f"Nextcloud logged an application error: {record!r}")
