<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobStatusTest extends TestCase
{
    public function testQueuedIsTheInitialBackedValue(): void
    {
        self::assertSame('queued', JobStatus::Queued->value);
    }

    public function testIsTerminalIsTrueOnlyForEndStates(): void
    {
        self::assertTrue(JobStatus::Done->isTerminal());
        self::assertTrue(JobStatus::PartiallyDone->isTerminal());
        self::assertTrue(JobStatus::Failed->isTerminal());
        self::assertFalse(JobStatus::Queued->isTerminal());
        self::assertFalse(JobStatus::Generating->isTerminal());
    }
}
