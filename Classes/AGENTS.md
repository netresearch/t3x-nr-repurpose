<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-09-28 -->

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

## Utilities (check before creating new)
Hand-maintained (outside the generated blocks). Reuse these before writing a helper; signatures are in the files.

| Need | Use | Location |
|------|-----|----------|
| Budget + availability guard for a TTS/image call | `specializedAllowed()` | `Classes/Generator/AbstractGenerator.php` |
| `prompts` block of artifact metadata | `promptsMetadata()` | `Classes/Generator/AbstractGenerator.php` |
| AI-origin label (ADR-005) | `provenance()` → `AiProvenance` | `Classes/Generator/AbstractGenerator.php` |
| Layout `imageSize` hint, validated | `resolveImageSize()` | `Classes/Generator/AbstractGenerator.php` |
| Branded HTML template, temp dir, failed artifact | `renderTemplate()`, `makeTempDir()`, `failArtifact()` | `Classes/Generator/AbstractGenerator.php` |
| New text format (one structured completion + parse) | extend `AbstractTextGenerator` | `Classes/Generator/AbstractTextGenerator.php` |
| Cut text at a sentence boundary | `TextLimiter::cut()` → `TextCut`, `truncate()` → string | `Classes/Generator/Support/` |
| "Q:"/"A:"/"Subject:" in the text's language | `TextLabels::get()` | `Classes/Generator/Support/TextLabels.php` |
| WebVTT from segments + durations | `WebVttBuilder::build()` | `Classes/Generator/Support/WebVttBuilder.php` |
| LLM answered in an unusable shape | `InvalidLlmOutputException` | `Classes/Generator/Support/` |
| Run node/ffmpeg from a renderer | `ProcessRunnerInterface::run()` | `Classes/Rendering/Process/` |
| Run pdftoppm/pdftotext | `PopplerRunnerInterface` | `Classes/Ingestion/Poppler/` |
| Source URL in a message or log line (no user info, query, fragment) | `SourceUrlRedactor::redact()` | `Classes/Ingestion/SourceUrlRedactor.php` |
| Source URL that is published (no user info; query and fragment kept) | `SourceUrlRedactor::withoutCredentials()` | `Classes/Ingestion/SourceUrlRedactor.php` |
| Store bytes in FAL (+ AI marker) | `JobFileStorage::store()` | `Classes/Resource/JobFileStorage.php` |
| Embed AI marker in PNG/MP3/VTT/PDF | `AiContentMarker::mark()` | `Classes/Provenance/AiContentMarker.php` |

<!-- AGENTS-GENERATED:START setup -->
## Setup & environment
- PHP ^8.3, TYPO3 ^14.3; nr-llm/nr-vault floors: see `composer.json` (do not pin versions here)
- Local dev: `ddev start && ddev setup` (seeds the provider key + nr-llm wiring)
- Tests/static analysis: see root `AGENTS.md` Commands — Docker runner only
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START structure -->
## Directory structure
```
Classes/
  Command/         → nr_repurpose:generate and nr_repurpose:publish-due CLI
  Controller/      → Backend module (JobController)
  Domain/          → Job/Artifact models, repositories, enums, value objects (Persona, PromptSnippetSelection)
  Exception/       → empty-artifact, FAL storage and Poppler failure exceptions
  Generator/       → Podcast/Schaubild/Story, text formats (AbstractTextGenerator), slide deck + handout (AbstractDocumentGenerator), Support/ helpers + Image/ and Speech/ adapter seams
  Ingestion/       → URL fetch, tiered PDF reader (Poppler runner)
  Persistence/     → JobProcessingRepository (direct DBAL writes from the worker)
  Pipeline/        → GenerationContext, JobProgress, PromptSnippetResolver
  Provenance/      → AI label: AiProvenance, AiContentMarker (PNG/MP3/VTT/PDF markers), AiLabelSettingsFactory (ADR-005)
  Queue/           → GenerateArtifactsMessage + handler (Symfony Messenger)
  Rendering/       → Playwright HTML→PNG/PDF, GD compositor, ffmpeg stitcher and slideshow, process runner
  Resource/        → FAL storage (JobFileStorage)
  Review/          → artifact review/approval (ArtifactReviewService, ReviewPermission)
  Service/         → GenerationOrchestrator, JobSubmissionService, capability grants, nr-llm presets/use cases
  Social/          → scheduled social-post publishing (DuePostPublisher, WebhookSocialPublisher)
  Understanding/   → DocumentAnalyzer → ContentBrief
  ViewHelpers/     → PublicUrlViewHelper
```
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START commands -->
## Build & tests
See the root `AGENTS.md` Commands table — tests run through
`./Build/Scripts/runTests.sh` (unit, functional, lint, cgl, phpstan). The CI
tools ship via `netresearch/typo3-ci-workflows` (require-dev) into
`.Build/bin/`; composer scripts: `ci:cgl`, `ci:rector`,
`ci:test:php:{cgl,phpstan,rector,unit,functional}`.
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
- Rector is provisioned: config `Build/rector.php`, dry-run via `composer ci:test:php:rector`, fix via `composer ci:rector`
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
