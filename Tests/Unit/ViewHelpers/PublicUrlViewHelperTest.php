<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\ViewHelpers;

use Netresearch\NrRepurpose\ViewHelpers\PublicUrlViewHelper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class PublicUrlViewHelperTest extends TestCase
{
    public function testResolvesTheFileThroughTheInjectedResourceFactory(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/repurpose/story-slide-1.png');
        $factory = $this->createMock(ResourceFactory::class);
        $factory->expects(self::once())->method('getFileObject')->with(42)->willReturn($file);

        self::assertSame('/fileadmin/repurpose/story-slide-1.png', $this->render($factory, 42));
    }

    public function testAnUnresolvableFileRendersNothing(): void
    {
        $factory = $this->createStub(ResourceFactory::class);
        $factory->method('getFileObject')->willThrowException(new RuntimeException('No file found'));

        self::assertSame('', $this->render($factory, 42));
    }

    public function testUidZeroRendersNothingWithoutALookup(): void
    {
        $factory = $this->createMock(ResourceFactory::class);
        $factory->expects(self::never())->method('getFileObject');

        self::assertSame('', $this->render($factory, 0));
    }

    private function render(ResourceFactory $factory, int $fileUid): string
    {
        $viewHelper = new PublicUrlViewHelper($factory);
        $viewHelper->setArguments(['fileUid' => $fileUid]);

        return $viewHelper->render();
    }
}
