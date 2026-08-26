# Security policy

## Supported versions

Security fixes are provided for the latest released version of Paperless Sync.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability reporting feature in this repository instead.

Include the affected version, configuration, reproduction steps, and potential impact. Reports will be acknowledged as soon as practical.

## Secrets

Paperless API tokens, Nextcloud credentials, private signing keys, production URLs, document metadata, and personal files must never be committed to this repository.

The application stores the Paperless API token through Nextcloud's server-side credentials manager. The token is never returned by an application endpoint or embedded in browser-side code.
