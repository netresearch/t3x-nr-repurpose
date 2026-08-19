# Architecture

Agent-facing component map. For conventions and commands see the root `AGENTS.md`; for directory-level rules see `Classes/AGENTS.md` and `Tests/AGENTS.md`.

## System overview

nr_repurpose turns a webpage or PDF into derived artifacts (podcast audio, Schaubild diagram images, Instagram-story slides). A backend module (or the `nr_repurpose:generate` CLI) creates a job; the pipeline ingests the source, condenses it into one `ContentBrief` via nr-llm, runs the tagged generators, and stores the results in FAL. All AI calls go through `netresearch/nr-llm` — this extension contains zero provider code.

## Components

| Component | Path | Role |
|-----------|------|------|
| Backend module | `Classes/Controller/JobController.php` | Job list/new/create/show, prompt-snippet selectors |
| CLI | `Classes/Command/GenerateCommand.php` | `nr_repurpose:generate` console entry point |
| Orchestrator | `Classes/Service/GenerationOrchestrator.php` | Pipeline driver: ingest → analyze → run generators, progress bands |
| Completion decorator | `Classes/Service/ConfiguredCompletionService.php` | Routes generator text completions through the `nr_repurpose_text` named nr-llm configuration |
| Ingestion | `Classes/Ingestion/` | URL fetch (`SourceIngestionService`) and tiered PDF reading (`PdfFileResolver`, `Poppler/` runner) |
| Understanding | `Classes/Understanding/DocumentAnalyzer.php` | One `ContentBrief` via nr-llm completion; map-reduce above 24k chars |
| Generators | `Classes/Generator/` | `PodcastGenerator`, `SchaubildGenerator`, `StoryGenerator` extend `AbstractGenerator`; adapter seams in `Image/` and `Speech/` |
| Rendering | `Classes/Rendering/` | Playwright HTML→PNG, GD compositor, ffmpeg audio stitcher behind interfaces |
| Queue | `Classes/Queue/` | `GenerateArtifactsMessage` + handler (Symfony Messenger, doctrine transport) |
| Persistence | `Classes/Persistence/JobProcessingRepository.php` | Direct DBAL writes from the worker |
| Storage | `Classes/Resource/JobFileStorage.php` | FAL storage under the `repurpose/` folder |
| DI wiring | `Configuration/Services.yaml` | Interface aliases, `nr_repurpose.artifact_generator` tag, completion bind |

## Dependency rules

Derived from `Configuration/Services.yaml` (no phpat architecture test suite exists):

- The orchestrator consumes generators only via the `nr_repurpose.artifact_generator` tagged iterator; new generators are registered there, never called directly.
- Swappable backends live behind interfaces with a DI alias: `ImageGeneratorInterface` → `DallEImageGenerator`, `SpeechSynthesizerInterface` → `OpenAiSpeechSynthesizer`, `HtmlToImageRendererInterface` → `PlaywrightHtmlToImageRenderer`, `ImageCompositorInterface` → `GdImageCompositor`, `AudioStitcherInterface` → `FfmpegAudioStitcher`, `PopplerRunnerInterface` → `SymfonyProcessPopplerRunner`, `ProcessRunnerInterface` → `SymfonyProcessRunner`. Change the alias, not the callers.
- Generator text completions go through `ConfiguredCompletionService` (bound to `CompletionServiceInterface $completion` for this extension's services only) — never instantiate nr-llm provider services directly.
- `Classes/Domain/Model/` and `Classes/Queue/Message/` are excluded from DI autowiring — plain data objects.

## Data flow

1. **Ingest** — `Classes/Ingestion/`: URL fetch or tiered PDF reader (Poppler).
2. **Analyze** — `DocumentAnalyzer` produces one `ContentBrief` via nr-llm completion (map-reduce above 24k chars).
3. **Generate** — tagged generators produce podcast (1–3 persona speakers), Schaubild (×3 variants), story (×N slides); async via Symfony Messenger doctrine transport. The worker host needs `ffmpeg`, `chromium` and `poppler`.
4. **Store** — artifacts land in FAL under `repurpose/` via `JobFileStorage`.

## Key decisions

- Provider keys and model selection belong to nr-llm Configuration records (`nr_repurpose_text`, `nr_repurpose_image`, `nr_repurpose_tts`) — see the root `AGENTS.md` Heuristics table.
- PHPStan gate scope (level 8, `Classes` only) is explained in `phpstan.neon` comments.
- CI gate layout (per-repo `ci.yml` matrix vs drift-enforced `checks.yml`) is documented in the comments of those workflow files.
