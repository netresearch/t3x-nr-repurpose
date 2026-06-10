<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrRepurpose\Domain\ValueObject\Persona;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;

/**
 * Resolves a job's PromptSnippetSelection into the plain ResolvedPromptSnippets VO the
 * generators consume — once per run (GenerationOrchestrator), with one batched repository
 * fetch. An empty selection short-circuits without touching the repository, so jobs
 * created before this feature (or with nothing selected) behave exactly as before.
 * Unknown or meanwhile-deactivated snippet uids are skipped, never failing the run.
 */
final class PromptSnippetResolver
{
    private const LABEL_AUDIENCE = 'TARGET AUDIENCE';
    private const LABEL_TONE = 'TONE OF VOICE';
    private const LABEL_LAYOUT = 'LAYOUT';
    private const LABEL_STYLE = 'STYLE';

    public function __construct(
        private readonly PromptSnippetRepository $snippets,
        private readonly PromptSnippetComposer $composer,
    ) {}

    public function resolve(PromptSnippetSelection $selection): ResolvedPromptSnippets
    {
        if ($selection->isEmpty()) {
            return new ResolvedPromptSnippets();
        }

        /** @var array<int, PromptSnippet> $byUid */
        $byUid = [];
        foreach ($this->snippets->findByUids($selection->selectedUids()) as $snippet) {
            $byUid[(int) $snippet->getUid()] = $snippet;
        }

        $audience = $byUid[$selection->audience] ?? null;
        $tone = $byUid[$selection->tone] ?? null;
        $schaubildStyle = $byUid[$selection->schaubildStyle] ?? null;

        $personas = [];
        foreach ($selection->personas as $uid) {
            $snippet = $byUid[$uid] ?? null;
            if ($snippet === null) {
                continue;
            }
            $voice = $snippet->getMetadataArray()['voice'] ?? null;
            $personas[] = new Persona(
                $snippet->getName(),
                $snippet->getSnippet(),
                is_string($voice) && $voice !== '' ? $voice : null,
            );
        }

        return new ResolvedPromptSnippets(
            schaubildSections: $this->composer->composeSections([
                self::LABEL_AUDIENCE => $audience,
                self::LABEL_TONE => $tone,
                self::LABEL_LAYOUT => $byUid[$selection->schaubildLayout] ?? null,
                self::LABEL_STYLE => $schaubildStyle,
            ]),
            storySections: $this->composer->composeSections([
                self::LABEL_AUDIENCE => $audience,
                self::LABEL_TONE => $tone,
                self::LABEL_LAYOUT => $byUid[$selection->storyLayout] ?? null,
                self::LABEL_STYLE => $byUid[$selection->storyStyle] ?? null,
            ]),
            audienceHint: $audience?->getSnippet() ?? '',
            styleHint: $schaubildStyle?->getSnippet() ?? '',
            personas: $personas,
        );
    }
}
