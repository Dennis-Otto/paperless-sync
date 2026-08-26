# Contributing

Contributions are welcome through issues and pull requests.

Every commit must include a Developer Certificate of Origin sign-off:

```text
Signed-off-by: Your Name <your-email@example.com>
```

Use `git commit -s` to add it automatically. Before opening a pull request, run:

```bash
composer install
composer lint
composer test
composer cs:check
composer psalm
composer version:check
```

Do not include real credentials, production endpoints, private documents, or screenshots containing personal data.
