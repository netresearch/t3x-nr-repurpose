# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Every artifact is labelled as AI-generated** (NEXT-182). Published synthetic audio, images and text must be marked in a machine-readable format and be detectable as artificially generated (EU AI Act, Art. 50(2)). Each stored file now carries the marker itself, written in PHP when it is stored, after the last re-encode: the podcast MP3 gets an ID3v2.3 tag with `TXXX:AI-generated = true`, `TXXX:DigitalSourceType`, `TSSE` and a `COMM` comment (it replaces the ID3v2.4 tag ffmpeg writes, which held only ffmpeg's own `TSSE`); every PNG gets `tEXt` `Software`/`Comment` chunks and an XMP packet with the IPTC `DigitalSourceType` — `trainedAlgorithmicMedia` for the full AI image, `compositeWithTrainedAlgorithmicMedia` for the HTML renders. A PNG that already carries a C2PA manifest is stored unchanged, because any change would break its signature. Every stored file (MP3, WebVTT, PNG) also gets a `sys_file_metadata.description` naming the AI origin, and every artifact row, the text formats included, an `aiLabel` block in its metadata (`aiGenerated`, `generator`, `digitalSourceType`, and the models where known — the text formats name none, because nr-llm does not report the completion model). The result view shows an "AI-generated" badge on every finished artifact. Two extension settings control the visible labels: `aiLabelImages` (default on) renders a small "AI-generated" corner label, in the artifact's language, into the Schaubild HTML renders and the story slides — the full AI image is never rendered from HTML and carries the machine-readable marker only; `aiLabelTexts` (default off) appends "This text was created with AI." to the copy-ready text of the text formats, off by default because those texts usually land in a page, newsletter tool or social network with its own disclosure. The social posts reserve the line's length inside their platform limit, and the FAQ JSON-LD is not changed. No database column is added. See ADR-005.

## [0.6.0] - 2026-09-25

### Security

- **Source text can no longer pose as an instruction in any LLM call** (NEXT-182). The podcast script, the Schaubild diagram body, the story copy and the document analysis put the source-derived text — the raw document, the chunk summaries, the brief — into the same user prompt as their own task, so text in a source could read as an instruction (CWE-1427). They now follow the boundary the text formats introduced: the task, the output rules, the editor's snippets and a validated output language sit in the system prompt, and the source material reaches the model only as one `<source_material>` block introduced as untrusted data, with tag-like `<source…` sequences in it neutralised. One helper (`SourceMaterial`) serves all seven generators and the analyzer. The JSON and HTML the calls return are unchanged; the prompts recorded in each artifact's metadata show the new split. Image-generation prompts and the PDF vision OCR are not covered — see ADR-004.

### Added

- **Four text formats: executive summary, FAQ, social posts and newsletter text** (NEXT-182). Each is an opt-in checkbox on the job form — off by default, so an upgraded installation makes no additional LLM calls until an editor ticks one — and one schema-validated nr-llm call (`completeStructured()`, one repair round-trip on a mismatch), written in the source language and steered by the *audience* and *tone of voice* snippets. The executive summary is asked for five to eight sentences, key facts first, and keeps at most eight. The FAQ is asked for five to ten question/answer pairs taken only from the source, keeps at most ten, and adds a schema.org `FAQPage` JSON-LD block (with `inLanguage`) to paste into a page. The labels in the plain text (`Q:`/`A:`, `Subject:`/`Preheader:`) follow the text's language (English and German shipped, English as fallback). The social posts are one artifact per platform — LinkedIn ≤ 3000, X ≤ 280, Instagram ≤ 2200 characters including hashtags — and the limits are enforced in code by cutting at the last sentence end that fits (or at a word boundary when that would keep less than half the limit), not only asked for in the prompt; the result view says how a post was cut and how many hashtags were left out. Hashtags are split on spaces and `#`, reduced to letters, digits and underscores (`AI-driven` → `#AIdriven`) and de-duplicated. The newsletter has a subject, a preheader, plain paragraphs and exactly one call to action. The text is stored on the artifact (`script_text`, structured answer in `metadata.content`); no file is written. The format's task is in the system prompt; the source-derived brief reaches the model only as untrusted data inside `<source_material>` tags, with tag-like `<source…` sequences in it neutralised, so text in the source cannot pose as an instruction or close the block. An unusable answer fails only that artifact, with the reason. The text formats make no speech or image call and need neither `generate_audio` nor `generate_vision`. Four `want_*` columns (default 0) are added to `tx_nrrepurpose_domain_model_job` — run the database analyzer after the update. See ADR-004.

