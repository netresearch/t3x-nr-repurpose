<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use function get_object_vars;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;

/**
 * Collects the hints nr-llm's guardrails report on a text they let through, such
 * as a broken rule of the rule-set judge in report mode (nr-llm ADR-288).
 *
 * The extension's completions return a decoded array or a string, which carries no
 * response metadata, so the hints come from nr-llm's GuardrailHintCollector: the
 * call runs inside its capture(). The collector exists from the nr-llm release that
 * brings the judge on. Configuration/Services.yaml injects it as an optional service
 * (`@?`), so with an older nr-llm it is null, the call simply runs and reports no
 * hints. It is used by its shape, without type checks against nr-llm classes.
 *
 * @internal
 */
final readonly class GuardrailHintCapture
{
    /**
     * @param object|null $collector nr-llm's GuardrailHintCollector, a shared service, so it
     *                               is the instance its middleware records into; null when
     *                               this nr-llm has none
     */
    public function __construct(
        private ?object $collector = null,
    ) {}

    /**
     * Run $call and return its result with the hints reported while it ran. A
     * throwing call throws on, as without the collector.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{result: T, hints: list<array{kind: string, message: string}>}
     */
    public function capture(callable $call): array
    {
        $capture = $this->collectorCapture();
        if ($capture === null) {
            return ['result' => $call(), 'hints' => []];
        }

        // The result is taken from the call itself, so a collector answer of another
        // shape costs the hints and never the result.
        $result   = null;
        $captured = $capture(static function () use ($call, &$result): mixed {
            return $result = $call();
        });

        /** @var T $result set by the call above; capture() runs it or throws */
        return ['result' => $result, 'hints' => is_array($captured) ? $this->normalize($captured['hints'] ?? null) : []];
    }

    /**
     * The collector's capture(), or null when this nr-llm has no collector.
     *
     * @return (callable(callable(): mixed): mixed)|null
     */
    private function collectorCapture(): ?callable
    {
        if ($this->collector === null) {
            return null;
        }

        $capture = [$this->collector, 'capture'];

        return is_callable($capture) ? $capture : null;
    }

    /**
     * The hints by shape: nr-llm's GuardrailHint objects, or arrays with the same
     * keys. Only kind and message are kept; an entry without a message is skipped.
     *
     * @return list<array{kind: string, message: string}>
     */
    private function normalize(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $hints = [];
        foreach ($entries as $entry) {
            $fields = is_object($entry) ? get_object_vars($entry) : $entry;
            if (!is_array($fields) || !is_string($fields['message'] ?? null) || $fields['message'] === '') {
                continue;
            }

            $hints[] = [
                'kind'    => is_string($fields['kind'] ?? null) ? $fields['kind'] : '',
                'message' => $fields['message'],
            ];
        }

        return $hints;
    }
}
