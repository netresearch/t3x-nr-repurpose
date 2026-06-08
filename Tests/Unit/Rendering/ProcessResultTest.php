<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class ProcessResultTest extends TestCase
{
    public function testSuccessfulResultExposesOutputs(): void
    {
        $result = new ProcessResult(0, "5.250000\n", '');

        self::assertTrue($result->successful());
        self::assertSame("5.250000\n", $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testNonZeroExitIsNotSuccessful(): void
    {
        $result = new ProcessResult(1, '', 'ffmpeg: No such file');

        self::assertFalse($result->successful());
        self::assertSame(1, $result->exitCode);
        self::assertSame('ffmpeg: No such file', $result->stderr);
    }
}
