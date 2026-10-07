<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Contributing to nr_repurpose

## Development Setup

### Prerequisites

- Docker — every test and tool runs in containers started by
  `Build/Scripts/runTests.sh`
- Composer on the host for the first run only: the script is a stub that runs
  `composer install` to fetch the shared runner from
  `netresearch/typo3-ci-workflows` into `.Build/bin/`, then hands over to it
- [DDEV](https://ddev.readthedocs.io/) for a running TYPO3 instance (optional
  for tests)

### Local instance

```bash
cp .ddev/.env.dist .ddev/.env   # set OPENAI_API_KEY, the only required key
ddev start
ddev setup                      # installs TYPO3 and stores the key in nr-vault
```

The key stays in the development environment; nr_repurpose itself never reads
it — all provider calls go through nr-llm. See `README.md` and
`Documentation/Installation/Index.rst`.

## Tests and Code Quality

Run everything through the Docker runner, never `phpunit` or `php-cs-fixer`
directly:

```bash
./Build/Scripts/runTests.sh -s unit
./Build/Scripts/runTests.sh -s functional            # SQLite
./Build/Scripts/runTests.sh -s functional -d mariadb
./Build/Scripts/runTests.sh -s lint
./Build/Scripts/runTests.sh -s phpstan
./Build/Scripts/runTests.sh -s cgl -n -p 8.3         # check only; drop -n to fix
./Build/Scripts/runTests.sh -s rector -n             # check only; drop -n to apply
```

`-p 8.3|8.4|8.5` selects the PHP version (default 8.5). Dependencies
resolved on a newer PHP do not load on an older one: after installing on 8.5,
run `./Build/Scripts/runTests.sh -p 8.3 -s composerUpdate` before any
`-p 8.3` suite, otherwise every suite except `lint` stops in Composer's
platform check. Dependencies resolved on 8.3 also run on 8.5. Run the
code-style check on PHP 8.3.

CI (`.github/workflows/ci.yml`) runs lint, PHPStan, unit and functional tests
for PHP 8.3, 8.4 and 8.5 against TYPO3 ^14.3, code style and Rector once on
PHP 8.3, and renders `Documentation/`.

Behaviour changes come with a test; a bug fix comes with a test that fails
without the fix.

## Commits

- [Conventional Commits](https://www.conventionalcommits.org/):
  `type(scope): subject`, one logical change per commit
- Every commit is signed and carries a DCO sign-off:
  `git commit -S --signoff`. The `require-signed-commits` ruleset enforces
  the signature, the DCO check the `Signed-off-by` trailer — two separate
  requirements, both mandatory. SSH signing is the quickest setup: register
  your SSH key as a *signing key* on GitHub, then
  `git config --global gpg.format ssh` and
  `git config --global user.signingkey ~/.ssh/<key>.pub`.
- Do not commit a `composer.lock`; the extension deliberately has none.

## Pull Requests

- Open the pull request as a draft and mark it ready once CI is green.
- Add a `CHANGELOG.md` entry under `## [Unreleased]` for every change a user
  or integrator can notice. Version bumps belong to the release commit, never
  to a feature pull request.
- A pull request needs one approving review, all review threads resolved and
  the required checks green.
- Pull requests are merged with a merge commit; squash and rebase merging are
  disabled.

## Releasing

1. Merge a `chore: prepare release vX.Y.Z` pull request that sets the version
   in `ext_emconf.php`, in `composer.json` (`extra.typo3/cms.version`) and in
   `Documentation/guides.xml` (`version` and `release`), moves the entries
   under `## [Unreleased]` in `CHANGELOG.md` below a new
   `## [X.Y.Z] - date` heading with the compare links, and adds the version
   to `Documentation/Changelog.rst`. The release workflow compares the tag
   with `composer.json` before it builds anything; the TER upload compares it
   with `ext_emconf.php`.
2. Push a signed annotated tag `vX.Y.Z` on the merge commit. The tag push
   starts `.github/workflows/release.yml`: it builds the archives and signs
   them with Cosign, creates the GitHub release, and then, independently of
   each other, publishes to TER, waits until Packagist lists the version and
   checks that Intercept accepted the docs.typo3.org render (a render run
   exists; it does not wait for the result and never gates). The release body
   ends with a publication-status block that names the result of each.

The TER upload comment is the version's section in `CHANGELOG.md`, cut at
1,900 characters, so put upgrade notes first. A filter in front of the TER
API refuses some text that looks like SQL, for example a parenthesis directly
followed by a database column name; the upload then fails with
`Unknown (Status 403)`, and since the comment comes from the tagged commit,
re-running the release cannot fix it — the fix is a new version with
reworded text (netresearch/typo3-ci-workflows#273).

A patch release for an older line is cut the same way from a `release/X.Y`
branch created from that line's last tag, with the pull request targeting that
branch; `CHANGELOG.md` on `main` records the version afterwards.

If a release ran only partly, `.github/workflows/republish.yml` (manual
`workflow_dispatch`, inputs `tag` and `target`: `all`, `ter`, `packagist` or
`docs`) repeats the external steps for an existing tag:

- `ter` uploads the version to TER unless TER already has it, and syncs the
  TER listing metadata.
- `packagist` only checks that Packagist lists the version, and `docs` only
  checks that Intercept has a render run for it. They cannot trigger either:
  Packagist updates through its GitHub hook, docs.typo3.org renders through
  the Intercept webhook on the tag push.
- It never creates or edits the GitHub release. If the release is missing
  because the release run stopped before creating it, re-run the failed
  `release.yml` run for that tag instead.

## Governance and policies

This extension follows the organisation-wide Netresearch policies:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md):
  ownership, roles, how decisions are made and conflicts resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md):
  planned and excluded work for the next twelve months.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings):
  which vulnerability, licence and static-analysis findings must be fixed,
  by when, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management):
  where project, CI and release credentials are stored, who may use them,
  and when they are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md):
  the people and teams with administrative or write access to this
  repository.

Checks that run on every pull request in this repository:

- `.github/workflows/checks.yml`: Composer Audit (fails on any advisory for
  an installed package) and Opengrep SAST, both through
  `typo3-ci-workflows`' `security.yml` (which Opengrep findings block a
  pull request is set by the organisation's
  [static analysis rule](https://github.com/netresearch/.github/blob/main/SECURITY.md#static-analysis-sast));
  Dependency Review (fails on newly added dependencies with a vulnerability
  of severity high or higher); PHP License Audit (`license-check.yml`, fails
  when a licence string in `composer licenses` output matches its default
  forbidden-licence pattern, which refuses `SSPL`, `BSL` and `BUSL` and every
  `SSPL-` or `BUSL-` identifier of any version (`BSL-1.0` passes));
  CodeQL; Betterleaks secret scanning; zizmor for the workflow files.
- `.github/workflows/ci.yml`: PHPStan (`Build/phpstan.neon`, which includes
  `phpstan.neon` and adds the phpat architecture rules), PHP lint, code style,
  Rector and Fractor checks, unit and functional tests, and Infection
  mutation testing (reports, does not block).

## Security

Report vulnerabilities privately through
[GitHub Security Advisories](https://github.com/netresearch/t3x-nr-repurpose/security/advisories/new),
not in a public issue.

The security expectations, threat model and controls of the extension are in
[docs/SECURITY-ASSURANCE.md](docs/SECURITY-ASSURANCE.md). A change that adds
or removes a control updates that document.
