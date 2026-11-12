<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrRepurpose\Service\GuardrailRefusal;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\PassageQuotingRefusal;
use PHPUnit\Framework\TestCase;

final class GuardrailRefusalTest extends TestCase
{
    public function testARefusalThatQuotesThePassageIsStoredWithoutIt(): void
    {
        $stored = GuardrailRefusal::storableMessage(new PassageQuotingRefusal());

        self::assertSame(PassageQuotingRefusal::STORABLE, $stored);
        self::assertStringNotContainsString(PassageQuotingRefusal::PASSAGE, $stored);
    }

    /** A term list's refusal names the term and quotes no generated text; it is kept as it is. */
    public function testARefusalWithoutAStorableFormKeepsItsMessage(): void
    {
        self::assertSame('The text contains "Rabatt".', GuardrailRefusal::storableMessage(new GuardrailViolationException('Guardrail', 'The text contains "Rabatt".')));
    }
}