## [0.5.3] - 2026-09-24

### Changed

- **Accept nr-llm 0.37.** `composer.json` requires `netresearch/nr-llm` at `^0.35 || ^0.36 || ^0.37`, and `ext_emconf.php` declares `nr_llm 0.35.0-0.37.99` to match. On a 0.x version `^0.36` does not admit 0.37.0, so this extension kept an installation from moving to nr-llm 0.37. The floor stays at 0.35.

## [0.5.2] - 2026-09-23

### Changed

- **Accept nr-llm 0.36.** `composer.json` requires `netresearch/nr-llm` at `^0.35 || ^0.36`, and `ext_emconf.php` declares `nr_llm 0.35.0-0.36.99` to match. On a 0.x version `^0.35` does not admit 0.36.0, so this extension kept an installation from moving to nr-llm 0.36. nr-llm 0.36.0 adds tools and guards and changes nothing this extension calls; the floor stays at 0.35.
- The README states the nr-llm range the extension requires (#115).
- CI synced with the `netresearch/.github` TYPO3 extension template (#116, #118).
- `.bestpractices.json` records the OpenSSF Best Practices badge answers with their evidence (#119).

### Fixed

- **The permissions `generate_audio` and `generate_vision` are enforced** (NEXT-182, #120). They were registered as `customPermOptions` and documented as gating AI spend per backend group, but nothing checked them, so every editor could generate podcast audio and AI imagery. The worker now resolves the job owner's grants once per run: without `generate_audio` the podcast artifact fails before any call; without `generate_vision` the Schaubild's two AI image variants fail (the HTML variant is still produced) and the story is rendered on flat backgrounds. Administrators hold both. Editors whose groups do not carry the options lose these artifacts after the update — grant them in the backend group's "Custom module options" to keep the previous behaviour.

## [0.5.1] - 2026-09-17

### Fixed

- **v0.5.0 shipped contradictory dependency metadata.** `composer.json` required `netresearch/nr-llm` at `^0.35` — the code needs it — while `ext_emconf.php` still declared `nr_llm 0.34.0-0.34.99`. A TYPO3 extension states the same dependency in both places, so Composer would install nr-llm 0.35 and the Extension Manager would refuse to activate against it, which is a failure that is invisible in this repository and surfaces at install time on somebody else's instance. The range is now `0.35.0-0.35.99`, matching the exclusive `^0.35`: this extension does not run on 0.34 any more.

## [0.5.0] - 2026-09-17

### Fixed

- **The `nr_repurpose_text` preset was declared twice, which 500s the whole nr_llm Configurations module.** `RepurposeConfigurationPresetProvider::getPresets()` returned the text preset AND `RepurposeStarterPackProvider`'s pack carries the same one, and nr-llm republishes every pack's configuration preset into the same registry through `UseCasePackPresetProvider` (ADR-163, nr-llm 0.29+). That registry refuses a duplicate identifier with `LogicException #1789347004`, so `/typo3/module/nrllm/configurations` answered 500 for every admin — not a degraded preset list, the module. Observed on the demo instance. The provider no longer returns it; `textPreset()` stays as the static factory the pack uses, so the preset itself is unchanged and still reaches the registry exactly once. Two unit cases now pin it, and one of them asserts the rule rather than the instance: no identifier may be declared both directly and by a pack. The case that previously asserted the text preset WAS in `getPresets()` pinned the defect, so it is rewritten rather than kept.

- **A string reached `ConfigurationResolver::getActiveByIdentifier()`, which nr-llm 0.35 narrowed to a value object.** `ConfiguredCompletionService` passed `self::CONFIGURATION` directly; under nr-llm 0.35 (#893, second step) that parameter is a `ConfigurationIdentifier` with no string union, so the call raised a `TypeError`. The surrounding `catch (NrLlmExceptionInterface)` does not catch a `TypeError`, so the fail-soft fallback to the instance-default configuration would have been bypassed and the whole text pipeline would have died instead of degrading. nr-llm's own release notes state that this change is invisible outside that extension; this call site is the counter-example.

- **A skipped GD test reported an error instead of a skip.** `GdImageCompositorTest::setUp()` calls `markTestSkipped()` when ext-gd is absent, but PHPUnit still runs `tearDown()`, which read an uninitialised typed `$tmpDir` — so on any machine without ext-gd the class produced six *errors* where it meant six skips. The property is nullable and `tearDown()` returns early. Nothing about the compositor changes; the suite now says what it means, which matters because six standing errors hide a real one.

### Changed

- **`netresearch/nr-llm` is required at `^0.35`.** The value-object parameter above exists only from 0.35, so the floor moves with the call rather than after it. `^0.34` would have installed a version this code cannot call.

## [0.4.9] - 2026-09-03

### Fixed

- **The `nr_repurpose_text` preset asks for `chat` and nothing else.** It required `ModelCapability::JSON_MODE` as well, which made it unimportable on every installation: no model discoverer in nr-llm assigns that capability to anything, so `ModelSelectionService` found no candidate and the import was refused with "no active model satisfies its configuration requirement". That also blocked the Content Repurpose Starter pack, which carries this preset — observed on the demo instance, netresearch/typo3-demo#236. The pipeline still asks for JSON on every completion through `responseFormat: 'json'`, which nr-llm passes to the provider as a plain option and gates on no capability, so nothing about the generated output changes.
- **`ext_emconf.php` declares `nr_vault`.** `composer.json` requires `netresearch/nr-vault: ^0.15` and `constraints.depends` named only `typo3` and `nr_llm`, so an Extension Manager or TER install could activate this extension without nr-vault. Every API key it uses is resolved through nr-vault by nr-llm, so the failure surfaced as a provider error at generation time rather than at install time (#104).


## [0.4.8] - 2026-09-03

### Added

- **The Content Repurpose Starter use-case pack.** The job form's five selectors — audience, tone of voice, persona, layout, style — read prompt snippets by tag, and a fresh installation has none, so every one of them shows "(none)" and the steering the form advertises is invisible until somebody hand-writes thirteen records in nr-llm's Snippets module. The pack installs a starting library instead: two audiences, two tones, three podcast personas each with its own TTS `voice`, three layouts each with the `imageSize` that drives the AI-image dimensions, and three visual styles. Install it in nr-llm's Use Case Packs module, or with `vendor/bin/typo3 nrllm:usecasepack:install content-repurpose-starter` from a provisioning script. The records are ordinary snippets — rename, rewrite or deactivate them; a second install creates only what is missing and leaves edits alone.
- Every snippet in the pack declares `composedByConfiguration: false` (nr-llm ADR-186). This extension resolves all five families by uid, per job, from the form's selection; letting the installer link their tags to `nr_repurpose_text` would additionally compose every active persona, layout and style into every completion on that configuration — three speakers the job did not choose, and two contradictory image sizes.

### Changed

- Requires `netresearch/nr-llm` `^0.34`. The floor rises because the pack needs both fields 0.34.0 adds to `PackSnippet`: `metadata`, without which a persona ships without its voice and a layout without its image size, and `composedByConfiguration`, without which shipping these snippets at all is the prompt defect described above. `ext_emconf.php` declares the same dependency and is raised with it — and its upper bound is now `0.34.99` rather than `0.99.99`, so the two agree on what this extension accepts instead of only on where it starts.
- `RepurposeConfigurationPresetProvider::textPreset()` is now a named factory the pack reuses, so the `nr_repurpose_text` preset has one definition rather than two hand-written copies of one identifier.

## [0.4.7] - 2026-08-21

### Changed

- Requires `netresearch/nr-llm` `^0.33`. The floor rises because 0.33.0 removes a regression 0.32.0 introduced: `vision()` and `embed()` handed the provider registry the `tx_nrllm_provider` row's identifier where it is keyed by the adapter's own name, so a call that names no provider — which is what this extension makes — failed with "Provider … not found" on an installation that has a perfectly good default configuration. 0.32.0 did not fix the failure it was written for, it renamed it.
- `ext_emconf.php` declares the same dependency and is raised with it, so the two cannot disagree about which versions this extension accepts.

## [0.4.6] - 2026-08-21

### Changed

- Requires `netresearch/nr-llm` `^0.32`. The floor rises because 0.32.0 is what
  makes the annotation below arrive on every path, including PDF vision, and it
  gives `vision()` and `embed()` the default-configuration fallback that `chat()`
  already had — a call naming no provider now uses the installation's default
  instead of throwing.

### Added

- Every nr-llm call names this extension and the pipeline step it belongs to
  (`withCallerSource('nr_repurpose', …)`, nr-llm ADR-177), so nr-llm's Analytics
  module attributes usage and cost to `nr_repurpose` instead of listing it as
  "Unattributed". Operations: `analyzeDocument`, `analyzeDocumentChunk`,
  `extractPdfVision`, `generatePodcast`, `generateDiagram`, `generateStory`.
  `ConfiguredCompletionService`, the funnel every text completion passes through,
  stamps the extension key on options that carry none — the operation stays with
  the call site. PDF vision is attributed too as of nr-llm 0.32.0, which stopped
  `VisionService::analyzeImageFull()` from rebuilding the options object without
  the caller source ([nr-llm#845](https://github.com/netresearch/t3x-nr-llm/issues/845)).

## [0.4.5] - 2026-08-20

### Changed

- netresearch/nr-llm requirement raised to `^0.31` (0.30 support dropped)

## [0.4.4] - 2026-08-19

### Changed

- netresearch/nr-llm requirement raised to `^0.30` (0.28/0.29 support dropped)

## [0.4.3] - 2026-08-13

### Changed

- Accepts nr-llm 0.29 alongside 0.28. Nothing here touches the three surfaces
  0.29 broke: the interface it gained a method on is consumed, never
  implemented, and neither `ContextFitResult` nor `InputSubmission` is
  constructed. The unit and functional suites run identically against both.

## [0.4.2] - 2026-08-11

### Fixed

- Generation jobs no longer fail in `analyzing` with a denied vault read. The
  job runs in a Messenger consumer or on the CLI, where TYPO3 boots an
  *unauthenticated* command-line user; nr-vault then has no actor to authorise
  and refuses every secret, so the provider connection failed before a single
  artifact was produced. `GenerationOrchestrator::process()` now resolves a
  configured backend user and wraps `processJob()` in nr-vault's
  `TechnicalActorContextInterface::runAs()`.

  This was a missing *identity*, not a missing grant — the unauthenticated CLI
  branch never consults `owner_uid` or the group tables at all, so widening
  permissions would not have helped.

### Added

- `technicalBeUserUid` extension setting: the backend user the asynchronous job
  acts as. Defaults to `0`, which keeps the previous behaviour, so an existing
  installation does not change until the value is set.

### Changed

- Requires `netresearch/nr-vault` `^0.15` for the technical-actor API.

## [0.4.1] - 2026-08-10

### Changed

- Requires nr-llm ^0.28. The previous cap at ^0.26 did not even admit 0.27 and
  held the dependency tree two minors back.

## [0.4.0] - 2026-08-07

### Changed

- Require `netresearch/nr-llm` `^0.26.0` (was `^0.25`).

### Removed

- The public alias for nr-llm's `CapabilityPermissionServiceInterface`. nr-llm
  0.26 removed that service and its interface outright — ADR-117 withdrew the
  backend capability permissions rather than deferring them again — so the
  alias, which existed only to expose them "once capability gating is added",
  now fails the container compile. Nothing in this extension consumed it.

## [0.3.1] - 2026-07-31

### Changed

- **`netresearch/nr-vault` constraint widened to `^0.10.0 || ^0.11.0 || ^0.12.0`.**
  nr-vault 0.12.0 is a security release whose highest-severity fix stops
  `%vault()%` site-configuration references from being resolved eagerly and
  persisted into TYPO3's on-disk cache in cleartext. Consumers that pin this
  extension could not take that fix, because 0.3.0 excluded `^0.12`.

  This extension holds no nr-vault code path of its own — every AI call and the
  key behind it go through nr-llm (ADR-003) — so neither behaviour change in
  0.12.0 reaches it: it implements no `AuditLogServiceInterface` and reads no
  site configuration. Verified against nr-vault 0.12.2 with nr-llm 0.25.1
  installed: 152 unit tests, 596 assertions, all passing.

- `playwright-core` development dependency updated to 1.62.0.

### Fixed

- `Documentation/guides.xml` declared `version="0.2"` alongside `release="0.3.0"`;
  both now track the released version.

## [0.3.0] - 2026-07-23

Released without a changelog entry; recorded here from the tag range for
completeness.

### Changed

- **Migrated to nr-llm `^0.25`**, including `completeStructured()` for the
  nr-llm 0.23 provider interface.
- `symfony/process` and `symfony/messenger` updated to 8.x.
- Backend icons redrawn in TYPO3 v14 style, artifact record icon added.
- Templates use a themable border token instead of a hardcoded `#ccc`.

### Fixed

- `guides.xml` repaired and documentation CI added.

## [0.2.0] - 2026-07-18

### Added

- **One-click nr-llm configuration presets.** The extension declares the three
  Configuration records it needs — `nr_repurpose_text`, `nr_repurpose_image`
  and `nr_repurpose_tts` — as nr-llm configuration presets (ADR-056). An
  administrator imports each with a single click from nr-llm's Configurations
  module instead of hand-creating the records.
- **Named text configuration.** Text generation (content brief, podcast script,
  diagram body, story copy) routes through the `nr_repurpose_text`
  configuration, so provider, model, system prompt, budget and cost attribution
  steer from one record exactly like image (`nr_repurpose_image`) and speech
  (`nr_repurpose_tts`) already did. Falls back to the instance-default
  configuration when the record is not imported.

### Changed

- **Require nr-llm `^0.22.0`.** Now that nr-llm's specialized
  configuration-resolution layer is guaranteed, the forward-compat
  `method_exists()`/`property_exists()` shims in the image and speech generators
  are removed and their calls are direct.

## [0.1.0] - 2026-06-12

First tagged release.

### Added

- **Content ingestion.** A backend module job takes a webpage URL or an
  uploaded PDF; the document is analyzed via nr-llm structured completion
  (map-reduce over long content) into a single content brief that drives all
  generators.
- **Podcast generator.** Persona-aware dialogue script (one to three speakers
  from persona snippets; two-host default), per-turn text-to-speech,
  ffmpeg stitching into one audio file, plus transcript and WebVTT captions.
  Transient TTS failures are retried.
- **Schaubild generator.** Three diagram variants rendered from branded HTML
  templates via headless Chromium (Playwright), with an AI-generated
  background composited behind the design canvas.
- **Instagram story generator.** Multi-slide 9:16 story carousel with
  optional AI-generated backgrounds.
- **Prompt-snippet steering.** Persona, tone, audience, image-style and
  layout selectors in the job form, backed by nr-llm's prompt-snippet
  library; layout snippets carry an `imageSize` metadata key that drives
  gpt-image-2 output dimensions per channel (skyscraper, wide, square, …).
- **Live progress and prompt transparency.** The job detail view shows
  fine-grained per-step progress with auto-refresh and, for every generated
  artifact, the complete creation parameters: exact system/user/image
  prompts, models, image sizes and voices.
- **Asynchronous generation.** Jobs run through Symfony Messenger (doctrine
  transport) with a `nr_repurpose:generate` CLI command for manual runs.
- **Central LLM governance.** Every text, image and speech call goes through
  nr-llm's Provider → Model → Configuration tiers, so token usage and cost
  are tracked centrally per model and configuration; API keys live in
  nr-vault, never in extension configuration.
- **Rendering hardening.** GD compositing pre-flights its memory requirement
  and fails the single artifact gracefully instead of taking the worker down.
- **Quality infrastructure.** Docker-isolated test runner
  (`Build/Scripts/runTests.sh`: unit, functional, PHPStan level 10, CGL,
  Rector), CI via the centralized netresearch reusable workflows, and a
  tag-triggered release pipeline with SBOMs, Cosign signatures and SLSA
  provenance.

[Unreleased]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.5.3...v0.6.0
[0.5.3]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.5.2...v0.5.3
[0.5.2]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.9...v0.5.0
[0.4.9]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.8...v0.4.9
[0.4.8]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.7...v0.4.8
[0.4.7]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.6...v0.4.7
[0.4.6]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.5...v0.4.6
[0.4.5]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.4...v0.4.5
[0.4.4]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.3...v0.4.4
[0.4.3]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.2.3...v0.3.0
[0.2.3]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/netresearch/t3x-nr-repurpose/releases/tag/v0.1.0
