<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service\UseCase;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\UseCase\PackSnippet;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackProviderInterface;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Service\Preset\RepurposeConfigurationPresetProvider;

/**
 * The Content Repurpose Starter pack (nr-llm ADR-163/ADR-186).
 *
 * The job form offers five selectors — audience, tone of voice, persona, layout
 * and style — each populated from the prompt snippets carrying that tag. On a
 * fresh installation there are none, so every selector reads "(none)" and the
 * feature the form advertises is invisible. This pack is the one-click answer:
 * a small, editable starting library, installed from the Use Case Packs module
 * or by `nrllm:usecasepack:install content-repurpose-starter`.
 *
 * **Every snippet declares `composedByConfiguration: false`.** All five families
 * are resolved BY UID, per job, by {@see PromptSnippetResolver} — the editor
 * picks one audience, one tone, up to three personas and a layout/style pair
 * per artifact type. Letting the installer link those tags to the text
 * configuration would make nr-llm compose every active snippet carrying them
 * into every completion as well: three personas the job did not choose and two
 * contradictory image sizes, on top of the selection the editor made.
 *
 * **The content is a starting point, not a house style.** It is deliberately
 * generic and English — an operator renames, rewrites and extends these records,
 * and a second install leaves their edits alone because the installer only
 * creates what is missing. Two metadata keys are load-bearing and documented
 * where they are read: a persona's `voice` reaches the TTS call, and a layout's
 * `imageSize` sets the AI-image dimensions ({@see PromptSnippetResolver}).
 *
 * Discovered automatically via the `nr_llm.use_case_pack` DI tag.
 */
final class RepurposeStarterPackProvider implements UseCasePackProviderInterface
{
    public const PACK = 'content-repurpose-starter';

    /**
     * @return list<UseCasePack>
     */
    public function getPacks(): array
    {
        return [
            new UseCasePack(
                identifier: self::PACK,
                // EDITORIAL is the closest of the six cases nr-llm declares:
                // this is content production from copy that already exists.
                // The vocabulary is nr-llm's and switches no behaviour — it
                // groups packs on the onboarding screen — so a
                // "content-repurposing" case would be a change to nr-llm's
                // enum and its two label files for a grouping, and that is a
                // decision for whoever adds the second pack in this shape.
                useCase: UseCase::EDITORIAL,
                name: 'Content Repurpose Starter',
                description: 'A starting library for the job form: two audiences, two tones of voice, '
                    . 'three podcast personas with their own voices, three layouts with their image '
                    . 'sizes and three visual styles. Rename and rewrite them — they are ordinary '
                    . 'snippet records.',
                configurationPreset: RepurposeConfigurationPresetProvider::textPreset(),
                // RECOMMENDED, never applied (nr-llm ADR-145). A repurpose job
                // sends the source page or PDF to the provider, which is the
                // posture "controlled cloud" describes.
                recommendedGovernanceProfile: GovernanceProfile::CONTROLLED_CLOUD,
                // No tasks: this extension runs its own pipeline and creates no
                // nr-llm Task records. Declaring one would put a button in the
                // Tasks module that does none of what the pack is for.
                tasks: [],
                snippets: [
                    ...$this->audiences(),
                    ...$this->tones(),
                    ...$this->personas(),
                    ...$this->layouts(),
                    ...$this->styles(),
                ],
            ),
        ];
    }

    /**
     * Composed under the "TARGET AUDIENCE" heading, and additionally handed to
     * the podcast generator as a plain hint.
     *
     * @return list<PackSnippet>
     */
    private function audiences(): array
    {
        return [
            $this->snippet(
                'nr-repurpose-audience-general',
                'General public',
                'No prior knowledge assumed. The safe default.',
                'The audience has no prior knowledge of the subject. Explain every technical term the '
                    . 'first time it appears, in half a sentence, and prefer a concrete example over an '
                    . 'abstract definition.',
                'audience',
            ),
            $this->snippet(
                'nr-repurpose-audience-decision-makers',
                'Decision makers',
                'Short on time, interested in consequences rather than mechanics.',
                'The audience decides about budget and priorities and has little time. Lead with what '
                    . 'changes and what it costs. Keep mechanics to the minimum needed to make the '
                    . 'consequence believable.',
                'audience',
            ),
        ];
    }

    /**
     * @return list<PackSnippet>
     */
    private function tones(): array
    {
        return [
            $this->snippet(
                'nr-repurpose-tone-plain',
                'Plain and factual',
                'Short sentences, no marketing language.',
                'Write plainly. Short sentences, everyday words, active voice. No superlatives, no '
                    . 'exclamation marks, no rhetorical questions.',
                'tone_of_voice',
            ),
            $this->snippet(
                'nr-repurpose-tone-conversational',
                'Conversational',
                'Spoken register — reads well aloud.',
                'Write the way a knowledgeable person talks: contractions are fine, sentences may start '
                    . 'with "And" or "But", and an aside in passing is welcome. Stay accurate; never trade '
                    . 'a fact for a turn of phrase.',
                'tone_of_voice',
            ),
        ];
    }

