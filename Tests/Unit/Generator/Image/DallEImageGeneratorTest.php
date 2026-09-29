<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Image;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use ReflectionClass;

final class DallEImageGeneratorTest extends TestCase
{
    public function testModelConstantPinsTheGptImage2Fallback(): void
    {
        // getModel() delegates to nr-llm's DallEImageService::resolveModelForConfiguration(),
        // falling back to this constant; asserting the constant pins the fallback without
        // reflection (nr-llm's DallEImageService is final, not mockable). The
        // ImageGeneratorInterface seam exists precisely so every other test stubs the
        // resolved model instead.
        self::assertSame('gpt-image-2', DallEImageGenerator::MODEL);
    }

    /**
     * The failure reason ends up in the artifact's error_message, which every module user
     * sees; the provider's message (request details, paths) belongs in the server log only.
     */
    public function testAFailedGenerationThrowsAFixedMessageAndLogsTheCause(): void
    {
        // nr-llm's service is final; an instance without its constructor fails on first
        // use with an Error, which stands in for any provider failure here.
        $service = (new ReflectionClass(DallEImageService::class))->newInstanceWithoutConstructor();
        $logger  = new RecordingLogger();

        try {
            (new DallEImageGenerator($service, $logger))->generateToFile('prompt', '1024x1024', '/var/secret-dir/out.png');
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('DALL-E image generation failed', $e->getMessage());
            $inner = $e->getPrevious();
            self::assertNotNull($inner);
            self::assertStringNotContainsString($inner->getMessage(), $e->getMessage());
        }

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($inner, $logger->records[0]['context']['exception'] ?? null);
    }
}
