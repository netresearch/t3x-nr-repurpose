<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Persistence;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Plain DBAL persistence for the long-running Messenger worker.
 * Avoids Extbase persistence-session pitfalls in a recycled consumer process.
 */
final class JobProcessingRepository
{
    private const JOB_TABLE = 'tx_nrrepurpose_domain_model_job';
    private const ARTIFACT_TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /** @return array<string, mixed>|null */
    public function findRow(int $jobUid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::JOB_TABLE)
            ->select(['*'], self::JOB_TABLE, ['uid' => $jobUid])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    public function markStatus(int $jobUid, JobStatus $status, ?string $currentStep = null, ?int $progress = null): void
    {
        $fields = ['status' => $status->value, 'tstamp' => time()];
        if ($currentStep !== null) {
            $fields['current_step'] = $currentStep;
        }
        if ($progress !== null) {
            $fields['progress'] = $progress;
        }
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, $fields, ['uid' => $jobUid]);
    }

    public function markFailed(int $jobUid, string $error): void
    {
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['status' => JobStatus::Failed->value, 'error_message' => $error, 'tstamp' => time()],
            ['uid' => $jobUid],
        );
    }

    public function insertArtifact(
        int $jobUid,
        ArtifactType $type,
        string $variant,
        int $fileUid,
        ArtifactStatus $status,
        ?string $error = null,
    ): int {
        $conn = $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE);
        $conn->insert(self::ARTIFACT_TABLE, [
            'pid' => 0,
            'job' => $jobUid,
            'type' => $type->value,
            'variant' => $variant,
            'file_uid' => $fileUid,
            'status' => $status->value,
            'error_message' => $error ?? '',
            'crdate' => time(),
            'tstamp' => time(),
        ]);

        return (int)$conn->lastInsertId();
    }
}
