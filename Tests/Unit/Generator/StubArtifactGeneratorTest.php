<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\StubArtifactGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\File;

final class StubArtifactGeneratorTest extends TestCase
{
    /**
     * The row's error_message is shown to every module user; a FAL error can carry the
     * storage path, so the row gets a fixed text and the exception goes to the log.
     */
    public function testAFailedWriteStoresAFixedReasonAndLogsTheCause(): void
    {
        $cause   = new RuntimeException('Could not write /var/www/html/fileadmin/repurpose/stub.txt');
        $storage = new class ($cause) extends JobFileStorage {
            public function __construct(private readonly RuntimeException $cause) {}

            public function store(string $content, string $fileName, ?AiProvenance $provenance = null): File
            {
                throw $this->cause;
            }
        };
        $jobs = new class extends JobProcessingRepository {
            /** @var list<array{status: string, error: ?string}> */
            public array $inserted = [];

            public function __construct() {}

            public function insertArtifact(int $jobUid, ArtifactType $type, string $variant, int $fileUid, ArtifactStatus $status, ?string $error = null): int
            {
                $this->inserted[] = ['status' => $status->value, 'error' => $error];

                return 1;
            }
        };
        $logger  = new RecordingLogger();
        $context = new GenerationContext(['uid' => 7], new SourceDocument('Doc', 'text', 'https://example.com/', 0, 'en'), new ContentBrief('Doc', 'Summary', [], [], 'All', 'en'), 'nr', 0);

        self::assertFalse((new StubArtifactGenerator($storage, $jobs, $logger))->generate($context));
        self::assertSame([['status' => 'failed', 'error' => 'Stub artifact failed']], $jobs->inserted);
        self::assertContains($cause, array_map(static fn (array $record): mixed => $record['context']['exception'] ?? null, $logger->records));
    }
}
