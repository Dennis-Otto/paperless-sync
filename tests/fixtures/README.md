# Answers of a real Paperless-ngx

The answers that a real Paperless-ngx gave to the requests of the app, which the unit tests in `tests/Unit/Service/PaperlessApiServiceTest.php` read instead of answers that follow what the app expects:

- `paperless-ngx-2/`: the last release of Paperless-ngx 2.x, with MD5 checksums and the tasks of version 9 of its API
- `paperless-ngx-3/`: the current release of Paperless-ngx 3.x, with SHA-256 checksums, and its tasks in version 9 and, in the files `*.v10.json`, in version 10

Each JSON file is one answer: the request, its HTTP status, the headers that the app reads and the body. `downloads.json` holds the checksums of the original and the archived download of the invoice and the original itself, in Base64. The documents, names and files are synthetic.

Two recordings of one image of Paperless are the same bytes, so a change of a fixture is a change of Paperless: `stable()` in `tests/e2e/contract.py` writes fixed values instead of what differs from run to run, while answers that share a value keep sharing it.

| In the answers of Paperless | In the fixtures |
| --- | --- |
| Times, such as `added`, `modified` and `date_done` | `2026-01-01T00:00:00`, with the digits and the offset of the answer |
| The day of the run, as the date of a document without one of its own, also in its file name | `2026-01-01` |
| Durations, such as `duration_seconds` | `1.0` |
| UUIDs, such as the ID of a task | `00000000-0000-4000-8000-000000000001` and so on |
| The ID of a task | 1, 2, 3, by their order, as Paperless may run a task of its own in between |
| The ID of a choice of a custom field | `choice0000000001` and so on |
| The size and the checksums of the archived version, which holds the time of the run | `50000`, and the checksums of a fixed text |
| The temporary folder of a run of OCR in a message | `/tmp/contract` |
| The order of `permissions` and `inherited_permissions`, sets inside Paperless | sorted |

The contract tests record them, against a Paperless started in Docker, and compare their shapes with these files in every run; [tests/e2e/README.md](../e2e/README.md#contract-tests-against-a-real-paperless-ngx) explains them. When the app passes against the new answers of Paperless in a pull request from a branch of this repository, such as the update of its image by Renovate, [a bot commits the fixtures that differ](../e2e/README.md#new-fixtures-in-the-pull-request-that-brings-them), so nobody needs to record them. To record them on your machine, such as for a pull request from a fork:

```bash
CONTRACT_RECORD=1 bash tests/e2e/run-contract.sh 2
CONTRACT_RECORD=1 bash tests/e2e/run-contract.sh 3
```
