---
hide:
  - navigation
  - toc
---

# Paperless Sync

A native Nextcloud app that mirrors finalized Paperless-ngx documents into a structured Nextcloud archive and can submit files from a Nextcloud inbox to Paperless. Paperless remains the source of truth.

[Get it from the App Store](https://apps.nextcloud.com/apps/paperless_sync){ .md-button .md-button--primary }
[Quick start](#quick-start){ .md-button }

![The administration settings of Paperless Sync in Nextcloud: the status of the last run, the connection to Paperless and the owner of the archive, and the schedule](https://raw.githubusercontent.com/Dennis-Otto/paperless-sync/main/screenshots/01-admin-settings.png)

## What it does

<div class="grid cards" markdown>

- :material-folder-sync-outline:{ .lg .middle } **A structured archive in Nextcloud**

    ---

    Documents appear in folders of the configured Nextcloud user, by correspondent first by default, built from a configurable path template, as archive PDF or original file.

- :material-file-move-outline:{ .lg .middle } **Follows changes in Paperless**

    ---

    When the metadata of a document changes in Paperless, its file is renamed or moved in Nextcloud. Documents in the Paperless inbox or with an excluded tag stay out.

- :material-inbox-arrow-up-outline:{ .lg .middle } **From a Nextcloud inbox to Paperless**

    ---

    Files in a Nextcloud inbox folder, including its subfolders, are submitted to Paperless, and the app follows their Paperless tasks.

- :material-delete-clock-outline:{ .lg .middle } **Careful with deletions**

    ---

    The Paperless trash is mirrored into a folder of its own. Permanent deletion is off by default and, when enabled, waits for the configured number of complete scans.

- :material-eye-check-outline:{ .lg .middle } **Dry run first**

    ---

    A dry run lists what a run would change without changing anything. Status and error summaries show what the last run did.

- :material-key-chain-variant:{ .lg .middle } **No passwords in Nextcloud**

    ---

    The app works through Nextcloud's filesystem API, without WebDAV credentials or an app password. The Paperless token stays in Nextcloud's credentials manager and never reaches the browser.

</div>

## Quick start

--8<-- "README.md:quick-start"

The [guide](guide.md) describes the configuration in detail: the Paperless account, the folders, the path template and the deletion safety.

## Learn more

<div class="grid cards" markdown>

- :material-book-open-variant:{ .lg .middle } **Guide**

    ---

    Installation, all features, usage and every part of the configuration.

    [:octicons-arrow-right-24: Read the guide](guide.md)

- :material-sitemap-outline:{ .lg .middle } **Architecture**

    ---

    How the app runs inside Nextcloud, reads Paperless through its REST API and writes files through Nextcloud's file API.

    [:octicons-arrow-right-24: Architecture](architecture.md)

- :material-shield-lock-outline:{ .lg .middle } **Security design**

    ---

    What the app protects, what it trusts, the threats with their countermeasures, and the risks that remain.

    [:octicons-arrow-right-24: Security design](security.md)

- :material-map-marker-path:{ .lg .middle } **Roadmap**

    ---

    What Paperless Sync intends to do in the next twelve months, and what it will not do.

    [:octicons-arrow-right-24: Roadmap](roadmap.md)

- :material-scale-balance:{ .lg .middle } **Decisions**

    ---

    The decisions that shape the project, each with its reasons.

    [:octicons-arrow-right-24: Decisions](decisions/README.md)

- :material-history:{ .lg .middle } **Releases and changelog**

    ---

    How a release is made, signed and published in the Nextcloud App Store, and the changes of each version.

    [:octicons-arrow-right-24: Releases](releases.md) · [Changelog](changelog.md)

</div>
