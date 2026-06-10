<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Pipeline;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Tests\Unit\Pipeline\Fixture\StubPromptSnippet;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real PromptSnippetComposer (pure string composition per the nr-llm contract)
 * over a stubbed repository, so the composed section strings are integration-faithful.
 */
final class PromptSnippetResolverTest extends TestCase
{
    /**
     * @param array<int, PromptSnippet> $byUid
     *
     * @return PromptSnippetRepository&object{seenUidBatches: list<list<int>>}
     */
    private function repository(array $byUid): PromptSnippetRepository
    {
        return new class($byUid) extends PromptSnippetRepository {
            /** @var list<list<int>> */
            public array $seenUidBatches = [];

            /** @param array<int, PromptSnippet> $byUid */
            public function __construct(private readonly array $byUid)
            {
            }

            public function findByUids(array $uids): array
            {
                $this->seenUidBatches[] = $uids;

                // Contract: input order, unknown uids skipped.
                $found = [];
                foreach ($uids as $uid) {
                    if (isset($this->byUid[$uid])) {
                        $found[] = $this->byUid[$uid];
                    }
                }

                return $found;
            }
        };
    }

    private function resolver(PromptSnippetRepository $repository): PromptSnippetResolver
    {
        return new PromptSnippetResolver($repository, new PromptSnippetComposer());
    }

    public function testEmptySelectionShortCircuitsWithoutRepositoryAccess(): void
    {
        $repository = $this->repository([]);

        $resolved = $this->resolver($repository)->resolve(new PromptSnippetSelection());

        self::assertSame([], $repository->seenUidBatches);
        self::assertSame('', $resolved->schaubildSections);
        self::assertSame('', $resolved->storySections);
        self::assertSame('', $resolved->audienceHint);
        self::assertSame('', $resolved->styleHint);
        self::assertSame([], $resolved->personas);
    }

    public function testResolvesSectionsHintsAndPersonasFromOneBatchedFetch(): void
    {
        $repository = $this->repository([
            1 => new StubPromptSnippet(1, 'Decision makers', 'C-level buyers without deep tech background.'),
            2 => new StubPromptSnippet(2, 'Optimistic', 'Upbeat, confident, plain language.'),
            3 => new StubPromptSnippet(3, 'Anna', 'Curious analyst who asks sharp questions.', ['voice' => 'fable']),
            4 => new StubPromptSnippet(4, 'Ben', 'Seasoned host who summarizes crisply.'),
            5 => new StubPromptSnippet(5, 'Grid', 'Strict three-column grid.'),
            6 => new StubPromptSnippet(6, 'Hand-drawn', 'Sketchy hand-drawn look.'),
            7 => new StubPromptSnippet(7, 'Full-bleed', 'Edge-to-edge imagery.'),
            8 => new StubPromptSnippet(8, 'Minimal', 'Lots of whitespace, one accent color.'),
        ]);
        $selection = new PromptSnippetSelection(
            audience: 1,
            tone: 2,
            personas: [3, 4],
            schaubildLayout: 5,
            schaubildStyle: 6,
            storyLayout: 7,
            storyStyle: 8,
        );

        $resolved = $this->resolver($repository)->resolve($selection);

        self::assertCount(1, $repository->seenUidBatches);
        self::assertEqualsCanonicalizing([1, 2, 3, 4, 5, 6, 7, 8], $repository->seenUidBatches[0]);

        // Composed per-purpose sections: nr-llm contract is "LABEL:\n<text>" blocks joined by blank lines.
        self::assertSame(
            "TARGET AUDIENCE:\nC-level buyers without deep tech background.\n\n"
            . "TONE OF VOICE:\nUpbeat, confident, plain language.\n\n"
            . "LAYOUT:\nStrict three-column grid.\n\n"
            . "STYLE:\nSketchy hand-drawn look.",
            $resolved->schaubildSections,
        );
        self::assertSame(
            "TARGET AUDIENCE:\nC-level buyers without deep tech background.\n\n"
            . "TONE OF VOICE:\nUpbeat, confident, plain language.\n\n"
            . "LAYOUT:\nEdge-to-edge imagery.\n\n"
            . "STYLE:\nLots of whitespace, one accent color.",
            $resolved->storySections,
        );

        self::assertSame('C-level buyers without deep tech background.', $resolved->audienceHint);
        self::assertSame('Sketchy hand-drawn look.', $resolved->styleHint);

        self::assertCount(2, $resolved->personas);
        self::assertSame('Anna', $resolved->personas[0]->name);
        self::assertSame('Curious analyst who asks sharp questions.', $resolved->personas[0]->description);
        self::assertSame('fable', $resolved->personas[0]->voice);
        self::assertSame('Ben', $resolved->personas[1]->name);
        self::assertNull($resolved->personas[1]->voice);   // no metadata voice -> generator falls back
    }

    public function testUnknownUidsAreSkippedNotFailed(): void
    {
        $repository = $this->repository([
            3 => new StubPromptSnippet(3, 'Anna', 'Curious analyst.'),
        ]);
        $selection = new PromptSnippetSelection(audience: 99, personas: [3, 98]);

        $resolved = $this->resolver($repository)->resolve($selection);

        self::assertSame('', $resolved->schaubildSections);  // all section slots unresolved -> ''
        self::assertSame('', $resolved->audienceHint);
        self::assertCount(1, $resolved->personas);
        self::assertSame('Anna', $resolved->personas[0]->name);
    }

    public function testNonStringMetadataVoiceDegradesToNull(): void
    {
        $repository = $this->repository([
            3 => new StubPromptSnippet(3, 'Anna', 'Analyst.', ['voice' => 42]),
            4 => new StubPromptSnippet(4, 'Ben', 'Host.', ['voice' => '']),
        ]);

        $resolved = $this->resolver($repository)->resolve(new PromptSnippetSelection(personas: [3, 4]));

        self::assertNull($resolved->personas[0]->voice);
        self::assertNull($resolved->personas[1]->voice);
    }
}
