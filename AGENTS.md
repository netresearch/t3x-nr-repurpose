<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-09-29 | Last verified: 2026-09-29 -->

# AGENTS.md

**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Overview
TYPO3 v14 extension `nr_repurpose` (`netresearch/nr-repurpose`): turns a URL or PDF into a podcast, a diagram (Schaubild), a story carousel (optionally as MP4 video), four text formats, and a slide deck and a handout (PDF) from the TYPO3 backend. Every AI call goes through nr-llm. Pipeline: see `## Architecture` below; component map in `docs/ARCHITECTURE.md`; user docs in `Documentation/`; human contributor flow in `CONTRIBUTING.md`.

## Setup / Environment
```bash
cp .ddev/.env.dist .ddev/.env   # set OPENAI_API_KEY — the only required key
ddev start && ddev setup        # TYPO3 v14.3 into .Build/Web, key stored in nr-vault as nr_repurpose_openai
```
- Tests need only Docker plus, on the very first run, `composer` on the host: `Build/Scripts/runTests.sh` is a stub that runs `composer install` to fetch the shared runner into `.Build/bin/`.
- `OPENAI_API_KEY` is dev-only; `.ddev/commands/web/setup` reads it (`ddev install` is an alias of `ddev setup`). The extension never reads a key — production keys live in nr-llm/nr-vault.
- `CHROMIUM_PATH`: `render.cjs` reads it from its environment. `Classes/Rendering/PlaywrightHtmlToImageRenderer.php` passes its `chromiumPath` argument (default `/usr/bin/chromium`) to the child explicitly through `ProcessRunnerInterface::run()`'s `$env`, so the worker does not need to export it; another Chromium path can be set as the `$chromiumPath` argument of that service in `Configuration/Services.yaml`. Worker binaries (node, chromium, ffmpeg, poppler): see `Documentation/Installation/Index.rst`.

## Commands (verified 2026-09-28)
> ALWAYS via the Docker test runner — NEVER `phpunit`/`php-cs-fixer` directly.

<!-- AGENTS-GENERATED:START commands -->
| Task | Command | ~Time |
|------|---------|-------|
| Unit tests | `./Build/Scripts/runTests.sh -s unit` | ~5s |
| Functional tests (sqlite) | `./Build/Scripts/runTests.sh -s functional` | ~30s |
| Functional vs MariaDB | `./Build/Scripts/runTests.sh -s functional -d mariadb` | ~1.5min |
| PHP lint | `./Build/Scripts/runTests.sh -s lint` | ~5s |
| PHPStan | `./Build/Scripts/runTests.sh -s phpstan` | ~10s |
| Code style check | `./Build/Scripts/runTests.sh -p 8.3 -s cgl -n` (drop `-n` to fix) | ~5s |
| Rector check | `./Build/Scripts/runTests.sh -s rector -n` (drop `-n` to apply) | ~5s |
| Fractor check (Fluid, TypoScript, YAML, XML/XLIFF, .htaccess) | `./Build/Scripts/runTests.sh -s fractor -n` (drop `-n` to apply) | ~5s |
| Pin PHP version | `./Build/Scripts/runTests.sh -p 8.3 -s composerUpdate`, then `-p 8.3 -s <suite>` (default 8.5) | ~1min |
| Reinstall deps | `./Build/Scripts/runTests.sh -s composerUpdate` | ~1min |
<!-- AGENTS-GENERATED:END commands -->

- Dependencies are resolved for one PHP version: after `-s composerUpdate` on 8.5, every `-p 8.3` suite except `lint` dies in Composer's platform check (`requires a PHP version ">= 8.4.1"`). Re-run `composerUpdate` with the same `-p` first.
- The tools (`phpstan`, `php-cs-fixer`, `rector`, `fractor`) come from `netresearch/typo3-ci-workflows` (require-dev) into `.Build/bin/`. Configs: `Build/phpstan.neon` is the entry point the runner and `composer ci:test:php:phpstan` use; it includes `phpstan.neon` (analysis settings: level 8, `Classes` only — level 10 plus `Tests` costs ~280 findings) and adds the phpat architecture rules from `Tests/Architecture/`. The runner's note that the root `phpstan.neon` is ignored is expected, since `Build/phpstan.neon` includes it. Also `.php-cs-fixer.dist.php`, `Build/rector.php`, `Build/fractor.php`. "config file does not exist" means a missing config, not a missing tool.
- **Run cgl on PHP 8.3** — CI's cgl job takes the first `php-versions` entry, and formatting on a newer runtime can produce output that job then rejects.

