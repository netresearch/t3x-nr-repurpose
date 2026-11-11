<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Exception\GuardrailPolicyException;

use function is_callable;
use function is_string;

/**
 * The text of a guardrail's refusal that may be stored on a job or an artifact.
 *
 * The refusal of nr-llm's rule-set judge quotes the rule and the passage of the
 * generated text it found (nr-llm ADR-288). A stored error_message outlives the run
 * and is shown to every module user, whatever the run's privacy level, so it gets
 * the refusal without the passage: GuardrailViolationException::storableMessage().
 * An nr-llm without that method quotes no generated text, and its message is kept
 * as before.
 *
 * @internal
 */
final class GuardrailRefusal
{
    public static function storableMessage(GuardrailPolicyException $e): string
    {
        $storable = [$e, 'storableMessage'];
        if (is_callable($storable)) {
            $message = $storable();
            if (is_string($message)) {
                return $message;
            }
        }

        return $e->getMessage();
    }
}
