<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — Tests

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 extension test suite. **Use the `typo3-testing` skill** for comprehensive guidance.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `Build/phpunit.xml` | Unit (+ fuzzy/integration testsuite) config |
| `Build/FunctionalTests.xml` | Functional config (sqlite default) |
| `Tests/Functional/Persistence/JobProcessingRepositoryTest.php` | Repository/DB reference test |
| `Tests/Functional/Rendering/FfmpegAudioStitcherTest.php` | Real-binary smoke test (ffmpeg) |
| `Tests/Unit/Rendering/GdImageCompositorTest.php` | Unit reference (memory guard) |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Golden Samples (follow these patterns)
| Pattern | Reference |
|---------|-----------|
| Functional (DB, CSV fixtures) | `Tests/Functional/Persistence/JobProcessingRepositoryTest.php` |
| Functional (real binaries) | `Tests/Functional/Rendering/PlaywrightHtmlToImageRendererTest.php` |
| Unit | `Tests/Unit/Rendering/GdImageCompositorTest.php` |
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START structure -->
## Test Structure
```
Tests/
├── Unit/          # Domain, Fixture, Generator, Ingestion, Pipeline, Rendering, Service, Understanding
├── Functional/    # Domain, Generator, Ingestion, Persistence, Queue, Rendering, Resource, Service
└── Fixtures/      # Pdf/ and Web/ sample inputs shared by both suites
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

> No composer phpunit scripts exist — the runner is the only test entry point
> (the `ci:*` composer scripts cover cgl/phpstan/rector, not phpunit).
> `-p <8.3|8.4|8.5>` selects the PHP version (default 8.5).
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Key Patterns (TYPO3-specific)
- Unit tests extend `\TYPO3\TestingFramework\Core\Unit\UnitTestCase`
- Functional tests extend `\TYPO3\TestingFramework\Core\Functional\FunctionalTestCase`
- Use `$this->importCSVDataSet()` for functional test fixtures
- Define `$testExtensionsToLoad` for extension dependencies
- Use `GeneralUtility::makeInstance()` for DI-aware instantiation in functional tests
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- Test class name matches source: `MyClass` → `MyClassTest`
- Test methods: `test` prefix or `@test` annotation
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
Nothing beyond Docker — the runner provisions PHP images itself. First run of
`./Build/Scripts/runTests.sh -s composerUpdate` installs `.Build/`.

## Security
- Never use real API keys or secrets in tests — mock nr-llm services
- Functional rendering tests use the real local binaries (ffmpeg, chromium), no network

## Examples
See Golden Samples above — real tests beat generic snippets.

## When stuck
- Verbose run: `./Build/Scripts/runTests.sh -s unit -v`
- Root `AGENTS.md` for tool provisioning (cgl/phpstan/rector ship via `netresearch/typo3-ci-workflows`)

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For comprehensive TYPO3 testing guidance including fixtures, mocking, CI setup, and runTests.sh:
> **Invoke skill:** `typo3-testing`
<!-- AGENTS-GENERATED:END skill-reference -->
