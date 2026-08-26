# Security policy

## Supported versions

Security fixes are provided for the latest released version of Paperless Sync. Before the first public release, fixes are applied to the `main` branch.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability reporting for this repository:

<https://github.com/Dennis-Otto/paperless-sync/security/advisories/new>

Include the affected version, configuration, reproduction steps, and potential impact. Reports will be acknowledged as soon as practical.

## Secrets

Paperless API tokens, Nextcloud credentials, private signing keys, App Store tokens, production URLs, document metadata, personal files, and logs containing those values must never be committed.

The Paperless API token is stored through Nextcloud's server-side credentials manager. It is never returned by an application endpoint or embedded in browser-side code. Local environment files, keys, generated packages, dependency trees, and tool caches are excluded from production archives, while every push and pull request is scanned with Gitleaks.

## Synchronization and deletion safety

Paperless remains the source of truth. Synchronization is disabled until an administrator saves a validated configuration. Dry-run mode does not modify files or synchronization state.

Permanent Nextcloud deletion is disabled by default. When enabled, a document must remain absent for the configured number of complete scans, and direct deletion of a document that was never observed in the Paperless trash remains a separate opt-in. Administrators should validate the workflow against synthetic documents and maintain independent backups.

The configured target user owns synchronized files. Normal Nextcloud sharing and filesystem permissions determine who else can access them. Paperless service accounts should receive only the document read/download permissions required for export and document creation permission only when inbox import is enabled.
