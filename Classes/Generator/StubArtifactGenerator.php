<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Placeholder generator for the walking skeleton: writes a small .txt file to FAL and
 * records a `stub` artifact. Replaced by the real podcast/schaubild/story generators in Plan 5.
 */
final class StubArtifactGenerator implements ArtifactGeneratorInterface
{
    public function __construct(
        private readonly JobFileStorage $fileStorage,
        private readonly JobProcessingRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(array $jobRow): bool
    {
        return true;
    }

    public function generate(array $jobRow): bool
    {
        $jobUid = (int)$jobRow['uid'];
        try {
            $content = sprintf(
                "nr_repurpose stub artifact\nJob #%d\nSource: %s\nTheme: %s\n",
                $jobUid,
                (string)($jobRow['source_value'] ?? ''),
                (string)($jobRow['theme'] ?? ''),
            );
            $file = $this->fileStorage->store($content, 'stub.txt');
            $this->jobs->insertArtifact($jobUid, ArtifactType::Stub, 'default', $file->getUid(), ArtifactStatus::Done);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Stub artifact failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->insertArtifact($jobUid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Failed, $e->getMessage());

            return false;
        }
    }
}
