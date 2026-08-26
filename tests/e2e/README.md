# Docker end-to-end tests

The suite mounts this checkout read-only as a Nextcloud Custom App and connects it to a deterministic local Paperless API mock. CI runs the same scenario against the minimum supported Nextcloud 33 release and the current stable Nextcloud 34 release.

Run locally:

```bash
bash tests/e2e/run.sh
```

The scenario verifies:

- administrator configuration and server-side token handling
- dry-run behavior without filesystem mutations
- archive export through the Paperless download API
- metadata-only moves without downloading the PDF again
- Paperless inbox-tag exclusion and empty-folder pruning
- Paperless trash mirroring and guarded permanent deletion
- successful and failed Nextcloud inbox imports
- target-user ownership and access isolation
- background-job registration, status reporting, and clean application logs

Optional environment variables:

- `E2E_PORT`: host port for Nextcloud, default `18083`
- `DOCKER_BIN`: Docker CLI path, default `docker`
- `E2E_PROJECT_NAME`: Compose project name, default `paperless_sync_e2e`
- `NEXTCLOUD_IMAGE`: pinned Nextcloud image override
- `KEEP_E2E=1`: keep containers and the disposable volume after the suite

All credentials, users, filenames, document content, and metadata are synthetic. The Paperless mock rejects every token except the explicit `e2e-only-token` fixture and records whether uploads arrived intact.
