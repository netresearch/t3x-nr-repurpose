<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Pipeline\Fixture;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;

/**
 * Test stand-in for an nr-llm PromptSnippet: overrides the full getter contract so the
 * stub never reads (possibly uninitialized) parent properties. Used by the resolver test.
 */
final class StubPromptSnippet extends PromptSnippet
{
    /** @param array<string, mixed> $stubMetadata */
    public function __construct(
        int $uid,
        private readonly string $stubName,
        private readonly string $stubSnippet,
        private readonly array $stubMetadata = [],
    ) {
        $this->uid = $uid;
    }

    public function getIdentifier(): string
    {
        return 'stub-' . $this->uid;
    }

    public function getName(): string
    {
        return $this->stubName;
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getTags(): string
    {
        return '';
    }

    /** @return list<string> */
    public function getTagList(): array
    {
        return [];
    }

    public function getSnippet(): string
    {
        return $this->stubSnippet;
    }

    /** @return array<string, mixed> */
    public function getMetadataArray(): array
    {
        return $this->stubMetadata;
    }

    public function isActive(): bool
    {
        return true;
    }
}
