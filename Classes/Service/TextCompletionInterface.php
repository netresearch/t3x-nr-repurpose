<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * The text completions this extension asks for: decoded JSON, a schema-valid
 * structured answer, and Markdown. Implemented by ConfiguredCompletionService.
 *
 * The extension's own contract rather than nr-llm's CompletionServiceInterface:
 * nr-llm 0.39 changed `completeStructured()` to return a
 * StructuredCompletionResponse instead of an array (ADR-211), and no single class
 * can implement that method for 0.38 and 0.39 at once — a return type has to be a
 * subtype of both, and `array` and an object have none in common. This interface
 * is implemented here and only calls into nr-llm, so it holds on both.
 */
interface TextCompletionInterface
{
    /**
     * @return array<string, mixed> the decoded JSON answer
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array;

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed> the decoded answer, valid against $schema
     */
    public function completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): array;

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string;
}
