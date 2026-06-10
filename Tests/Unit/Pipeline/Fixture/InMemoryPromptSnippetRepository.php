<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Pipeline\Fixture;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;

/**
 * In-memory stand-in for the nr-llm PromptSnippetRepository: serves a fixed uid => snippet
 * map and records every findByUids() batch so tests can assert the one-fetch contract.
 */
final class InMemoryPromptSnippetRepository extends PromptSnippetRepository
{
    /** @var list<list<int>> */
    public array $seenUidBatches = [];

    /** @var array<int, PromptSnippet> */
    private array $byUid;

    /** @param array<int, PromptSnippet> $byUid */
    public function __construct(array $byUid)
    {
        $this->byUid = $byUid;
    }

    /**
     * Contract: input order, unknown uids skipped.
     *
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    public function findByUids(array $uids): array
    {
        $this->seenUidBatches[] = $uids;

        $found = [];
        foreach ($uids as $uid) {
            if (isset($this->byUid[$uid])) {
                $found[] = $this->byUid[$uid];
            }
        }

        return $found;
    }
}
