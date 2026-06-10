<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * One slide of the Instagram-story carousel: a cover (hook/title), a key-point slide,
 * or the outro (takeaway + source attribution). Parsed from the single LLM carousel
 * response by StoryGenerator.
 */
final readonly class StorySlide
{
    public const ROLE_COVER = 'cover';
    public const ROLE_POINT = 'point';
    public const ROLE_OUTRO = 'outro';

    public function __construct(
        public string $role,
        public string $headline,
        public string $subline,
    ) {}
}
