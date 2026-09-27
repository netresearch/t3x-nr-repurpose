<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\ViewHelpers;

use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\ViewHelpers\PublicUrlViewHelper;

final class PublicUrlViewHelperTest extends AbstractFunctionalTestCase
{
    /**
     * Fluid resolves ViewHelpers from the container (cms-fluid tags them public and
     * non-shared), so the constructor-injected resource factory must be wired there.
     */
    public function testTheContainerBuildsTheViewHelperWithItsDependency(): void
    {
        $first  = $this->get(PublicUrlViewHelper::class);
        $second = $this->get(PublicUrlViewHelper::class);

        self::assertInstanceOf(PublicUrlViewHelper::class, $first);
        self::assertNotSame($first, $second);

        $first->setArguments(['fileUid' => 999999]);
        self::assertSame('', $first->render());
    }
}
