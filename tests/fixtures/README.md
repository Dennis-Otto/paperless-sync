# Answers of a real Paperless-ngx

The answers that a real Paperless-ngx gave to the requests of the app, which the unit tests in `tests/Unit/Service/PaperlessApiServiceTest.php` read instead of answers that follow what the app expects:

- `paperless-ngx-2/`: the last release of Paperless-ngx 2.x, with MD5 checksums and the tasks of version 9 of its API
- `paperless-ngx-3/`: the current release of Paperless-ngx 3.x, with SHA-256 checksums, and its tasks in version 9 and, in the files `*.v10.json`, in version 10

Each JSON file is one answer: the request, its HTTP status, the headers that the app reads and the body. `downloads.json` holds the checksums of the original and the archived download of the invoice and the original itself, in Base64. The documents, names and files are synthetic.

The contract tests record them, against a Paperless started in Docker, and compare their shapes with these files in every run; [tests/e2e/README.md](../e2e/README.md#contract-tests-against-a-real-paperless-ngx) explains them. To record them anew, such as after a release of Paperless that answers in a new shape:

```bash
CONTRACT_RECORD=1 bash tests/e2e/run-contract.sh 2
CONTRACT_RECORD=1 bash tests/e2e/run-contract.sh 3
```
