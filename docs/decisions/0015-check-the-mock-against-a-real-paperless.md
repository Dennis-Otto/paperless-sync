# Check the app and the answers of its unit tests against a real Paperless-ngx every week

- Status: accepted
- Date: 2026-10-14

## Context

Every test of the app talked to Paperless as the app expects it to answer: the unit tests to answers written into them and to `TestPaperlessClient`, the end-to-end tests to `tests/e2e/mock_server.py`. Both came from the same assumptions, and twice they drifted from Paperless together while every test passed. They kept SHA-256 checksums, while Paperless-ngx 2.x keeps MD5 ones ([decision 0009](0009-checksums-of-paperless-2-and-3.md)), and they knew only the tasks of version 9, while Paperless-ngx 3.x lists them in another shape ([decision 0012](0012-ask-for-version-9-of-the-paperless-api.md)). The mock also never read the list of fields of a request.

The users of the app run two lines of Paperless-ngx: 2.x, whose last release is 2.20, and 3.x. Paperless publishes images of both, of about 1.5 and 2.5 GB, which start in about half a minute.

## Options

1. Keep the mock and the answers of the unit tests, and fix them when a user reports a difference. Fast and simple, but users find the difference first, after a release.
2. Replace the mock by a real Paperless in every end-to-end test. Each run would load both images and wait for Paperless, in every pull request, for answers that change only with a release of Paperless, and a failure of Paperless would block the merge of any change.
3. Keep the mock for the end-to-end tests, and check it and the answers of the unit tests against a real Paperless of both lines: in contract tests that run every week, when the images move, and in every pull request that changes how the app talks to Paperless, with the answers recorded for the unit tests.

## Decision

Option 3. `tests/e2e/run-contract.sh` starts Paperless-ngx 2.20 or the current 3.x next to Nextcloud, checks every answer of Paperless that the app reads, runs the app against it, and records the answers into `tests/fixtures/paperless-ngx-<line>/`, which `PaperlessApiServiceTest` reads. A later run compares the shapes of the answers with the fixtures and fails when a field that the tests read is gone or has another type. The job `paperless` of `.github/workflows/e2e.yml` runs it for both lines every Monday, when started by hand and in pull requests that change the client of Paperless, the mock, the fixtures or the contract tests. It is no required check: like the canary of the coming Nextcloud, it gives notice in an issue, and a failure of Paperless itself blocks no other change. Renovate keeps both images current by their digests, 2.x within 2.x.

## Consequences

- A release of Paperless-ngx that answers differently opens an issue within a week, and the update of its image by Renovate shows it in the pull request, which Renovate merges only once the contract tests pass too.
- The unit tests read answers that Paperless sent, so a difference between the mock and Paperless shows in the unit tests too, once the fixtures are recorded anew.
- The contract tests do not compare the mock itself with Paperless; the unit tests read the recorded answers, and the end-to-end tests keep the mock.
- Two recordings of one image give the same bytes, with fixed values for their times, IDs and the rest of what differs from run to run, so a fixture that changes is a change of Paperless; only a change of their shape fails the contract tests. `CONTRACT_RECORD=1 bash tests/e2e/run-contract.sh 2` and `3` record them. When the app passes against new answers in a pull request from a branch of this repository, such as the update of an image by Renovate, the workflow *Paperless fixtures* commits the fixtures that differ, and the unit tests read them in that commit; the fix bot of the blueprint takes fixes only from Lint and CI, and only of checks that failed.
- The README names the versions of Paperless-ngx that the app is tested with.
