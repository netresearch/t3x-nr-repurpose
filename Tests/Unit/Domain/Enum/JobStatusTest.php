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

    public function testLabelKeyIsNamespacedByBackedValue(): void
    {
        self::assertSame('status.queued', JobStatus::Queued->getLabelKey());
        self::assertSame('status.partially_done', JobStatus::PartiallyDone->getLabelKey());
    }

    public function testSeverityMapsTerminalStatesToContextualClasses(): void
    {
        self::assertSame('success', JobStatus::Done->getSeverity());
        self::assertSame('warning', JobStatus::PartiallyDone->getSeverity());
        self::assertSame('danger', JobStatus::Failed->getSeverity());
        self::assertSame('info', JobStatus::Queued->getSeverity());
        self::assertSame('info', JobStatus::Generating->getSeverity());
    }
}
