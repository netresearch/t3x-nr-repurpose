<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Persistence;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Plain DBAL persistence for the long-running Messenger worker.
 * Avoids Extbase persistence-session pitfalls in a recycled consumer process.
 */
class JobProcessingRepository
{
    private const JOB_TABLE = 'tx_nrrepurpose_domain_model_job';
    private const ARTIFACT_TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {}

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

    public function setLanguageDetected(int $jobUid, string $language): void
    {
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['language_detected' => $language, 'tstamp' => time()],
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

    /**
     * Remove all artifact rows for a job before a fresh generation run. Generators append a row
     * per (type, variant), so reprocessing — e.g. a Messenger redelivery after a mid-run worker
     * crash, or a manual re-queue — would otherwise accumulate a second set of rows and the
     * result view would show duplicates. Clearing first makes generation idempotent.
     *
     * The referenced FAL files (sys_file + physical bytes) are removed first so a re-run does not
     * leak orphaned files/records. Permission evaluation is disabled for the delete because the
     * worker runs in a CLI context with no backend user (mirrors JobFileStorage::store()).
     */
    public function deleteArtifactsForJob(int $jobUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE);

        /** @var list<array{file_uid: int|string|null, subtitle_file_uid: int|string|null}> $rows */
        $rows = $connection->select(['file_uid', 'subtitle_file_uid'], self::ARTIFACT_TABLE, ['job' => $jobUid])
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            foreach (['file_uid', 'subtitle_file_uid'] as $column) {
                $this->deleteFalFile((int) ($row[$column] ?? 0));
            }
        }

        $connection->delete(self::ARTIFACT_TABLE, ['job' => $jobUid]);
    }

    /**
     * Delete one FAL file by sys_file uid, tolerating an already-removed/unresolvable file.
     * Permission evaluation is toggled off for the trusted system delete and restored afterwards
     * so the shared (cached) storage instance is not left mutated.
     */
    private function deleteFalFile(int $fileUid): void
    {
        if ($fileUid <= 0) {
            return;
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $storage = $file->getStorage();
            $previousEvaluatePermissions = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(false);
            try {
                $storage->deleteFile($file);
            } finally {
                $storage->setEvaluatePermissions($previousEvaluatePermissions);
            }
        } catch (\Throwable) {
            // File already deleted or unresolvable — the artifact row delete still cleans up.
        }
    }

    /**
     * Fill a previously-inserted (pending) artifact row. Only whitelisted columns are writable.
     * Empty $fields is a no-op (no UPDATE issued).
     *
     * @param array<string, mixed> $fields keys: file_uid, subtitle_file_uid, source_html,
     *                                      script_text, metadata, status, variant, error_message
     */
    public function updateArtifact(int $artifactUid, array $fields): void
    {
        $allowed = [
            'file_uid', 'subtitle_file_uid', 'source_html',
            'script_text', 'metadata', 'status', 'variant', 'error_message',
        ];
        $update = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $update[$column] = $fields[$column];
            }
        }
        if ($update === []) {
            return;
        }
        $update['tstamp'] = time();

        $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE)
            ->update(self::ARTIFACT_TABLE, $update, ['uid' => $artifactUid]);
    }
}
