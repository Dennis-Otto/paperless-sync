# Security policy

## Supported versions

Security fixes are provided for the latest release.

## Reporting a vulnerability

Please do not open a public issue, discussion or pull request for a suspected vulnerability. Use GitHub's private vulnerability reporting for this repository:

<https://github.com/Dennis-Otto/paperless-sync/security/advisories/new>

Include the affected release, the set-up, reproduction steps and the potential impact. Reports in English or German are welcome.

## What happens next

| Step | Target |
| --- | --- |
| Acknowledgement of the report | within 7 days |
| First assessment, including whether the report is accepted | within 14 days |
| Fix released for a confirmed vulnerability | as fast as possible, at the latest within 90 days |
| Public disclosure | when the fixed release is available, in a GitHub security advisory and the release notes |

If a fix needs longer, for example because the cause lies in an upstream project, you receive an update at least every 14 days. Reporters are credited in the advisory and the release notes unless they prefer to stay anonymous.

## Secrets

Paperless API tokens, Nextcloud credentials, private signing keys, App Store tokens, production URLs, document metadata, personal files, and logs containing those values must never be committed.

The Paperless API token is stored through Nextcloud's server-side credentials manager. It is never returned by an application endpoint or embedded in browser-side code. Local environment files, keys, generated packages, dependency trees, and tool caches are excluded from production archives, while every push and pull request is scanned with Gitleaks.

## Synchronization and deletion safety

Paperless remains the source of truth. Synchronization is disabled until an administrator saves a validated configuration. Dry-run mode does not modify files or synchronization state.

Permanent Nextcloud deletion is disabled by default. When enabled, a document must remain absent for the configured number of complete scans, and direct deletion of a document that was never observed in the Paperless trash remains a separate opt-in. Administrators should validate the workflow against synthetic documents and maintain independent backups.

The configured target user owns synchronized files. Normal Nextcloud sharing and filesystem permissions determine who else can access them. Paperless service accounts should receive only the document read/download permissions required for export and document creation permission only when inbox import is enabled.

## How the project keeps itself secure

- Every pull request and every push to `main` runs CodeQL, a Gitleaks secret scan and, for changed dependencies, a review against known vulnerabilities. OpenSSF Scorecard checks the practices of the repository every week.
- Actions are pinned to commit hashes, tokens get the least permissions they need, and Renovate keeps actions, dependencies and images current, with the updates that fix a vulnerability at once.
- OSV-Scanner checks every lock file against the OSV database of known vulnerabilities, on every pull request and every week.
- Harden-Runner records the network traffic of every job of the workflows, so that a connection that doesn't belong there shows.
- Releases carry an SBOM as SPDX and as CycloneDX, the licenses of their third-party components (`THIRD_PARTY_NOTICES.md`), an OpenVEX document of the advisories that the project accepts with their reasons, and signed build provenance, are immutable once published, and are verified as their users can after every release and every week.

## Findings of code scanning

CodeQL and OpenSSF Scorecard report their findings in the repository's Security tab. The Findings workflow of the [issue assistant](https://github.com/Dennis-Otto/issue-assistant#findings) dismisses the findings that `.github/findings.toml` accepts, each with its reason, and fails while any other finding is open. It names an open finding only by the number and link of its alert, which only maintainers can open; nothing about a possible vulnerability becomes a public issue.
