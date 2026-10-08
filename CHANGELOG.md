<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **The chat tool `start_repurpose_job` is offered to runs against external providers.** nr-llm's trust-zone gate ranked it as secret-adjacent, because it declared no data class and nr-llm has no default for its group `nr_repurpose`; with `tools.dataClassEnforcement = enforce`, the shipped setting, a provider without a trust zone (externalGlobal, ceiling editorContent) therefore never got the tool, even with the tool and its group switched on. The tool now declares `editorContent`: its result is the uid of the job it created, the source URL without query and fragment, and the artifact names (ADR-009). Every call still needs a person's approval. (#172)

## [0.11.0] - 2026-10-07

### Upgrade notes

- Installations need no change. Code that subclasses a generator or `DocumentAnalyzer`, or calls `ConfiguredCompletionService` directly, has to follow the interface change described under Changed.

### Changed

- **nr-llm 0.39 is supported, next to 0.38 (breaking for code that subclasses a generator or `DocumentAnalyzer`, or calls `ConfiguredCompletionService` directly).** nr-llm 0.39 returns a `StructuredCompletionResponse` from `completeStructured()` instead of the decoded array (nr-llm ADR-211). Under 0.39, 0.10.0 stops at class load: `ConfiguredCompletionService` implemented nr-llm's `CompletionServiceInterface` with the 0.38 return types, and no class can declare that method for both versions. The extension now has its own `TextCompletionInterface` (`completeJson()`, `completeStructured()`, `completeMarkdown()`), which `ConfiguredCompletionService` implements while calling nr-llm's interface; it returns the decoded answer of a structured completion on both versions. The generators and `DocumentAnalyzer` take `TextCompletionInterface` in their constructors instead of nr-llm's interface, and `ConfiguredCompletionService` drops the nine methods only nr-llm's interface required. Code that subclasses a generator or `DocumentAnalyzer` and passes nr-llm's completion service to its constructor has to pass a `TextCompletionInterface` instead; code that calls `complete()`, `completeFactual()`, `completeCreative()` or a `*ForConfiguration()` method on `ConfiguredCompletionService` has to call nr-llm's `CompletionServiceInterface` instead. The composer constraint is `^0.35 || ^0.36 || ^0.37 || ^0.38 || ^0.39`; `ext_emconf.php` allows `0.35.0-0.39.99`.
- **TER, TYPO3 and docs.typo3.org show one title, "Content Repurpose", and one description.** The `ext_emconf.php` title, the `composer.json` description, the manual's title and the `guides.xml` project title carry the same name; the description no longer ends in "by Netresearch".

## [0.10.0] - 2026-10-07

### Upgrade notes

- **Installations that sign their social posts move the signing secret to nr-vault.** 0.10.0 no longer reads the extension setting `socialWebhookSecret`; while it still holds a value, no post is sent and every due post fails. Before updating: 1. store the secret in nr-vault through its backend module; on the command line `vault:store` works only with `--as-provisioner` and nr-vault's `provisioningBeUserUid` set, or with nr-vault's CLI access switched on; 2. make sure `nr_repurpose:publish-due` can read it: with `technicalBeUserUid` set, it reads as that backend user, who needs read access to the secret (administrator, owner, or a group the secret is shared with); with `technicalBeUserUid` at 0, it reads as TYPO3's command-line backend user, an administrator, when `scheduler:run` starts it, and needs nr-vault's CLI access when started directly from cron. After updating: 3. enter the identifier in the new extension setting `socialWebhookSecretIdentifier`; 4. clear `socialWebhookSecret`. Posts that fall due between the update and step 4 fail instead of being sent unsigned. Installations without a webhook secret need no change.
- **The social webhook URL must point to a host on the public internet.** A `socialWebhookUrl` whose host resolves to a private, loopback or other non-public address is now refused, and the post fails.
- 0.10.0 contains the fixes of 0.9.1.

### Security

- **The social webhook is sent only to a host on the public internet.** The `socialWebhookUrl` passes the same address check as a source URL, the request connects to the checked addresses, follows no redirect and has 15 seconds to complete. A refused URL fails the post with `Webhook URL refused: only http and https to a host on the public internet are allowed`; a post that cannot be encoded as JSON fails with a fixed message instead of staying in the `publishing` state.
- **The Schaubild body is reduced to static markup before it is rendered.** The diagram body the text model writes keeps text, block, list and table elements with `class`, `style` and a few layout attributes; scripts, style elements, links, images, media, frames, forms, SVG and event-handler attributes are removed with their content. The sanitizer is `Classes/Generator/Support/DiagramBodySanitizer.php`, built on `typo3/html-sanitizer`, which TYPO3 core already requires and `composer.json` now names.

### Changed

- **The webhook signing secret is read from nr-vault (breaking for installations that sign their posts).** The new extension setting `socialWebhookSecretIdentifier` names the nr-vault secret the body is signed with; `nr_repurpose:publish-due` reads it as the backend user in `technicalBeUserUid` when one is set. The setting `socialWebhookSecret`, which held the secret itself in the system configuration, is no longer read: while it still holds a value no post is sent and each due post fails with `socialWebhookSecret is no longer read: …`, so a receiver that checks the signature never gets unsigned posts. The upgrade notes above list the migration steps.
- **TYPO3 and TER show the extension as "Content Repurpose".** The composer.json `description` now starts with the title that `ext_emconf.php` already carries; the text after the title is unchanged.

### Added

- **A repurpose job can be started from the backend chat.** With nr-llm's tool runtime, the nr_mcp_agent chat offers the tool `start_repurpose_job` in the tool group `nr_repurpose`. It is off by default, because a job spends provider money: an administrator switches it on in the nr-llm *Tools* module and permits the group in the chat configuration. Every call waits for the user's approval; the user needs access to the Repurpose module and owns the job, as for a job created in the module.
- **Extension setting `chromiumSandbox`** (default off): with it on, the renderer starts Chromium with its sandbox. The sandbox needs unprivileged user namespaces or Chromium's setuid helper on the worker host; in a container with Docker's default seccomp profile, or as root, Chromium does not start and every render fails, which is why it is off by default. `render.cjs` takes the new flag `--sandbox`.
- **`docs/SECURITY-ASSURANCE.md`** states what users can and cannot expect in terms of security, the threat model, trust boundaries, the design principles applied and how common weaknesses are countered, each tied to the file that implements it. README and CONTRIBUTING link it.
- **SPDX notices in the source files.** The configuration, script, SQL, XLIFF, Fluid, Markdown and RST files carry `SPDX-License-Identifier` and `SPDX-FileCopyrightText`; the PHP classes already carried `SPDX-License-Identifier` next to their copyright line; the RST manual is `CC-BY-4.0`, the workflow and labeler files synced from the organisation's typo3-extension template `MIT`, everything else `GPL-2.0-or-later`.
- **CONTRIBUTING links the organisation's governance, roadmap, finding-handling and secret-management policies and the access roster**, and names the checks every pull request runs.

## [0.9.2] - 2026-10-07

0.9.2 contains the same code as 0.9.1 and publishes its fixes to TER, where 0.9.1 could not be uploaded.

### Security

- **A remote source is fetched from the addresses its host was checked with.** The worker resolves the host of a `url` or `pdf_url` source once, checks every address, and the transfer then connects to exactly those addresses; the request keeps the host name, so the `Host` header, TLS SNI and certificate verification are unchanged. With an HTTP proxy configured in TYPO3, the proxy resolves the name instead. Fetching needs the PHP extension `curl`, which `composer.json` requires.
- **The module shows a user the jobs they created.** Administrators and users whose backend group grants *Approve artifacts* still see every job; any other user sees only the jobs they created, and opening the result view of another user's job returns to the list with an error message. Jobs that were created without a backend user are visible to administrators and reviewers only.
- **A PDF source and the analysis have an upper bound.** A PDF with more pages than the extension setting `maxPdfPages` (default 200) fails right after parsing, before any page's text is read. The analysis fails before its first completion call when the source text splits into more than 100 sections of about 12,000 characters. Both failures name the limit in the job's error.

## [0.9.1] - 2026-10-07

### Security

- **A remote source is fetched from the addresses its host was checked with.** The worker resolves the host of a `url` or `pdf_url` source once, checks every address, and the transfer then connects to exactly those addresses (curl `CURLOPT_RESOLVE`); the request keeps the host name, so the `Host` header, TLS SNI and certificate verification are unchanged. With an HTTP proxy configured in `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']` the proxy resolves the name instead. Fetching therefore needs the PHP extension `curl`, which `composer.json` now requires; without it the fetch fails with `Fetching a remote source needs the PHP extension curl`.
- **The module shows a user the jobs they created.** The job list, the result view and the social planning showed every job to every user with access to the module. Administrators and users whose backend group grants *Approve artifacts* still see every job, since approving and scheduling the posts of all editors is their task; any other user sees only the jobs they created, and opening the result view of another user's job returns to the list with an error message. Jobs created without a backend user (`be_user` 0) are visible to administrators and reviewers only.
- **A PDF source and the analysis have an upper bound.** A PDF with more pages than the new extension setting `maxPdfPages` (default 200) fails right after parsing, before any page's text is read or a page goes to Vision OCR or the layout reader. The analysis fails before its first completion call when the source text splits into more than 100 sections of about 12,000 characters. Both failures name the limit in the job's error.

## [0.9.0] - 2026-09-30

### Added

- **`LICENSE`** with the GPL-2.0 text. `composer.json` and `ext_emconf.php` declare `GPL-2.0-or-later`; the repository did not ship the licence text.
- **A developer chapter and a troubleshooting page** in the documentation: running the test suites, adding a generator, swapping the image or speech adapter; and the error messages a job or artifact shows when the worker, nr-vault, Chromium, ffmpeg, poppler, a permission or the budget stops it.
- **The README explains how to verify a release**: `gh attestation verify` with `--signer-repo netresearch/typo3-ci-workflows`, because the shared release workflow signs the build provenance.
- **ADR-008** records the capability-permission gate that 0.5.2 introduced: `generate_audio` and `generate_vision` are checked for the job's creator, once per run, before the budget.
- **The usage chapter shows the backend module.** Six screenshots in `Documentation/Images/Usage/`: the job list with its pager, the *New job* form, the top of a result view, the story strip, social posts in the approval step, and the social planning view.

### Changed

- **nr-vault 1.1 or later is required** (`^1.1`, was `^0.16`). nr-llm accepts it from 0.38.2, so an installation resolves nr-llm 0.38.2 or later. nr-vault 1.x refuses a provider host that DNS does not resolve, so a host reached through `/etc/hosts` or a container runtime's resolver needs a literal entry in `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']`.
- **The Netresearch-theme Schaubild, story slides, slide deck and handout carry the [n] logo and a linked company footer.** The four `Nr.html` output templates had the brand colours and fonts but no logo, and only the Schaubild linked to netresearch.de. Each now shows the symbol-only [n] logo in its header — once per Schaubild, story slide and handout, once per slide of the deck, since each slide is its own PDF page — and names "Netresearch DTT GmbH" in its footer as a link to `https://www.netresearch.de/`, which stays a clickable link in the slide deck and handout PDFs. The logo is inline SVG, so the renderer needs no file or network request for it; on the dark title slides, the teal story gradient and a KI background it sits on a white plate. On a story slide it sits 330px from the top, and the copy with the company line now ends 330px above the bottom edge instead of 120px, in both themes: Meta's Stories ads guide asks to keep 14% of the height (269px) at the top free of text and logos, where the story interface sits, and the same 14% now keeps the bottom clear of the reply bar (Meta's 35% at the bottom is for an ad's call-to-action, which an organic story does not have). The extra 61px are for the story video, which zooms each slide in by 8% around its centre: on its last frame, measured, the logo starts 280px from the top and the company line ends 287px above the bottom. The AI label and the slide indicator moved out of that top band as well; they sat 96px from the top and now share a row at the top right, level with the logo. The Schaubild footer link is `#15585E` instead of `#2F99A4`, which has 3.38:1 on white and is too light for 14px text.
- **The extension icon is the Netresearch [n] symbol.** `Resources/Public/Icons/Extension.svg` was a teal tile with a white refresh glyph and an orange spark, a feature glyph of the kind the brand standard puts on the backend module icon, while the extension icon is the symbol-only logo. It now carries the [n] mark with the teal frame `#2F99A4` and the anthracite letter `#585961`, as fill attributes rather than the `<style>` block of the brand reference's source, since TYPO3 renders a registered SVG inline where the backend asks for inline icon markup, and a hardened backend Content Security Policy blocks inline styles. The backend module keeps its own glyph, `module.svg`.
- **The record forms, the permission options and the extension settings are translated.** The titles, field labels and select items of the job and artifact records were English strings in the TCA, the descriptions of the three custom permissions were English strings in `ext_localconf.php`, and the extension settings had English labels. They now come from the new `locallang_db.xlf`, from `locallang.xlf` and from the new `locallang_em.xlf`, each with a German translation. The records use the registered icon identifiers (`typeicon_classes`) instead of the SVG paths. The permission descriptions name the nr-llm capabilities `audio` and `vision` as text now, no longer from the nr-llm enum.
- **`composer.json` carries the extension version and `Package.providesPackages`** (TYPO3 deprecation #108345). `extra.typo3/cms.version` is `0.8.2`, and `providesPackages` names `smalot/pdfparser`, the one required package that is neither a TYPO3 extension nor shipped by the TYPO3 core. The entry has no vendor path, so in classic mode it only keeps TYPO3 from treating the package as a missing extension: the TER package does not contain smalot/pdfparser, and PDF ingestion in a classic installation fails with `Class "Smalot\PdfParser\Config" not found`, as it did before. With both fields present TYPO3 14 no longer evaluates `ext_emconf.php` and takes the extension's dependencies from `composer.json`'s `require`. The version has to be bumped in both files on every release; `Tests/Unit/VersionConsistencyTest.php` fails when they differ. In classic mode the Extension Manager no longer shows the `alpha` state from `ext_emconf.php`.
- **`typo3/cms-install` moves from `require` to `require-dev`.** No class, configuration or upgrade wizard of this extension uses EXT:install. Once `composer.json` carries the #108345 metadata, TYPO3 turns every `require` entry into a hard package dependency, so every classic-mode installation and every functional test instance would have had to load EXT:install. It stays in `require-dev` for `typo3 setup` in `ddev install`.
- **`composer.json` names the issue tracker and the repository** in `support.issues` and `support.source`, so Packagist links to both.
- **The source URL of a job is a TCA `link` field that allows only the `url` link type** (was `input`). Editing a job in the List module now opens the link element and keeps an http(s) URL as entered (trimmed). A page, e-mail, path or JavaScript link is not stored: the field is saved empty and DataHandler logs an error, also when an existing job carries such a value and is saved again. TYPO3 counts a host without a scheme (`example.org/a.pdf`) and other schemes (`ftp:`, `file:`) as URLs too and stores them. The link browser offers no target, title, class or rel, because they would be stored as part of the URL. Jobs created in the backend module are not affected; they are stored through Extbase without this check.
- **`ddev setup` installs the local development instance**, following the Netresearch DDEV convention. `ddev install` still works and runs the same command. Run again on an installed instance (settings file and `be_users` table present), it skips `typo3 setup`, which refuses a database that already has tables, and repeats only `composer install`, the dev settings, `extension:setup`, the key seeding, the renderer's `npm` install and the cache flush; the database, the admin account and the site stay.
- **README**: badges (CI, codecov, documentation, OpenSSF Scorecard, PHPStan, PHP, TYPO3, licence, latest release, TER), an installation section for Composer that states classic mode (TER) is not supported, the story video and the two PDF documents in the feature list, and a licence and credits section naming Netresearch DTT GmbH.
- The configuration and architecture chapters are split into subpages (nr-llm wiring, worker environment, AI labelling and publishing; generators). Every link target keeps its name.
- **The pipeline passes a typed `JobSnapshot` instead of the raw job row (breaking for code that implements or calls these PHP interfaces).** `GenerationOrchestrator` converts the row once with `JobSnapshot::fromRow()`; `SourceIngestionServiceInterface::ingest()`, `DocumentAnalyzerInterface::analyze()` and `PdfFileResolver::resolve()` take the `JobSnapshot`, `GenerationContext` carries it as `$job` in place of `$jobRow`, and `AbstractTextGenerator` no longer declares `wantColumn()` (a text format reads its flag through `JobSnapshot::wants()` with its `artifactType()`). A row with a missing or non-positive uid, an unknown status or source type, or a value of the wrong type now fails the job before ingestion with "Reading the job failed"; the exception names the column and goes to the TYPO3 log. Until now an unknown source type failed at ingestion with "Unknown source_type: …", and an unknown status threw a `ValueError` out of the orchestrator, which the queue handler stored as "Generation failed".

### Fixed

- **A failed job says why, and counts its artifacts.** A job whose formats all failed ended `failed` with an empty error message, and its `artifacts` counter stayed 0 while the artifact rows held the reasons. The job now states "All N formats failed; each artifact shows its error", or "X of N formats failed; each failed artifact shows its error" for a partly done run, and a finished run clears the message of an earlier one. The counter is the inline field's column, which DataHandler keeps for records it writes; the worker writes artifact rows directly, so the rows are counted when the run ends, and the counter is reset when a re-run clears the old artifacts. (#77)
- **Source downloads work under Guzzle 8.** Guzzle 8, which nr-vault 1.x and TYPO3 13.4.35 / 14.3.7 or later allow, removed the handler context the timeout check read (`getHandlerContext()`), so under Guzzle 8 every transport failure caught as `RequestException` or `ConnectException` without a size or time limit behind it would have ended in a PHP error instead of "not reachable" or "too slow"; no release could install Guzzle 8 before this one. The check now also recognises Guzzle 8's timeout exceptions, including `NetworkTimeoutException`, which the previous catch clause did not reach. A body stream that times out under guzzlehttp/psr7 3 (whose `InflateStream` throws a `TimeoutException`) reports the timeout as well (not reachable yet: the body is buffered before it is read).
- **A long story headline no longer runs off the slide or into the logo.** The cover headline was 116px on an 888px line: a German compound such as "Mehrfamilienhaus:" was cut off at the right edge, and a 60-character headline of words that do not pair on a line took eight lines and pushed the copy up over the logo. Every slide now uses the 88px of the other slides, a word wider than the line breaks inside the word (`overflow-wrap: break-word`), and the source label on the last slide, a URL or file name of any length, is held to two lines. Rendered with a 60-character headline of 6- to 12-letter words, a 110-character subline and a 262-character URL, the Netresearch-theme cover keeps at least 138px to the logo and the last slide 6px.
- **The company footer and the story copy stay legible over a KI background.** The transparent render of the Netresearch-theme Schaubild and story slide is composited over the AI image, and its footer — white text on the story, `#15585E` on the Schaubild — had nothing behind it, so it disappeared over light or busy parts of the image. In the transparent render the footer now sits on the logo's white plate with `#15585E` text (8.11:1). The white copy of a story slide — headline, subline, source and slide indicator — had no backing either, and the Neutral theme asks the image model for a light background, so it came out white on white; in the transparent render of both story themes it now sits on a dark plate line by line, the dark end of the theme's own gradient (`#0f3f44`, 11.57:1; Neutral `#1f2933`, 14.76:1). The opaque renders are unchanged; the Neutral templates have no footer.
- **A `pdf_fal` job reads the PDF attached to it.** A PDF attached in the List module is stored as a `sys_file_reference` of the job, and the job's `source_pdf` column holds the number of attached files, so the worker, which read that column as a `sys_file` uid, loaded the file with uid 1 instead of the attached PDF. `PdfFileResolver` now looks the file up through the job's file reference; a job without one, or whose referenced file no longer exists, fails with "pdf_fal job has no attached PDF".
- **The story no longer leaves its slide renders behind.** Each story slide is a Chromium render in the renderer's output directory, and none of them was removed. The transparent foreground of a slide with a KI background is now deleted once it is composited, a slide that fails deletes its render, and the finished slides are deleted after the story, once the video (when requested) has been made from them.
- **A podcast whose persona has a numeric name no longer fails.** PHP stores a persona name such as "123" as an integer array key, so a turn whose speaker matched no persona fell back to that integer, and the podcast failed with a type error. The fallback now uses the name as a string; the voice of that persona is still found.
- **A podcast turn with a nested value is no longer voiced as "Array".** The dialogue script is decoded LLM JSON, and a turn whose `text` or `speaker` came back as an object or a list was cast to the string `Array`, which the TTS then read out (with a PHP warning). Both dialogue shapes now take a field only when it is a scalar: a turn without usable text is dropped, and a speaker that is not a scalar falls back to the default speaker, as a missing one already did.
- **`ddev start` no longer waits for the worker container until it times out.** The worker reuses the web image and with it the image's health check, which tests php-fpm and Mailpit — neither runs in the worker, so the container never became healthy and `ddev start` failed after its container timeout even though the worker was consuming. The worker's health check is disabled; `ddev start` now reports it ready in under a second.
- **`ddev setup` no longer fails at `cache:flush` with "Permission denied".** The worker ran as root and wrote the TYPO3 caches under `var/` as root, which the web container's user could not replace. The worker now runs as the host user, like the web container.
- **The DDEV worker has ffmpeg, Chromium and Poppler.** It ran DDEV's stock web image instead of the project's built one, which `.ddev/web-build/Dockerfile` extends with these binaries, so none of the steps that call them (Playwright rendering, ffmpeg, the Poppler PDF readers) could run in the worker. It now runs the built image.
- **Most temp files of a run are now removed.** Every run left `/tmp/nrrepurpose_*` directories with the generated images and podcast segments, the Schaubild's Chromium renders, and the downloaded `pdf_url` PDF, and the long-running worker never removed them. The Schaubild now deletes its renders and temp directories after each variant, also when the variant fails; the orchestrator removes whatever temp directory a generator made once that generator is done (the story's composited slides and background, the podcast segments), also when it threw; and a downloaded PDF is deleted once it has been read, as is a partial download when writing it fails (for example on a full disk). An attached `pdf_fal` PDF on a storage with another driver than the local one was copied into `var/transient` by the driver and the copy was never removed; the worker now reads such a file into a temp copy of its own and deletes it after reading. An attached file on the local driver is read in place and never touched.
- **`ext_emconf.php` states the same dependency ranges as `composer.json`.** It declared `nr_vault 0.15.0-0.15.99`, which no nr-llm version this extension accepts could run with. It now declares the nr-vault range of `composer.json` (see the entry above), states the PHP range `8.3.0-8.99.99` and the repository description, and `ExtensionDependencyRangeTest` compares every dependency range, PHP included, between the two files.
- **The renderer uses the configured Chromium again.** `PlaywrightHtmlToImageRenderer` set `CHROMIUM_PATH` with `putenv()`, but Symfony Process passes on only those variables of `getenv()` that are also in `$_SERVER`, so `render.cjs` never saw it and Playwright looked for its own downloaded browser, which the extension does not install. The DDEV image hid this because it sets `CHROMIUM_PATH` itself. The path is now handed to the process as its environment: `ProcessRunnerInterface::run()` takes an optional `$env` array, which `SymfonyProcessRunner` sets on top of the inherited environment. A custom `ProcessRunnerInterface` implementation has to add the parameter.
- **The configuration chapter documents `technicalBeUserUid`.** It said the extension has no configuration of its own, while `ext_conf_template.txt` declares five settings; the setting a worker needs to read provider keys from nr-vault was documented nowhere. The chapter now lists the five settings and describes `technicalBeUserUid`.
- **The installation chapter states the nr-llm range `composer.json` requires** (`^0.35 || ^0.36 || ^0.37 || ^0.38`, it said `^0.25`), adds nr-vault to the requirements, and states that the extension needs a Composer installation: the TER package carries neither `smalot/pdfparser` nor the Node renderer's `package.json`, so classic mode is not supported.
- **The changelog page of the rendered documentation covers every release up to 0.8.2.** It stopped at 0.1.0.
- **The documentation describes how the renderer finds Chromium.** The configuration, installation, architecture and troubleshooting pages said the worker has to export `CHROMIUM_PATH`, or that the PHP renderer exports it. The renderer passes the path to `render.cjs` itself (see the entry above), from its `$chromiumPath` argument, default `/usr/bin/chromium`; the pages now say so and show how to set another path in a `Services.yaml`.
- **The job list fits the module again.** A long source URL (a 400-character tracking URL) did not wrap and pushed every column after "Source" out of view: at 1440 and 1280 px only the ID column was visible without scrolling the table. The source cell now uses the core column class `col-responsive` (one line, ellipsis, full URL in the `title`, the full text still in the cell for copying and screen readers); the artifact icons, progress and action columns use `col-nowrap`, `col-progress` and `col-control`. All six columns are visible at 1280 px and at 200 % zoom, and every row is one line high. The social-planning table does the same for its source column.
- **Long values and generated text wrap inside the job detail view** instead of widening the page (source URL, error messages, LLM text in the artifact cards).
- **Error text on cards and table rows is readable in the dark scheme.** Core `.text-danger` measures 4.49:1 on a card and 4.18:1 on a striped table row there, below WCAG AA; artifact and publishing errors on the cards now use the core error box (`f:be.infobox`), and in the social-planning table the publish error is plain text next to the "failed" status.
- **The artifact status in the job list no longer depends on colour.** Failed and pending artifact types carry a core icon overlay (`overlay-missing`, `overlay-scheduled`); the PDF icon, for example, is red whether its artifact failed or not.
- **The job list draws its progress bar.** TYPO3 14 ships no CSS for `.progress`/`.progress-bar`, so the bar was never drawn. The list now uses a native `<progress>`, which carries the progressbar role, its value, range and a per-row accessible name itself, styled with the per-engine pseudo-elements: the fill in `--typo3-component-primary-color` on a `--typo3-surface-container-high` track, with the percentage visible beside it. The core `<typo3-backend-progress-bar>` was not used: it is `@internal`, its shadow-DOM progressbar cannot be given an accessible name, and its dark-scheme fill measures below 3:1.
- **The running-job reload script uses `csp="true"`** instead of the `useNonce` argument, deprecated since TYPO3 14.2.
- **The job list is paged.** It showed every job on one page. It now shows 25 jobs per page, newest first, with the core backend pager below the table (record range, first/previous/next/last links with an accessible name, a page-number field); the labels come from EXT:core, except the accessible name of the page-number field. A page number beyond the last page shows the last page. The list runs 4 queries for any number of jobs.
- **The job list no longer runs two queries per job.** Mapping a job loaded its artifacts and its source-PDF file references with it, and the artifact column read them for every row: 20 jobs cost 42 queries, one job 4. The two relations are now lazy, and the list reads the artifact summaries of all its rows in one grouped query (`ArtifactRepository::findTypeSummariesByJobs()`, the same fold as `Job::getArtifactTypeSummaries()`), so the number of queries no longer depends on the number of rows.
- **A refused approval or scheduling attempt is logged.** When a backend user without the permission `nrrepurpose:approve_artifacts` sends an approve, reject, schedule or unschedule request, the module now writes a warning to the TYPO3 log (component `Netresearch.NrRepurpose.Controller.JobController`) with the action, the backend user uid, the artifact uid and the job uid. Opening a result view without the permission logs nothing: there the answer only hides the buttons.
- **The module has no inline layout styles any more.** Media sizes, the story strip and wrapped prompt text live in `Resources/Public/Css/backend.css`, built on core custom properties. The job tables are named by their page heading (`aria-labelledby`), and each "Details" link names its job for screen readers.

