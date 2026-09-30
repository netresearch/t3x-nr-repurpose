<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Security assurance

What users of nr_repurpose can and cannot expect in terms of security, and the argument for it: threat model, trust boundaries, the design principles applied, and how common weaknesses are countered. Every claim names the file that implements it. Components and data flow: `docs/ARCHITECTURE.md`. Vulnerability reporting: `SECURITY.md` of the organisation and the Security section of `CONTRIBUTING.md`.

The document describes the code on `main`.

## What the extension does, security-wise

A backend user submits a URL or a PDF. A worker (Symfony Messenger, doctrine transport) fetches the source, sends its text to a language model through nr-llm, renders media in a headless Chromium, ffmpeg and Poppler, and stores the results in FAL. An approved social post can be sent to a configured webhook by the `nr_repurpose:publish-due` command.

## Security expectations

Users can expect:

- **No provider credentials in this extension.** It reads no API key; every AI call goes through nr-llm, which holds the key by identifier (ADR-003, `Documentation/Adr/Adr003ProviderCredentialsViaNrLlm.rst`). No `getenv`, `$_ENV` or `apiKey` read exists in `Classes/`.
- **Only public http(s) sources are fetched.** `Classes/Ingestion/RemoteSourceGuard.php` allows only `http` and `https` (lines 98-99), refuses hosts that do not resolve, numeric host spellings, and any resolved address in loopback, private, link-local, carrier-grade NAT, multicast or reserved ranges (`BLOCKED_RANGES`, lines 44-58), including IPv4 embedded in IPv6. Redirects are not followed (`Classes/Ingestion/BoundedResponseReader.php`, `ALLOW_REDIRECTS => false`).
- **Bounded downloads.** 5 MiB and 30 s for a web page (`Classes/Ingestion/WebPageFetcher.php`), 50 MiB and 120 s for a PDF (`Classes/Ingestion/PdfFileResolver.php`); the size limit is checked on `Content-Length`, while reading and after decompression (`BoundedResponseReader.php`).
- **Generated HTML cannot run scripts or reach the network while it is rendered.** `Resources/Private/NodeRenderer/render.cjs` sets `javaScriptEnabled: false`, blocks service workers, aborts every request except `data:` and `blob:` URLs, and points the browser at a dead proxy as a second barrier. HTML reaches the renderer on stdin, not in a command line (`Classes/Rendering/PlaywrightHtmlToImageRenderer.php`).
- **No shell.** node, ffmpeg, pdftoppm and pdftotext are started through Symfony Process with argument arrays (`Classes/Rendering/Process/SymfonyProcessRunner.php`, `Classes/Ingestion/Poppler/SymfonyProcessPopplerRunner.php`, `Classes/Rendering/FfmpegAudioStitcher.php`, `Classes/Rendering/FfmpegSlideshowRenderer.php`), each with a timeout. No `exec`, `shell_exec`, `proc_open` or `Process::fromShellCommandline` exists in `Classes/`.
- **Expensive AI calls are permission-checked before they are made.** Speech and image generation and PDF vision OCR require the custom permissions `nrrepurpose:generate_audio` and `nrrepurpose:generate_vision` of the job's creator (`Classes/Service/CapabilityGrantResolver.php`, ADR-008), checked before nr-llm's budget check (`Classes/Generator/AbstractGenerator.php`, `specializedAllowed()`).
- **Publishing needs a human approval.** Approving, rejecting and scheduling require `nrrepurpose:approve_artifacts` (`Classes/Review/ReviewPermission.php`, enforced in `Classes/Controller/JobController.php`); the publish command sends only approved posts (ADR-007).
- **AI-generated output is labelled.** PNG, MP3, WebVTT and PDF files carry machine-readable provenance (`Classes/Provenance/AiContentMarker.php`, ADR-005); webhook posts carry `aiGenerated` and `aiLabel`.
- **Parameterised database access.** Queries use the QueryBuilder with named parameters or the DBAL connection's criteria arrays (`Classes/Persistence/JobProcessingRepository.php`, `Classes/Social/DuePostPublisher.php`, `Classes/Review/ArtifactReviewService.php`); no SQL is built by string concatenation.

