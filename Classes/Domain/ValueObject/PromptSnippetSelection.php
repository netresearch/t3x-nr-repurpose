<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The editor's prompt-snippet picks from the New-job form, persisted as JSON on the job row
 * (column prompt_snippets). A uid of 0 means "no snippet selected" for that slot; personas
 * are de-duplicated, zero-free and capped at MAX_PERSONAS. Resolution to actual snippet
 * texts happens once per run in the generation pipeline (PromptSnippetResolver).
 */
final readonly class PromptSnippetSelection
{
    public const MAX_PERSONAS = 3;

    /** @var list<int> persona snippet uids in selection order */
    public array $personas;

    /** @param list<int> $personas */
    public function __construct(
        public int $audience = 0,
        public int $tone = 0,
        array $personas = [],
        public int $schaubildLayout = 0,
        public int $schaubildStyle = 0,
        public int $storyLayout = 0,
        public int $storyStyle = 0,
    ) {
        $unique = [];
        foreach ($personas as $uid) {
            if ($uid > 0 && !in_array($uid, $unique, true) && count($unique) < self::MAX_PERSONAS) {
                $unique[] = $uid;
            }
        }
        $this->personas = $unique;
    }

    /**
     * Parse the persisted JSON tolerantly: an empty, invalid or partial document degrades to
     * "nothing selected" (the pre-feature default) instead of failing the whole run.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }

        $personas = [];
        foreach (is_array($data['personas'] ?? null) ? $data['personas'] : [] as $uid) {
            if (is_numeric($uid)) {
                $personas[] = (int) $uid;
            }
        }

        $schaubild = is_array($data['schaubild'] ?? null) ? $data['schaubild'] : [];
        $story = is_array($data['story'] ?? null) ? $data['story'] : [];

        return new self(
            audience: self::uidFrom($data, 'audience'),
            tone: self::uidFrom($data, 'tone'),
            personas: $personas,
            schaubildLayout: self::uidFrom($schaubild, 'layout'),
            schaubildStyle: self::uidFrom($schaubild, 'style'),
            storyLayout: self::uidFrom($story, 'layout'),
            storyStyle: self::uidFrom($story, 'style'),
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'audience' => $this->audience,
            'tone' => $this->tone,
            'personas' => $this->personas,
            'schaubild' => ['layout' => $this->schaubildLayout, 'style' => $this->schaubildStyle],
            'story' => ['layout' => $this->storyLayout, 'style' => $this->storyStyle],
        ], JSON_THROW_ON_ERROR);
    }

    public function isEmpty(): bool
    {
        return $this->audience === 0
            && $this->tone === 0
            && $this->personas === []
            && $this->schaubildLayout === 0
            && $this->schaubildStyle === 0
            && $this->storyLayout === 0
            && $this->storyStyle === 0;
    }

    /**
     * All selected snippet uids (unique, > 0) for one batched repository fetch.
     *
     * @return list<int>
     */
    public function selectedUids(): array
    {
        $uids = $this->personas;
        foreach ([$this->audience, $this->tone, $this->schaubildLayout, $this->schaubildStyle, $this->storyLayout, $this->storyStyle] as $uid) {
            if ($uid > 0 && !in_array($uid, $uids, true)) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /** @param array<mixed> $data */
    private static function uidFrom(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
