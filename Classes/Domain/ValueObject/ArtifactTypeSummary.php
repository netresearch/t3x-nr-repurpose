<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;

/**
 * Aggregate status of all artifacts of one type within a job — drives the
 * per-type artifact icon in the backend job list.
 */
final readonly class ArtifactTypeSummary
{
    public function __construct(
        public ArtifactType $type,
        public ArtifactStatus $status,
    ) {}

    /**
     * Folds the statuses of all artifacts of one type into a single aggregate:
     * any failure wins, done only when every artifact is done, pending otherwise.
     *
     * @param non-empty-list<ArtifactStatus> $statuses
     */
    public static function fromStatuses(ArtifactType $type, array $statuses): self
    {
        return new self($type, self::aggregate($statuses));
    }

    /**
     * Bootstrap contextual text-colour suffix for the list icon.
     * `get` prefix so Fluid `{summary.severity}` resolves it.
     */
    public function getSeverity(): string
    {
        return match ($this->status) {
            ArtifactStatus::Done => 'success',
            ArtifactStatus::Failed => 'danger',
            ArtifactStatus::Pending => 'muted',
        };
    }

    /** @param non-empty-list<ArtifactStatus> $statuses */
    private static function aggregate(array $statuses): ArtifactStatus
    {
        if (in_array(ArtifactStatus::Failed, $statuses, true)) {
            return ArtifactStatus::Failed;
        }

        if (in_array(ArtifactStatus::Pending, $statuses, true)) {
            return ArtifactStatus::Pending;
        }

        return ArtifactStatus::Done;
    }
}
