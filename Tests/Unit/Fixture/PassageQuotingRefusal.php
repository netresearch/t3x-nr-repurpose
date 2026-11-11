<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use RuntimeException;

/**
 * A block of nr-llm's rule-set judge (nr-llm ADR-288) as the released nr-llm cannot
 * build it yet: the message quotes the passage of the generated text, and
 * storableMessage() is the same refusal without it. GuardrailViolationException is
 * final, so this double has the shape instead of the class.
 */
final class PassageQuotingRefusal extends RuntimeException implements GuardrailPolicyException
{
    public const string PASSAGE = '50 % Rabatt auf alles';

    public const string SHOWN = 'The text breaks the rule "No discounts": "' . self::PASSAGE . '".';

    public const string STORABLE = 'The text breaks the rule "No discounts".';

    public function __construct()
    {
        parent::__construct(self::SHOWN, 1790500001);
    }

    public function storableMessage(): string
    {
        return self::STORABLE;
    }
}
