<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Model;

use Netresearch\NrRepurpose\Domain\Model\Artifact;
use PHPUnit\Framework\TestCase;

final class ArtifactTest extends TestCase
{
    public function testGetMetadataArrayDecodesSlideMetadata(): void
    {
        $artifact = new Artifact();
        $artifact->_setProperty('metadata', '{"role":"cover","slideIndex":1,"slideTotal":3}');

        self::assertSame(
            ['role' => 'cover', 'slideIndex' => 1, 'slideTotal' => 3],
            $artifact->getMetadataArray(),
        );
    }

    public function testGetMetadataArrayToleratesEmptyAndInvalidJson(): void
    {
        $artifact = new Artifact();
        self::assertSame([], $artifact->getMetadataArray());

        $artifact->_setProperty('metadata', '{broken');
        self::assertSame([], $artifact->getMetadataArray());

        $artifact->_setProperty('metadata', '"just a string"');
        self::assertSame([], $artifact->getMetadataArray());
    }
}
