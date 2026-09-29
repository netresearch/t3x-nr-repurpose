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

1. Merge a `chore(release): X.Y.Z` pull request that sets the version in
   `ext_emconf.php` and `Documentation/guides.xml`, and in `CHANGELOG.md` adds
   the `## [X.Y.Z] - date` heading below `## [Unreleased]` plus the compare
   links.
   `composer.json` carries no version.
2. Push a signed annotated tag `vX.Y.Z` on the merge commit. The tag push
   starts `.github/workflows/release.yml`: it builds the archives and signs
   them with Cosign, publishes to TER, waits until Packagist lists the
   version, checks that Intercept accepted the docs.typo3.org render (a render
   run exists; it does not wait for the result and never gates), and — only
   after TER and Packagist succeeded — creates the GitHub release.

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

## Security

Report vulnerabilities privately through
[GitHub Security Advisories](https://github.com/netresearch/t3x-nr-repurpose/security/advisories/new),
not in a public issue.
