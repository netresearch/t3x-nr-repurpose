<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The job's prompt-snippet selection resolved to plain strings/VOs — once per run by
 * PromptSnippetResolver, consumed by the generators. Holding only scalars and own VOs
 * (no nr-llm PromptSnippet models) keeps the generators decoupled and unit-testable.
 *
 * The default instance (all empty) means "no snippets selected": every generator falls
 * back to its pre-snippet behavior unchanged.
 */
final readonly class ResolvedPromptSnippets
{
    /** @param list<Persona> $personas */
    public function __construct(
        public string $schaubildSections = '',  // composed TARGET AUDIENCE / TONE OF VOICE / LAYOUT / STYLE blocks
        public string $storySections = '',      // same labels, with the story's own layout/style snippets
        public string $audienceHint = '',       // raw audience snippet text for the image prompts
        public string $styleHint = '',          // raw Schaubild style snippet text for the image prompts
        public string $schaubildImageSize = '', // "WxH" hint from the Schaubild layout snippet metadata ('' = default)
        public string $storyImageSize = '',     // "WxH" hint from the Story layout snippet metadata ('' = default)
        public array $personas = [],
    ) {}
}
