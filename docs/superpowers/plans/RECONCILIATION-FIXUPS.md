# Plans 2–6 — Reconciliation Fixups

A consistency agent reviewed Plans 1–6 against the contracts + grounding after the
parallel authoring. Verdict: contracts/types/columns/nr-llm APIs are consistent across
plans; 5 issues to apply **during execution** of the affected plan. Decisions below are authoritative.

## 1. HIGH — template variable contract (Plan 5 ↔ Plan 6)

Plan 6 Task 7 rewrote the NR theme templates to read `brief.*`, but the Plan 5 generators
assign **flat** variables. **Decision: standardise on the flat contract** (simpler, matches
what the generators actually produce):

- `Generated/Schaubild/{Nr,Neutral}.html` consume `{title}`, `{bodyHtml}`, `{transparent}`.
- `Generated/Story/{Nr,Neutral}.html` consume `{headline}`, `{subline}`, `{transparent}`.

When executing Plan 6 Task 7: edit the templates to the flat vars (NOT `brief.*`), and make
`NrThemeTemplateTest` assign exactly that flat set (so a future mismatch fails the test).

## 2. MEDIUM — stub de-registration (Plan 5 Task 7)

`tags: []` does NOT remove a tag applied via `_instanceof` (Symfony merges them).
**Decision:** in Plan 5 Task 7, de-register the stub with
`Netresearch\NrRepurpose\Generator\StubArtifactGenerator: { autoconfigure: false }`
(drop the `tags: []` edit). Keep the single `_instanceof` tag mechanism authoritative.

## 3. MEDIUM — ChatOptions read-back (Plan 3 ↔ Plan 5) — VERIFIED

Checked `t3x-nr-llm/main/Classes/Service/Option/ChatOptions.php` + `BudgetFieldsTrait.php`:
constructor params are **not** public; the read surface is **public getters**
(`getResponseFormat()`, `getSystemPrompt()`, `getBeUserUid()`, `getPlannedCost()`, …).
**Decision:** Plan 3 (getters) is correct; in Plan 5 fix `PodcastGeneratorTest` (and any
sibling) to assert via getters, not `->responseFormat`/`->beUserUid` property access.

## 4. LOW — `artifacts int` column in Plan 1 schema — KEEP (override)

The reconciliation flagged the parent `artifacts` int column as dead. **Decision: keep it.**
A parent counter column alongside a TCA `type=inline` (foreign_field) relation is the
conventional, schema-analyzer-safe TYPO3 pattern; removing it risks FormEngine surprises.
Harmless. No change.

## 5. LOW — brittle `HEAD~12` isolation guard (Plan 2 Task 13)

A fixed commit offset is fragile. **Decision:** before starting Plan 2 execution, run
`git tag plan1-done`; in Plan 2 Task 13 diff against the tag
(`git diff --name-only plan1-done -- Classes/Service Classes/Queue Classes/Generator`)
instead of `HEAD~12`.
