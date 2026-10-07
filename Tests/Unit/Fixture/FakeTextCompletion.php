<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Service\TextCompletionInterface;
use Throwable;

/**
 * Records every call and replays canned answers. Its members carry the names of
 * nr-llm's Testing\FakeCompletionService, which the generator tests used before
 * the generators took TextCompletionInterface, and set {@see $throwable} the
 * same way: the next call throws it, once.
 */
final class FakeTextCompletion implements TextCompletionInterface
{
    /** @var array<string, mixed> */
    public array $jsonResult = [];

    /** @var array<string, mixed> */
    public array $structuredResult = [];

    public string $markdownResult = '';

    public ?Throwable $throwable = null;

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeJsonCalls = [];

    /** @var list<array{prompt: string, schema: array<string, mixed>, options: ?ChatOptions}> */
    public array $completeStructuredCalls = [];

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeMarkdownCalls = [];

    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $this->completeJsonCalls[] = ['prompt' => $prompt, 'options' => $options];
        $this->guardThrow();

        return $this->jsonResult;
    }

    public function completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): array
    {
        $this->completeStructuredCalls[] = ['prompt' => $prompt, 'schema' => $schema, 'options' => $options];
        $this->guardThrow();

        return $this->structuredResult;
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $this->completeMarkdownCalls[] = ['prompt' => $prompt, 'options' => $options];
        $this->guardThrow();

        return $this->markdownResult;
    }

    private function guardThrow(): void
    {
        if ($this->throwable instanceof Throwable) {
            $throwable       = $this->throwable;
            $this->throwable = null;

            throw $throwable;
        }
    }
}