Users cannot expect:

- **Protection of the content sent to the language model.** Source text and generated prompts go to the provider configured in nr-llm. What that provider stores is outside this extension.
- **Correct or safe AI output.** Generated text, images and audio can be wrong or unsuitable; the approval step exists for that reason, and it is enforced only for social-post publishing.

## Threat model and trust boundaries

| Boundary | Untrusted input | Control |
|----------|-----------------|---------|
| Backend user → module | Source URL, PDF upload, prompt snippet selection, review actions | TYPO3 backend authentication; module access `user`; custom permissions for approval and for audio/vision spend |
| Worker → remote web server | URL target, response body and headers | `RemoteSourceGuard`, no redirects, size and time limits |
| Remote content / PDF → parser | HTML, PDF structure | smalot/pdfparser and Poppler with timeouts; parse errors become fixed messages (`Classes/Ingestion/PdfTextExtractor.php`) |
| Language model → templates and renderer | Generated text and HTML | Fluid escaping in the backend templates; rendering without JavaScript and network |
| Worker → external binaries | File paths and arguments | Argument arrays, no shell, timeouts |
| Worker → nr-llm | Prompts, budget | nr-llm budget check before specialised calls; credentials stay in nr-llm |
| `publish-due` command → webhook | Configured URL | http(s) only, optional HMAC-SHA256 signature `X-Nr-Repurpose-Signature` (`Classes/Social/WebhookSocialPublisher.php`) |

Attackers considered: a backend user trying to reach internal hosts through the URL field (SSRF), a malicious web page or PDF trying to exploit the parser or the renderer, and a language-model answer carrying markup or script. The TYPO3 administrator, the worker host and the nr-llm configuration are trusted.

## Secure design principles applied

- **Least privilege:** the extension holds no provider credentials; spend and approval each need their own permission; the worker can run as a dedicated technical backend user (`technicalBeUserUid`, `Classes/Service/GenerationOrchestrator.php`), and capability checks use the job's creator, not that actor.
- **Fail-safe defaults:** the grant default is "no grant" (`GenerationContext`); an unresolvable or non-public host is refused; a missing webhook URL sends nothing.
- **Complete mediation:** every fetch of a source URL goes through `RemoteSourceGuard` (`WebPageFetcher`, `PdfFileResolver`).
- **Economy of mechanism:** all external programs go through two runner interfaces (`ProcessRunnerInterface`, `PopplerRunnerInterface`).
- **Defence in depth:** the renderer blocks the network by route and by dead proxy, and runs without JavaScript.
- **Error hiding:** process, renderer, speech, image, PDF-parser and webhook failures store a fixed message and log the cause (for example `SymfonyProcessRunner.php`, `WebhookSocialPublisher.php`).

## Countering common weaknesses

| Weakness (CWE / OWASP) | Counter |
|------------------------|---------|
| SSRF (CWE-918, A10:2021) | `RemoteSourceGuard` on every source fetch, no redirects |
| OS command injection (CWE-78) | Argument arrays through Symfony Process, no shell |
| SQL injection (CWE-89) | QueryBuilder named parameters, DBAL criteria arrays |
| Cross-site scripting (CWE-79) | Fluid auto-escaping in backend templates; no `escaping=false` in `Resources/Private` |
| Missing authorisation (CWE-862) | `ReviewPermission`, `CapabilityGrantResolver` |
| Uncontrolled resource consumption (CWE-400) | Download size and time limits, process timeouts, output caps (for example 10 slides in `SlideDeckGenerator.php`, 6 in `StoryGenerator.php`), nr-llm budget |
| Hard-coded credentials (CWE-798) | None in the code; Betterleaks scans every pull request (`.github/workflows/checks.yml`) |
| Vulnerable components (A06:2021) | Composer Audit and Dependency Review on every pull request (`checks.yml`) |

Static checks on every pull request (Opengrep, CodeQL, PHPStan level 8 on `Classes/`) and the unit and functional suites (`Tests/`) back these claims; see "Governance and policies" in `CONTRIBUTING.md`.

