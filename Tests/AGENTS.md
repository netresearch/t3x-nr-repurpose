<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-09-28 -->

# AGENTS.md — Tests

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 extension test suite. **Use the `typo3-testing` skill** for comprehensive guidance.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `Build/phpunit.xml` | Unit config (one `unit` testsuite) |
| `Build/FunctionalTests.xml` | Functional config (sqlite default) |
| `Tests/Functional/Persistence/JobProcessingRepositoryTest.php` | Repository/DB reference test |
| `Tests/Functional/Rendering/FfmpegAudioStitcherTest.php` | Real-binary smoke test (ffmpeg) |
| `Tests/Unit/Rendering/GdImageCompositorTest.php` | Unit reference (memory guard) |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Golden Samples (follow these patterns)
| Pattern | Reference |
|---------|-----------|
| Functional (DB rows via DBAL) | `Tests/Functional/Persistence/JobProcessingRepositoryTest.php` |
| Functional (CSV fixtures) | `Tests/Functional/Service/CapabilityGrantResolverTest.php` |
| Functional (real binaries) | `Tests/Functional/Rendering/PlaywrightHtmlToImageRendererTest.php` |
| Unit | `Tests/Unit/Rendering/GdImageCompositorTest.php` |
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START structure -->
## Test Structure
```
Tests/
├── Unit/          # Controller, Domain, Fixture, Generator, Ingestion, Pipeline, Provenance, Rendering, Service, Social, Understanding
├── Functional/    # Controller, Domain, Fixtures, Generator, Ingestion, Persistence, Queue, Rendering, Resource, Review, Service
└── Fixtures/      # Audio/, Document/, Image/, Pdf/, Video/, Web/ sample files shared by both suites
```
PHPUnit configs live in `Build/` (repo root), not under `Tests/`: `Build/phpunit.xml` and `Build/FunctionalTests.xml` — the locations the shared runner finds without a conf.
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START commands -->
## Running Tests
| Type | Command |
|------|---------|
| Unit tests | `./Build/Scripts/runTests.sh -s unit` |
| Functional tests | `./Build/Scripts/runTests.sh -s functional` (sqlite; `-d mariadb` for MariaDB) |
| Single file | `./Build/Scripts/runTests.sh -s unit Tests/Unit/Path/To/Test.php` (extra args pass through to phpunit) |
| Coverage | `./Build/Scripts/runTests.sh -s unitCoverage` |

> `composer ci:test:php:unit` and `composer ci:test:php:functional` call
> phpunit directly inside a container or CI and hand over to the runner
> otherwise.
> `-p <8.3|8.4|8.5>` selects the PHP version (default 8.5).
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Key Patterns (TYPO3-specific)
- Unit tests extend `\PHPUnit\Framework\TestCase` (none use the testing framework's `UnitTestCase`)
- Functional tests extend `Tests/Functional/AbstractFunctionalTestCase.php` (a `FunctionalTestCase`)
- Use `$this->importCSVDataSet()` for functional test fixtures
- `$testExtensionsToLoad` is set in `AbstractFunctionalTestCase` (nr-vault, nr-llm, nr-repurpose); a test needing a fixture extension overrides the full list (see `Tests/Functional/Resource/JobFileStorageIndexingTest.php`)
- Functional tests take services from the container with `$this->get(Foo::class)`; `GeneralUtility::makeInstance()` only for `ConnectionPool`
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- Test class name matches source: `MyClass` → `MyClassTest`
- Test methods: `test` prefix or the `#[Test]` attribute — never `@test` (PHPUnit 11 deprecates doc-comment metadata, 12 drops it)
- One assertion concept per test
- Use data providers for multiple similar cases
- Mock external services, never real HTTP calls
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] All tests pass: `./Build/Scripts/runTests.sh -s unit` and `-s functional`
- [ ] New functionality has tests
- [ ] Fixtures are minimal and focused
- [ ] No hardcoded credentials or paths
- [ ] Coverage hasn't decreased
<!-- AGENTS-GENERATED:END checklist -->

## Setup
Docker, plus `composer` on the host for the very first run:
`Build/Scripts/runTests.sh` is a stub that runs `composer install` to fetch the
shared runner into `.Build/bin/`; after that the runner provisions PHP images
itself. Switching PHP with `-p` needs `-p <version> -s composerUpdate` first —
see root `AGENTS.md` Commands.

## Security
- Never use real API keys or secrets in tests — mock nr-llm services
- Functional rendering tests use the real local binaries (ffmpeg, chromium), no network

## Examples
See Golden Samples above — real tests beat generic snippets.

## When stuck
- Verbose run: `./Build/Scripts/runTests.sh -s unit --debug` (a `--long` option and everything after it goes to phpunit; the runner itself has no `-v`)
- Root `AGENTS.md` for tool provisioning (cgl/phpstan/rector ship via `netresearch/typo3-ci-workflows`)

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For comprehensive TYPO3 testing guidance including fixtures, mocking, CI setup, and runTests.sh:
> **Invoke skill:** `typo3-testing`
<!-- AGENTS-GENERATED:END skill-reference -->