## Workflow
1. **Before coding**: Read nearest `AGENTS.md` + check Golden Samples for the area you're touching
2. **After each change**: Run the smallest relevant check (lint → typecheck → single test)
3. **Before committing**: Run full test suite if changes affect >2 files or touch shared code
4. **Response style**: answer first, no sycophantic openers; match response length to task complexity
5. **Before claiming done**: Run verification and **show output as evidence** — never say "try again", "should work now", "tested", "verified", or "all green" without pasted command output in the same turn

## File Map
<!-- AGENTS-GENERATED:START filemap -->
```
Classes/         → PHP classes (PSR-4)
Tests/           → test suites
Resources/       → templates and assets
Documentation/   → documentation (RST/MD)
Configuration/   → framework configuration
Build/           → project files
```
<!-- AGENTS-GENERATED:END filemap -->

## Golden Samples (follow these patterns)
<!-- AGENTS-GENERATED:START golden-samples -->
| For | Reference | Key patterns |
|-----|-----------|--------------|
| Controller | `Classes/Controller/JobController.php` | backend module, snippet selectors |
| Generator | `Classes/Generator/PodcastGenerator.php` | LLM + specialized calls, personas |
| Test | `Tests/Functional/Persistence/JobProcessingRepositoryTest.php` | DB fixtures |
<!-- AGENTS-GENERATED:END golden-samples -->

## Utilities (check before creating new)
Shared helpers — generator base methods, `Classes/Generator/Support/*` (text limits, labels, WebVTT), process runner, FAL storage, AI markers: see `Classes/AGENTS.md` § Utilities before writing a new one.

## Heuristics (quick decisions)
<!-- AGENTS-GENERATED:START heuristics -->
| When | Do |
|------|-----|
| Adding a generator | Extend `Classes/Generator/AbstractGenerator.php`; register in `Configuration/Services.yaml` |
| Swapping image/TTS backend | New adapter behind `ImageGeneratorInterface` / `SpeechSynthesizerInterface`; change the DI alias in `Configuration/Services.yaml` — never bypass nr-llm |
| Changing models/prompts | Edit nr-llm Configuration records (`nr_repurpose_image`, `nr_repurpose_tts`, instance default for text) — never hardcode model ids beyond documented fallbacks |
| Steering generation | nr-llm prompt snippets, tags `audience` / `tone_of_voice` / `persona` / `layout` / `style`; layout metadata `{"imageSize":"WxH"}` drives AI-image dimensions |
| Committing | Conventional Commits + `git commit -S -s` (DCO + SSH signing enforced) |
| Merging a PR | `--merge`, directly — this repo has NO merge queue; gate: 1 approving review + threads resolved + checks green + no in-flight review |
| Running locally | See `## Setup / Environment` above |
| Adding dependency | Ask first — we minimize deps |
<!-- AGENTS-GENERATED:END heuristics -->

## Repository Settings
<!-- AGENTS-GENERATED:START repo-settings -->
- **Default branch:** `main`
- **Merge strategy:** merge
- **Active rulesets:** Copilot review, require-signed-commits, t3x-baseline (required status checks), t3x-pull-request
<!-- AGENTS-GENERATED:END repo-settings -->

