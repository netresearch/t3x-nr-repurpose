<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-06-12 | Last verified: 2026-06-12 -->

# AGENTS.md

**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Commands (verified 2026-06-12)
> ALWAYS via the Docker test runner — NEVER `phpunit`/`php-cs-fixer` directly.

<!-- AGENTS-GENERATED:START commands -->
| Task | Command | ~Time |
|------|---------|-------|
| Unit tests | `./Build/Scripts/runTests.sh -s unit` | ~30s |
| Functional tests (sqlite) | `./Build/Scripts/runTests.sh -s functional` | ~1min |
| Functional vs MariaDB | `./Build/Scripts/runTests.sh -s functional -d mariadb` | ~2min |
| PHP lint | `./Build/Scripts/runTests.sh -s lint` | ~20s |
| Pin PHP version | `./Build/Scripts/runTests.sh -p 8.3 -s unit` (default 8.5) | — |
| Reinstall deps | `./Build/Scripts/runTests.sh -s composerUpdate` | ~2min |
<!-- AGENTS-GENERATED:END commands -->

> **PHPStan, cgl, rector and lint all run here.** The tools come in through
> `netresearch/typo3-ci-workflows`, which is in `require-dev` — a plain install
> puts `phpstan`, `php-cs-fixer` and `rector` into `.Build/bin/`.
>
> PHPStan runs at level 8 over `Classes` only (`phpstan.neon`), with `Tests`
> and level 10 deliberately not enabled yet: level 10 on both costs about 280
> findings, and a baseline that size hides every new one. `ci.yml` still sets
> `run-cgl: false` and `run-functional-tests: false`.
>
> This paragraph used to say those tools were "not provisioned" and told you
> not to investigate the failure. That was true when the template was adopted
> and stopped being true when the shared package started shipping them; the
> instruction to stop looking is why nobody noticed for two months. If a tool
> reports "config file does not exist", that is a missing config, not a
> missing tool — check before concluding.

## Response Style
- Answer first, elaborate only if needed. No sycophantic openers ("Great question!", "Absolutely!").
- For yes/no or status questions, lead with the answer.
- Skip preamble. Match response length to task complexity.

## Workflow
1. **Before coding**: Read nearest `AGENTS.md` + check Golden Samples for the area you're touching
2. **After each change**: Run the smallest relevant check (lint → typecheck → single test)
3. **Before committing**: Run full test suite if changes affect >2 files or touch shared code
4. **Before claiming done**: Run verification and **show output as evidence** — never say "try again", "should work now", "tested", "verified", or "all green" without pasted command output in the same turn

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

## Heuristics (quick decisions)
<!-- AGENTS-GENERATED:START heuristics -->
| When | Do |
|------|-----|
| Adding a generator | Extend `Classes/Generator/AbstractGenerator.php`; register in `Configuration/Services.yaml` |
| Swapping image/TTS backend | New adapter behind `ImageGeneratorInterface` / `SpeechSynthesizerInterface`; change the DI alias in `Configuration/Services.yaml` — never bypass nr-llm |
| Changing models/prompts | Edit nr-llm Configuration records (`nr_repurpose_image`, `nr_repurpose_tts`, instance default for text) — never hardcode model ids beyond documented fallbacks |
| Steering generation | nr-llm prompt snippets, tags `audience` / `tone_of_voice` / `persona` / `layout` / `style`; layout metadata `{"imageSize":"WxH"}` drives AI-image dimensions |
| Committing | Conventional Commits + `git commit -S -s` (DCO + SSH signing enforced) |
| Merging a PR | `--merge`, directly — this repo has NO merge queue; gate: threads resolved + checks green + no in-flight review |
| Running locally | `ddev start` + `ddev install` (seeds the provider key, see README) |
| Adding dependency | Ask first — we minimize deps |
<!-- AGENTS-GENERATED:END heuristics -->

## Repository Settings
<!-- AGENTS-GENERATED:START repo-settings -->
- **Default branch:** `main`
- **Merge strategy:** merge
- **Active rulesets:** Copilot review for default branch
<!-- AGENTS-GENERATED:END repo-settings -->

<!-- AGENTS-GENERATED:START ci-rules -->
## CI (reusable netresearch/typo3-ci-workflows)
- Matrix: PHP 8.3 / 8.4 / 8.5 × TYPO3 ^14.3 — Lint + Unit Tests per version
- `run-phpstan: true` — level 8 over `Classes`, config in `phpstan.neon`
- `run-cgl: false`, `run-functional-tests: false` — the tools are installed,
  these gates are simply not switched on yet
- Plus: security (Opengrep SAST, composer audit), license-check, CodeQL, SonarCloud, DCO
- Release: signed annotated tag `vX.Y.Z` triggers `release.yml` (skip-ter/packagist/docs set — not published there yet)
<!-- AGENTS-GENERATED:END ci-rules -->

## Boundaries

### Always Do
- Run pre-commit checks before committing
- Add tests for new code paths
- Use conventional commit format: `type(scope): subject`
- Use **atomic commits** (one logical change per commit); preserve signatures, keep bisection useful
- **Show test output as evidence before claiming work is complete** — never say "try again", "should work now", "tested", "verified", or "all green" without pasted command output
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
- Commit composer.lock without composer.json changes
- Modify core framework files

## Contributing (for AI agents)
- **Comprehension**: Understand the problem before submitting code. Read the linked issue, understand *why* the change is needed, not just *what* to change.
- **Context**: Every PR must explain the trade-offs considered and link to the issue it addresses. Disclose AI assistance if the project requires it.
- **Continuity**: Respond to review feedback. Drive-by PRs without follow-up will be closed.

## Architecture (pipeline)
<!-- AGENTS-GENERATED:START codebase-state -->
ingest (`Classes/Ingestion/`: URL fetch or tiered PDF reader) → analyze
(`Classes/Understanding/DocumentAnalyzer` → one `ContentBrief` via nr-llm
completion, map-reduce above 24k chars) → generate (`Classes/Generator/`:
podcast with 1–3 persona speakers, Schaubild ×3 variants, story ×N slides;
async via Symfony Messenger doctrine transport, worker needs ffmpeg +
chromium + poppler) → store in FAL (`repurpose/` folder). ALL AI calls go
through nr-llm — this extension contains zero provider code; the keys belong to
nr-llm (identifier `nr_repurpose_openai` on the live instance).
<!-- AGENTS-GENERATED:END codebase-state -->

## Scoped AGENTS.md (MUST read when working in these directories)
<!-- AGENTS-GENERATED:START scope-index -->
- `./Classes/AGENTS.md` — PHP source: generators, pipeline, nr-llm seams
- `./Tests/AGENTS.md` — unit + functional suites via runTests.sh
<!-- AGENTS-GENERATED:END scope-index -->

> **Agents**: When you read or edit files in a listed directory, you **must** load its AGENTS.md first. It contains directory-specific conventions that override this root file.

## When instructions conflict
The nearest `AGENTS.md` wins. Explicit user prompts override files.
- For PHP-specific patterns, follow PSR standards
