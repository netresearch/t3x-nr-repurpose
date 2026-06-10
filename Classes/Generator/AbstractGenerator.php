<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Shared base for the real artifact generators. Provides Specialized-call guarding
 * (budget + availability), Fluid rendering of the branded theme templates via the
 * v14 ViewFactory API, a per-run temp directory and a uniform failed-artifact helper.
 *
 * Concrete generators MUST NOT throw for a single-artifact business failure: record the
 * artifact as failed and return false so sibling generators keep running.
 */
abstract class AbstractGenerator implements ArtifactGeneratorInterface
{
    public function __construct(
        protected readonly JobProcessingRepository $jobs,
        protected readonly BudgetServiceInterface $budget,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Guard a Specialized nr-llm call (TTS/FAL) which is NOT covered by the budget middleware.
     * Returns true when the planned cost is within budget AND the service is available.
     */
    protected function specializedAllowed(GenerationContext $ctx, float $plannedCost, bool $serviceAvailable): bool
    {
        if (!$this->budget->check($ctx->beUser, $plannedCost)->allowed) {
            return false;
        }

        return $serviceAvailable;
    }

    /**
     * Render one of the branded theme templates to an HTML string.
     *
     * @param array<string, mixed> $variables
     */
    protected function renderTemplate(string $area, string $theme, array $variables): string
    {
        $templateName = $theme === 'nr' ? 'Nr' : 'Neutral';
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                sprintf('EXT:nr_repurpose/Resources/Private/Templates/Generated/%s/%s.html', $area, $templateName),
            ),
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }

    /** Absolute path to a fresh, writable per-run temp directory (auto-created). */
    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/nrrepurpose_' . bin2hex(random_bytes(8));
        GeneralUtility::mkdir_deep($dir);

        return $dir;
    }

    /**
     * Canonical shape of the "prompts" object every generator stores in the artifact
     * metadata JSON for full generation transparency. Texts are verbatim and complete —
     * never truncated. All keys are optional; an artifact only carries the calls it
     * actually made:
     *
     *   system     LLM system prompt
     *   user       LLM user prompt
     *   image      image-generation prompt
     *   imageModel image model id (ImageGeneratorInterface::getModel())
     *   imageSize  effective image size used ("WIDTHxHEIGHT")
     *   ttsModel   TTS model id
     *   voices     per-speaker TTS voice map {speaker: voice}
     *
     * The Show view renders this object in the per-artifact "Generation parameters" panel.
     *
     * @param array<string, string>|null $voices
     *
     * @return array<string, mixed>
     */
    protected function promptsMetadata(
        ?string $system = null,
        ?string $user = null,
        ?string $image = null,
        ?string $imageModel = null,
        ?string $imageSize = null,
        ?string $ttsModel = null,
        ?array $voices = null,
    ): array {
        return array_filter(
            [
                'system' => $system,
                'user' => $user,
                'image' => $image,
                'imageModel' => $imageModel,
                'imageSize' => $imageSize,
                'ttsModel' => $ttsModel,
                'voices' => $voices,
            ],
            static fn (string|array|null $value): bool => $value !== null,
        );
    }

    /** Record a previously-inserted artifact row as failed and log the reason. */
    protected function failArtifact(int $artifactUid, int $jobUid, string $reason): void
    {
        $this->logger->warning('Artifact generation failed', [
            'job' => $jobUid,
            'artifact' => $artifactUid,
            'reason' => $reason,
        ]);
        $this->jobs->updateArtifact($artifactUid, [
            'status' => ArtifactStatus::Failed->value,
            'error_message' => $reason,
        ]);
    }
}