### Security

- **Artifact and job errors no longer show the text model's message, server paths or database errors.** The text formats, the podcast and the story stored the message of whatever exception failed them in the artifact's error message, which every user of the module sees: the text model's own error (provider answer, request detail), a FAL error with its path, a database error with its SQL. The GD compositor that puts a KI background behind a story slide or Schaubild named its input and output paths in its own messages. The row now shows this extension's own messages as before — the checks of the LLM answer ("the handout has no title or no lead") and the rendering steps ("Cannot AI-label the file: …") — and a fixed "… failed" text for everything else, for example "Story generation failed". The exception, and the compositor's path, go to the TYPO3 log. The job's own error message had the same leak: a failed analysis stored the text model's error, and whatever escaped the pipeline — a generator that threw, such as the Schaubild's diagram call to the text model, or a database error — was stored by the queue handler as it came. The job row keeps this extension's own ingestion and analysis messages ("PDF URL returned HTTP 404: …", "Cannot analyze an empty source document") and otherwise shows "Ingestion failed", "Analysis failed", "Prompt snippet resolution failed" or "Generation failed"; the exception goes to the TYPO3 log. The ingestion messages that name the source URL ("URL returned HTTP 404: …", "Source is larger than 5 MiB: …", the refusals of `RemoteSourceGuard` and eleven more) carried it as entered, so a URL such as `https://user:secret@example.com/doc.pdf?token=abc` put its password and token into the job's error message. They now name it as `scheme://host[:port]/path` (`SourceUrlRedactor`): user name, password, query and fragment are left out, and a value that is not a URL with a scheme and a host shows as "(URL not shown)". The job still keeps the URL as entered, since the fetch needs it, and the job record in the List module shows it whole. The same URL reached users on three more paths, now in the same form: the job list, the result view and the social planning of the backend module showed it as entered (`Job::getSourceValueForDisplay()`, `ArtifactReviewService::planned()`); `WebPageFetcher` used it as the document's label, which goes into the analysis and generation prompts sent to the text model and is printed on the story slides, the slide deck and the handout; and the unregistered stub generator wrote it into its file. The social webhook sent it as entered in the post's `sourceUrl`; it now leaves out only the user name and password (`SourceUrlRedactor::withoutCredentials()`) and keeps the query and fragment, since the link is published and a query can be part of the page's address (`index.php?id=5`). A value without a scheme and a host is sent unchanged unless it has an at sign where a user name would sit, in which case it becomes "(URL not shown)". The guard's log line for a URL the HTTP library cannot parse uses the same form and no longer carries the library's message or exception, which repeat the whole URL and give no other reason.
- **The source URL can no longer reach the host or the internal network.** An editor enters a free URL for a `url` or `pdf_url` job, and the worker fetched it from inside the hosting network with no restriction, so a job could read a service on localhost, on the private network or the cloud metadata endpoint `169.254.169.254` and have its answer analysed into artifacts. `WebPageFetcher` and `PdfFileResolver` now pass the URL through one `RemoteSourceGuard` before the request: only `http` and `https` are fetched, and the host is refused when it does not resolve or when any of its addresses — or the IP literal in the URL — lies in loopback, RFC 1918, carrier-grade NAT, link-local, unique-local IPv6, unspecified, multicast or the reserved `240.0.0.0/4` block (an IPv6 address that carries an IPv4 address — IPv4-mapped, IPv4-compatible, NAT64 `64:ff9b::/96`, 6to4 `2002::/16` — counts as that IPv4 address). An IPv4 address written in another numeric spelling than `a.b.c.d` (`2130706433`, `0x7f.1`, `0177.0.0.1`) is refused as well, because the HTTP client reads it as an address while the system resolver may read it differently. A URL the HTTP library cannot parse is refused the same way, with the fixed reason "Source URL cannot be parsed" and a log entry: `guzzlehttp/psr7` 2.9.0, the lowest version the dependencies allow, cannot parse an IPv6 literal with a dotted IPv4 tail such as `[::ffff:127.0.0.1]`. The job fails with the reason. TYPO3's `allowed_hosts` setting does not cover this: it is a host allow-list, and the HTTP client the container injects is built without the context it needs.
- **Ingestion errors no longer show server paths.** A failed PDF ingestion stored the absolute path of the file on the server (the attached file in the storage, or the downloaded temp copy) in the job's error message, which every user of the module sees; the text extractor added the PDF parser's own message, and the SSRF refusal named the internal address a host resolved to. These messages are now fixed texts. The path, the resolved address and the original exception go to the TYPO3 log instead (`SourceIngestionService` and `RemoteSourceGuard` log through the injected logger). Error messages about a source URL still name that URL, which is the editor's own input, now without user name, password, query and fragment (see above).
- **A remote source is now limited in size and time.** The web page and the PDF were read whole into memory with no size limit, and the request had no timeout (TYPO3's default `timeout` is 0). A web page is now limited to 5 MiB and 30 seconds, a PDF download to 50 MiB and 120 seconds. The download stops as soon as it passes the size or the time limit, a declared `Content-Length` above the size limit fails before the body is read, and a gzip or deflate body counts with its inflated size. With the PHP extension `curl` loaded (Guzzle then uses its curl handler) the time limit covers the whole transfer including the response headers, the connection has its own 10-second limit, and curl refuses response headers above its size cap. Without `curl` Guzzle falls back to its stream handler: the body is bounded the same way, but the time limit applies to each read only, so a server that sends its headers very slowly or without end can still hold the worker or exhaust its memory; install `curl` on the worker. Redirects are still not followed, now stated explicitly, because a redirect target would bypass the address check above.
- **Vision OCR of a PDF now needs the `generate_vision` permission.** An editor whose groups do not grant "Generate AI imagery" (`nrrepurpose:generate_vision`) could still have every PDF page read by nr-llm Vision by choosing the `vision` PDF mode, and a scanned page in `auto` mode went there too; only the budget check applied. The permission is now checked against the job owner's groups before any OCR call: without it a page keeps its embedded text, as a denied generator step fails only its own part, and the document metadata records `visionDenied`. A PDF with no embedded text at all fails the job with an error naming the option.
- **HTML renders no longer run scripts or reach the network.** The Schaubild body is LLM output derived from the fetched page or PDF and is inserted unescaped, so a prompt-injected `<script>` ran in the worker's Chromium, and any `<img>`, CSS background or navigation in it was fetched from the worker's network. `render.cjs` now creates its browser context with JavaScript disabled and service workers blocked, aborts every request except `data:` and `blob:` URLs, and launches Chromium with an unreachable proxy and no bypass list, because Playwright's request routing does not see `<link rel="prefetch">` requests or the target of a redirect. The renderer has no network access at all: the web fonts the templates `@import` from Google Fonts (Raleway, Open Sans, Inter) now ship with the extension in `Resources/Private/Fonts` as the unmodified upstream files under the SIL Open Font License, and `render.cjs` replaces the `@import` with `@font-face` rules over them before rendering. A render no longer falls back to a system font when Google Fonts is slow or unreachable, and the worker needs no outbound access to Google. The Schaubild and Story PNGs render byte-identical to the Google-loaded renders; the slide-deck and handout PDFs rasterise identically.
- **Error messages of the webhook publisher, the image and speech adapters, the Poppler runner and the HTML and ffmpeg renderers no longer carry webhook URLs, provider replies, process output or file paths.** An unreachable social webhook stored the HTTP client's message as the post's publishing error, and that message names the request URI — including a token in `socialWebhookUrl`. The image and speech adapters and the Poppler runner did the same with the provider's message and with Symfony Process's message (command line with the stored PDF's absolute path, and stderr), the HTML renderer with the renderer's stderr (the Chromium launch line with its profile directory) and its output paths, the ffmpeg audio stitcher and slideshow renderer with ffmpeg's stderr (input paths), ffprobe's output and their work and output paths, and the image adapter with the path it could not save to. A timeout, a failed start or a signal of the renderer, ffmpeg, ffprobe or Poppler bypassed even the fixed texts: Symfony Process throws its own exception then, whose message names the command line with the script, input and output paths, and the generators stored that message. These exceptions now carry a fixed message ("Webhook not reachable", "DALL-E image generation failed", "DALL-E could not save generated image", "TTS synthesis failed", "pdftoppm failed for page N", "pdftotext -layout failed for page N", "HTML render failed (exit N)", "Renderer produced no PNG" or "… no PDF", "Render output dir not writable", "ffmpeg concat failed (exit N)", "ffprobe failed (exit N)", "ffprobe returned no numeric duration", "ffmpeg slideshow failed (exit N)", "Audio work dir not writable", "ffmpeg produced no output", "ffmpeg produced no video", "External process timed out", "External process was terminated by a signal", "External process could not be run"); the original exception, stderr or path is written to the TYPO3 log at error level, and a wrapped exception stays attached as the previous exception.

## [0.8.2] - 2026-09-27

### Fixed

- **The "Create & queue" button gives its feedback again.** Its script sat inline in the new-job template, and the TYPO3 backend Content Security Policy refuses an inline `<script>` without a nonce, so the browser never ran it: the button was never disabled and never showed the spinner, and a double click could queue the same job twice. The script is now the ES module `Resources/Public/JavaScript/job-new.js`, registered under the import-map prefix `@netresearch/nr-repurpose/` (`Configuration/JavaScriptModules.php`) and loaded by the new-job action only.
- **An alt-text generator no longer fails every PNG artifact with "Invalid base64 image_url."** (#78). `JobFileStorage` created each file empty and wrote the bytes afterwards, but FAL indexes the file when it is created and dispatches `AfterFileMetaDataCreatedEvent` then. A listener of that event which reads the file — an extension generating alt text on upload, here through nr-llm's vision service — got 0 bytes and sent `data:image/jpeg;base64,` with nothing after it, which OpenAI rejects. The exception left the slide, the Schaubild renders and the KI image failed, and a 0-byte file behind in `fileadmin/repurpose/`. Files are now written with `ResourceStorage::addFile()` from a temporary file, so the bytes are in place when FAL indexes them. A file whose indexing fails is removed again, empty content is refused with the file name in the message instead of being stored, and the PDF vision OCR refuses an empty page image before the vision call. Stored files now pass the same extension and MIME-type check as an upload; `ext_localconf.php` adds `vtt` to `textfile_ext` for the podcast subtitles, because a fresh TYPO3 14 installation enforces that list and does not contain it.

## [0.8.1] - 2026-09-27

### Changed

- **Accept nr-llm 0.38.** `composer.json` requires `netresearch/nr-llm` at `^0.35 || ^0.36 || ^0.37 || ^0.38`, and `ext_emconf.php` declares `nr_llm 0.35.0-0.38.99` to match. On a 0.x version `^0.37` does not admit 0.38.0, so this extension kept an installation from moving to nr-llm 0.38. The floor stays at 0.35.

## [0.8.0] - 2026-09-27

### Added

- **The story as a video.** With the new option "Also as video" in the story card (off by default), the finished story slides also become one silent MP4 of 1080×1920: each slide zooms in by 8 % over four seconds and cross-fades into the next for half a second, encoded as H.264 (yuv420p, fast start) by one ffmpeg call (`FfmpegSlideshowRenderer`, zoompan and xfade). It is made from the slides of the same run, so it needs the story and makes no further AI call. ffmpeg writes the AI marker into the MP4 as keys (`comment`, `AIGenerated`, `DigitalSourceType` = `compositeWithTrainedAlgorithmicMedia`); the file also gets the FAL description. A failed render fails only the video; the slides stay done. The result view plays it and offers the download. Checked with ffmpeg 6.1.2 and 8.1.2: three slides give 11.0 s and 275 frames, and `exiftool` reads the keys. One `want_video` column (default 0) is added to `tx_nrrepurpose_domain_model_job` — run the database analyzer after the update.

- **Approval step for every artifact.** A finished artifact is approved or rejected in the result view by users whose groups grant the new custom permission `nrrepurpose:approve_artifacts` (administrators always). The decision is stored with who and when (`review_status`, `reviewed_by`, `reviewed_at`). Only an approved social post can be scheduled, and the publishing command sends only approved posts. See ADR-007.
- **Social planning.** An approved social post can be scheduled for a date and time; the schedulable command `nr_repurpose:publish-due` sends every post whose time has come to the webhook in the new extension setting `socialWebhookUrl`, as JSON with platform, text, publishing time, source URL and the AI label, optionally signed with `socialWebhookSecret` (HMAC-SHA256 in `X-Nr-Repurpose-Signature`). A post is claimed before sending, so two runs cannot send it twice; a refused post is marked failed with the reason. Without a URL the command sends nothing and reports the posts that are due. "Social planning" in the job list shows scheduled, published and failed posts across all jobs. Seven columns are added to `tx_nrrepurpose_domain_model_artifact` — run the database analyzer after the update. See ADR-007.

- **Two documents as PDF: a slide deck and a handout**. Each is an opt-in checkbox on the job form (off by default) and one schema-validated nr-llm call, like the text formats, then printed by the Chromium the extension already uses for its images: `render.cjs --pdf` calls Playwright's `page.pdf()`, and the template's CSS (`@page`) sets the page size. The slide deck is 16:9 (1920×1080 CSS pixels per page): a title slide, at most eight content slides with at most five bullet points each, and a closing slide with the takeaway; headings and bullet points are cut to fit the fixed slide. The handout is A4: title, lead, at most five sections and six key facts, with the "Key facts" heading in the text's language. Both follow the job's theme; the Netresearch templates use the darker teal `#1d6f77` where text sits on or in teal, because `#2F99A4` has 3.4:1 against white. The PDF is stored in FAL, the HTML it was printed from in `source_html`, and the content as for a text format. The result view has "Open PDF" and "Download PDF". A failed print fails the artifact with "file error". Two `want_*` columns (default 0) are added to `tx_nrrepurpose_domain_model_job` — run the database analyzer after the update. See ADR-006.
- **The PDF carries the AI label**. `AiContentMarker::markPdf()` appends an incremental update to the file Chromium printed: a document information dictionary with the original entries plus `Subject` (the AI statement), `Keywords`, `AIGenerated` and `DigitalSourceType`, the same XMP packet as the PNG files as the catalog's `/Metadata`, and a cross-reference section pointing back at Chromium's. The printed bytes stay unchanged. Only a complete, unencrypted PDF with a classic cross-reference table is accepted; anything else fails the artifact rather than storing an unlabelled file. Checked with `qpdf --check`, `pdfinfo` and `exiftool`.

## [0.7.0] - 2026-09-26

### Added

- **Every artifact is labelled as AI-generated** (NEXT-182). Published synthetic audio and images must be marked in a machine-readable format and be detectable as artificially generated (EU AI Act, Art. 50(2)). Each stored file now carries the marker itself, written in PHP when it is stored, after the last re-encode: the podcast MP3 gets an ID3v2.3 tag with `TXXX:AI-generated = true`, `TXXX:DigitalSourceType` and a `COMM` comment (it replaces the ID3v2.4 tag ffmpeg writes and keeps ffmpeg's `TSSE`); the WebVTT subtitles get a `NOTE` block after the header; every PNG gets `tEXt` `Software`/`Comment` chunks and an XMP packet with the IPTC `DigitalSourceType` — `trainedAlgorithmicMedia` for the full AI image, `compositeWithTrainedAlgorithmicMedia` for the HTML renders. A PNG that already carries a C2PA manifest is stored unchanged, because any change would break the C2PA content hash binding and the manifest would fail validation; such a manifest from the image model is expected to declare the AI origin itself, which is not yet checked against real output (ADR-005). A file that is not what its name says — a truncated PNG render, for instance — is not stored; the artifact fails with the reason. Every stored file also gets a `sys_file_metadata.description` naming the AI origin, and every artifact row, the text formats included, an `aiLabel` block in its metadata (`aiGenerated`, `generator`, `digitalSourceType`, and the models where known — the text formats name none, because nr-llm does not report the completion model). A copied text carries nothing machine-readable: whoever publishes it has to disclose it there. The result view shows an "AI-generated" badge on every finished artifact; its tooltip mentions the machine-readable marker only for rows that have one. Two extension settings control the visible labels: `aiLabelImages` (default on) renders a small "AI-generated" corner label, in the artifact's language, into the Schaubild HTML renders and the story slides — the full AI image is never rendered from HTML and carries the machine-readable marker only; `aiLabelTexts` (default off) appends "This text was created with AI." to the copy-ready text of the text formats, off by default because those texts usually land in a page, newsletter tool or social network with its own disclosure. The social posts reserve the line's length inside their platform limit, and the FAQ JSON-LD is not changed. No database column is added. See ADR-005.

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

## [0.3.0] - 2026-07-24

Released without a changelog entry; recorded here from the tag range
`v0.2.3..v0.3.0`.

### Changed

- **Migrated to nr-llm `^0.25`** (was `^0.22.0 || ^0.23.0`); `ext_emconf.php`
  declares `nr_llm 0.25.0-0.99.99` to match.

## [0.2.3] - 2026-07-22

Released without a changelog entry; recorded here from the tag range
`v0.2.2..v0.2.3` and the GitHub release notes.

### Changed

- `symfony/process` and `symfony/messenger` accept 8.x: both are required at
  `^7.0 || ^8.0`.

### Fixed

- **Composer resolves the latest tag again.** `composer.json` carried an
  explicit `"version": "0.2.0"`, so Composer's VCS driver reported every git tag
  as 0.2.0 and a consumer resolving the package from the repository got the
  oldest tag, with the pre-0.23 nr-llm constraint. The field is removed; the git
  tags drive the version.

## [0.2.2] - 2026-07-22

Released without a changelog entry; recorded here from the tag range
`v0.2.1..v0.2.2` and the GitHub release notes.

### Added

- **nr-llm 0.23 support.** `ConfiguredCompletionService` implements the
  `completeStructured()` and `completeStructuredForConfiguration()` methods
  nr-llm 0.23 adds to `CompletionServiceInterface`: the plain form resolves the
  `nr_repurpose_text` configuration, the configuration form passes through.
- A documentation render job in CI, so `Documentation/guides.xml` is validated
  on every change.

### Changed

- Requires `netresearch/nr-llm` `^0.22.0 || ^0.23.0`.

### Fixed

- **The documentation renders again.** The release script had corrupted the
  XML declaration of `Documentation/guides.xml` (`<?xml version="0.2.1"?>`) and
  left the project version stale; both are restored.

## [0.2.1] - 2026-07-21

Released without a changelog entry; recorded here from the tag range
`v0.2.0..v0.2.1` and the GitHub release notes.

### Changed

- **Backend icons in the TYPO3 v14 style.** The module icon is redrawn with
  filled paths, a `currentColor` glyph and one brand accent, so it follows the
  backend light and dark scheme; the extension icon is a teal tile with the
  repurpose arrows. The artifact table gets its own record icon
  (`tx-nrrepurpose-artifact`); it had none (#44).
- `netresearch/nr-vault` is accepted at `^0.10.0 || ^0.11.0`.

### Fixed

- **Borders in the job detail view follow the dark scheme.** The image
  preview, the story slide images and the failed-slide placeholder used a
  hardcoded `#ccc` border; they now use
  `var(--typo3-component-border-color)` with `var(--bs-border-color)` as
  fallback (#44).

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

[Unreleased]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.8.2...v0.9.0
[0.8.2]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/netresearch/t3x-nr-repurpose/compare/v0.6.0...v0.7.0
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
