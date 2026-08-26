# Contributing

Contributions are welcome through issues and pull requests.

Every commit must include a Developer Certificate of Origin sign-off:

```text
Signed-off-by: Your Name <your-email@example.com>
```

Use `git commit -s` to add it automatically. Pull requests verify the exact author sign-off of every new commit.

Before opening a pull request, run:

```bash
composer install
composer validate --strict
composer audit
composer lint
composer l10n:check
composer test
composer cs:check
composer psalm
composer version:check
bash tests/e2e/run.sh
krankerl package
composer package:check
```

Changes to synchronization, deletion, path generation, permissions, scheduling, or Paperless API behavior must include focused unit coverage and an end-to-end scenario where practical. Review `tests/e2e/MANUAL_ACCEPTANCE_TESTS.md` before releases that affect user data.

Do not include real credentials, production endpoints, private documents, personal metadata, or screenshots and logs containing those values. Use reserved example domains and clearly synthetic fixtures.
