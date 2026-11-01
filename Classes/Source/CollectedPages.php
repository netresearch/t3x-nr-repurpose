<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

/**
 * The texts of a page source while PageSourceReader fetches them, and what happened to the pages
 * that gave none. The text limit ends the collection at a page boundary, except for a first page
 * longer than the limit, which is cut.
 */
final class CollectedPages
{
    /** @var list<SourceDocument> */
    private array $documents = [];

    /** @var list<string> */
    private array $sections = [];

    private int $length = 0;

    public int $failed = 0;

    public int $leftOut = 0;

    public bool $cutByText = false;

    public string $firstFailure = '';

    public function __construct(
        private readonly int $maxChars,
    ) {}

    public function add(SourceDocument $document, string $pageTitle): void
    {
        $text = $document->text;
        if (mb_strlen($text) > $this->maxChars - $this->length) {
            $this->cutByText = true;
            if ($this->sections !== []) {
                ++$this->leftOut;

                return;
            }

            $text = mb_substr($text, 0, $this->maxChars);
        }

        $this->length += mb_strlen($text);
        $this->documents[] = $document;
        $this->sections[]  = '# ' . $pageTitle . "\n\n" . $text;
    }

    public function fail(string $message): void
    {
        ++$this->failed;
        if ($this->firstFailure === '') {
            $this->firstFailure = $message;
        }
    }

    public function first(): ?SourceDocument
    {
        return $this->documents[0] ?? null;
    }

    public function count(): int
    {
        return count($this->sections);
    }

    /** One page keeps its plain text; several become sections under their titles. */
    public function text(): string
    {
        if (count($this->sections) === 1) {
            return explode("\n\n", $this->sections[0], 2)[1] ?? '';
        }

        return implode("\n\n", $this->sections);
    }
}
