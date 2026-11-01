---
description: "The automation behind Paperless Sync: about 30 GitHub Actions workflows and bots that check every pull request, test every supported Nextcloud version, release signed packages to the Nextcloud App Store, verify them, answer issues and keep the repository current."
---

# Automation

[← README](https://github.com/Dennis-Otto/paperless-sync) · [Development](development.md) · [Releases](releases.md) · [Security design](security.md)

Paperless Sync has one maintainer and about 30 workflows of GitHub Actions that do the rest. They check every change before it reaches `main`, the end-to-end tests against every supported version of Nextcloud included, make a signed release of what reaches `main`, deliver it to the Nextcloud App Store and check it there as you can, answer and close issues, and keep the repository, its dependencies and its settings current, every night and every week. Most of them come from the [Repo Blueprint](https://github.com/Dennis-Otto/repo-blueprint), which brings every improvement to each repository made from it; the end-to-end tests, the canary of the coming Nextcloud and the upstream bot are those of a Nextcloud app.

What it means for you:

- Every change passed the same 15 required checks before it reached `main`, the end-to-end tests in Docker against every supported version of Nextcloud, and on PostgreSQL, MariaDB and S3, included.
- Every release is built in isolation, signed, delivered to the App Store and then checked there as you can check it; [SECURITY.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/SECURITY.md) says how.
- The coming version of Nextcloud is tested every week, long before its release, and the app supports it as soon as the end-to-end tests pass against it.
- An issue gets a first answer within minutes and closes with the release that fixes it.

In the pictures, you and what is yours are orange, the bots blue, and what comes out green.

## An issue

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-an-issue.dark.svg">
  <img src="images/diagrams/the-way-of-an-issue.svg" alt="The way of an issue: You open an issue. The issue assistant labels it from its form and gives a first analysis, written by an AI that only reads. When it needs more information, the issue lifecycle reminds you after 15 days and closes the issue after 30 days without an answer, and your answer goes back to the assistant; a possible duplicate closes after 3 days unless someone objects. Otherwise a pull request fixes the issue; when it merges, the issue gets the label fixed-in-next-release, and the release that ships the fix closes it with a link to the release, in the milestone of the release. You are orange, the bots blue, and the closed issue green.">
</picture>

The [issue assistant](https://github.com/Dennis-Otto/issue-assistant) reads every new issue and every new comment. It sets the labels of the issue form, and an AI, which reads the code and the documentation but can't change anything, writes a first analysis: the likely cause with links to the lines of code, what to try, and the information still missing. A job without AI checks the answer before it is posted, such as [the first analysis of #84](https://github.com/Dennis-Otto/paperless-sync/issues/84#issuecomment-6125470710), less than three minutes after the issue was opened. The issue lifecycle does the waiting without AI: it reminds whoever was asked for information, closes the issues that get no answer and the duplicates that nobody objects to, marks the issues that a merged pull request fixes, and closes them once the release with the fix is out. Your question fits the [Q&A discussions](https://github.com/Dennis-Otto/paperless-sync/discussions/categories/q-a) better than an issue.

## A pull request

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-a-pull-request-into-main.dark.svg">
  <img src="images/diagrams/the-way-of-a-pull-request-into-main.svg" alt="The way of a pull request into main: You open a pull request. The title check and the labeler give it the labels of its type and its areas, and the 15 required checks run. After they finish, the flaky-test bot runs a failed job once more, the cancelled-check bot restarts a run that a late run cancelled, and the coverage bot comments with the coverage against main; a job that runs again goes back to the checks. Once every check passes, the pull request merges if it is up to date with main; otherwise the branch bot updates its branch and the checks run again. It is then squashed into main. You are orange, the bots blue, and main green.">
</picture>

The 15 required checks are those of the CI (`scripts/check.sh` with PHPUnit, which runs every line of `lib/`, Psalm and the coding standard), the end-to-end tests in Docker, which two checks share: `nextcloud` runs the oldest and the newest supported version of Nextcloud, and `nextcloud on PostgreSQL, MariaDB and S3` the newest one on PostgreSQL and on MariaDB with S3 object storage, the upgrade of an installation in use from the last release, and the versions between that `nextcloud` doesn't take, the lint of the workflows, the licenses of every file, the Markdown, the pictures of the diagrams and the sign-off of every commit, the strict build of the website, the setup of the issue assistant, the Conventional Commit title and the entry under *Unreleased* in `CHANGELOG.md` for every change for users, CodeQL, the dependency review and the secret scan. A pull request that changes only the documentation or other workflows skips the end-to-end tests and passes their checks at once, and one that can't behave differently on another database or storage or break the upgrade runs only those versions between in the second. OSV-Scanner checks a pull request that changes a lock file, and zizmor one that changes a workflow; both send their findings to code scanning. The link check fails a pull request with a broken link. The welcome bot greets a first pull request, and [CONTRIBUTING.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/CONTRIBUTING.md) has the rules.

A check that fails says what it found, why that matters and how to fix it, with the commands to run: in its summary, as its annotation and, after the checks, in the one comment of the PR coach on the pull request, also on a pull request from a fork, whose own runs can't comment. The blueprint explains its checks in `.github/checks.toml`, and the checks of this app alone explain themselves in [`.github/checks.project.toml`](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/checks.project.toml): both jobs of the end-to-end tests, the contract tests against Paperless-ngx, the canary of the coming Nextcloud, the package with `scripts/check-package.sh`, and each check of `scripts/check-project.sh`, such as the German pages, the area labels and the translations. Where a command can fix what a check found, the CI runs it, and the fix bot commits the fix onto the branch of the pull request, or suggests it at its lines on a pull request from a fork: php-cs-fixer for the coding standard, for example. The checks of `scripts/check-project.sh` name the commands that copy the icon of the website and write the translations for the browser anew, which the fix bot runs as well once the blueprint finds their step ([repo-blueprint#351](https://github.com/Dennis-Otto/repo-blueprint/issues/351)). A test of the whole app that fails needs a person, who runs it on their machine with the command that its explanation names.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/how-a-check-of-the-app-that-fails-explains-itself.dark.svg">
  <img src="images/diagrams/how-a-check-of-the-app-that-fails-explains-itself.svg" alt="How a check of the app that fails explains itself: A check of the app fails, such as the end-to-end tests, the contract tests against Paperless, the package or a check of scripts/check-project.sh. Its job explains the failure from .github/checks.project.toml, in its summary and as its annotation, and where a command can fix it, the CI runs that command; the end-to-end tests, the contract tests and the package need a person, and the commands of the checks of scripts/check-project.sh join the fix bot once repo-blueprint#351 ships. After the checks, the PR coach keeps one comment on the pull request with the explanation of each check that failed, also on a pull request from a fork, and the fix bot commits the fixes onto a branch of this repository or suggests them at their lines on a fork. You fix the rest with the commands that the explanation names and push, and the checks run again, as they do after a commit of the fix bot. You are orange, the checks and the bots blue, and the explanations and the new run green.">
</picture>

The labeler gives a pull request the label of each area whose files it changes, by the rules of [`.github/labeler.yml`](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/labeler.yml), and takes it away again when a later push no longer changes them: *area: settings* for the administration settings, the connection test and their page, *area: archive* for the path template, the files in Nextcloud and the record of the mirrored documents, *area: inbox* for the uploads from the inbox, and *area: runs* for the sync engine, the background job, the client of Paperless, the status and the reports. Every file of `lib/`, `js/`, `css/`, `templates/` and `tests/Unit/` belongs to an area, and `scripts/check-project.sh` fails on a new file without one, on a glob that names no file and on an area of `.github/labels.toml` without a rule. The translations in `l10n/` have no area: the title check labels a pull request with the scope `i18n` as *translations*.

A pull request that changes the app or the story of its pictures gets the visual check, which only reports: it renders the pictures of the documentation and of the App Store in English and German, side by side, and comments with every picture that looks different and the share of its changed pixels; its artifact holds each one before and after, with the changed pixels in red. After the merge, the screenshot bot renews them, as [Development](development.md#pictures) shows.

Renovate, which the blueprint runs for this repository every two hours as the release app, keeps the dependencies, the actions and the images current; Dependabot keeps the Features of the dev container. Their routine updates merge themselves once every check passes.

## A release

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-a-release-into-the-nextcloud-app-store.dark.svg">
  <img src="images/diagrams/the-way-of-a-release-into-the-nextcloud-app-store.svg" alt="The way of a release into the Nextcloud App Store: A change reaches main. The release bot keeps the pull request of the next release, with the version from the titles of the merged pull requests and the notes from Unreleased. When it is merged, an isolated build makes the package, signed with the certificate of the app, its SBOMs, its VEX and its signed provenance. The release is published on GitHub and announced in the discussions, the issue lifecycle closes the issues that it fixes, and the package goes to the Nextcloud App Store. The release verification then checks the release as users can, and does so again every week. Main is orange, the bots blue, and the App Store green.">
</picture>

The version follows from the titles of the merged pull requests: `fix` a patch, `feat` a minor, `!` a major version. The maintainer merges the pull request of the release; one that only updates dependencies merges itself once its checks pass. The [release guide](releases.md) describes every step and how to verify a release.

## Every night and every week

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/what-the-bots-do-on-their-own.dark.svg">
  <img src="images/diagrams/what-the-bots-do-on-their-own.svg" alt="What the bots do on their own: Every night, the end-to-end tests run against every supported Nextcloud, as its images move with each point release. Every morning, the findings bot keeps every alert of code scanning fixed or accepted, and the issue lifecycle sends its reminders and closes what is due. Every week, the upstream bot and the blueprint bot open pull requests for a new Nextcloud and a new blueprint, the canary tests the coming Nextcloud, and the contract tests check the app against a real Paperless-ngx 2.x and 3.x; the link check, the mutation tests, the OpenSSF Scorecard, the release verification, CodeQL, OSV-Scanner, the secret scan of the whole history, the SBOM and zizmor check the repository; the clean-up bot and the settings bot tidy it up. All of it ends in a pull request that merges itself, an issue that closes itself once fixed, or a report. The bots are blue, and what comes out green.">
</picture>

- **Upstream bot:** when Nextcloud has a new major version, a pull request raises `max-version` in `appinfo/info.xml` and merges itself once every check, the end-to-end tests against the new version included, passes.
- **Canary:** the end-to-end tests against the beta or release candidate of the next major version of Nextcloud, or its daily build, keep one issue open while they fail. It blocks nothing and gives notice while there is time.
- **Contract tests:** the app against a real Paperless-ngx, the last release of 2.x and the current release of 3.x, whose images Renovate keeps current, keep one issue open while they fail. They also run in every pull request that changes how the app talks to Paperless.
- **Screenshot bot:** renders the pictures of the documentation, of the App Store and the social preview anew after a change of `main` that can change them, after every release and every week, from a frozen story, so that each picture whose pixels stayed the same keeps its file. When pixels changed, the pull request *docs: update the screenshots* merges itself once every check passes.
- **Blueprint bot:** brings the repository up to the latest release of the blueprint with `copier update`, in a pull request that merges itself without conflicts.
- **Link check:** keeps one issue open while a link of the documentation is broken.
- **Settings bot:** brings the settings of the repository on GitHub in line with the settings as code in `.github/repository.toml`.

## Every workflow

Each workflow says in its first lines what it does. An environment holds the secrets of the jobs that need them, and only `main` may use it; the release app is a GitHub App that the jobs of the environment `release` act as, so that the pull requests they open run their checks.

| Workflow | What it does | When | Environment |
| --- | --- | --- | --- |
| [CI (php)](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/ci.yml) | `scripts/check.sh`: Composer, the schema of `appinfo/info.xml`, the coding standard, Psalm, PHPUnit with every line of `lib/` covered, the package | every pull request, every push to `main` | — |
| [Docker E2E](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/e2e.yml) | the end-to-end tests against every supported Nextcloud, and against the newest one on PostgreSQL, and on MariaDB with S3, with the upgrade from the last release, in a second job for pull requests that change the migrations, the state, the storage, the uploads, the package or the tests; on Monday also the canary against the coming one, and the contract tests against a real Paperless-ngx 2.x and 3.x | pull requests that can affect the app, every push to `main`, every night, every Monday | — |
| [Visual check](development.md#pictures) | `visual.yml` renders the pictures of the documentation and of the App Store in English and German, side by side, and keeps each one that looks different before and after, with its changed pixels in red; it only reports | a pull request that changes the app or the story of the pictures | — |
| [Visual check comment](development.md#pictures) | `visual-comment.yml` writes one comment on the pull request with the pictures that look different and the share of their changed pixels, without running its code | after each visual check | — |
| [Paperless fixtures](development.md#tests) | `paperless-fixtures.yml` commits the fixtures of the unit tests that differ from the answers of a real Paperless onto the pull request whose app passed against it, and suggests them on one from a fork, without running its code | after the end-to-end tests of a pull request | `release` |
| [Lint](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/lint.yml) | the workflows, the licenses (REUSE), the Markdown, the sign-off of every commit (DCO), the setup of the issue assistant, and hints at spelling and style | every pull request | — |
| [Pull request title](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/pull-request-title.yml) | a Conventional Commit title, the label of its type, and an entry under *Unreleased* for `feat`, `fix` and `perf` | a pull request opened, edited or pushed | — |
| [Area labels](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/area-labels.yml) | the labels of the areas whose files a pull request changes, by the rules of `.github/labeler.yml` | a pull request opened or pushed | — |
| [Welcome](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/welcome.yml) | a comment with what helps a first pull request along | the first pull request of a person | — |
| [Docs](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/docs.yml) | builds this website strictly, and publishes it on GitHub Pages | every pull request, every push to `main` | `github-pages` |
| [Links](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/links.yml) | lychee checks every link of the documentation; the weekly run keeps one issue open while one is broken | a change of a Markdown file, every week | — |
| [CodeQL](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/codeql.yml) | the static analysis of the JavaScript and of the workflows | every pull request, every push to `main`, every week | — |
| [Dependency review](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/dependency-review.yml) | the vulnerabilities and licenses of the dependencies that a pull request brings in | every pull request | — |
| [Secret scan](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/secret-scan.yml) | gitleaks looks for secrets in the commits of a pull request, and every week in the whole history of `main` | every pull request, every week | — |
| [OSV-Scanner](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/osv-scanner.yml) | every lock file against the OSV database of known vulnerabilities | a pull request that changes a lock file, every push to `main`, every week | — |
| [Workflow security](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/zizmor.yml) | zizmor audits every workflow for injection, wide permissions and unpinned actions | a pull request that changes a workflow, every push to `main`, every week | — |
| [SBOM](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/sbom.yml) | a software bill of materials of the repository in SPDX; every release brings its own | every week | — |
| [After the checks](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/after-checks.yml) | the flaky-test bot, the coverage bot, the cancelled-check bot, and the bot that runs a failed release once more where it stopped | after every run of the checks and of the release | — |
| [Update branches](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/update-branches.yml) | the branch bot: brings every pull request that waits for auto-merge up to date, and labels one that conflicts with `main` | every push to `main`, every 30 minutes | `release` |
| [Dependabot maintenance](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/dependabot-automerge.yml) | merges the routine updates of Dependabot once every check passes | a pull request of Dependabot | — |
| [Issue assistant](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/issue-assistant.yml) | the labels of the issue forms and a first analysis by an AI that only reads, on a runner whose firewall lets through only the hosts it needs | a new or edited issue, a new comment | `issue-assistant` |
| [Issue lifecycle](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/issue-lifecycle.yml) | reminders, closing without an answer or as a duplicate, the label of fixed issues, and closing them with the release | every morning, a merged pull request, a release | — |
| [Labels](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/labels.yml) | creates and updates the labels of `.github/labels.toml` | a change of the labels | — |
| [Release](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/release.yml) | the release bot: the pull request of the next release; once merged, the release, its announcement and the delivery to the App Store | every push to `main`, every hour | `release` |
| [Build the release](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/build-release.yml) | the isolated build: the signed package, SBOMs, the licenses of its components, VEX and signed provenance (SLSA Build Level 3) | called by the release | `release` |
| [Release verification](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/verify-release.yml) | checks the latest release as users can: provenance, SBOM, immutable release, signed commit, the same package in the App Store | after every release, every week | — |
| [Register the app](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/register-app.yml) | registers the app id with its certificate in the Nextcloud App Store | by hand, once | `release` |
| [Upstream](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/upstream.yml) | the upstream bot: a pull request for a new major version of Nextcloud | every week | `release` |
| [Screenshots](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/screenshot-bot.yml) | the screenshot bot: `scripts/screenshots.sh` renders the pictures anew, and a pull request with those whose pixels changed merges itself | a push to `main` that touches a file of `.github/screenshots-paths.txt`, every release, every week | `release` |
| [Blueprint update](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/blueprint-update.yml) | the blueprint bot: a pull request with the latest release of the blueprint | every week, after every release of the blueprint | `release` |
| [Settings](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/settings.yml) | the settings bot: the settings as code on GitHub | a change of the settings, every week | `release` |
| [Clean-up](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/cleanup.yml) | deletes the merged branches and the caches of closed pull requests, and lists old branches with work of their own | every week | — |
| [Mutation](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/mutation.yml) | Infection changes the code in thousands of small ways and reports the changes that no test notices | every week, a pull request that changes how it runs | — |
| [OpenSSF Scorecard](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/scorecard.yml) | rates the security practices of the repository | every push to `main`, every week | — |
| [Findings](https://github.com/Dennis-Otto/paperless-sync/blob/main/.github/workflows/findings.yml) | dismisses the alerts of code scanning that `.github/findings.toml` accepts, with the reason, and fails while another is open | every morning, after CodeQL and Scorecard | — |

## Safe by design

Bots with write access are a target, so they are built to give an attacker nothing:

- Every action is pinned to the hash of a commit, which the settings of the repository require, and Renovate moves the pins.
- The token of a workflow can only read by default; each job asks for the permissions it needs.
- The secrets live in the environments `release` and `issue-assistant`, which only `main` may use, never in the repository.
- The workflows of a pull request from a fork wait for the approval of the maintainer. The bots that act on pull requests never check out or run their code.
- Harden-Runner records the network traffic of the jobs, the AI of the issue assistant runs behind a firewall that lets through only the hosts it needs, and zizmor and CodeQL audit the workflows themselves.

The [security design](security.md) covers the app, and [SECURITY.md](https://github.com/Dennis-Otto/paperless-sync/blob/main/SECURITY.md) the repository and its releases. A repository made from the [Repo Blueprint](https://github.com/Dennis-Otto/repo-blueprint) gets the same automation.
