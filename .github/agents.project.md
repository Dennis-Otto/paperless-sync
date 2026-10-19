# Notes of this project for coding agents

Paperless Sync: Nextcloud app that syncs Paperless-ngx documents into Nextcloud Files, by correspondent, type and year, and sends an inbox folder to Paperless. No WebDAV, no Nextcloud password.

This file belongs to the project: the blueprint wrote it once, and its updates leave it alone. It adds to `AGENTS.md` what a coding agent needs to know about this project alone, such as its architecture, the commands of its own checks and what to watch for in its tests.

## Where things are

- The app is in `lib/`, its metadata, version and supported Nextcloud versions in `appinfo/info.xml`.
- `README.md` documents installation, configuration and use, `docs/` holds the pages of the website, and `CHANGELOG.md` lists the changes of every release.
- `README.de.md` and `docs/index.de.md`, `guide.de.md`, `faq.de.md` and `troubleshooting.de.md` are the German twins of those pages: change them together with the English page, in the words of the German settings page (`l10n/de.json`), and give a German page the German diagrams, `docs/diagrams/NAME.de.mmd`. `scripts/check-project.sh` fails when a twin has other headings, snippets or diagrams than its English page; `docs/development.md#in-english-and-german` has the rest.
- The tests are in `tests/`; `scripts/check.sh` runs every check.
- A new check of `scripts/check-project.sh`, or a new job of `.github/workflows/e2e.yml`, comes with its explanation in `.github/checks.project.toml`, with its `autofix`, and a job of `e2e.yml` that runs the code has the step *Explain the failure*; `tests/coach/test_checks_project.py` fails otherwise.