<!-- AGENTS-GENERATED:START ci-rules -->
## CI (reusable netresearch/typo3-ci-workflows)
- `ci.yml` sets `run-cgl`, `run-phpstan`, `run-rector`, `run-fractor`, `run-unit-tests`, `run-functional-tests` and `upload-coverage: true`; matrix PHP 8.3 / 8.4 / 8.5 × TYPO3 ^14.3
- Per PHP version: lint, PHPStan (with the phpat rules), unit, functional (SQLite, the reusable default); once on PHP 8.3 (first matrix entry): cgl, rector, fractor; one advisory PHPStan pass against the unpinned PHPUnit (warns, does not fail); unit and functional coverage go to Codecov. The functional command installs ffmpeg, poppler-utils, a Chromium at `/usr/bin/chromium` and the NodeRenderer's `node_modules`, and runs with `--fail-on-skipped`: a real-binary test that skips for a missing binary fails the job
- `lowest-dependencies` job (PHP 8.3): `composer update --prefer-lowest` of every declared package except `netresearch/typo3-ci-workflows` and `phpunit/*`, then unit tests and PHPStan. `mutation` job: Infection (`infection.json.dist`) through the shared `fuzz.yml`, on pull requests and the weekly schedule; reports only (continue-on-error), thresholds 65 % MSI and covered MSI. `docs.yml` renders `Documentation/` through the shared docs workflow
- `checks.yml` (drift-enforced): security (Opengrep SAST, composer audit), betterleaks, zizmor, fuzz, license-check, CodeQL, Scorecard, dependency-review, pr-quality — all behind one required `All security checks` gate; SonarCloud + DCO run as apps
- Release: signed annotated tag `vX.Y.Z` triggers `release.yml`, which publishes to TER, verifies Packagist, then creates the GitHub release with Cosign-signed artifacts; the tag push itself triggers the docs.typo3.org render through the Intercept webhook, and the release only checks that Intercept accepted the render (a render run exists), without waiting for its result or gating on it
- `republish.yml` (manual, `tag` + `target`): re-uploads to TER only if the version is missing there (the TER metadata sync runs either way), only checks that Packagist lists the version and that Intercept has a render run for it, never touches the GitHub release — details in `CONTRIBUTING.md` § Releasing
<!-- AGENTS-GENERATED:END ci-rules -->

## Boundaries

### Always Do
- Run pre-commit checks before committing
- Add tests for new code paths
- Use conventional commit format: `type(scope): subject`
- Use **atomic commits** (one logical change per commit); preserve signatures, keep bisection useful
- Before any edit, verify `pwd` resolves inside the intended repo worktree — not `.bare/`, not `~/.claude/skills/…`, not `~/.claude/plugins/cache/…` (those are read-only caches that get clobbered on update)
- For upstream dependency fixes: run **full** test suite, not just affected tests
- Force-push only with `--force-with-lease`
- Follow PSR-12 coding standards and PHP ^8.3 features

### Ask First
- Adding new dependencies
- Modifying CI/CD configuration
- Changing public API signatures
- Running full e2e test suites
- Repo-wide refactoring or rewrites
- Operations that touch >3 repos (produce a dry-run plan first)

### Never Do
- Commit secrets, credentials, or sensitive data
- Modify vendor/, node_modules/, or generated files
- Push directly to main/master branch — open a PR
- Merge a PR before all review threads are resolved
- Squash commits during merge or rebase unless the user explicitly asked
- Edit installed skill/plugin cache paths (`~/.claude/skills/`, `~/.claude/plugins/cache/`, `**/.bare/**`) — always the source worktree
- Reply to review comments with bare "Addressed" or "Fixed" — cite the resolving commit SHA
- Delete migration files or schema changes
- Use `secrets: inherit` in reusable GitHub Actions workflows (pass secrets explicitly)
- Commit a `composer.lock` — this extension deliberately has none

## Architecture (pipeline — component map: `docs/ARCHITECTURE.md`)
<!-- AGENTS-GENERATED:START codebase-state -->
ingest (`Classes/Ingestion/`: URL fetch or tiered PDF reader) → analyze (`Classes/Understanding/DocumentAnalyzer` → one `ContentBrief` via nr-llm completion, map-reduce above 24k chars) → generate (`Classes/Generator/`: podcast with 1–3 persona speakers, Schaubild ×3 variants, story ×N slides (+ optional MP4), four text formats, slide deck + handout PDFs; async via Symfony Messenger doctrine transport, worker needs ffmpeg, chromium, poppler) → store in FAL (`repurpose/` folder).
ALL AI calls go through nr-llm — this extension contains zero provider code; the keys belong to nr-llm (identifier `nr_repurpose_openai` on the live instance).
<!-- AGENTS-GENERATED:END codebase-state -->

## Scoped AGENTS.md (MUST read when working in these directories)
<!-- AGENTS-GENERATED:START scope-index -->
- `./Classes/AGENTS.md` — PHP source: generators, pipeline, nr-llm seams
- `./Tests/AGENTS.md` — unit + functional suites via runTests.sh
<!-- AGENTS-GENERATED:END scope-index -->

> **Agents**: When you read or edit files in a listed directory, you **must** load its AGENTS.md first. It contains directory-specific conventions that override this root file. Explicit user prompts override both.
