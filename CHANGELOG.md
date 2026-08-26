# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- Move release commits before reproducible packaging while keeping commit and tag publication atomic.
- Pin checkout, PHP setup, and Gitleaks actions to immutable Node 24-compatible revisions.
- Verify DCO sign-offs, translations, dependencies, app metadata, and production-package boundaries automatically.
- Validate unsigned and signed release archives and exclude development-only files from packages.
- Add reproducible Docker end-to-end coverage for Nextcloud 33 and 34 with a deterministic Paperless API mock.
- Exercise dry-run, export, metadata moves, exclusions, trash, guarded deletion, inbox success and failure, pruning, permissions, and background-job registration.
- Expand contribution, security, testing, release, and manual acceptance documentation.

## 0.1.0 - 2026-08-26

- Add configurable one-way Paperless archive synchronization using Nextcloud's native filesystem API.
- Add optional Nextcloud inbox import with Paperless task tracking and error handling.
- Add metadata-aware moves, configurable path templates, Paperless trash mirroring, optional permanent deletion, and empty-folder cleanup.
- Add dry-run support, synchronization status, safe defaults, persistent database state, and native background jobs.
- Add automated testing, secret scanning, semantic versioning, signed packaging, and App Store release workflows.
