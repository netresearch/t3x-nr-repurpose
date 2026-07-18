# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/netresearch/t3x-nr-repurpose/releases/tag/v0.1.0