    /**
     * Podcast speakers. The NAME becomes the dialogue speaker label, the body
     * is the character description, and `metadata.voice` is the TTS voice —
     * the three things {@see PromptSnippetResolver} builds a Persona from.
     *
     * The voices are OpenAI TTS names, matching the two this extension already
     * defaults to (`nova`, `onyx`). A provider that does not know a voice falls
     * back to its own default; a wrong name cannot fail the job.
     *
     * @return list<PackSnippet>
     */
    private function personas(): array
    {
        return [
            $this->snippet(
                'nr-repurpose-persona-host',
                'Alex',
                'Host. Opens, moves the conversation on, asks the obvious question.',
                'Alex hosts the episode. Opens with what the piece is about and why it matters, keeps '
                    . 'the conversation moving, and asks the obvious question the listener is already '
                    . 'thinking. Never lectures.',
                'persona',
                metadata: ['voice' => 'nova'],
            ),
            $this->snippet(
                'nr-repurpose-persona-expert',
                'Sam',
                'Subject expert. Answers concretely, names numbers.',
                'Sam knows the subject. Answers concretely and names the number, the version or the date '
                    . 'when there is one. Says "I do not know" rather than filling a gap.',
                'persona',
                metadata: ['voice' => 'onyx'],
            ),
            $this->snippet(
                'nr-repurpose-persona-sceptic',
                'Robin',
                'Sceptic. Asks what the claim rests on and who it does not apply to.',
                'Robin pushes back. Asks what a claim rests on, who it does not apply to, and what it '
                    . 'would cost to be wrong. Sceptical about the subject, never about the other '
                    . 'speakers.',
                'persona',
                metadata: ['voice' => 'shimmer'],
            ),
        ];
    }

    /**
     * Composed under the "LAYOUT" heading, and the one place `imageSize`
     * matters: it sets the dimensions of the generated diagram and story
     * visuals. The values obey the generator's own validation — divisible by
     * 16, at most 3840x2160, aspect within 1:3 to 3:1 — so none of them can be
     * rejected and silently fall back.
     *
     * @return list<PackSnippet>
     */
    private function layouts(): array
    {
        return [
            $this->snippet(
                'nr-repurpose-layout-widescreen',
                'Widescreen (16:9)',
                'For slides and embeds. 1792x1024.',
                'Lay the content out for a wide frame. One horizontal flow, at most five stations, room '
                    . 'to breathe between them.',
                'layout',
                metadata: ['imageSize' => '1792x1024'],
            ),
            $this->snippet(
                'nr-repurpose-layout-square',
                'Square (1:1)',
                'For feeds and thumbnails. 1024x1024.',
                'Lay the content out for a square frame. A centred subject with the supporting elements '
                    . 'arranged around it; nothing important near the edges.',
                'layout',
                metadata: ['imageSize' => '1024x1024'],
            ),
            $this->snippet(
                'nr-repurpose-layout-portrait',
                'Portrait story (9:16)',
                'For phone-screen stories. 1024x1792.',
                'Lay the content out for a full phone screen. A vertical stack read top to bottom, the '
                    . 'headline in the upper third, and the lower fifth kept clear for interface '
                    . 'elements.',
                'layout',
                metadata: ['imageSize' => '1024x1792'],
            ),
        ];
    }

    /**
     * @return list<PackSnippet>
     */
    private function styles(): array
    {
        return [
            $this->snippet(
                'nr-repurpose-style-flat',
                'Flat editorial',
                'Flat shapes, few colours, generous white space.',
                'Flat vector illustration. Few, clearly separated colours, no gradients, no drop '
                    . 'shadows, generous white space. Shapes carry the meaning; decoration carries none.',
                'style',
            ),
            $this->snippet(
                'nr-repurpose-style-blueprint',
                'Technical blueprint',
                'Line drawing on a dark ground, for process and architecture.',
                'Technical line drawing: thin even strokes on a dark ground, orthogonal connectors, one '
                    . 'accent colour used sparingly for the element that matters.',
                'style',
            ),
            $this->snippet(
                'nr-repurpose-style-photographic',
                'Photographic',
                'Photo-realistic scene, natural light.',
                'Photo-realistic scene in natural light, shallow depth of field, no text and no logos in '
                    . 'the image. Realistic proportions; no surreal composition.',
                'style',
            ),
        ];
    }

    /**
     * @param array<string, string> $metadata
     */
    private function snippet(
        string $identifier,
        string $name,
        string $description,
        string $body,
        string $tag,
        array $metadata = [],
    ): PackSnippet {
        return new PackSnippet(
            identifier: $identifier,
            name: $name,
            description: $description,
            snippet: $body,
            tags: [$tag],
            // Editorial steering about how to present already-public source
            // material — the least sensitive class there is (nr-llm ADR-144).
            dataClass: ToolDataClass::PUBLIC_CONTENT,
            metadata: $metadata,
            // Read by uid, per job, from the form's selection. See the class
            // docblock for why linking these tags would be a defect.
            composedByConfiguration: false,
        );
    }
}
