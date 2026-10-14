---
hide:
  - navigation
  - toc
# A link to the home page shows the social preview of the repository, with the layout
# in .github/social-cards/preview.yml.
social:
  cards_layout: preview
---

# <img src="images/app.svg" alt="" class="off-glb" width="64" height="64"> Paperless Sync { .ps-hero-title }

**Your finished Paperless-ngx documents, as a tidy archive in Nextcloud.**
{ .ps-hero-tagline }

A native Nextcloud app that mirrors finalized Paperless-ngx documents into a structured Nextcloud archive and can submit files from a Nextcloud inbox to Paperless. Paperless remains the source of truth.

<!-- On the website, the hook in .github/mkdocs_project.py writes the versions of appinfo/info.xml into the fields. -->
<ul class="ps-requirements" aria-label="Requirements">
  <li>Nextcloud <span data-app="nextcloud">33–35</span></li>
  <li>PHP <span data-app="php">8.2+</span></li>
  <li>Paperless-ngx 2.16+ with an API token</li>
  <li>Nextcloud's system cron</li>
</ul>

[Get it from the App Store](https://apps.nextcloud.com/apps/paperless_sync){ .md-button .md-button--primary }
[Quick start](#quick-start){ .md-button }
[Requirements](guide.md#requirements){ .md-button }
{ .ps-hero-actions }

![The archive in Nextcloud Files: from the folders of the correspondents through a correspondent, a document type and a year to a document of Paperless, which opens in the viewer of Nextcloud](images/archive.gif)

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

    A dry run lists what a run would change without changing anything. The status, the errors and the report of the last run, also of a scheduled one, stay on the settings page.

- :material-key-chain-variant:{ .lg .middle } **No passwords in Nextcloud**

    ---

    The app works through Nextcloud's filesystem API, without WebDAV credentials or an app password. The Paperless token stays in Nextcloud's credentials manager and never reaches the browser.

</div>

--8<-- "README.md:how-it-works"

## Quick start

--8<-- "README.md:quick-start"

![The settings of Paperless Sync: Run dry-run lists the documents that a run would export, then Synchronize now exports them and the status turns to completed](images/dry-run.gif)

The [guide](guide.md) describes the configuration in detail: the Paperless account, the folders, the path template and the deletion safety.

## Learn more

<div class="grid cards" markdown>

- :material-book-open-variant:{ .lg .middle } **Guide**

    ---

    Installation, all features, usage and every part of the configuration.

    [:octicons-arrow-right-24: Read the guide](guide.md)

- :material-lifebuoy:{ .lg .middle } **FAQ and troubleshooting**

    ---

    What happens to edits and deletions, how long the first synchronization takes, and the fixes for the error messages.

    [:octicons-arrow-right-24: FAQ](faq.md) · [Troubleshooting](troubleshooting.md)

- :material-compare-horizontal:{ .lg .middle } **How it compares**

    ---

    Paperless Sync next to Nextcloud's Paperless integration, the WebDAV container, rclone and a mount, and how to move over.

    [:octicons-arrow-right-24: Compare](alternatives.md)

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

- :material-robot-outline:{ .lg .middle } **Automation**

    ---

    The bots that check every change, release and verify the app, answer issues and keep the repository current.

    [:octicons-arrow-right-24: Automation](automation.md)

- :material-hammer-wrench:{ .lg .middle } **Development and releases**

    ---

    How to build and test the app, how a release is made, signed and published in the Nextcloud App Store, and the changes of each version.

    [:octicons-arrow-right-24: Development](development.md) · [Releases](releases.md) · [Changelog](changelog.md)

</div>

--8<-- "README.md:help"
