<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Placeholder generator for the walking skeleton: writes a small .txt file to FAL and records a
 * `stub` artifact. Now consumes the GenerationContext (Plan 3). Replaced by the real
 * podcast/schaubild/story generators in Plan 5.
 */
final class StubArtifactGenerator implements ArtifactGeneratorInterface
{
    public function __construct(
        private readonly JobFileStorage $fileStorage,
        private readonly JobProcessingRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();
        try {
            $content = sprintf(
                "nr_repurpose stub artifact\nJob #%d\nSource: %s\nTheme: %s\nTitle: %s\nLanguage: %s\n",
                $jobUid,
                (string) ($ctx->jobRow['source_value'] ?? ''),
                $ctx->theme,
                $ctx->brief->title,
                $ctx->brief->language,
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
