<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Plain DBAL persistence for the long-running Messenger worker.
 * Avoids Extbase persistence-session pitfalls in a recycled consumer process.
 */
class JobProcessingRepository
{
    private const string JOB_TABLE = 'tx_nrrepurpose_domain_model_job';

    private const string ARTIFACT_TABLE = 'tx_nrrepurpose_domain_model_artifact';

    private const string REFERENCE_TABLE = 'sys_file_reference';

    private const string SOURCE_PDF_FIELD = 'source_pdf';

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

    /**
     * End a generation run: final status, its error message ('' clears one from an
     * earlier run), and the artifacts counter. The counter is the inline field's
     * column; DataHandler keeps it for records it writes, and the worker writes
     * artifact rows directly, so the rows are counted here.
     */
    public function finishJob(int $jobUid, JobStatus $status, string $error, ?FailureReason $reason = null): void
    {
        $artifacts = $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE)
            ->count('uid', self::ARTIFACT_TABLE, ['job' => $jobUid]);

        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            [
                'status'         => $status->value,
                'current_step'   => 'done',
                'progress'       => 100,
                'error_message'  => $error,
                'failure_reason' => $reason->value ?? '',
                'artifacts'      => $artifacts,
                'tstamp'         => time(),
            ],
            ['uid' => $jobUid],
        );
    }

    public function markFailed(int $jobUid, string $error, FailureReason $reason): void
    {
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['status' => JobStatus::Failed->value, 'error_message' => $error, 'failure_reason' => $reason->value, 'tstamp' => time()],
            ['uid' => $jobUid],
        );
    }

    /**
     * Back into the queue. One statement that only a failed job passes, so of two clicks, or of
     * two people, one run starts: the caller dispatches only when this returns true.
     */
    public function requeueFailed(int $jobUid): bool
    {
        return $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            [
                'status'            => JobStatus::Queued->value,
                'current_step'      => '',
                'progress'          => 0,
                'error_message'     => '',
                'failure_reason'    => '',
                'source_report'     => '',
                'language_detected' => '',
                'tstamp'            => time(),
            ],
            ['uid' => $jobUid, 'status' => JobStatus::Failed->value],
        ) === 1;
    }

    public function setLanguageDetected(int $jobUid, string $language): void
    {
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['language_detected' => $language, 'tstamp' => time()],
            ['uid' => $jobUid],
        );
    }

    /** What a page source used, left out or was cut by; the result view shows it (ADR-010). */
    public function setSourceReport(int $jobUid, SourceReport $report): void
    {
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['source_report' => $report->toJson(), 'tstamp' => time()],
            ['uid' => $jobUid],
        );
    }

    /**
     * Attach the file a backend user picked in the new-job form, stored as DataHandler stores a
     * TCA type=file field: a sys_file_reference row of the job, the number of references in the
     * job's column, and the reference index, so the Filelist module warns before deleting it.
     */
    public function attachSourcePdf(int $jobUid, int $fileUid): void
    {
        $now  = time();
        $conn = $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE);
        $conn->insert(self::REFERENCE_TABLE, [
            // A job lies on the page the form was opened on; its reference lies with it.
            'pid'             => ScalarCast::int($this->findRow($jobUid)['pid'] ?? 0),
            'uid_local'       => $fileUid,
            'uid_foreign'     => $jobUid,
            'tablenames'      => self::JOB_TABLE,
            'fieldname'       => self::SOURCE_PDF_FIELD,
            'sorting_foreign' => 1,
            'crdate'          => $now,
            'tstamp'          => $now,
        ]);

        $referenceUid = (int) $conn->lastInsertId();

        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, [self::SOURCE_PDF_FIELD => 1, 'tstamp' => $now], ['uid' => $jobUid]);
        // Core offers no public API for this short of DataHandler, which an editor's table
        // permissions would refuse here.
        /** @phpstan-ignore classConstant.internalClass */
        $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
        /** @phpstan-ignore method.internal */
        $referenceIndex->updateRefIndexTable(self::JOB_TABLE, $jobUid);
        /** @phpstan-ignore method.internal */
        $referenceIndex->updateRefIndexTable(self::REFERENCE_TABLE, $referenceUid);
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
            'pid'           => 0,
            'job'           => $jobUid,
            'type'          => $type->value,
            'variant'       => $variant,
            'file_uid'      => $fileUid,
            'status'        => $status->value,
            'error_message' => $error ?? '',
            'crdate'        => time(),
            'tstamp'        => time(),
        ]);

        return (int) $conn->lastInsertId();
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
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, ['artifacts' => 0], ['uid' => $jobUid]);
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
            $file                        = $this->resourceFactory->getFileObject($fileUid);
            $storage                     = $file->getStorage();
            $previousEvaluatePermissions = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(false);
            try {
                $storage->deleteFile($file);
            } finally {
                $storage->setEvaluatePermissions($previousEvaluatePermissions);
            }
        } catch (Throwable) {
            // File already deleted or unresolvable — the artifact row delete still cleans up.
        }
    }

    /**
     * Fill a previously-inserted (pending) artifact row. Only whitelisted columns are writable.
     * Empty $fields is a no-op (no UPDATE issued).
     *
     * @param array<string, mixed> $fields keys: file_uid, subtitle_file_uid, source_html,
     *                                     script_text, metadata, status, variant, error_message,
     *                                     failure_reason
     */
    public function updateArtifact(int $artifactUid, array $fields): void
    {
        $allowed = [
            'file_uid',
            'subtitle_file_uid',
            'source_html',
            'script_text',
            'metadata',
            'source_review',
            'status',
            'variant',
            'error_message',
            'failure_reason',
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

    /** @param array<string, mixed> $review */
    public function setSourceReview(int $jobUid, array $review): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::JOB_TABLE)
            ->update(
                self::JOB_TABLE,
                [
                    'source_review' => json_encode(
                        $review,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                    'tstamp' => time(),
                ],
                ['uid' => $jobUid],
            );
    }

    /**
     * Store only against the exact review read before the potentially slow evaluation.
     * SQL NULL is a distinct snapshot; concurrent stronger verdicts must survive.
     *
     * @param array<string, mixed> $review
     */
    public function compareAndSetSourceReview(
        int $jobUid,
        array $review,
        ?string $expected,
    ): bool {
        $encoded      = json_encode($review, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::JOB_TABLE);
        $queryBuilder
            ->update(self::JOB_TABLE)
            ->set('source_review', $encoded)
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $jobUid,
                        Connection::PARAM_INT,
                    ),
                ),
            );
        if ($expected === null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNull('source_review'),
            );
        } else {
            $parameter = $queryBuilder->createNamedParameter($expected);
            // MySQL's default text collation ignores case. Compare the raw snapshot bytes.
            $condition = $this->connectionPool
                ->getConnectionForTable(self::JOB_TABLE)
                ->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'BINARY ' . $queryBuilder->quoteIdentifier('source_review') . ' = BINARY ' . $parameter : $queryBuilder->expr()->eq('source_review', $parameter);
            $queryBuilder->andWhere($condition);
        }

        if ($queryBuilder->executeStatement() > 0) {
            return true;
        }

        // Some drivers report changed rather than matched rows for an identical write.
        return $encoded === $expected && ($this->findRow($jobUid)['source_review'] ?? null) === $expected;
    }
}
