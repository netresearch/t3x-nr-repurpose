<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Generator\Support\TextArtifact;
use Netresearch\NrRepurpose\Generator\Support\TextLimiter;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Service\CallerSource;
use Psr\Log\LoggerInterface;

/**
 * One post per platform variant from ONE completion: LinkedIn (long form, ≤ 3000
 * characters), X (≤ 280) and an Instagram caption with hashtags (≤ 2200 including the
 * hashtags). The limits are enforced here, not only asked for in the prompt: an
 * over-long post is cut at the last sentence end inside the limit (TextLimiter). For
 * Instagram the hashtag line is kept and the caption gives way; hashtags are normalised
 * to "#word", de-duplicated and capped at Instagram's 30.
 *
 * Each variant is its own artifact row (variant "linkedin" / "x" / "instagram"), like the
 * Schaubild variants; the metadata records the limit and whether the post was cut.
 */
final class SocialPostGenerator extends AbstractTextGenerator
{
    public const LIMIT_LINKEDIN = 3000;

    public const LIMIT_X = 280;

    public const LIMIT_INSTAGRAM = 2200;

    public const MAX_HASHTAGS = 30;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        CompletionServiceInterface $completion,
        private readonly TextLimiter $limiter,
    ) {
        parent::__construct($jobs, $budget, $logger, $completion);
    }

    protected function artifactType(): ArtifactType
    {
        return ArtifactType::SocialPost;
    }

    protected function wantColumn(): string
    {
        return 'want_social_post';
    }

    protected function label(): string
    {
        return 'Social posts';
    }

    protected function operation(): string
    {
        return CallerSource::GENERATE_SOCIAL_POST;
    }

    protected function systemPrompt(): string
    {
        return 'You are a social-media editor. Output ONLY valid JSON.';
    }

    protected function taskInstruction(GenerationContext $ctx): string
    {
        return sprintf(
            'Write one social-media post per platform about this content: "linkedin" — a long-form post of '
            . 'at most %d characters with a strong first line; "x" — a short post of at most %d characters '
            . 'including everything; "instagram" — a caption of at most %d characters plus 3 to 10 relevant '
            . 'hashtags given separately (without the # sign). Output ONLY JSON '
            . '{"linkedin":"...","x":"...","instagram":{"caption":"...","hashtags":["..."]}}.',
            self::LIMIT_LINKEDIN,
            self::LIMIT_X,
            self::LIMIT_INSTAGRAM - 300,   // leave room for the hashtag line
        );
    }

    public function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['linkedin', 'x', 'instagram'],
            'additionalProperties' => false,
            'properties'           => [
                'linkedin'  => ['type' => 'string', 'minLength' => 1],
                'x'         => ['type' => 'string', 'minLength' => 1],
                'instagram' => [
                    'type'                 => 'object',
                    'required'             => ['caption', 'hashtags'],
                    'additionalProperties' => false,
                    'properties'           => [
                        'caption'  => ['type' => 'string', 'minLength' => 1],
                        'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    protected function parse(array $data, GenerationContext $ctx): array
    {
        $instagram = is_array($data['instagram'] ?? null) ? $data['instagram'] : [];

        return [
            $this->post('linkedin', $this->requirePost($data['linkedin'] ?? null, 'linkedin'), self::LIMIT_LINKEDIN),
            $this->post('x', $this->requirePost($data['x'] ?? null, 'x'), self::LIMIT_X),
            $this->instagramPost(
                $this->requirePost($instagram['caption'] ?? null, 'instagram'),
                $this->hashtags($instagram['hashtags'] ?? null),
            ),
        ];
    }

    private function requirePost(mixed $value, string $platform): string
    {
        return $this->stringField($value)
            ?? throw new InvalidLlmOutputException(sprintf('the answer has no "%s" post', $platform), 1790000301);
    }

    private function post(string $platform, string $text, int $limit): TextArtifact
    {
        $post = $this->limiter->truncate($text, $limit);

        return new TextArtifact($platform, $post, [
            'platform'  => $platform,
            'text'      => $post,
            'length'    => mb_strlen($post),
            'maxChars'  => $limit,
            'truncated' => $post !== $text,
        ]);
    }

    /**
     * Caption + blank line + hashtag line, at most LIMIT_INSTAGRAM characters. The hashtag
     * line keeps its room; when hashtags alone would crowd the caption below half the
     * limit, hashtags are dropped from the end first.
     *
     * @param list<string> $hashtags
     */
    private function instagramPost(string $caption, array $hashtags): TextArtifact
    {
        while ($hashtags !== [] && mb_strlen(implode(' ', $hashtags)) + 2 > intdiv(self::LIMIT_INSTAGRAM, 2)) {
            array_pop($hashtags);
        }

        $hashtagLine = implode(' ', $hashtags);
        $budget      = self::LIMIT_INSTAGRAM - ($hashtagLine === '' ? 0 : mb_strlen($hashtagLine) + 2);
        $cutCaption  = $this->limiter->truncate($caption, $budget);
        $post        = $hashtagLine === '' ? $cutCaption : $cutCaption . "\n\n" . $hashtagLine;

        return new TextArtifact('instagram', $post, [
            'platform'  => 'instagram',
            'text'      => $post,
            'caption'   => $cutCaption,
            'hashtags'  => $hashtags,
            'length'    => mb_strlen($post),
            'maxChars'  => self::LIMIT_INSTAGRAM,
            'truncated' => $cutCaption !== $caption,
        ]);
    }

    /**
     * "#word" hashtags: the answer's entries with any leading "#" and all whitespace
     * removed, empty ones dropped, de-duplicated case-insensitively, at most MAX_HASHTAGS.
     *
     * @return list<string>
     */
    private function hashtags(mixed $raw): array
    {
        $tags = [];
        $seen = [];
        foreach ($this->stringList($raw) as $tag) {
            $tag = (string) preg_replace('/\s+/u', '', ltrim($tag, '#'));
            $key = mb_strtolower($tag);
            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tags[]     = '#' . $tag;
            if (count($tags) >= self::MAX_HASHTAGS) {
                break;
            }
        }

        return $tags;
    }
}
