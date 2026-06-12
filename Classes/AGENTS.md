<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-06-12 -->

# AGENTS.md — Classes

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 extension following TYPO3 CGL and PSR-12
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `Classes/Controller/JobController.php` | Backend module: job list/new/create/show, snippet selectors |
| `Classes/Service/GenerationOrchestrator.php` | Pipeline driver: ingest → analyze → run generators, progress bands |
| `Classes/Understanding/DocumentAnalyzer.php` | One `ContentBrief` via nr-llm completion (map-reduce >24k chars) |
| `Classes/Pipeline/PromptSnippetResolver.php` | Resolves selected snippets, persona voices, layout `imageSize` hint |
| `Classes/Generator/AbstractGenerator.php` | Base: budget/availability guard, prompts metadata, `resolveImageSize()` |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Golden Samples (follow these patterns)
| Pattern | Reference |
|---------|-----------|
| Generator (LLM + TTS + stitching) | `Classes/Generator/PodcastGenerator.php` |
| nr-llm specialized adapter | `Classes/Generator/Image/DallEImageGenerator.php` |
| Render primitive behind interface | `Classes/Rendering/GdImageCompositor.php` |
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START setup -->
## Setup & environment
- PHP ^8.3, TYPO3 ^14.3, nr-llm ^0.12, nr-vault ^0.10
- Local dev: `ddev start && ddev install` (seeds vault key + nr-llm wiring)
- Tests/static analysis: see root `AGENTS.md` Commands — Docker runner only
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START structure -->
## Directory structure
```
Classes/
  Command/         → nr_repurpose:generate CLI
  Controller/      → Backend module (JobController)
  Domain/          → Job/Artifact models, value objects (Persona, PromptSnippetSelection)
  Generator/       → Podcast/Schaubild/Story + Image/ and Speech/ adapter seams
  Ingestion/       → URL fetch, tiered PDF reader (Poppler runner)
  Persistence/     → JobProcessingRepository (direct DBAL writes from the worker)
  Pipeline/        → GenerationContext, JobProgress, PromptSnippetResolver
  Queue/           → GenerateArtifactsMessage + handler (Symfony Messenger)
  Rendering/       → Playwright HTML→PNG, GD compositor, ffmpeg stitcher
  Resource/        → FAL storage (JobFileStorage)
  Understanding/   → DocumentAnalyzer → ContentBrief
  ViewHelpers/     → PublicUrlViewHelper
```
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START commands -->
## Build & tests
See the root `AGENTS.md` Commands table — everything runs through
`./Build/Scripts/runTests.sh` (unit, functional, lint only; cgl/phpstan/rector
are NOT provisioned in this repo). No composer scripts exist.
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style & conventions
- **PSR-12** + TYPO3 CGL (Coding Guidelines)
- Strict types: `declare(strict_types=1);` in all PHP files
- Namespace: `Netresearch\NrRepurpose\` (PSR-4 from Classes/)
- Use dependency injection via `Services.yaml`, not `GeneralUtility::makeInstance()`
- Extbase conventions for domain models and repositories
- Fluid templates: use `<f:` and custom ViewHelpers
- TCA: use TYPO3 API, not raw SQL for schema
- Never use `$GLOBALS['TYPO3_DB']` (deprecated since v8)

### Naming conventions
| Type | Convention | Example |
|------|------------|---------|
| Extension key | `lowercase_underscore` | `my_extension` |
| Composer name | `vendor/ext-key` | `vendor/my-extension` |
| Namespace | `Vendor\ExtKey\` | `Vendor\MyExtension\` |
| Controller | `*Controller` | `BlogController` |
| Repository | `*Repository` | `PostRepository` |
| ViewHelper | `*ViewHelper` | `FormatDateViewHelper` |
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START security -->
## Security & safety
- **Always use QueryBuilder** or Extbase repositories - never raw SQL
- **Escape output** in Fluid: `{variable}` auto-escapes, use `<f:format.raw>` only when safe
- **CSRF protection**: use `\TYPO3\CMS\Core\FormProtection\FormProtectionFactory` for forms
- **Access checks**: use `$GLOBALS['BE_USER']->check()` for backend
- **File handling**: use FAL (File Abstraction Layer), never direct file paths
- **Never trust user input**: validate via Extbase validators or custom validation
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR/commit checklist
- [ ] `./Build/Scripts/runTests.sh -s unit` and `-s functional` pass
- [ ] No version bump in feature PRs (releases are a separate flow)
- [ ] TCA changes have matching SQL in ext_tables.sql
- [ ] Documentation updated in Documentation/
- [ ] No deprecated TYPO3 APIs (run Extension Scanner)
- [ ] Tested on target TYPO3 versions (^14.3)
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Patterns to Follow
> **Prefer looking at real code in this repo over generic examples.**
> See **Golden Samples** section above for files that demonstrate correct patterns.
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START upgrade -->
## TYPO3 upgrade considerations
- Run **Extension Scanner** before upgrading: Backend → Upgrade → Scan Extension Files
- Rector is NOT provisioned in this repo (no config, not in require-dev)
- Check **deprecation log** in TYPO3 backend
- Review [TYPO3 Changelog](https://docs.typo3.org/c/typo3/cms-core/main/en-us/Index.html) for breaking changes
<!-- AGENTS-GENERATED:END upgrade -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- TYPO3 Documentation: https://docs.typo3.org
- TCA Reference: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/
- Core API: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/
- Extbase Guide: https://docs.typo3.org/m/typo3/book-extbasefluid/main/en-us/
- Check existing patterns in EXT:core or EXT:backend
- Review root AGENTS.md for project-wide conventions
<!-- AGENTS-GENERATED:END help -->

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For TYPO3 extension standards, TER compliance, and conformance checks:
> **Invoke skill:** `typo3-conformance`
<!-- AGENTS-GENERATED:END skill-reference -->
